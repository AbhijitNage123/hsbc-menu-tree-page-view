<?php
/**
 * HSBC menu integration for HSBC Menu Tree Page View.
 *
 * @package HSBC_Menu_Tree_Page_View
 *
 * Responsibilities:
 *   - Inject the page-tree HTML panel into each selected post type's
 *     hsbc sidebar sub-menu (hooked late at priority 999 so WP's own
 *     menu items are already in place).
 *   - Enqueue the stylesheet, SortableJS vendor script, and the main
 *     tree.js, then expose runtime data to JS via wp_localize_script().
 *   - Server-render the root-level tree items; deeper levels are
 *     lazy-loaded on demand via AJAX (see class-ajax.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the tree panel in the WP hsbc sidebar and loads assets.
 *
 * One instance of this class is created by the plugin bootstrap on
 * the `plugins_loaded` hook.
 */
class HSBC_Menu {

	/**
	 * Wire up WordPress hooks.
	 *
	 * Priority 999 on `hsbc_menu` guarantees that all built-in post-type
	 * sub-menus already exist when we try to attach our panel to them.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// -------------------------------------------------------------------------
	// Capability check
	// -------------------------------------------------------------------------

	/**
	 * Determine whether the current user may see the tree panel.
	 *
	 * If the hsbc has restricted visibility to specific roles
	 * (`hsbc_mtpv_roles` option), the user must hold at least one of those
	 * roles. When no roles are configured, the capability `edit_pages` is
	 * used as the gate — which covers HSBCistrators and Editors by default.
	 *
	 * @return bool True if the current user should see the tree.
	 */
	private function can_view() {
		$roles = get_option( 'hsbc_mtpv_roles', array() );

		if ( empty( $roles ) ) {
			return current_user_can( 'edit_pages' );
		}

		$user = wp_get_current_user();
		return ! empty( array_intersect( $roles, (array) $user->roles ) );
	}

	// -------------------------------------------------------------------------
	// HSBC menu
	// -------------------------------------------------------------------------

	/**
	 * Register a hidden sub-menu panel under each selected post type's menu.
	 *
	 * WordPress wraps every sub-menu item in `<a href="…">…</a>`. We exploit
	 * this by injecting our own HTML as the menu title:
	 *   </a> [tree HTML] <a href="#" style="display:none">
	 * WordPress closes the reopened dummy `<a>` for us. This is the only
	 * supported way to embed arbitrary HTML inside the WP hsbc sidebar.
	 *
	 * Each selected post type gets its own tree panel registered under the
	 * correct parent slug so it appears inside the right hsbc menu section:
	 *   - 'post'          → edit.php
	 *   - 'page'          → edit.php?post_type=page
	 *   - any other CPT   → edit.php?post_type={slug}
	 */
	public function register_menu() {
		if ( ! $this->can_view() ) {
			return;
		}

		$post_types = get_option( 'hsbc_mtpv_post_types', array( 'page' ) );

		/*
		 * WordPress automatically opens an <a href="..."> for every submenu item.
		 * We close it, inject the tree HTML, then reopen a dummy <a> for WP to close.
		 * This is the same approach used by the original plugin and is the only way
		 * to embed arbitrary HTML inside the WP hsbc sidebar.
		 */
		foreach ( $post_types as $post_type ) {
			$tree_html  = $this->render_root_tree( $post_type );
			$menu_title = '</a>' . $tree_html . '<a href="#" style="display:none">';

			if ( 'post' === $post_type ) {
				$parent_slug = 'edit.php';
			} elseif ( 'page' === $post_type ) {
				$parent_slug = 'edit.php?post_type=page';
			} else {
				$parent_slug = 'edit.php?post_type=' . $post_type;
			}

			add_submenu_page(
				$parent_slug,
				'HSBC Menu Tree Page View',
				$menu_title,
				'edit_pages',
				'hsbc-mtpv-panel-' . $post_type,
				array( $this, 'render_settings_redirect' )
			);
		}
	}

