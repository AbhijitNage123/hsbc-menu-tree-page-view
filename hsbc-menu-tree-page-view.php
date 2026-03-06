<?php
/**
 * Plugin Name: HSBC Menu Tree Page View
 * Description: A fast, modern page tree in the WordPress hsbc sidebar. Lazy loading, drag-to-reorder, role-based visibility. No jQuery.
 * Version:     1.0.0
 * Author:      Abhijit Nage
 * License:     GPL-2.0+
 * Text Domain: hsbc-menu-tree-page-view
 *
 * @package HSBC_Menu_Tree_Page_View
 *
 * Plugin bootstrap file. Defines global constants, loads class files,
 * and instantiates the three core classes on the `plugins_loaded` hook
 * so that all WordPress APIs are fully available before any code runs.
 *
 * Architecture overview:
 *   HSBC_MTPV_Settings   – Settings page under Settings > HSBC Menu Tree.
 *   HSBC_Menu – Injects the tree HTML into the WP hsbc sidebar
 *                          and enqueues all front-end assets.
 *   HSBC_MTPV_Ajax       – Handles all wp_ajax_* AJAX actions (lazy-load
 *                          children, move, trash, add page).
 *
 * Constants defined here:
 *   HSBC_MTPV_VERSION – Semver string used as cache-buster for assets.
 *   HSBC_MTPV_PATH    – Absolute server path to the plugin directory (trailing slash).
 *   HSBC_MTPV_URL     – Public URL to the plugin directory (trailing slash).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var string Current plugin version, used as asset cache-buster. */
define( 'HSBC_MTPV_VERSION', '1.0.0' );

/** @var string Absolute filesystem path to the plugin root directory. */
define( 'HSBC_MTPV_PATH', plugin_dir_path( __FILE__ ) );

/** @var string Public URL to the plugin root directory. */
define( 'HSBC_MTPV_URL', plugin_dir_url( __FILE__ ) );

require_once HSBC_MTPV_PATH . 'includes/class-settings.php';
require_once HSBC_MTPV_PATH . 'includes/class-hsbc-menu.php';
require_once HSBC_MTPV_PATH . 'includes/class-ajax.php';

/**
 * Instantiate all plugin classes after WordPress has finished loading.
 *
 * Using `plugins_loaded` (instead of `init`) ensures that every WordPress
 * function and hook is available before our constructors register their
 * own actions/filters.
 */
add_action(
	'plugins_loaded',
	function () {
		new HSBC_MTPV_Settings();
		new HSBC_Menu();
		new HSBC_MTPV_Ajax();
	}
);
