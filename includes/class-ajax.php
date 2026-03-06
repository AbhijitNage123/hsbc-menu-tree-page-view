<?php
/**
 * AJAX request handlers for HSBC Menu Tree Page View.
 *
 * @package HSBC_Menu_Tree_Page_View
 *
 * All handlers are registered exclusively on the `wp_ajax_*` hooks (logged-in
 * users only). Every handler calls verify() first, which validates the nonce
 * and checks the `edit_pages` capability before any data is read or written.
 *
 * Registered actions:
 *   hsbc_mtpv_get_children  – Lazy-load child posts for a given parent ID.
 *   hsbc_mtpv_move_page     – Reorder a post via drag-and-drop (updates menu_order).
 *   hsbc_mtpv_trash_page    – Move a post to the trash (gated by settings toggle).
 *   hsbc_mtpv_add_page      – Create a new post as a sibling (after) or child (inside).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all AJAX interactions initiated by tree.js.
 *
 * One instance of this class is created by the plugin bootstrap on
 * the `plugins_loaded` hook.
 */
class HSBC_MTPV_Ajax {

	/**
	 * Register all AJAX action hooks.
	 *
	 * Only `wp_ajax_*` (not `wp_ajax_nopriv_*`) hooks are used because the
	 * tree is only ever shown to logged-in users inside the hsbc area.
	 */
	public function __construct() {
		add_action( 'wp_ajax_hsbc_mtpv_get_children', [ $this, 'get_children' ] );
		add_action( 'wp_ajax_hsbc_mtpv_move_page',    [ $this, 'move_page' ] );
		add_action( 'wp_ajax_hsbc_mtpv_trash_page',   [ $this, 'trash_page' ] );
		add_action( 'wp_ajax_hsbc_mtpv_add_page',     [ $this, 'add_page' ] );
	}

	// -------------------------------------------------------------------------
	// Shared auth check
	// -------------------------------------------------------------------------

	/**
	 * Validate the AJAX nonce and verify the current user's capability.
	 *
	 * Must be called at the top of every public AJAX handler. Terminates
	 * with a JSON error response (403) if either check fails, so callers
	 * do not need additional guards after invoking this method.
	 *
	 * Nonce:      'hsbc-mtpv'  (created in enqueue_assets via wp_create_nonce)
	 * Capability: 'edit_pages' (held by HSBCistrators and Editors by default)
	 */
	private function verify() {
		check_ajax_referer( 'hsbc-mtpv', 'nonce' );

		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
	}

	// -------------------------------------------------------------------------
	// Lazy load children
	// -------------------------------------------------------------------------

	/**
	 * Return JSON data for all direct children of a given parent post.
	 *
	 * Called when the user clicks the expand toggle on a tree row for the
	 * first time. Subsequent expand/collapse cycles use the already-rendered
	 * DOM and do not trigger another AJAX call.
	 *
	 * POST params:
	 *   parent_id  int  ID of the parent post whose children to fetch.
	 *   nonce      string  Security nonce.
	 *
	 * Success response data (array of objects):
	 *   id, title, edit_link, view_link, status, status_label,
	 *   is_protected, has_children, can_trash, order
	 */
	public function get_children() {
		$this->verify();

		$parent_id  = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;
		$post_types = get_option( 'hsbc_mtpv_post_types', [ 'page' ] );

		if ( ! $parent_id ) {
			wp_send_json_error( [ 'message' => 'Invalid parent ID.' ], 400 );
		}

		$parent_ids   = $this->get_parent_ids( $post_types );
		$enable_trash = (bool) get_option( 'hsbc_mtpv_enable_trash', false );

		$children = get_posts( [
			'post_type'      => $post_types,
			'post_parent'    => $parent_id,
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'post_status'    => 'any',
		] );

		$data = array_map(
			function ( $page ) use ( $parent_ids, $enable_trash ) {
				return [
					'id'           => $page->ID,
					'title'        => $page->post_title ?: __( '(no title)', 'hsbc-menu-tree-page-view' ),
					'edit_link'    => get_edit_post_link( $page->ID, 'raw' ),
					'view_link'    => get_permalink( $page->ID ),
					'status'       => $page->post_status,
					'status_label' => ucfirst( $page->post_status ),
					'is_protected' => (bool) $page->post_password,
					'has_children' => in_array( $page->ID, $parent_ids, true ),
					'can_trash'    => $enable_trash && current_user_can( 'delete_post', $page->ID ),
					'order'        => (int) $page->menu_order,
				];
			},
			$children
		);

		wp_send_json_success( $data );
	}