	/**
	 * Callback for the sub-menu page slug — redirects to the Settings page.
	 *
	 * If a user somehow navigates directly to the hidden panel URL
	 * (hsbc-mtpv-panel-{post_type}), they are sent to the plugin settings
	 * instead of seeing a blank hsbc page.
	 */
	public function render_settings_redirect() {
		wp_safe_redirect( admin_url( 'options-general.php?page=hsbc-mtpv' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Asset enqueue
	// -------------------------------------------------------------------------

	/**
	 * Enqueue the tree stylesheet, SortableJS, and the main tree script.
	 *
	 * Also calls wp_localize_script() to pass PHP runtime data to JS:
	 *   - ajaxUrl       AJAX endpoint URL.
	 *   - nonce         Security nonce for all AJAX requests.
	 *   - enableTrash   Whether the Trash action is enabled in settings.
	 *   - currentPostId ID of the post currently being edited (0 on list screens).
	 *   - ancestors     Ordered array of ancestor IDs (root → parent) so the
	 *                   tree can auto-expand to the current post on page load.
	 *   - i18n          All translatable UI strings.
	 */
	public function enqueue_assets() {
		if ( ! $this->can_view() ) {
			return;
		}

		wp_enqueue_style(
			'hsbc-mtpv',
			HSBC_MTPV_URL . 'assets/css/tree.css',
			array(),
			HSBC_MTPV_VERSION
		);

		wp_register_script(
			'sortablejs',
			HSBC_MTPV_URL . 'assets/js/vendor/Sortable.min.js',
			array(),
			'1.15.3',
			true
		);

		wp_enqueue_script(
			'hsbc-mtpv',
			HSBC_MTPV_URL . 'assets/js/tree.js',
			array( 'sortablejs' ),
			HSBC_MTPV_VERSION,
			true
		);

		// Ancestors of the page currently being edited, so the tree auto-expands to it.
		$current_post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$ancestors       = $current_post_id ? array_map( 'intval', get_post_ancestors( $current_post_id ) ) : array();

		wp_localize_script(
			'hsbc-mtpv',
			'AdminHSBCMTPV',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'hsbc-mtpv' ),
				'enableTrash'   => (bool) get_option( 'hsbc_mtpv_enable_trash', false ),
				'currentPostId' => $current_post_id,
				'ancestors'     => $ancestors,
				'i18n'          => array(
					'edit'         => __( 'Edit', 'hsbc-menu-tree-page-view' ),
					'view'         => __( 'View', 'hsbc-menu-tree-page-view' ),
					'trash'        => __( 'Trash', 'hsbc-menu-tree-page-view' ),
					'expand'       => __( 'Expand', 'hsbc-menu-tree-page-view' ),
					'collapse'     => __( 'Collapse', 'hsbc-menu-tree-page-view' ),
					'confirmTrash' => __( 'Move this page to trash?', 'hsbc-menu-tree-page-view' ),
					'loading'      => __( 'Loading...', 'hsbc-menu-tree-page-view' ),
					'noResults'    => __( 'No pages found', 'hsbc-menu-tree-page-view' ),
					'noTitle'      => __( '(no title)', 'hsbc-menu-tree-page-view' ),
					'addPage'      => __( 'Add new page', 'hsbc-menu-tree-page-view' ),
					'addAfter'     => __( 'Add page after:', 'hsbc-menu-tree-page-view' ),
					'addInside'    => __( 'Add page inside:', 'hsbc-menu-tree-page-view' ),
					'after'        => __( 'After', 'hsbc-menu-tree-page-view' ),
					'inside'       => __( 'Inside', 'hsbc-menu-tree-page-view' ),
					'add'          => __( 'Add', 'hsbc-menu-tree-page-view' ),
					'adding'       => __( 'Adding...', 'hsbc-menu-tree-page-view' ),
					'cancel'       => __( 'Cancel', 'hsbc-menu-tree-page-view' ),
					'pageTitlePh'  => __( 'Page title...', 'hsbc-menu-tree-page-view' ),
					'draft'        => __( 'Draft', 'hsbc-menu-tree-page-view' ),
					'pending'      => __( 'Pending', 'hsbc-menu-tree-page-view' ),
					'publish'      => __( 'Published', 'hsbc-menu-tree-page-view' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Tree rendering (PHP — root level only, children lazy-loaded via AJAX)
	// -------------------------------------------------------------------------

	/**
	 * Build and return the full tree panel HTML for a single post type.
	 *
	 * Only root-level posts (post_parent = 0) are rendered server-side.
	 * Child nodes are fetched on demand via AJAX when a user expands a row,
	 * keeping the initial page load fast regardless of the total page count.
	 *
	 * @param string $post_type The post type slug to render (e.g. 'page').
	 * @return string Complete HTML for the .hsbc-mtpv-wrap panel.
	 */
	private function render_root_tree( string $post_type ) {
		$enable_trash = (bool) get_option( 'hsbc_mtpv_enable_trash', false );
		$parent_ids   = $this->get_parent_ids( array( $post_type ) );

		$pages = get_posts(
			array(
				'post_type'      => $post_type,
				'post_parent'    => 0,
				'posts_per_page' => -1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'post_status'    => 'any',
			)
		);

		$pt_obj   = get_post_type_object( $post_type );
		$pt_label = $pt_obj ? $pt_obj->labels->name : ucfirst( $post_type );

		ob_start();
		?>
		<div class="hsbc-mtpv-wrap">
			<div>
			<div class="hsbc-mtpv-search-wrap-title"><?php echo esc_html( $pt_label ); ?></div>
			<div class="hsbc-mtpv-search-wrap">
				<input
					type="text"
					class="hsbc-mtpv-search"
					placeholder="<?php esc_attr_e( 'Search pages...', 'hsbc-menu-tree-page-view' ); ?>"
					autocomplete="off"
				>
				<button
					type="button"
					class="hsbc-mtpv-search-clear"
					hidden
					aria-label="<?php esc_attr_e( 'Clear search', 'hsbc-menu-tree-page-view' ); ?>"
				>&#x2715;</button>
			</div>
			</div>
			<p class="hsbc-mtpv-no-results" hidden>
				<?php esc_html_e( 'No pages found', 'hsbc-menu-tree-page-view' ); ?>
			</p>
			<?php if ( empty( $pages ) ) : ?>
				<p class="hsbc-mtpv-empty">
					<?php esc_html_e( 'No pages found.', 'hsbc-menu-tree-page-view' ); ?>
				</p>
			<?php else : ?>
				<ul class="hsbc-mtpv-tree hsbc-mtpv-list" data-parent="0">
					<?php foreach ( $pages as $page ) : ?>
						<?php $this->render_item( $page, $parent_ids, $enable_trash ); ?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Output the HTML for a single tree row (`<li>`) and its hover popup.
	 *
	 * HTML structure produced:
	 * <li class="hsbc-mtpv-item [hsbc-mtpv-has-children] [hsbc-mtpv-current]"
	 *     data-id="{id}" data-has-children="true|false">
	 *   <div class="hsbc-mtpv-row">
	 *     drag-handle | toggle-button-or-spacer | title-link | status-badge | lock-badge
	 *   </div>
	 *   <div class="hsbc-mtpv-popup"> … actions … </div>
	 * </li>
	 *
	 * This method is intentionally public so that it can be called directly
	 * from template overrides if needed.
	 *
	 * @param WP_Post $page         The post object to render.
	 * @param int[]   $parent_ids   IDs of posts that have at least one child
	 *                              (pre-fetched in a single query by get_parent_ids()).
	 * @param bool    $enable_trash Whether to show the Trash action in the popup.
	 */
	public function render_item( $page, array $parent_ids, bool $enable_trash ) {
		$has_children = in_array( $page->ID, $parent_ids, true );
		$edit_link    = get_edit_post_link( $page->ID, 'raw' );
		$view_link    = get_permalink( $page->ID );
		$title        = $page->post_title ?: __( '(no title)', 'hsbc-menu-tree-page-view' );

		$current_post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$is_current      = $current_post_id === $page->ID;

		$classes = array( 'hsbc-mtpv-item' );
		if ( $has_children ) {
			$classes[] = 'hsbc-mtpv-has-children';
		}
		if ( $is_current ) {
			$classes[] = 'hsbc-mtpv-current';
		}
		?>
		<li
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-id="<?php echo esc_attr( $page->ID ); ?>"
			data-has-children="<?php echo $has_children ? 'true' : 'false'; ?>"
			draggable="true"
		>
			<div class="hsbc-mtpv-row">
				<span
					class="hsbc-mtpv-drag-handle"
					title="<?php esc_attr_e( 'Drag to reorder', 'hsbc-menu-tree-page-view' ); ?>"
					aria-hidden="true"
				></span>

				<?php if ( $has_children ) : ?>
					<button
						type="button"
						class="hsbc-mtpv-toggle"
						aria-expanded="false"
						aria-label="<?php esc_attr_e( 'Expand', 'hsbc-menu-tree-page-view' ); ?>"
					></button>
				<?php else : ?>
					<span class="hsbc-mtpv-toggle-spacer"></span>
				<?php endif; ?>

				<a href="<?php echo esc_url( $edit_link ); ?>" class="hsbc-mtpv-title">
					<?php echo esc_html( $title ); ?>
				</a>

				<?php if ( $page->post_status !== 'publish' ) : ?>
					<span class="hsbc-mtpv-status hsbc-mtpv-status--<?php echo esc_attr( $page->post_status ); ?>">
						<?php echo esc_html( ucfirst( $page->post_status ) ); ?>
					</span>
				<?php endif; ?>

				<?php if ( $page->post_password ) : ?>
					<span class="hsbc-mtpv-protected" title="<?php esc_attr_e( 'Password protected', 'hsbc-menu-tree-page-view' ); ?>" aria-label="<?php esc_attr_e( 'Password protected', 'hsbc-menu-tree-page-view' ); ?>"></span>
				<?php endif; ?>
			</div>

			<?php /* Hover popup ------------------------------------------------ */ ?>
			<div class="hsbc-mtpv-popup"
				data-id="<?php echo esc_attr( $page->ID ); ?>"
				data-post-type="<?php echo esc_attr( $page->post_type ); ?>"
			>
				<div class="hsbc-mtpv-popup-group">
					<a href="<?php echo esc_url( $edit_link ); ?>" class="hsbc-mtpv-popup-btn">
						<?php esc_html_e( 'Edit', 'hsbc-menu-tree-page-view' ); ?>
					</a>
					<span class="hsbc-mtpv-popup-sep">|</span>
					<a href="<?php echo esc_url( $view_link ); ?>" class="hsbc-mtpv-popup-btn" target="_blank" rel="noopener">
						<?php esc_html_e( 'View', 'hsbc-menu-tree-page-view' ); ?>
					</a>
					<span class="hsbc-mtpv-popup-sep">|</span>
					<div class="hsbc-mtpv-popup-page-id"><?php echo esc_attr( $page->ID ); ?></div>
				</div>

				<div class="hsbc-mtpv-popup-group">
					<span class="hsbc-mtpv-popup-label"><?php esc_html_e( 'Add page', 'hsbc-menu-tree-page-view' ); ?></span>
					<button type="button" class="hsbc-mtpv-popup-btn hsbc-mtpv-add-trigger" data-type="after">
						<?php esc_html_e( 'After', 'hsbc-menu-tree-page-view' ); ?>
					</button>
					<span class="hsbc-mtpv-popup-sep">|</span>
					<button type="button" class="hsbc-mtpv-popup-btn hsbc-mtpv-add-trigger" data-type="inside">
						<?php esc_html_e( 'Inside', 'hsbc-menu-tree-page-view' ); ?>
					</button>
				</div>

				<?php if ( $enable_trash && current_user_can( 'delete_post', $page->ID ) ) : ?>
					<div class="hsbc-mtpv-popup-group">
						<button type="button" class="hsbc-mtpv-popup-btn hsbc-mtpv-popup-btn--danger hsbc-mtpv-trash"
							data-id="<?php echo esc_attr( $page->ID ); ?>">
							<?php esc_html_e( 'Trash', 'hsbc-menu-tree-page-view' ); ?>
						</button>
					</div>
				<?php endif; ?>

				<form class="hsbc-mtpv-add-form" hidden>
					<p class="hsbc-mtpv-add-form-label"></p>
					<input type="text" class="hsbc-mtpv-add-name"
						placeholder="<?php esc_attr_e( 'Page title...', 'hsbc-menu-tree-page-view' ); ?>">
					<select class="hsbc-mtpv-add-status">
						<option value="draft"><?php esc_html_e( 'Draft', 'hsbc-menu-tree-page-view' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Pending', 'hsbc-menu-tree-page-view' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Published', 'hsbc-menu-tree-page-view' ); ?></option>
					</select>
					<div class="hsbc-mtpv-add-form-btns">
						<button type="submit" class="hsbc-mtpv-add-submit">
							<?php esc_html_e( 'Add', 'hsbc-menu-tree-page-view' ); ?>
						</button>
						<button type="button" class="hsbc-mtpv-add-cancel">
							<?php esc_html_e( 'Cancel', 'hsbc-menu-tree-page-view' ); ?>
						</button>
					</div>
				</form>
			</div>
		</li>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return all post IDs that are a parent of at least one other post.
	 *
	 * A single `SELECT DISTINCT post_parent` query replaces what would
	 * otherwise be N+1 `get_children()` calls — one per visible tree item.
	 * The result is used to decide whether each item renders an expand
	 * toggle button or a plain page-icon spacer.
	 *
	 * Trashed posts are excluded: a post whose only children are trashed
	 * should not show the expand toggle.
	 *
	 * @param string[] $post_types Post type slugs to include in the query.
	 * @return int[]               Unique IDs of posts that have children.
	 */
	private function get_parent_ids( array $post_types ): array {
		global $wpdb;

		if ( empty( $post_types ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_parent
				 FROM {$wpdb->posts}
				 WHERE post_type IN ({$placeholders})
				   AND post_status != 'trash'
				   AND post_parent != 0",
				...$post_types
			)
		);

		return array_map( 'intval', $results );
	}
}
