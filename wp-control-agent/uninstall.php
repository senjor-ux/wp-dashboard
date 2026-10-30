<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wpca_connection' );
delete_option( 'wpca_last_sync' );
delete_transient( 'wpca_refreshed' );
wp_clear_scheduled_hook( 'wpca_sync' );
wp_clear_scheduled_hook( 'wpca_sync', array( 'soon' ) );
