<?php
/**
 * Plugin Name:       WP Control Agent
 * Description:       Connects this website to a WP Control Manager. Securely reports WordPress/PHP versions, plugins, themes, available updates, backup status and security checks.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            WP Control
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-control-agent
 */

defined( 'ABSPATH' ) || exit;

define( 'WPCA_VERSION', '1.0.0' );
define( 'WPCA_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCA_URL', plugin_dir_url( __FILE__ ) );

/*
 * For local development only: define( 'WPCA_ALLOW_INSECURE', true ); in wp-config.php
 * to allow a non-HTTPS / private-network Manager.
 */

require_once WPCA_DIR . 'includes/class-wpca-signature.php';
require_once WPCA_DIR . 'includes/class-wpca-connection.php';
require_once WPCA_DIR . 'includes/class-wpca-collector.php';
require_once WPCA_DIR . 'includes/class-wpca-rest.php';
require_once WPCA_DIR . 'includes/class-wpca-admin.php';

register_activation_hook( __FILE__, array( 'WPCA_Connection', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPCA_Connection', 'deactivate' ) );

add_action( 'rest_api_init', array( 'WPCA_Rest', 'register_routes' ) );
add_action( 'wpca_sync', array( 'WPCA_Connection', 'cron_push' ) );

foreach ( array( 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'switch_theme', '_core_updated_successfully' ) as $wpca_hook ) {
	add_action( $wpca_hook, array( 'WPCA_Connection', 'schedule_soon' ) );
}

if ( is_admin() ) {
	WPCA_Admin::init();
}
