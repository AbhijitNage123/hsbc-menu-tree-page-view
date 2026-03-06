/**
 * HSBC Menu Tree Page View – front-end tree controller.
 *
 * Stack:
 *  - Vanilla JS for all DOM / event logic  (no jQuery)
 *  - fetch() + URLSearchParams for all AJAX calls
 *  - localStorage to persist expanded-node state across page loads
 *  - SortableJS (loaded as a WP script dependency) for drag-and-drop
 *  - CSS max-height transitions for expand / collapse animations
 *
 * Runtime data is passed from PHP via wp_localize_script() as window.AdminHSBCMTPV:
 * @see HSBC_Menu::enqueue_assets()
 *
 * High-level flow:
 *  1. DOMContentLoaded – find .hsbc-mtpv-wrap, set up search, event delegation,
 *     SortableJS on the root list, then auto-expand to the current post.
 *  2. User expands a node → expandItem() lazy-fetches children via AJAX,
 *     builds DOM rows with buildItem(), applies CSS transition, initialises
 *     SortableJS on the new child list.
 *  3. User hovers a row → showPopup() reveals the action popup for that item
 *     and clears any previously highlighted row across the whole tree.
 *  4. User drags a row → SortableJS onEnd calls movePage() AJAX handler.
 *  5. User clicks "After" / "Inside" in a popup → inline add-page form appears;
 *     on submit addPage() AJAX handler creates the post and redirects to its editor.
 *
 * CSS class conventions (defined in tree.css):
 *  .hsbc-mtpv-item            – <li> wrapper for each tree node.
 *  .hsbc-mtpv-has-children    – item has at least one child (shows toggle button).
 *  .hsbc-mtpv-expanded        – item is currently expanded.
 *  .hsbc-mtpv-current         – item represents the post currently being edited.
 *  .hsbc-mtpv-row--hover      – row is being hovered (shows drag handle, highlights bg).
 *  .hsbc-mtpv-popup--visible  – popup is shown.
 *  .hsbc-mtpv-popup--working  – popup has the add-form open (prevents auto-close).
 *  .hsbc-mtpv-children--collapsed – child list is collapsed (max-height: 0).
 */
