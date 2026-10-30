<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wpcm_settings = get_option( 'wpcm_settings', array() );
if ( empty( $wpcm_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$wpcm_tables = array( 'clients', 'sites', 'agents', 'components', 'update_offers', 'component_events', 'maintenance_logs', 'maintenance_event_links', 'site_checks', 'backups', 'sync_runs', 'audit_log', 'notes' );
foreach ( $wpcm_tables as $wpcm_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpc_' . $wpcm_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}
delete_option( 'wpcm_settings' );
delete_option( 'wpcm_db_version' );

$wpcm_role = get_role( 'administrator' );
if ( $wpcm_role ) {
	$wpcm_role->remove_cap( 'manage_wpcontrol' );
}
