<?php
/**
 * Plugin Name:       WP Control Manager
 * Description:       Central client & website management: health dashboard, update history, maintenance records and reporting for sites running the WP Control Agent.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            WP Control
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-control-manager
 */

defined( 'ABSPATH' ) || exit;

define( 'WPCM_VERSION', '1.0.0' );
define( 'WPCM_DB_VERSION', '1' );
define( 'WPCM_FILE', __FILE__ );
define( 'WPCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCM_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCM_CAP', 'manage_wpcontrol' );

require_once WPCM_DIR . 'includes/class-wpcm-db.php';
require_once WPCM_DIR . 'includes/class-wpcm-install.php';
require_once WPCM_DIR . 'includes/class-wpcm-settings.php';
require_once WPCM_DIR . 'includes/class-wpcm-crypto.php';
require_once WPCM_DIR . 'includes/class-wpcm-signature.php';
require_once WPCM_DIR . 'includes/class-wpcm-audit.php';
require_once WPCM_DIR . 'includes/class-wpcm-sites.php';
require_once WPCM_DIR . 'includes/class-wpcm-sync.php';
require_once WPCM_DIR . 'includes/class-wpcm-rest-agent.php';
require_once WPCM_DIR . 'includes/class-wpcm-rest-admin.php';
require_once WPCM_DIR . 'includes/class-wpcm-export.php';
require_once WPCM_DIR . 'includes/class-wpcm-admin.php';

register_activation_hook( __FILE__, array( 'WPCM_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPCM_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WPCM_Install', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'WPCM_Rest_Agent', 'register_routes' ) );
add_action( 'rest_api_init', array( 'WPCM_Rest_Admin', 'register_routes' ) );
add_action( 'admin_post_wpcontrol_export', array( 'WPCM_Export', 'handle' ) );
add_action( 'wpcm_pull_all', array( 'WPCM_Sync', 'pull_all' ) );

if ( is_admin() ) {
	WPCM_Admin::init();
}