	// -------------------------------------------------------------------------
	// Move page (drag-and-drop reorder)
	// -------------------------------------------------------------------------

	/**
	 * Reorder a post relative to a reference sibling after a drag-and-drop.
	 *
	 * SortableJS fires this after a drag ends. The JS calculates which
	 * adjacent sibling to use as the reference point and sends direction
	 * 'up' (moved before ref) or 'down' (moved after ref).
	 *
	 * Strategy to avoid menu_order collisions:
	 *   'up'   – Increment menu_order of all siblings >= ref's order by 1,
	 *            then assign the moved page ref's original order.
	 *   'down' – Increment menu_order of all siblings >= ref's order by 2,
	 *            then assign the moved page ref's original order + 1.
	 *
	 * Both moved page and ref page are re-parented to ref's post_parent,
	 * which handles cross-level drops if they ever occur.
	 *
	 * POST params:
	 *   page_id    int     ID of the post being moved.
	 *   ref_id     int     ID of the adjacent reference post.
	 *   direction  string  'up' | 'down'.
	 *   nonce      string  Security nonce.
	 */
	public function move_page() {
		$this->verify();

		$page_id   = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$ref_id    = isset( $_POST['ref_id'] ) ? absint( $_POST['ref_id'] ) : 0;
		$direction = isset( $_POST['direction'] ) ? sanitize_key( $_POST['direction'] ) : '';

		if ( ! $page_id || ! $ref_id || ! in_array( $direction, [ 'up', 'down' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid parameters.' ], 400 );
		}

		$page     = get_post( $page_id );
		$ref_page = get_post( $ref_id );

		if ( ! $page || ! $ref_page || $page->post_type !== $ref_page->post_type ) {
			wp_send_json_error( [ 'message' => 'Invalid pages.' ], 400 );
		}

		if ( 'trash' === $page->post_status ) {
			wp_send_json_error( [ 'message' => 'Cannot move a trashed page.' ], 400 );
		}

		global $wpdb;

		if ( 'up' === $direction ) {
			// Place $page before $ref_page
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts}
					 SET menu_order = menu_order + 1
					 WHERE post_type  = %s
					   AND post_parent = %d
					   AND menu_order >= %d
					   AND ID != %d",
					$ref_page->post_type,
					$ref_page->post_parent,
					$ref_page->menu_order,
					$ref_page->ID
				)
			);
			wp_update_post( [
				'ID'          => $page->ID,
				'menu_order'  => $ref_page->menu_order,
				'post_parent' => $ref_page->post_parent,
			] );
		} else {
			// Place $page after $ref_page
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts}
					 SET menu_order = menu_order + 2
					 WHERE post_type  = %s
					   AND post_parent = %d
					   AND menu_order >= %d
					   AND ID != %d",
					$ref_page->post_type,
					$ref_page->post_parent,
					$ref_page->menu_order,
					$ref_page->ID
				)
			);
			wp_update_post( [
				'ID'          => $page->ID,
				'menu_order'  => $ref_page->menu_order + 1,
				'post_parent' => $ref_page->post_parent,
			] );
		}

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Trash page
	// -------------------------------------------------------------------------

	/**
	 * Move a post to the trash.
	 *
	 * This action is only available when the hsbc has enabled it via the
	 * `hsbc_mtpv_enable_trash` setting. A double-check on `delete_post`
	 * capability is performed per post, because the tree may display posts
	 * created by other authors.
	 *
	 * POST params:
	 *   page_id  int     ID of the post to trash.
	 *   nonce    string  Security nonce.
	 */
	public function trash_page() {
		$this->verify();

		if ( ! get_option( 'hsbc_mtpv_enable_trash', false ) ) {
			wp_send_json_error( [ 'message' => 'Trash action is disabled.' ], 403 );
		}

		$page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;

		if ( ! $page_id ) {
			wp_send_json_error( [ 'message' => 'Invalid page ID.' ], 400 );
		}

		if ( ! current_user_can( 'delete_post', $page_id ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}

		$result = wp_trash_post( $page_id );

		if ( $result ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => 'Failed to trash page.' ] );
		}
	}

	// -------------------------------------------------------------------------
	// Add page (after a sibling or inside a parent)
	// -------------------------------------------------------------------------

	/**
	 * Create a new post relative to a reference post.
	 *
	 * Two insertion modes are supported:
	 *
	 *   'before' (submitted as type=before from the "After" trigger in JS):
	 *     Inserts the new post as a sibling immediately before the reference.
	 *     All existing siblings with menu_order >= ref's order are shifted up
	 *     by 1 first, then the new post takes ref's original menu_order.
	 *
	 *   'inside':
	 *     Inserts the new post as the first child of the reference post.
	 *     All existing children of ref are shifted up by 1 first, then the
	 *     new post is placed at menu_order = 0.
	 *
	 * On success, returns { id, edit_link } so JS can redirect to the
	 * editor immediately.
	 *
	 * POST params:
	 *   ref_id     int     ID of the reference post.
	 *   type       string  'before' | 'inside'.
	 *   title      string  Title for the new post.
	 *   status     string  'draft' | 'pending' | 'publish' | 'private'.
	 *   post_type  string  Post type slug (defaults to 'page').
	 *   nonce      string  Security nonce.
	 */
	public function add_page() {
		$this->verify();

		$ref_id    = isset( $_POST['ref_id'] ) ? absint( $_POST['ref_id'] ) : 0;
		$type      = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		$title     = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$status    = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'draft';
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : 'page';

		if ( ! $ref_id || ! in_array( $type, [ 'after', 'inside' ], true ) || '' === $title ) {
			wp_send_json_error( [ 'message' => 'Invalid parameters.' ], 400 );
		}

		if ( ! in_array( $status, [ 'draft', 'pending', 'publish', 'private' ], true ) ) {
			$status = 'draft';
		}

		$ref_post = get_post( $ref_id );
		if ( ! $ref_post ) {
			wp_send_json_error( [ 'message' => 'Reference page not found.' ], 404 );
		}

		global $wpdb;

		if ( 'before' === $type ) {
			// Insert as a sibling placed immediately before the reference page.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts}
					 SET menu_order = menu_order + 1
					 WHERE post_type = %s AND post_parent = %d AND menu_order >= %d AND ID != %d",
					$ref_post->post_type,
					$ref_post->post_parent,
					$ref_post->menu_order,
					$ref_post->ID
				)
			);

			$new_id = wp_insert_post( [
				'post_title'  => $title,
				'post_status' => $status,
				'post_type'   => $post_type,
				'post_parent' => $ref_post->post_parent,
				'menu_order'  => $ref_post->menu_order,
			] );
		} else {
			// Insert as the first child inside the reference page.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts}
					 SET menu_order = menu_order + 1
					 WHERE post_type = %s AND post_parent = %d",
					$ref_post->post_type,
					$ref_post->ID
				)
			);

			$new_id = wp_insert_post( [
				'post_title'  => $title,
				'post_status' => $status,
				'post_type'   => $post_type,
				'post_parent' => $ref_post->ID,
				'menu_order'  => 0,
			] );
		}

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			wp_send_json_error( [ 'message' => 'Failed to create page.' ] );
		}

		wp_send_json_success( [
			'id'        => $new_id,
			'edit_link' => get_edit_post_link( $new_id, 'raw' ),
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return all post IDs that have at least one non-trashed child.
	 *
	 * Identical in purpose to the same method in HSBC_Menu:
	 * one query replaces N+1 child checks when building the child list JSON.
	 * The result tells JS whether to render an expand toggle for each item.
	 *
	 * @param string[] $post_types Post type slugs to include.
	 * @return int[]               IDs of posts that have children.
	 */
	private function get_parent_ids( array $post_types ): array {
		global $wpdb;

		if ( empty( $post_types ) ) {
			return [];
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
