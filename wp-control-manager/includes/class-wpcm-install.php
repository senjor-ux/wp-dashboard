<?php

defined( 'ABSPATH' ) || exit;

class WPCM_Install {

	public static function activate() {
		self::create_tables();

		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( WPCM_CAP );
		}

		if ( ! wp_next_scheduled( 'wpcm_pull_all' ) ) {
			wp_schedule_event( time() + 300, 'twicedaily', 'wpcm_pull_all' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'wpcm_pull_all' );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'wpcm_db_version' ) !== WPCM_DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c = $wpdb->get_charset_collate();
		$t = array();
		foreach ( WPCM_DB::TABLES as $name ) {
			$t[ $name ] = WPCM_DB::t( $name );
		}

		$sql = array();

		$sql[] = "CREATE TABLE {$t['clients']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			contact_name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(64) NOT NULL DEFAULT '',
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY name (name)
		) $c;";

		$sql[] = "CREATE TABLE {$t['sites']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NULL,
			name varchar(191) NOT NULL,
			url varchar(255) NOT NULL,
			reported_url varchar(255) NOT NULL DEFAULT '',
			wp_version varchar(32) NOT NULL DEFAULT '',
			php_version varchar(32) NOT NULL DEFAULT '',
			active_theme varchar(191) NOT NULL DEFAULT '',
			info longtext NULL,
			last_sync_at datetime NULL,
			last_error text NULL,
			last_error_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY client_id (client_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t['agents']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			secret text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			agent_api varchar(255) NOT NULL DEFAULT '',
			agent_version varchar(32) NOT NULL DEFAULT '',
			paired_at datetime NULL,
			last_seen_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_id (site_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t['components']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL,
			slug varchar(150) NOT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			version varchar(64) NOT NULL DEFAULT '',
			active tinyint(1) NOT NULL DEFAULT 0,
			first_seen_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			removed_at datetime NULL,
			PRIMARY KEY  (id),
			KEY site_type_slug (site_id,type,slug)
		) $c;";

		$sql[] = "CREATE TABLE {$t['update_offers']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			component_id bigint(20) unsigned NOT NULL,
			current_version varchar(64) NOT NULL DEFAULT '',
			new_version varchar(64) NOT NULL,
			first_seen_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			resolved_at datetime NULL,
			PRIMARY KEY  (id),
			KEY site_open (site_id,resolved_at),
			KEY component_id (component_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t['component_events']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			component_id bigint(20) unsigned NOT NULL,
			event_type varchar(20) NOT NULL,
			old_version varchar(64) NOT NULL DEFAULT '',
			new_version varchar(64) NOT NULL DEFAULT '',
			detected_at datetime NOT NULL,
			sync_run_id bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY site_detected (site_id,detected_at)
		) $c;";

		$sql[] = "CREATE TABLE {$t['maintenance_logs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			performed_at datetime NOT NULL,
			performer_user_id bigint(20) unsigned NULL,
			performer_name varchar(191) NOT NULL,
			category varchar(50) NOT NULL,
			description text NOT NULL,
			duration_minutes int(11) NULL,
			notes text NULL,
			created_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY site_performed (site_id,performed_at)
		) $c;";

		$sql[] = "CREATE TABLE {$t['maintenance_event_links']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			maintenance_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY maintenance_id (maintenance_id),
			KEY event_id (event_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t['site_checks']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			check_key varchar(100) NOT NULL,
			severity varchar(20) NOT NULL DEFAULT 'low',
			title varchar(255) NOT NULL,
			component varchar(191) NOT NULL DEFAULT '',
			details text NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			first_seen_at datetime NOT NULL,
			last_seen_at datetime NOT NULL,
			resolved_at datetime NULL,
			PRIMARY KEY  (id),
			KEY site_key (site_id,check_key)
		) $c;";

		$sql[] = "CREATE TABLE {$t['backups']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			provider varchar(50) NOT NULL,
			external_id varchar(191) NOT NULL,
			status varchar(20) NOT NULL,
			started_at datetime NULL,
			completed_at datetime NULL,
			size_bytes bigint(20) unsigned NULL,
			storage varchar(100) NOT NULL DEFAULT '',
			triggered_by varchar(100) NOT NULL DEFAULT '',
			first_seen_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY site_ext (site_id,provider,external_id)
		) $c;";

		$sql[] = "CREATE TABLE {$t['sync_runs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			direction varchar(10) NOT NULL,
			status varchar(20) NOT NULL,
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			changes int(11) NOT NULL DEFAULT 0,
			message text NULL,
			PRIMARY KEY  (id),
			KEY site_started (site_id,started_at)
		) $c;";

		$sql[] = "CREATE TABLE {$t['audit_log']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NULL,
			actor varchar(191) NOT NULL DEFAULT '',
			action varchar(100) NOT NULL,
			object_type varchar(50) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NULL,
			site_id bigint(20) unsigned NULL,
			details text NULL,
			ip varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY site_created (site_id,created_at),
			KEY created_at (created_at)
		) $c;";

		$sql[] = "CREATE TABLE {$t['notes']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NULL,
			author varchar(191) NOT NULL DEFAULT '',
			body text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY site_id (site_id)
		) $c;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'wpcm_db_version', WPCM_DB_VERSION );
	}
}