( function () {
	'use strict';

	/** @type {{ ajaxUrl: string, nonce: string, enableTrash: boolean, currentPostId: number, ancestors: number[], i18n: Object }} */
	const { ajaxUrl, nonce, enableTrash, currentPostId, ancestors, i18n } = window.AdminHSBCMTPV;

	// =========================================================================
	// State
	// =========================================================================

	/** localStorage key used to persist the set of expanded node IDs. */
	const STORAGE_KEY = 'hsbc_mtpv_expanded';

	/**
	 * Load persisted expanded-node IDs from localStorage.
	 *
	 * Returns an empty Set if the key is missing or the stored JSON is invalid,
	 * so the tree starts fully collapsed on first use.
	 *
	 * @returns {Set<number>} Set of post IDs that were expanded in the last session.
	 */
	function loadExpandedState() {
		try {
			return new Set( JSON.parse( localStorage.getItem( STORAGE_KEY ) ) || [] );
		} catch {
			return new Set();
		}
	}

	/**
	 * Persist the current expanded-node IDs to localStorage.
	 *
	 * Called after every expand or collapse so the state survives page navigation.
	 */
	function saveExpandedState() {
		localStorage.setItem( STORAGE_KEY, JSON.stringify( [ ...state.expanded ] ) );
	}

	/**
	 * Module-level state object.
	 *
	 * @type {{ expanded: Set<number> }}
	 */
	const state = {
		expanded: loadExpandedState(),
	};

	// =========================================================================
	// Boot
	// =========================================================================

	document.addEventListener( 'DOMContentLoaded', async () => {
		const wrap = document.querySelector( '.hsbc-mtpv-wrap' );
		if ( ! wrap ) return;

		setupSearch( wrap );
		setupEventDelegation( wrap );
		initSortable( wrap.querySelector( '.hsbc-mtpv-tree' ) );

		await initExpandedState( wrap );
	} );

	// =========================================================================
	// Expanded-state restoration
	// Ancestors first (root → leaf order), then any other saved expansions.
	// =========================================================================

	/**
	 * Auto-expand the tree to reveal the post currently being edited,
	 * then restore any additional nodes the user had open last session.
	 *
	 * Ancestors are expanded in root-to-leaf order so each parent is in
	 * the DOM before we try to expand its child. The `ancestors` array
	 * from PHP is already ordered parent-first, so we reverse it to get
	 * root-first.
	 *
	 * Previously saved expansions that are already covered by the ancestor
	 * path are skipped to avoid duplicate AJAX calls.
	 *
	 * @param {HTMLElement} wrap - The .hsbc-mtpv-wrap container element.
	 * @returns {Promise<void>}
	 */
	async function initExpandedState( wrap ) {
		// Expand path to the page currently being edited.
		const orderedAncestors = [ ...( ancestors || [] ) ].reverse();
		for ( const id of orderedAncestors ) {
			const item = wrap.querySelector( `.hsbc-mtpv-item[data-id="${ id }"]` );
			if ( item && ! item.classList.contains( 'hsbc-mtpv-expanded' ) ) {
				await expandItem( item, false ); // false = skip localStorage write
			}
		}

		// Restore previously saved expansions.
		for ( const id of state.expanded ) {
			if ( ( ancestors || [] ).includes( id ) ) continue;
			const item = wrap.querySelector( `.hsbc-mtpv-item[data-id="${ id }"]` );
			if ( item && ! item.classList.contains( 'hsbc-mtpv-expanded' ) ) {
				await expandItem( item, false );
			}
		}
	}

	// =========================================================================
	// Search
	// =========================================================================

	/**
	 * Initialise the live-search filter for the tree panel.
	 *
	 * Behaviour:
	 *  - Filters all .hsbc-mtpv-item elements by their title text.
	 *  - Matching items are shown; non-matching items are hidden.
	 *  - Ancestor items of a matching node are always kept visible so the
	 *    match is reachable in the hierarchy.
	 *  - Matching text within the title link is wrapped in
	 *    <mark class="hsbc-mtpv-highlight"> for visual emphasis.
	 *  - The clear (×) button appears as soon as there is any input and
	 *    resets the filter when clicked.
	 *  - A "No pages found" message is shown when no items match.
	 *
	 * @param {HTMLElement} wrap - The .hsbc-mtpv-wrap container element.
	 */
	function setupSearch( wrap ) {
		const input     = wrap.querySelector( '.hsbc-mtpv-search' );
		const clearBtn  = wrap.querySelector( '.hsbc-mtpv-search-clear' );
		const noResults = wrap.querySelector( '.hsbc-mtpv-no-results' );

		if ( ! input ) return;

		input.addEventListener( 'input', () => {
			const query = input.value.trim().toLowerCase();
			clearBtn.hidden = ! query;

			const allItems = wrap.querySelectorAll( '.hsbc-mtpv-item' );

			if ( ! query ) {
				allItems.forEach( el => {
					el.hidden = false;
					clearHighlight( el.querySelector( '.hsbc-mtpv-title' ) );
				} );
				noResults.hidden = true;
				return;
			}

			let hits = 0;

			allItems.forEach( item => {
				const titleEl = item.querySelector( '.hsbc-mtpv-title' );
				const text    = titleEl ? titleEl.textContent.toLowerCase() : '';
				const match   = text.includes( query );

				item.hidden = ! match;
				clearHighlight( titleEl );

				if ( match ) {
					hits++;
					highlightText( titleEl, query );

					// Ensure every ancestor <li> is visible.
					let ancestor = item.parentElement?.closest( '.hsbc-mtpv-item' );
					while ( ancestor ) {
						ancestor.hidden = false;
						ancestor = ancestor.parentElement?.closest( '.hsbc-mtpv-item' );
					}
				}
			} );

			noResults.hidden = hits > 0;
		} );

		clearBtn.addEventListener( 'click', () => {
			input.value = '';
			input.dispatchEvent( new Event( 'input' ) );
			input.focus();
		} );
	}

	/**
	 * Wrap the first occurrence of `query` inside `el`'s text with a
	 * <mark class="hsbc-mtpv-highlight"> element.
	 *
	 * Sets innerHTML directly after escaping all surrounding text to prevent
	 * XSS. The title element's original text content is preserved outside
	 * the matched segment.
	 *
	 * @param {HTMLElement|null} el    - The element whose text to highlight.
	 * @param {string}           query - Lowercase search query string.
	 */
	function highlightText( el, query ) {
		if ( ! el ) return;
		const text = el.textContent;
		const idx  = text.toLowerCase().indexOf( query );
		if ( idx === -1 ) return;

		el.innerHTML =
			esc( text.slice( 0, idx ) ) +
			'<mark class="hsbc-mtpv-highlight">' +
			esc( text.slice( idx, idx + query.length ) ) +
			'</mark>' +
			esc( text.slice( idx + query.length ) );
	}

	/**
	 * Remove any <mark> highlight from `el` by replacing innerHTML with
	 * the element's plain textContent (strips all child elements).
	 *
	 * @param {HTMLElement|null} el - The element to clear.
	 */
	function clearHighlight( el ) {
		if ( ! el ) return;
		if ( el.querySelector( '.hsbc-mtpv-highlight' ) ) {
			el.textContent = el.textContent;
		}
	}

	// =========================================================================
	// Event delegation  (toggle + trash + popup + add-page form)
	// =========================================================================

	/**
	 * Pending timer ID for hiding the hover popup.
	 *
	 * A 120 ms delay is used so the mouse can travel from the row into the
	 * popup without the popup disappearing in between. Cleared on any
	 * mouseover that re-enters the row or the popup itself.
	 *
	 * @type {number|null}
	 */
	let popupTimer = null;

	/**
	 * Attach all delegated event listeners to the tree wrap element.
	 *
	 * Using a single listener per event type on the wrap (rather than
	 * individual listeners per row) means dynamically-added lazy-loaded
	 * rows work without any re-wiring.
	 *
	 * Handled events:
	 *  click      – expand/collapse toggle, trash button, add-trigger buttons,
	 *               add-form submit and cancel.
	 *  submit     – add-form native submit (triggered by Enter key in title field).
	 *  mouseover  – show popup when entering a row; cancel hide timer when
	 *               entering the popup.
	 *  mouseout   – schedule popup hide with 120 ms delay when leaving row or popup.
	 *  document click – dismiss popup when clicking outside the tree.
	 *
	 * @param {HTMLElement} wrap - The .hsbc-mtpv-wrap container element.
	 */
	function setupEventDelegation( wrap ) {

		// ── Clicks ────────────────────────────────────────────────────────────
		wrap.addEventListener( 'click', async e => {

			// Expand / collapse toggle
			const toggle = e.target.closest( '.hsbc-mtpv-toggle' );
			if ( toggle ) {
				await handleToggle( toggle.closest( '.hsbc-mtpv-item' ) );
				return;
			}

			// Trash button (inside popup)
			const trash = e.target.closest( '.hsbc-mtpv-trash' );
			if ( trash ) {
				await handleTrash( trash );
				return;
			}

			// "After" / "Inside" trigger buttons
			const addTrigger = e.target.closest( '.hsbc-mtpv-add-trigger' );
			if ( addTrigger ) {
				handleAddTrigger( addTrigger );
				return;
			}

			// Add form – submit
			const addSubmit = e.target.closest( '.hsbc-mtpv-add-submit' );
			if ( addSubmit ) {
				e.preventDefault();
				await handleAddSubmit( addSubmit );
				return;
			}

			// Add form – cancel
			const addCancel = e.target.closest( '.hsbc-mtpv-add-cancel' );
			if ( addCancel ) {
				handleAddCancel( addCancel );
				return;
			}
		} );

		// Prevent native form submit (Enter key in the title field)
		wrap.addEventListener( 'submit', async e => {
			const form = e.target.closest( '.hsbc-mtpv-add-form' );
			if ( form ) {
				e.preventDefault();
				const submitBtn = form.querySelector( '.hsbc-mtpv-add-submit' );
				if ( submitBtn ) await handleAddSubmit( submitBtn );
			}
		} );

		// ── Popup hover ───────────────────────────────────────────────────────

		wrap.addEventListener( 'mouseover', e => {
			// Entering a row → show that item's popup
			const row = e.target.closest( '.hsbc-mtpv-row' );
			if ( row ) {
				const item = row.parentElement;
				if ( item?.classList.contains( 'hsbc-mtpv-item' ) ) {
					clearTimeout( popupTimer );
					showPopup( item );
				}
				return;
			}
			// Entering the popup itself → cancel any pending hide
			if ( e.target.closest( '.hsbc-mtpv-popup' ) ) {
				clearTimeout( popupTimer );
			}
		} );

		wrap.addEventListener( 'mouseout', e => {
			const row   = e.target.closest( '.hsbc-mtpv-row' );
			const popup = e.target.closest( '.hsbc-mtpv-popup' );
			if ( ! row && ! popup ) return;

			const item        = row ? row.parentElement : popup?.parentElement;
			const relatedItem = e.relatedTarget?.closest( '.hsbc-mtpv-item' );

			// Still within the same item (moving between row ↔ popup) – keep open
			if ( item && item === relatedItem ) return;

			popupTimer = setTimeout( () => hidePopup( item ), 120 );
		} );

		// Close popup when clicking anywhere outside the tree
		document.addEventListener( 'click', e => {
			if ( ! e.target.closest( '.hsbc-mtpv-item' ) ) {
				document.querySelectorAll( '.hsbc-mtpv-popup--visible' ).forEach( p =>
					hidePopup( p.parentElement )
				);
			}
		} );
	}

	// ── Popup show / hide ─────────────────────────────────────────────────────

	/**
	 * Show the hover popup for `item` and highlight its row.
	 *
	 * Before showing the target popup, all other hover states and visible
	 * popups across the entire tree are cleared. This prevents the bug where
	 * moving the mouse from a parent row to a child row leaves both rows
	 * highlighted simultaneously.
	 *
	 * A popup that has .hsbc-mtpv-popup--working (add-form is open) is never
	 * closed — the user must explicitly cancel or submit the form.
	 *
	 * @param {HTMLElement} item - The .hsbc-mtpv-item element to activate.
	 */
	function showPopup( item ){
		const wrap = item.closest( '.hsbc-mtpv-wrap' );

		// Clear ALL hover states across the entire tree first
		wrap?.querySelectorAll( '.hsbc-mtpv-row--hover' ).forEach( r =>
			r.classList.remove( 'hsbc-mtpv-row--hover' )
		);

		// Hide all visible popups that aren't the target and aren't mid-form
		wrap?.querySelectorAll( '.hsbc-mtpv-popup--visible' ).forEach( p => {
			if ( p.parentElement !== item && ! p.classList.contains( 'hsbc-mtpv-popup--working' ) ) {
				p.classList.remove( 'hsbc-mtpv-popup--visible' );
			}
		} );

		item.querySelector( ':scope > .hsbc-mtpv-row' )?.classList.add( 'hsbc-mtpv-row--hover' );
		item.querySelector( ':scope > .hsbc-mtpv-popup' )?.classList.add( 'hsbc-mtpv-popup--visible' );
	}

	/**
	 * Hide the popup and remove the hover highlight for `item`.
	 *
	 * A no-op if the popup currently has .hsbc-mtpv-popup--working, which
	 * means the inline add-page form is open and the user is mid-interaction.
	 *
	 * @param {HTMLElement|null} item - The .hsbc-mtpv-item element to deactivate.
	 */
	function hidePopup( item ) {
		if ( ! item ) return;
		const popup = item.querySelector( ':scope > .hsbc-mtpv-popup' );
		// Don't close while the add-form is active
		if ( popup?.classList.contains( 'hsbc-mtpv-popup--working' ) ) return;
		popup?.classList.remove( 'hsbc-mtpv-popup--visible' );
		item.querySelector( ':scope > .hsbc-mtpv-row' )?.classList.remove( 'hsbc-mtpv-row--hover' );
	}

	// ── Add-page form ─────────────────────────────────────────────────────────

	/**
	 * Show the inline add-page form inside the popup for the clicked trigger.
	 *
	 * Sets .hsbc-mtpv-popup--working on the popup to prevent it closing
	 * while the form is active. The form's data-type attribute records
	 * whether the new page will be inserted 'before' (after sibling) or
	 * 'inside' (as first child) so handleAddSubmit can read it.
	 *
	 * @param {HTMLButtonElement} btn - The clicked .hsbc-mtpv-add-trigger button.
	 */
	function handleAddTrigger( btn ) {
		const popup = btn.closest( '.hsbc-mtpv-popup' );
		const form  = popup.querySelector( '.hsbc-mtpv-add-form' );
		const type  = btn.dataset.type; // 'before' | 'inside'

		form.dataset.type = type;
		form.querySelector( '.hsbc-mtpv-add-form-label' ).textContent =
			type === 'after' ? i18n.addAfter : i18n.addInside;

		form.hidden = false;
		popup.classList.add( 'hsbc-mtpv-popup--working' );
		form.querySelector( '.hsbc-mtpv-add-name' )?.focus();
	}

	/**
	 * Submit the add-page form: call the AJAX handler and redirect to the
	 * new post's editor on success.
	 *
	 * Disables the submit button and shows an "Adding…" label while the
	 * request is in flight. Re-enables on failure so the user can retry.
	 *
	 * @param {HTMLButtonElement} btn - The .hsbc-mtpv-add-submit button.
	 * @returns {Promise<void>}
	 */
	async function handleAddSubmit( btn ) {
		const form     = btn.closest( '.hsbc-mtpv-add-form' );
		const popup    = form.closest( '.hsbc-mtpv-popup' );
		const item     = popup.parentElement;
		const nameEl   = form.querySelector( '.hsbc-mtpv-add-name' );
		const title    = nameEl.value.trim();

		if ( ! title ) {
			nameEl.focus();
			return;
		}

		btn.disabled    = true;
		btn.textContent = i18n.adding;

		const result = await addPage( {
			refId:    parseInt( item.dataset.id, 10 ),
			type:     form.dataset.type,
			title,
			status:   form.querySelector( '.hsbc-mtpv-add-status' ).value,
			postType: popup.dataset.postType || 'page',
		} );

		if ( result?.edit_link ) {
			window.location.href = result.edit_link;
		} else {
			btn.disabled    = false;
			btn.textContent = i18n.add;
		}
	}

	/**
	 * Cancel the add-page form: hide it, clear the title input, and
	 * remove .hsbc-mtpv-popup--working so the popup can close normally again.
	 *
	 * @param {HTMLButtonElement} btn - The .hsbc-mtpv-add-cancel button.
	 */
	function handleAddCancel( btn ) {
		const form  = btn.closest( '.hsbc-mtpv-add-form' );
		const popup = form.closest( '.hsbc-mtpv-popup' );

		form.hidden = true;
		form.querySelector( '.hsbc-mtpv-add-name' ).value = '';
		popup.classList.remove( 'hsbc-mtpv-popup--working' );
	}

	// =========================================================================
	// Expand / Collapse  (CSS transitions, not display:none toggling)
	// =========================================================================

	/**
	 * Toggle the expanded / collapsed state of a tree node.
	 *
	 * @param {HTMLElement} item - The .hsbc-mtpv-item element to toggle.
	 * @returns {Promise<void>}
	 */
	async function handleToggle( item ) {
		if ( item.classList.contains( 'hsbc-mtpv-expanded' ) ) {
			collapseItem( item );
		} else {
			await expandItem( item, true );
		}
	}

	/**
	 * Expand a tree node, lazy-loading its children if not yet fetched.
	 *
	 * First expansion:
	 *  1. Show a loading spinner on the toggle button.
	 *  2. Fetch children via AJAX (fetchChildren).
	 *  3. Build the child <ul> with buildChildList / buildItem.
	 *  4. Apply the CSS transition trick: add --collapsed before appending to
	 *     DOM, then remove it two animation frames later so the browser
	 *     records the starting state (max-height: 0) before transitioning to
	 *     the open state (max-height: 3000px).
	 *  5. Initialise SortableJS on the new child list.
	 *
	 * Subsequent expansions: the child list is already in the DOM; just
	 * remove --collapsed to trigger the CSS transition again.
	 *
	 * @param {HTMLElement} item    - The .hsbc-mtpv-item element to expand.
	 * @param {boolean}     persist - Whether to save this expansion to localStorage.
	 *                               Pass false when restoring state on page load
	 *                               to avoid redundant writes.
	 * @returns {Promise<void>}
	 */
	async function expandItem( item, persist = true ) {
		const id     = parseInt( item.dataset.id, 10 );
		const toggle = item.querySelector( ':scope > .hsbc-mtpv-row .hsbc-mtpv-toggle' );

		let childList = item.querySelector( ':scope > .hsbc-mtpv-children' );

		if ( ! childList ) {
			// Lazy-load children from the server.
			if ( toggle ) {
				toggle.classList.add( 'hsbc-mtpv-toggle--loading' );
				toggle.disabled = true;
			}

			const children = await fetchChildren( id );

			if ( toggle ) {
				toggle.classList.remove( 'hsbc-mtpv-toggle--loading' );
				toggle.disabled = false;
			}

			if ( ! children || children.length === 0 ) {
				// Edge case: server said there were children but returned none.
				item.dataset.hasChildren = 'false';
				item.classList.remove( 'hsbc-mtpv-has-children' );
				if ( toggle ) toggle.remove();
				return;
			}

			childList = buildChildList( children );

			/*
			 * CSS transition trick:
			 * Start collapsed (max-height: 0), append to DOM, then on the next
			 * two animation frames remove the collapsed class so the browser
			 * has time to compute the starting state before transitioning.
			 */
			childList.classList.add( 'hsbc-mtpv-children--collapsed' );
			item.appendChild( childList );

			requestAnimationFrame( () => {
				requestAnimationFrame( () => {
					childList.classList.remove( 'hsbc-mtpv-children--collapsed' );
				} );
			} );

			// Wire up SortableJS on the new child list.
			initSortable( childList );
		} else {
			// Already loaded — just re-open with a CSS transition.
			childList.classList.remove( 'hsbc-mtpv-children--collapsed' );
		}

		item.classList.add( 'hsbc-mtpv-expanded' );

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'true' );
			toggle.setAttribute( 'aria-label', i18n.collapse );
		}

		if ( persist ) {
			state.expanded.add( id );
			saveExpandedState();
		}
	}

	/**
	 * Collapse a tree node using a CSS max-height transition.
	 *
	 * The child <ul> is NOT removed from the DOM — it stays hidden
	 * (max-height: 0) so the next expand is instant (no AJAX needed).
	 *
	 * @param {HTMLElement} item - The .hsbc-mtpv-item element to collapse.
	 */
	function collapseItem( item ) {
		const id        = parseInt( item.dataset.id, 10 );
		const childList = item.querySelector( ':scope > .hsbc-mtpv-children' );
		const toggle    = item.querySelector( ':scope > .hsbc-mtpv-row .hsbc-mtpv-toggle' );

		// CSS transition collapses the list; we do NOT remove it from the DOM.
		if ( childList ) {
			childList.classList.add( 'hsbc-mtpv-children--collapsed' );
		}

		item.classList.remove( 'hsbc-mtpv-expanded' );

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle.setAttribute( 'aria-label', i18n.expand );
		}

		state.expanded.delete( id );
		saveExpandedState();
	}

	// =========================================================================
	// AJAX – fetch children
	// =========================================================================

	/**
	 * Fetch the direct children of a post from the server.
	 *
	 * Maps to the `hsbc_mtpv_get_children` AJAX action.
	 *
	 * @param {number} parentId - ID of the parent post.
	 * @returns {Promise<Array|null>} Array of child data objects, or null on error.
	 */
	async function fetchChildren( parentId ) {
		try {
			const res  = await fetch( ajaxUrl, {
				method: 'POST',
				body:   new URLSearchParams( {
					action:    'hsbc_mtpv_get_children',
					parent_id: parentId,
					nonce,
				} ),
			} );
			const json = await res.json();
			return json.success ? json.data : null;
		} catch {
			return null;
		}
	}

	// =========================================================================
	// Render child list from JSON (mirrors PHP render_item output)
	// =========================================================================

	/**
	 * Build a <ul class="hsbc-mtpv-children"> element from an array of
	 * child data objects returned by the get_children AJAX handler.
	 *
	 * @param {Array} items - Array of child data objects from the server.
	 * @returns {HTMLUListElement}
	 */
	function buildChildList( items ) {
		console.log( 'Building child list for', items );
		const ul = document.createElement( 'ul' );
		ul.className = 'hsbc-mtpv-children hsbc-mtpv-list';
		items.forEach( item => ul.appendChild( buildItem( item ) ) );
		return ul;
	}

	/**
	 * Build a single <li class="hsbc-mtpv-item"> element from a child data object.
	 *
	 * Mirrors the HTML structure produced by PHP's render_item() so that
	 * lazy-loaded rows behave identically to server-rendered ones.
	 *
	 * @param {{ id: number, title: string, edit_link: string, view_link: string,
	 *           status: string, status_label: string, is_protected: boolean,
	 *           has_children: boolean, can_trash: boolean, post_type: string }} data
	 * @returns {HTMLLIElement}
	 */
	function buildItem( data ) {
		const li = document.createElement( 'li' );
		li.className = 'hsbc-mtpv-item' + ( data.has_children ? ' hsbc-mtpv-has-children' : '' );
		if ( data.id === currentPostId ) li.classList.add( 'hsbc-mtpv-current' );
		li.dataset.id          = data.id;
		li.dataset.hasChildren = data.has_children ? 'true' : 'false';

		const statusBadge = data.status !== 'publish'
			? `<span class="hsbc-mtpv-status hsbc-mtpv-status--${ data.status }">${ esc( data.status_label ) }</span>`
			: '';

		const protectedBadge = data.is_protected
			? `<span class="hsbc-mtpv-protected" title="Password protected" aria-label="Password protected"></span>`
			: '';

		const toggleEl = data.has_children
			? `<button type="button" class="hsbc-mtpv-toggle" aria-expanded="false" aria-label="${ esc( i18n.expand ) }"></button>`
			: `<span class="hsbc-mtpv-toggle-spacer"></span>`;

		const trashGroup = data.can_trash
			? `<div class="hsbc-mtpv-popup-group">
				<button type="button" class="hsbc-mtpv-popup-btn hsbc-mtpv-popup-btn--danger hsbc-mtpv-trash"
					data-id="${ data.id }">${ esc( i18n.trash ) }</button>
			   </div>`
			: '';

		li.innerHTML = `
			<div class="hsbc-mtpv-row">
				<span class="hsbc-mtpv-drag-handle" title="Drag to reorder" aria-hidden="true"></span>
				${ toggleEl }
				<a href="${ esc( data.edit_link ) }" class="hsbc-mtpv-title">${ esc( data.title ) }</a>
				${ statusBadge }${ protectedBadge }
			</div>

			<div class="hsbc-mtpv-popup" data-id="${ data.id }" data-post-type="${ esc( data.post_type || 'page' ) }">
				<div class="hsbc-mtpv-popup-group">
					<a href="${ esc( data.edit_link ) }" class="hsbc-mtpv-popup-btn">${ esc( i18n.edit ) }</a>
					<span class="hsbc-mtpv-popup-sep">|</span>
					<a href="${ esc( data.view_link ) }" class="hsbc-mtpv-popup-btn" target="_blank" rel="noopener">${ esc( i18n.view ) }</a>
					<span class="hsbc-mtpv-popup-sep">|</span>
					<div class="hsbc-mtpv-popup-page-id">${ esc(data.id) }</div>
				</div>
				<div class="hsbc-mtpv-popup-group">
					<span class="hsbc-mtpv-popup-label">${ esc( i18n.addPage ) }</span>
					<button type="button" class="hsbc-mtpv-popup-btn hsbc-mtpv-add-trigger" data-type="before">${ esc( i18n.before ) }</button>
					<span class="hsbc-mtpv-popup-sep">|</span>
					<button type="button" class="hsbc-mtpv-popup-btn hsbc-mtpv-add-trigger" data-type="inside">${ esc( i18n.inside ) }</button>
				</div>
				${ trashGroup }
				<form class="hsbc-mtpv-add-form" hidden>
					<p class="hsbc-mtpv-add-form-label"></p>
					<input type="text" class="hsbc-mtpv-add-name" placeholder="${ esc( i18n.pageTitlePh ) }">
					<select class="hsbc-mtpv-add-status">
						<option value="draft">${ esc( i18n.draft ) }</option>
						<option value="pending">${ esc( i18n.pending ) }</option>
						<option value="publish">${ esc( i18n.publish ) }</option>
					</select>
					<div class="hsbc-mtpv-add-form-btns">
						<button type="submit" class="hsbc-mtpv-add-submit">${ esc( i18n.add ) }</button>
						<button type="button" class="hsbc-mtpv-add-cancel">${ esc( i18n.cancel ) }</button>
					</div>
				</form>
			</div>`;

		return li;
	}

	// =========================================================================
	// SortableJS – drag-and-drop (same level only)
	// =========================================================================

	/**
	 * Initialise SortableJS on a tree list element.
	 *
	 * Called once for the root list on boot, and again for each lazy-loaded
	 * child list so newly-expanded rows are immediately draggable.
	 *
	 * Drag is restricted to the same list level — items cannot be dropped
	 * into a different parent via dragging (use "Inside" in the popup for that).
	 *
	 * After a drop, the new position is persisted server-side via movePage().
	 * The reference sibling and direction are derived from newIndex / oldIndex:
	 *   moved down → ref = item now directly above (newIndex - 1), direction 'down'
	 *   moved up   → ref = item now directly below (newIndex + 1), direction 'up'
	 *
	 * @param {HTMLElement|null} listEl - The <ul> to make sortable.
	 */
	function initSortable( listEl ) {
		if ( ! listEl || typeof Sortable === 'undefined' ) return;

		Sortable.create( listEl, {
			animation:       150,           // CSS transition duration in ms
			handle:          '.hsbc-mtpv-drag-handle',
			draggable:       '.hsbc-mtpv-item',
			ghostClass:      'hsbc-mtpv-sortable-ghost',   // placeholder while dragging
			chosenClass:     'hsbc-mtpv-sortable-chosen',  // the item being dragged
			dragClass:       'hsbc-mtpv-sortable-drag',    // the actual drag clone
			forceFallback:   false,
			scroll:          true,
			scrollSensitivity: 60,
			scrollSpeed:     10,

			onEnd: async function ( evt ) {
				if ( evt.newIndex === evt.oldIndex ) return;

				const pageId   = parseInt( evt.item.dataset.id, 10 );
				const siblings = [ ...evt.to.querySelectorAll( ':scope > .hsbc-mtpv-item' ) ];

				let refItem, direction;

				if ( evt.newIndex > evt.oldIndex ) {
					// Moved down: reference is the item now directly above.
					refItem   = siblings[ evt.newIndex - 1 ];
					direction = 'down';
				} else {
					// Moved up: reference is the item now directly below.
					refItem   = siblings[ evt.newIndex + 1 ];
					direction = 'up';
				}

				if ( ! refItem ) return;

				const refId = parseInt( refItem.dataset.id, 10 );
				await movePage( pageId, refId, direction );
			},
		} );
	}

	// =========================================================================
	// AJAX – move page (called after SortableJS onEnd)
	// =========================================================================

	/**
	 * Persist a drag-and-drop reorder to the server.
	 *
	 * Maps to the `hsbc_mtpv_move_page` AJAX action.
	 *
	 * @param {number} pageId    - ID of the post that was moved.
	 * @param {number} refId     - ID of the adjacent reference post.
	 * @param {string} direction - 'up' (moved before ref) | 'down' (moved after ref).
	 * @returns {Promise<boolean>} True if the server confirmed success.
	 */
	async function movePage( pageId, refId, direction ) {
		try {
			const res  = await fetch( ajaxUrl, {
				method: 'POST',
				body:   new URLSearchParams( {
					action:    'hsbc_mtpv_move_page',
					page_id:   pageId,
					ref_id:    refId,
					direction,
					nonce,
				} ),
			} );
			const json = await res.json();
			return json.success;
		} catch {
			return false;
		}
	}

	// =========================================================================
	// AJAX – add page
	// =========================================================================

	/**
	 * Create a new post relative to a reference post.
	 *
	 * Maps to the `hsbc_mtpv_add_page` AJAX action.
	 *
	 * @param {{ refId: number, type: string, title: string,
	 *           status: string, postType: string }} params
	 * @returns {Promise<{id: number, edit_link: string}|null>}
	 *          New post data on success, or null on failure.
	 */
	async function addPage( { refId, type, title, status, postType } ) {
		try {
			const res  = await fetch( ajaxUrl, {
				method: 'POST',
				body:   new URLSearchParams( {
					action:    'hsbc_mtpv_add_page',
					ref_id:    refId,
					type,
					title,
					status,
					post_type: postType,
					nonce,
				} ),
			} );
			const json = await res.json();
			return json.success ? json.data : null;
		} catch {
			return null;
		}
	}

	// =========================================================================
	// AJAX – trash page
	// =========================================================================

	/**
	 * Confirm and trash a post, then fade-remove its row from the tree.
	 *
	 * Maps to the `hsbc_mtpv_trash_page` AJAX action.
	 * A native confirm() dialog is shown before any request is made.
	 * The row fades out over 250 ms via an inline CSS transition before
	 * being removed from the DOM.
	 *
	 * @param {HTMLButtonElement} btn - The .hsbc-mtpv-trash button that was clicked.
	 * @returns {Promise<void>}
	 */
	async function handleTrash( btn ) {
		if ( ! enableTrash ) return;
		if ( ! window.confirm( i18n.confirmTrash ) ) return;

		const id   = parseInt( btn.dataset.id, 10 );
		const item = btn.closest( '.hsbc-mtpv-item' );

		btn.disabled    = true;
		btn.textContent = i18n.loading;

		try {
			const res  = await fetch( ajaxUrl, {
				method: 'POST',
				body:   new URLSearchParams( {
					action:  'hsbc_mtpv_trash_page',
					page_id: id,
					nonce,
				} ),
			} );
			const json = await res.json();

			if ( json.success && item ) {
				// CSS transition fade-out before removal.
				item.style.transition = 'opacity 0.25s ease';
				item.style.opacity    = '0';
				setTimeout( () => item.remove(), 260 );
			} else {
				btn.disabled    = false;
				btn.textContent = i18n.trash;
			}
		} catch {
			btn.disabled    = false;
			btn.textContent = i18n.trash;
		}
	}

	// =========================================================================
	// Helper – HTML escape
	// =========================================================================

	/**
	 * Escape a value for safe insertion into HTML attribute or text content.
	 *
	 * Converts the five characters that have special meaning in HTML
	 * (&, <, >, ", ') to their named or numeric entities. Always call this
	 * when building innerHTML from server-provided data (titles, URLs, etc.).
	 *
	 * @param {*}      str - Value to escape (coerced to string).
	 * @returns {string}   HTML-safe string.
	 */
	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}
} )();
