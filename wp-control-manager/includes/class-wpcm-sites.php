<?php

defined( 'ABSPATH' ) || exit;

class WPCM_Sites {

	/**
	 * Inner SELECT returning every site with derived health columns.
	 * Wrapped as a derived table so outer filters can use the computed status.
	 */
	private static function base_sql() {
		global $wpdb;
		$s = WPCM_DB::t( 'sites' );
		$c = WPCM_DB::t( 'clients' );
		$a = WPCM_DB::t( 'agents' );
		$o = WPCM_DB::t( 'update_offers' );
		$b = WPCM_DB::t( 'backups' );
		$k = WPCM_DB::t( 'site_checks' );
		$m = WPCM_DB::t( 'maintenance_logs' );

		$offline_before = WPCM_DB::ago( HOUR_IN_SECONDS * (int) WPCM_Settings::get( 'offline_after_hours' ) );

		return $wpdb->prepare(
			"SELECT s.id, s.client_id, s.name, s.url, s.reported_url, s.wp_version, s.php_version, s.active_theme,
				s.last_sync_at, s.last_error, s.last_error_at, s.created_at,
				c.name AS client_name,
				a.status AS agent_status, a.agent_version, a.paired_at, a.last_seen_at,
				CASE
					WHEN a.status IS NULL OR a.status <> 'paired' THEN 'pending'
					WHEN a.last_seen_at IS NULL OR a.last_seen_at < %s THEN 'offline'
					WHEN s.last_error_at IS NOT NULL AND s.last_error_at > a.last_seen_at THEN 'offline'
					ELSE 'online'
				END AS status,
				(SELECT COUNT(*) FROM $o o WHERE o.site_id = s.id AND o.resolved_at IS NULL) AS pending_updates,
				(SELECT MAX(COALESCE(bk.completed_at, bk.started_at)) FROM $b bk WHERE bk.site_id = s.id AND bk.status = 'success') AS last_backup_at,
				(SELECT COUNT(*) FROM $k ck WHERE ck.site_id = s.id AND ck.status = 'open' AND ck.severity = 'critical') AS critical_issues,
				(SELECT COUNT(*) FROM $k ck WHERE ck.site_id = s.id AND ck.status = 'open') AS open_issues,
				(SELECT ml.performed_at FROM $m ml WHERE ml.site_id = s.id ORDER BY ml.performed_at DESC, ml.id DESC LIMIT 1) AS last_maintenance_at,
				(SELECT ml.performer_name FROM $m ml WHERE ml.site_id = s.id ORDER BY ml.performed_at DESC, ml.id DESC LIMIT 1) AS last_maintenance_by
			FROM $s s
			LEFT JOIN $c c ON c.id = s.client_id
			LEFT JOIN $a a ON a.site_id = s.id",
			$offline_before
		);
	}

	public static function query( $args = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(t.name LIKE %s OR t.url LIKE %s OR t.client_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['client_id'] ) ) {
			$where[]  = 't.client_id = %d';
			$params[] = (int) $args['client_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			switch ( $args['status'] ) {
				case 'updates':
					$where[] = 't.pending_updates > 0';
					break;
				case 'critical':
					$where[] = 't.critical_issues > 0';
					break;
				case 'backup_stale':
					$where[]  = '(t.last_backup_at IS NULL OR t.last_backup_at < %s)';
					$params[] = WPCM_DB::ago( DAY_IN_SECONDS * (int) WPCM_Settings::get( 'backup_stale_days' ) );
					break;
				default:
					$where[]  = 't.status = %s';
					$params[] = sanitize_key( $args['status'] );
			}
		}

