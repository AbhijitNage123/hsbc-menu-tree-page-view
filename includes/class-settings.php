<?php
/**
 * Settings page for HSBC Menu Tree Page View.
 *
 * @package HSBC_Menu_Tree_Page_View
 *
 * Registers and renders the plugin settings page at:
 *   Settings > HSBC Menu Tree  (options-general.php?page=hsbc-mtpv)
 *
 * Managed options:
 *   hsbc_mtpv_post_types   array   Post type slugs to show in the tree.
 *                                  Only post types that support 'page-attributes'
 *                                  (i.e. have a parent selector) are offered.
 *                                  Default: ['page'].
 *
 *   hsbc_mtpv_enable_trash bool    Whether to show a Trash button on each
 *                                  tree item. Default: false.
 *
 *   hsbc_mtpv_roles        array   Role slugs allowed to see the tree.
 *                                  Restricted to 'admin-hsbcistrator' and 'editor'
 *                                  in the UI. Empty = any user with edit_pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages plugin options and renders the Settings hsbc page.
 *
 * One instance of this class is created by the plugin bootstrap on
 * the `plugins_loaded` hook.
 */
class HSBC_MTPV_Settings {

	/**
	 * Wire up WordPress hooks for the settings page and plugin links.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( HSBC_MTPV_PATH . 'hsbc-menu-tree-page-view.php' ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Register the settings page under the Settings hsbc menu.
	 *
	 * Requires `manage_options` capability (HSBCistrators only) to access.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'HSBC Menu Tree Page View', 'hsbc-menu-tree-page-view' ),
			__( 'HSBC Menu Tree', 'hsbc-menu-tree-page-view' ),
			'manage_options',
			'hsbc-mtpv',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register all plugin options with the WordPress Settings API.
	 *
	 * Sanitize callbacks are applied on save to ensure stored values are
	 * always in the expected format regardless of how the form is submitted.
	 */
	public function register_settings() {
		register_setting(
			'hsbc_mtpv_settings',
			'hsbc_mtpv_post_types',
			array(
				'type'              => 'array',
				'default'           => array( 'page' ),
				'sanitize_callback' => array( $this, 'sanitize_post_types' ),
			)
		);

		register_setting(
			'hsbc_mtpv_settings',
			'hsbc_mtpv_enable_trash',
			array(
				'type'    => 'boolean',
				'default' => false,
			)
		);

		register_setting(
			'hsbc_mtpv_settings',
			'hsbc_mtpv_roles',
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_roles' ),
			)
		);
	}

	/**
	 * Sanitize the submitted post types array.
	 *
	 * Falls back to ['page'] if the submitted value is not an array,
	 * ensuring the tree always has at least one post type to display.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string[]    Sanitized array of post type slugs.
	 */
	public function sanitize_post_types( $value ) {
		if ( ! is_array( $value ) ) {
			return array( 'page' );
		}
		return array_map( 'sanitize_key', $value );
	}

	/**
	 * Sanitize the submitted roles array.
	 *
	 * Returns an empty array (meaning "no restriction") if the value is
	 * not an array, which is the safe default.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string[]    Sanitized array of role slugs.
	 */
	public function sanitize_roles( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_map( 'sanitize_key', $value );
	}

	/**
	 * Prepend a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Existing action links for the plugin row.
	 * @return string[]       Modified links with Settings prepended.
	 */
	public function add_settings_link( $links ) {
		$url = admin_url( 'options-general.php?page=hsbc-mtpv' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . __( 'Settings', 'hsbc-menu-tree-page-view' ) . '</a>' );
		return $links;
	}

	/**
	 * Render the Settings hsbc page HTML.
	 *
	 * Post types list is filtered to only those that support 'page-attributes'
	 * (i.e. the "Choose a parent" selector is available in their editor),
	 * since the tree is only meaningful for hierarchical content.
	 *
	 * Role list is restricted to 'admin-hsbcistrator' and 'editor' — the only
	 * built-in roles that have page-management capabilities by default.
	 */
	public function render_page() {
		$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
		$saved_types    = get_option( 'hsbc_mtpv_post_types', array( 'page' ) );
		$enable_trash   = get_option( 'hsbc_mtpv_enable_trash', false );
		$saved_roles    = get_option( 'hsbc_mtpv_roles', array() );
		$all_roles      = wp_roles()->roles;

		// Only show post types that support a parent selector ("Choose a parent page").
		$post_types = array_filter(
			$all_post_types,
			fn( $pt ) => post_type_supports( $pt->name, 'page-attributes' )
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'HSBC Menu Tree Page View – Settings', 'hsbc-menu-tree-page-view' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'hsbc_mtpv_settings' ); ?>
				<table class="form-table">

					<tr>
						<th scope="row"><?php esc_html_e( 'Post Types', 'hsbc-menu-tree-page-view' ); ?></th>
						<td>
							<?php foreach ( $post_types as $pt ) : ?>
								<label style="display:block;margin-bottom:5px">
									<input type="checkbox"
										name="hsbc_mtpv_post_types[]"
										value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, $saved_types, true ) ); ?>>
									<?php echo esc_html( $pt->labels->singular_name ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">
								<?php esc_html_e( 'Only post types that support a parent page selector are listed. "Page" is selected by default.', 'hsbc-menu-tree-page-view' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Trash', 'hsbc-menu-tree-page-view' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="hsbc_mtpv_enable_trash" value="1" <?php checked( $enable_trash ); ?>>
								<?php esc_html_e( 'Show a "Trash" button on each item in the tree', 'hsbc-menu-tree-page-view' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Restrict Visibility to Roles', 'hsbc-menu-tree-page-view' ); ?></th>
						<td>
							<?php
							$allowed_roles = array( 'admin-hsbcistrator', 'editor' );
							foreach ( $all_roles as $slug => $role ) :
								if ( ! in_array( $slug, $allowed_roles, true ) ) {
									continue;
								}
								?>
								<label style="display:block;margin-bottom:5px">
									<input type="checkbox"
										name="hsbc_mtpv_roles[]"
										value="<?php echo esc_attr( $slug ); ?>"
										<?php checked( in_array( $slug, $saved_roles, true ) ); ?>>
									<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">
								<?php esc_html_e( 'Leave all unchecked to allow any user who can edit pages.', 'hsbc-menu-tree-page-view' ); ?>
							</p>
						</td>
					</tr>

				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