		$orderable = array( 'name', 'client_name', 'status', 'pending_updates', 'last_backup_at', 'php_version', 'last_sync_at', 'last_maintenance_at' );
		$orderby   = in_array( $args['orderby'] ?? '', $orderable, true ) ? $args['orderby'] : 'name';
		$order     = 'desc' === strtolower( $args['order'] ?? '' ) ? 'DESC' : 'ASC';

		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 25 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$from      = '(' . self::base_sql() . ') t WHERE ' . implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM $from";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$sql  = "SELECT t.* FROM $from ORDER BY t.$orderby $order, t.id ASC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ) );

		return array(
			'items' => array_map( array( __CLASS__, 'format' ), (array) $rows ),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT t.* FROM (' . self::base_sql() . ') t WHERE t.id = %d', (int) $id ) );
		if ( ! $row ) {
			return null;
		}
		$site = self::format( $row );
		$info = $wpdb->get_var( $wpdb->prepare( 'SELECT info FROM ' . WPCM_DB::t( 'sites' ) . ' WHERE id = %d', (int) $id ) );
		$site['info'] = $info ? json_decode( $info, true ) : null;
		return $site;
	}

	private static function format( $row ) {
		$row = WPCM_DB::format_row( $row );
		foreach ( array( 'pending_updates', 'critical_issues', 'open_issues' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		$stale_days        = (int) WPCM_Settings::get( 'backup_stale_days' );
		$row['backup_stale'] = empty( $row['last_backup_at'] ) || strtotime( $row['last_backup_at'] ) < time() - $stale_days * DAY_IN_SECONDS;
		return $row;
	}

	public static function stats() {
		global $wpdb;
		$rows  = $wpdb->get_results( 'SELECT t.status, t.pending_updates, t.critical_issues, t.last_backup_at FROM (' . self::base_sql() . ') t' );
		$stale = time() - DAY_IN_SECONDS * (int) WPCM_Settings::get( 'backup_stale_days' );

		$stats = array(
			'total'           => 0,
			'online'          => 0,
			'offline'         => 0,
			'pending'         => 0,
			'pending_updates' => 0,
			'sites_with_updates' => 0,
			'critical_issues' => 0,
			'backup_stale'    => 0,
		);
		foreach ( (array) $rows as $r ) {
			$stats['total']++;
			$stats[ $r->status ]++;
			$stats['pending_updates'] += (int) $r->pending_updates;
			$stats['critical_issues'] += (int) $r->critical_issues;
			if ( (int) $r->pending_updates > 0 ) {
				$stats['sites_with_updates']++;
			}
			if ( 'pending' !== $r->status && ( ! $r->last_backup_at || strtotime( $r->last_backup_at . ' UTC' ) < $stale ) ) {
				$stats['backup_stale']++;
			}
		}
		$monitored              = $stats['total'] - $stats['pending'];
		$stats['online_percent'] = $monitored ? (int) round( 100 * $stats['online'] / $monitored ) : 0;
		return $stats;
	}

	public static function sanitize( array $data ) {
		$out = array();
		if ( array_key_exists( 'name', $data ) ) {
			$out['name'] = sanitize_text_field( $data['name'] );
		}
		if ( array_key_exists( 'url', $data ) ) {
			$out['url'] = untrailingslashit( esc_url_raw( trim( (string) $data['url'] ), array( 'http', 'https' ) ) );
		}
		if ( array_key_exists( 'client_id', $data ) ) {
			$out['client_id'] = $data['client_id'] ? (int) $data['client_id'] : null;
		}
		return $out;
	}

	/** @return array|WP_Error Created site plus a one-time pairing token. */
	public static function create( array $data ) {
		global $wpdb;
		$data = self::sanitize( $data );
		if ( empty( $data['url'] ) ) {
			return new WP_Error( 'wpc_invalid', 'A valid site URL is required.', array( 'status' => 400 ) );
		}
		if ( empty( $data['name'] ) ) {
			$data['name'] = wp_parse_url( $data['url'], PHP_URL_HOST );
		}
		$now = WPCM_DB::now();
		$wpdb->insert( WPCM_DB::t( 'sites' ), array_merge( $data, array( 'created_at' => $now, 'updated_at' => $now ) ) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'wpc_db', 'Could not create site.', array( 'status' => 500 ) );
		}
		$token = self::issue_token( $id );
		WPCM_Audit::log( 'site.created', 'site', $id, $id, array( 'name' => $data['name'], 'url' => $data['url'] ) );
		return array( 'site' => self::get( $id ), 'pairing_token' => $token );
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$data = self::sanitize( $data );
		if ( array_key_exists( 'url', $data ) && empty( $data['url'] ) ) {
			return new WP_Error( 'wpc_invalid', 'A valid site URL is required.', array( 'status' => 400 ) );
		}
		$data['updated_at'] = WPCM_DB::now();
		$wpdb->update( WPCM_DB::t( 'sites' ), $data, array( 'id' => (int) $id ) );
		WPCM_Audit::log( 'site.updated', 'site', $id, $id, $data );
		return self::get( $id );
	}

	public static function delete( $id ) {
		global $wpdb;
		$id   = (int) $id;
		$site = self::get( $id );
		if ( ! $site ) {
			return false;
		}
		$maint_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . WPCM_DB::t( 'maintenance_logs' ) . ' WHERE site_id = %d', $id ) );
		if ( $maint_ids ) {
			$wpdb->query( 'DELETE FROM ' . WPCM_DB::t( 'maintenance_event_links' ) . ' WHERE maintenance_id IN (' . implode( ',', array_map( 'intval', $maint_ids ) ) . ')' );
		}
		foreach ( array( 'agents', 'components', 'update_offers', 'component_events', 'maintenance_logs', 'site_checks', 'backups', 'sync_runs', 'notes' ) as $table ) {
			$wpdb->delete( WPCM_DB::t( $table ), array( 'site_id' => $id ) );
		}
		$wpdb->delete( WPCM_DB::t( 'sites' ), array( 'id' => $id ) );
		WPCM_Audit::log( 'site.deleted', 'site', $id, null, array( 'name' => $site['name'], 'url' => $site['url'] ) );
		return true;
	}

	/**
	 * Generate (or rotate) the site's shared secret and return a pairing token
	 * for the agent: "wpc1." + base64url(json{api, site, key}).
	 * Rotating invalidates any previously paired agent until it re-pairs.
	 */
	public static function issue_token( $site_id ) {
		global $wpdb;
		$secret = WPCM_Crypto::new_secret();
		$table  = WPCM_DB::t( 'agents' );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE site_id = %d", $site_id ) );
		$data   = array(
			'secret'    => WPCM_Crypto::encrypt( $secret ),
			'status'    => 'pending',
			'paired_at' => null,
		);
		if ( $exists ) {
			$wpdb->update( $table, $data, array( 'site_id' => $site_id ) );
		} else {
			$wpdb->insert( $table, array_merge( $data, array( 'site_id' => $site_id, 'created_at' => WPCM_DB::now() ) ) );
		}

		$payload = wp_json_encode(
			array(
				'v'    => 1,
				'api'  => get_rest_url(),
				'site' => (int) $site_id,
				'key'  => $secret,
			)
		);
		return 'wpc1.' . rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
	}

	public static function get_agent( $site_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPCM_DB::t( 'agents' ) . ' WHERE site_id = %d', (int) $site_id ) );
	}

	public static function agent_secret( $agent ) {
		return $agent ? WPCM_Crypto::decrypt( $agent->secret ) : false;
	}

	public static function clients() {
		global $wpdb;
		$c = WPCM_DB::t( 'clients' );
		$s = WPCM_DB::t( 'sites' );
		$rows = $wpdb->get_results( "SELECT c.*, (SELECT COUNT(*) FROM $s s WHERE s.client_id = c.id) AS site_count FROM $c c ORDER BY c.name ASC" );
		$out  = array();
		foreach ( WPCM_DB::format_rows( $rows ) as $row ) {
			$row['site_count'] = (int) $row['site_count'];
			$out[]             = $row;
		}
		return $out;
	}

	public static function get_client( $id ) {
		global $wpdb;
		return WPCM_DB::format_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPCM_DB::t( 'clients' ) . ' WHERE id = %d', (int) $id ) ) );
	}

	public static function sanitize_client( array $data ) {
		$out = array();
		foreach ( array( 'name', 'contact_name', 'phone' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$out[ $k ] = sanitize_text_field( $data[ $k ] );
			}
		}
		if ( array_key_exists( 'email', $data ) ) {
			$out['email'] = sanitize_email( $data['email'] );
		}
		if ( array_key_exists( 'notes', $data ) ) {
			$out['notes'] = sanitize_textarea_field( $data['notes'] );
		}
		return $out;
	}

	public static function save_client( $id, array $data ) {
		global $wpdb;
		$data = self::sanitize_client( $data );
		$now  = WPCM_DB::now();
		$t    = WPCM_DB::t( 'clients' );
		if ( $id ) {
			$data['updated_at'] = $now;
			$wpdb->update( $t, $data, array( 'id' => (int) $id ) );
			WPCM_Audit::log( 'client.updated', 'client', $id, null, array( 'name' => $data['name'] ?? null ) );
		} else {
			if ( empty( $data['name'] ) ) {
				return new WP_Error( 'wpc_invalid', 'Client name is required.', array( 'status' => 400 ) );
			}
			$wpdb->insert( $t, array_merge( $data, array( 'created_at' => $now, 'updated_at' => $now ) ) );
			$id = (int) $wpdb->insert_id;
			WPCM_Audit::log( 'client.created', 'client', $id, null, array( 'name' => $data['name'] ) );
		}
		return self::get_client( $id );
	}

	public static function delete_client( $id ) {
		global $wpdb;
		$client = self::get_client( $id );
		if ( ! $client ) {
			return false;
		}
		$wpdb->update( WPCM_DB::t( 'sites' ), array( 'client_id' => null ), array( 'client_id' => (int) $id ) );
		$wpdb->delete( WPCM_DB::t( 'clients' ), array( 'id' => (int) $id ) );
		WPCM_Audit::log( 'client.deleted', 'client', $id, null, array( 'name' => $client['name'] ) );
		return true;
	}
}
