<?php

defined( 'ABSPATH' ) || exit;

class WPCM_Rest_Admin {

	const NS = 'wpcontrol/v1';

	public static function can_manage() {
		return current_user_can( WPCM_CAP );
	}

	private static function route( $path, array $handlers ) {
		$routes = array();
		foreach ( $handlers as $method => $callback ) {
			$routes[] = array(
				'methods'             => $method,
				'callback'            => array( __CLASS__, $callback ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			);
		}
		register_rest_route( self::NS, $path, $routes );
	}

	public static function register_routes() {
		$id = '(?P<id>\d+)';

		self::route( '/dashboard', array( 'GET' => 'dashboard' ) );
		self::route( '/settings', array( 'GET' => 'get_settings', 'POST' => 'save_settings' ) );
		self::route( '/users', array( 'GET' => 'users' ) );

		self::route( '/clients', array( 'GET' => 'list_clients', 'POST' => 'create_client' ) );
		self::route( "/clients/$id", array( 'PUT' => 'update_client', 'DELETE' => 'delete_client' ) );

		self::route( '/sites', array( 'GET' => 'list_sites', 'POST' => 'create_site' ) );
		self::route( "/sites/$id", array( 'GET' => 'get_site', 'PUT' => 'update_site', 'DELETE' => 'delete_site' ) );
		self::route( "/sites/$id/token", array( 'POST' => 'regenerate_token' ) );
		self::route( "/sites/$id/revoke", array( 'POST' => 'revoke_agent' ) );
		self::route( "/sites/$id/sync", array( 'POST' => 'sync_site' ) );
		self::route( "/sites/$id/components", array( 'GET' => 'site_components' ) );
		self::route( "/sites/$id/events", array( 'GET' => 'site_events' ) );
		self::route( "/sites/$id/backups", array( 'GET' => 'site_backups' ) );
		self::route( "/sites/$id/checks", array( 'GET' => 'site_checks' ) );
		self::route( "/sites/$id/activity", array( 'GET' => 'site_activity' ) );
		self::route( "/sites/$id/notes", array( 'GET' => 'site_notes', 'POST' => 'add_note' ) );
		self::route( "/notes/$id", array( 'DELETE' => 'delete_note' ) );

		self::route( '/events', array( 'GET' => 'list_events' ) );
		self::route( '/maintenance', array( 'GET' => 'list_maintenance', 'POST' => 'create_maintenance' ) );
		self::route( "/maintenance/$id", array( 'GET' => 'get_maintenance', 'PUT' => 'update_maintenance', 'DELETE' => 'delete_maintenance' ) );

		self::route( '/activity', array( 'GET' => 'activity' ) );
	}

	private static function not_found() {
		return new WP_Error( 'wpc_not_found', 'Not found.', array( 'status' => 404 ) );
	}

	private static function require_site( WP_REST_Request $r ) {
		$site = WPCM_Sites::get( (int) $r['id'] );
		return $site ? $site : self::not_found();
	}

	private static function paging( WP_REST_Request $r, $default = 25 ) {
		$per_page = max( 1, min( 500, (int) ( $r['per_page'] ? $r['per_page'] : $default ) ) );
		$page     = max( 1, (int) ( $r['page'] ? $r['page'] : 1 ) );
		return array( $per_page, $page, ( $page - 1 ) * $per_page );
	}

	public static function dashboard() {
		$recent_maintenance = self::query_maintenance( array( 'per_page' => 6 ) );
		$recent_events      = self::query_events( array( 'per_page' => 8 ) );
		return array(
			'stats'              => WPCM_Sites::stats(),
			'recent_maintenance' => $recent_maintenance['items'],
			'recent_events'      => $recent_events['items'],
		);
	}

	public static function get_settings() {
		return WPCM_Settings::all();
	}

	public static function save_settings( WP_REST_Request $r ) {
		$saved = WPCM_Settings::update( (array) $r->get_json_params() );
		WPCM_Audit::log( 'settings.updated', 'settings' );
		return $saved;
	}

	public static function users() {
		$users = get_users(
			array(
				'capability' => WPCM_CAP,
				'fields'     => array( 'ID', 'display_name' ),
				'orderby'    => 'display_name',
			)
		);
		return array_map(
			function ( $u ) {
				return array( 'id' => (int) $u->ID, 'name' => $u->display_name );
			},
			$users
		);
	}

	public static function list_clients() {
		return WPCM_Sites::clients();
	}

	public static function create_client( WP_REST_Request $r ) {
		return WPCM_Sites::save_client( 0, (array) $r->get_json_params() );
	}

	public static function update_client( WP_REST_Request $r ) {
		if ( ! WPCM_Sites::get_client( (int) $r['id'] ) ) {
			return self::not_found();
		}
		return WPCM_Sites::save_client( (int) $r['id'], (array) $r->get_json_params() );
	}

	public static function delete_client( WP_REST_Request $r ) {
		return WPCM_Sites::delete_client( (int) $r['id'] ) ? array( 'deleted' => true ) : self::not_found();
	}

	public static function list_sites( WP_REST_Request $r ) {
		return WPCM_Sites::query(
			array(
				'search'    => sanitize_text_field( (string) $r['search'] ),
				'client_id' => (int) $r['client_id'],
				'status'    => sanitize_key( (string) $r['status'] ),
				'orderby'   => sanitize_key( (string) $r['orderby'] ),
				'order'     => sanitize_key( (string) $r['order'] ),
				'page'      => (int) $r['page'],
				'per_page'  => (int) $r['per_page'],
			)
		);
	}

	public static function create_site( WP_REST_Request $r ) {
		return WPCM_Sites::create( (array) $r->get_json_params() );
	}

	public static function get_site( WP_REST_Request $r ) {
		return self::require_site( $r );
	}

	public static function update_site( WP_REST_Request $r ) {
		$site = self::require_site( $r );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		return WPCM_Sites::update( $site['id'], (array) $r->get_json_params() );
	}

	public static function delete_site( WP_REST_Request $r ) {
		return WPCM_Sites::delete( (int) $r['id'] ) ? array( 'deleted' => true ) : self::not_found();
	}

	public static function regenerate_token( WP_REST_Request $r ) {
		$site = self::require_site( $r );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$token = WPCM_Sites::issue_token( $site['id'] );
		WPCM_Audit::log( 'agent.token_regenerated', 'site', $site['id'], $site['id'] );
		return array( 'pairing_token' => $token, 'site' => WPCM_Sites::get( $site['id'] ) );
	}

	public static function revoke_agent( WP_REST_Request $r ) {
		global $wpdb;
		$site = self::require_site( $r );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$wpdb->update( WPCM_DB::t( 'agents' ), array( 'status' => 'revoked' ), array( 'site_id' => $site['id'] ) );
		WPCM_Audit::log( 'agent.revoked', 'site', $site['id'], $site['id'] );
		return WPCM_Sites::get( $site['id'] );
	}

	public static function sync_site( WP_REST_Request $r ) {
		$site = self::require_site( $r );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$result = WPCM_Sync::pull( $site['id'], true );
		WPCM_Audit::log( 'site.sync_requested', 'site', $site['id'], $site['id'], is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'result' => $result, 'site' => WPCM_Sites::get( $site['id'] ) );
	}

	public static function site_components( WP_REST_Request $r ) {
		global $wpdb;
		$site = self::require_site( $r );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$ct = WPCM_DB::t( 'components' );
		$ot = WPCM_DB::t( 'update_offers' );

		$where  = 'c.site_id = %d AND c.removed_at IS NULL';
		$params = array( $site['id'] );
		if ( in_array( $r['type'], array( 'core', 'plugin', 'theme' ), true ) ) {
			$where   .= ' AND c.type = %s';
			$params[] = $r['type'];
		}
		if ( $r['updates_only'] ) {
			$where .= ' AND o.id IS NOT NULL';
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, o.new_version AS update_version, o.first_seen_at AS update_seen_at
				FROM $ct c LEFT JOIN $ot o ON o.component_id = c.id AND o.resolved_at IS NULL
				WHERE $where
				ORDER BY (o.id IS NULL) ASC, FIELD(c.type, 'core', 'plugin', 'theme'), c.name ASC",
				$params
			)
		);
		$items = array();
		foreach ( WPCM_DB::format_rows( $rows ) as $row ) {
			$row['active'] = (bool) $row['active'];
			$items[]       = $row;
		}

		$last_checked = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(finished_at) FROM ' . WPCM_DB::t( 'sync_runs' ) . " WHERE site_id = %d AND status = 'success'", $site['id'] ) );
		return array( 'items' => $items, 'last_checked_at' => WPCM_DB::iso( $last_checked ) );
	}

	public static function site_events( WP_REST_Request $r ) {
		$site = self::require_site( $r );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		list( $per_page, $page ) = self::paging( $r, 50 );
		return self::query_events(
			array(
				'site_id'  => $site['id'],
				'unlinked' => (bool) $r['unlinked'],
				'include'  => (int) $r['include_maintenance'],
				'per_page' => $per_page,
				'page'     => $page,
			)
		);
	}

	public static function list_events( WP_REST_Request $r ) {
		list( $per_page, $page ) = self::paging( $r, 50 );
		return self::query_events(
			array(
				'site_id'   => (int) $r['site_id'],
				'client_id' => (int) $r['client_id'],
				'unlinked'  => (bool) $r['unlinked'],
				'per_page'  => $per_page,
				'page'      => $page,
			)
		);
	}

	/**
	 * Events joined with their components and attributing maintenance records.
	 *
	 * Args: site_id, client_id, unlinked (only events no record claims yet),
	 * include (a maintenance id whose own links should still be listed when unlinked=1),
	 * from, to, per_page, page.
	 */
	public static function query_events( $args ) {
		global $wpdb;
		$et = WPCM_DB::t( 'component_events' );
		$ct = WPCM_DB::t( 'components' );
		$st = WPCM_DB::t( 'sites' );
		$lt = WPCM_DB::t( 'maintenance_event_links' );

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['site_id'] ) ) {
			$where[]  = 'e.site_id = %d';
			$params[] = (int) $args['site_id'];
		}
		if ( ! empty( $args['client_id'] ) ) {
			$where[]  = 's.client_id = %d';
			$params[] = (int) $args['client_id'];
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'e.detected_at >= %s';
			$params[] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'e.detected_at <= %s';
			$params[] = $args['to'];
		}
		if ( ! empty( $args['unlinked'] ) ) {
			$where[]  = "NOT EXISTS (SELECT 1 FROM $lt l WHERE l.event_id = e.id AND l.maintenance_id <> %d)";
			$params[] = (int) ( $args['include'] ?? 0 );
		}

		$per_page  = max( 1, min( 5000, (int) ( $args['per_page'] ?? 50 ) ) );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where_sql = implode( ' AND ', $where );
		$from      = "$et e JOIN $ct c ON c.id = e.component_id JOIN $st s ON s.id = e.site_id WHERE $where_sql";

		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $from", $params ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM $from" ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, c.type AS component_type, c.slug AS component_slug, c.name AS component_name, s.name AS site_name
				FROM $from ORDER BY e.detected_at DESC, e.id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			)
		);
		$items = WPCM_DB::format_rows( $rows );

		// Attach the maintenance records (performer) that document each event.
		$ids = wp_list_pluck( $items, 'id' );
		$map = array();
		if ( $ids ) {
			$mt    = WPCM_DB::t( 'maintenance_logs' );
			$links = $wpdb->get_results(
				"SELECT l.event_id, m.id, m.performer_name, m.performed_at, m.category
				FROM $lt l JOIN $mt m ON m.id = l.maintenance_id
				WHERE l.event_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')'
			);
			foreach ( (array) $links as $l ) {
				$map[ (int) $l->event_id ][] = array(
					'id'             => (int) $l->id,
					'performer_name' => $l->performer_name,
					'performed_at'   => WPCM_DB::iso( $l->performed_at ),
					'category'       => $l->category,
				);
			}
		}
		foreach ( $items as &$item ) {
			$item['maintenance'] = $map[ $item['id'] ] ?? array();
		}
		unset( $item );

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	public static function site_backups( WP_REST_Request $r ) {
		global $wpdb;
		$site = self::require_site( $r );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . WPCM_DB::t( 'backups' ) . ' WHERE site_id = %d ORDER BY COALESCE(started_at, first_seen_at) DESC LIMIT 100',
				$site['id']
			)
		);
		$items = array();
		foreach ( WPCM_DB::format_rows( $rows ) as $row ) {
			$row['size_bytes'] = null === $row['size_bytes'] ? null : (int) $row['size_bytes'];
			$items[]           = $row;
		}
		$info = is_array( $site['info'] ) ? $site['info'] : array();
		return array(
			'items'     => $items,
			'providers' => isset( $info['backup_providers'] ) ? $info['backup_providers'] : '',
		);
	}

	public static function site_checks( WP_REST_Request $r ) {
		global $wpdb;
		$site = self::require_site( $r );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$status = 'resolved' === $r['status'] ? 'resolved' : 'open';
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . WPCM_DB::t( 'site_checks' ) . " WHERE site_id = %d AND status = %s
				ORDER BY FIELD(severity, 'critical', 'moderate', 'low'), last_seen_at DESC LIMIT 200",
				$site['id'],
				$status
			)
		);
		$counts = array( 'critical' => 0, 'moderate' => 0, 'low' => 0 );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT severity, COUNT(*) AS n FROM ' . WPCM_DB::t( 'site_checks' ) . " WHERE site_id = %d AND status = 'open' GROUP BY severity", $site['id'] ) ) as $c ) {
			$counts[ $c->severity ] = (int) $c->n;
		}
		return array(
			'items'           => WPCM_DB::format_rows( $rows ),
			'counts'          => $counts,
			'last_checked_at' => $site['last_sync_at'],
		);
	}

	public static function site_activity( WP_REST_Request $r ) {
		global $wpdb;
		$site = self::require_site( $r );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$audit = WPCM_Audit::query( array( 'site_id' => $site['id'], 'per_page' => 100 ) );
		$runs  = WPCM_DB::format_rows(
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . WPCM_DB::t( 'sync_runs' ) . ' WHERE site_id = %d ORDER BY started_at DESC, id DESC LIMIT 50', $site['id'] )
			)
		);
		return array( 'audit' => $audit['items'], 'sync_runs' => $runs );
	}

	public static function site_notes( WP_REST_Request $r ) {
		global $wpdb;
		$site = self::require_site( $r );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		return WPCM_DB::format_rows(
			$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WPCM_DB::t( 'notes' ) . ' WHERE site_id = %d ORDER BY created_at DESC, id DESC', $site['id'] ) )
		);
	}

	public static function add_note( WP_REST_Request $r ) {
		global $wpdb;
		$site = self::require_site( $r );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$body = sanitize_textarea_field( (string) $r['body'] );
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'wpc_invalid', 'Note cannot be empty.', array( 'status' => 400 ) );
		}
		$user = wp_get_current_user();
		$wpdb->insert(
			WPCM_DB::t( 'notes' ),
			array(
				'site_id'    => $site['id'],
				'user_id'    => $user->ID,
				'author'     => $user->display_name,
				'body'       => $body,
				'created_at' => WPCM_DB::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		WPCM_Audit::log( 'note.created', 'note', $id, $site['id'] );
		return WPCM_DB::format_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPCM_DB::t( 'notes' ) . ' WHERE id = %d', $id ) ) );
	}

	public static function delete_note( WP_REST_Request $r ) {
		global $wpdb;
		$note = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPCM_DB::t( 'notes' ) . ' WHERE id = %d', (int) $r['id'] ) );
		if ( ! $note ) {
			return self::not_found();
		}
		$wpdb->delete( WPCM_DB::t( 'notes' ), array( 'id' => (int) $note->id ) );
		WPCM_Audit::log( 'note.deleted', 'note', (int) $note->id, (int) $note->site_id );
		return array( 'deleted' => true );
	}

	public static function activity( WP_REST_Request $r ) {
		list( $per_page, $page ) = self::paging( $r, 50 );
		return WPCM_Audit::query(
			array(
				'site_id'  => (int) $r['site_id'],
				'action'   => sanitize_text_field( (string) $r['action'] ),
				'per_page' => $per_page,
				'page'     => $page,
			)
		);
	}

	public static function query_maintenance( $args ) {
		global $wpdb;
		$mt = WPCM_DB::t( 'maintenance_logs' );
		$st = WPCM_DB::t( 'sites' );
		$ct = WPCM_DB::t( 'clients' );
		$lt = WPCM_DB::t( 'maintenance_event_links' );

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['site_id'] ) ) {
			$where[]  = 'm.site_id = %d';
			$params[] = (int) $args['site_id'];
		}
		if ( ! empty( $args['client_id'] ) ) {
			$where[]  = 's.client_id = %d';
			$params[] = (int) $args['client_id'];
		}
		if ( ! empty( $args['category'] ) ) {
			$where[]  = 'm.category = %s';
			$params[] = $args['category'];
		}
		if ( ! empty( $args['performer'] ) ) {
			$where[]  = 'm.performer_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['performer'] ) . '%';
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(m.description LIKE %s OR m.notes LIKE %s OR s.name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'm.performed_at >= %s';
			$params[] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'm.performed_at <= %s';
			$params[] = $args['to'];
		}

		$per_page  = max( 1, min( 5000, (int) ( $args['per_page'] ?? 25 ) ) );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$from      = "$mt m JOIN $st s ON s.id = m.site_id LEFT JOIN $ct c ON c.id = s.client_id WHERE " . implode( ' AND ', $where );

		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $from", $params ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM $from" ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, s.name AS site_name, s.url AS site_url, c.name AS client_name,
					(SELECT COUNT(*) FROM $lt l WHERE l.maintenance_id = m.id) AS linked_events
				FROM $from ORDER BY m.performed_at DESC, m.id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			)
		);
		$items = array();
		foreach ( WPCM_DB::format_rows( $rows ) as $row ) {
			$row['linked_events']    = (int) $row['linked_events'];
			$row['duration_minutes'] = null === $row['duration_minutes'] ? null : (int) $row['duration_minutes'];
			$items[]                 = $row;
		}
		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	public static function list_maintenance( WP_REST_Request $r ) {
		list( $per_page, $page ) = self::paging( $r );
		return self::query_maintenance(
			array(
				'site_id'   => (int) $r['site_id'],
				'client_id' => (int) $r['client_id'],
				'category'  => sanitize_text_field( (string) $r['category'] ),
				'performer' => sanitize_text_field( (string) $r['performer'] ),
				'search'    => sanitize_text_field( (string) $r['search'] ),
				'from'      => WPCM_DB::to_mysql( $r['from'] ),
				'to'        => $r['to'] ? WPCM_DB::to_mysql( $r['to'] . ' 23:59:59' ) : null,
				'per_page'  => $per_page,
				'page'      => $page,
			)
		);
	}

	private static function maintenance_record( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPCM_DB::t( 'maintenance_logs' ) . ' WHERE id = %d', (int) $id ) );
		if ( ! $row ) {
			return null;
		}
		$record                     = WPCM_DB::format_row( $row );
		$record['duration_minutes'] = null === $record['duration_minutes'] ? null : (int) $record['duration_minutes'];
		$record['event_ids']        = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT event_id FROM ' . WPCM_DB::t( 'maintenance_event_links' ) . ' WHERE maintenance_id = %d', (int) $id ) ) );

		$events = array();
		if ( $record['event_ids'] ) {
			$et     = WPCM_DB::t( 'component_events' );
			$ct     = WPCM_DB::t( 'components' );
			$events = WPCM_DB::format_rows(
				$wpdb->get_results(
					"SELECT e.*, c.type AS component_type, c.name AS component_name FROM $et e JOIN $ct c ON c.id = e.component_id
					WHERE e.id IN (" . implode( ',', $record['event_ids'] ) . ') ORDER BY e.detected_at DESC'
				)
			);
		}
		$record['events'] = $events;
		return $record;
	}

	public static function get_maintenance( WP_REST_Request $r ) {
		$record = self::maintenance_record( (int) $r['id'] );
		return $record ? $record : self::not_found();
	}

	private static function sanitize_maintenance( array $in, $partial = false ) {
		$out = array();

		if ( ! $partial || isset( $in['site_id'] ) ) {
			$out['site_id'] = (int) ( $in['site_id'] ?? 0 );
			if ( ! WPCM_Sites::get( $out['site_id'] ) ) {
				return new WP_Error( 'wpc_invalid', 'Choose a valid site.', array( 'status' => 400 ) );
			}
		}
		if ( ! $partial || isset( $in['performed_at'] ) ) {
			$out['performed_at'] = WPCM_DB::to_mysql( $in['performed_at'] ?? '' );
			if ( ! $out['performed_at'] ) {
				return new WP_Error( 'wpc_invalid', 'Performed date is required.', array( 'status' => 400 ) );
			}
		}
		if ( ! $partial || isset( $in['performer_user_id'] ) || isset( $in['performer_name'] ) ) {
			$user_id = (int) ( $in['performer_user_id'] ?? 0 );
			$user    = $user_id ? get_userdata( $user_id ) : null;
			$name    = sanitize_text_field( $in['performer_name'] ?? '' );
			if ( $user ) {
				$out['performer_user_id'] = $user->ID;
				$out['performer_name']    = $name ? $name : $user->display_name;
			} else {
				$out['performer_user_id'] = null;
				$out['performer_name']    = $name;
			}
			if ( '' === $out['performer_name'] ) {
				return new WP_Error( 'wpc_invalid', 'Performer is required.', array( 'status' => 400 ) );
			}
		}
		if ( ! $partial || isset( $in['category'] ) ) {
			$out['category'] = sanitize_text_field( $in['category'] ?? '' );
			if ( '' === $out['category'] ) {
				return new WP_Error( 'wpc_invalid', 'Category is required.', array( 'status' => 400 ) );
			}
		}
		if ( ! $partial || isset( $in['description'] ) ) {
			$out['description'] = sanitize_textarea_field( $in['description'] ?? '' );
			if ( '' === trim( $out['description'] ) ) {
				return new WP_Error( 'wpc_invalid', 'Description is required.', array( 'status' => 400 ) );
			}
		}
		if ( array_key_exists( 'duration_minutes', $in ) ) {
			$out['duration_minutes'] = ( '' === $in['duration_minutes'] || null === $in['duration_minutes'] ) ? null : max( 0, (int) $in['duration_minutes'] );
		}
		if ( array_key_exists( 'notes', $in ) ) {
			$out['notes'] = sanitize_textarea_field( (string) $in['notes'] );
		}
		return $out;
	}

	/** Replace a record's event links; only events from the record's own site are accepted. */
	private static function save_links( $maintenance_id, $site_id, $event_ids ) {
		global $wpdb;
		$lt = WPCM_DB::t( 'maintenance_event_links' );
		$wpdb->delete( $lt, array( 'maintenance_id' => (int) $maintenance_id ) );

		$event_ids = array_filter( array_map( 'intval', (array) $event_ids ) );
		if ( ! $event_ids ) {
			return;
		}
		$valid = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . WPCM_DB::t( 'component_events' ) . ' WHERE site_id = %d AND id IN (' . implode( ',', $event_ids ) . ')',
				(int) $site_id
			)
		);
		foreach ( $valid as $event_id ) {
			$wpdb->insert( $lt, array( 'maintenance_id' => (int) $maintenance_id, 'event_id' => (int) $event_id ) );
		}
	}

	public static function create_maintenance( WP_REST_Request $r ) {
		global $wpdb;
		$in   = (array) $r->get_json_params();
		$data = self::sanitize_maintenance( $in );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$now = WPCM_DB::now();
		$wpdb->insert(
			WPCM_DB::t( 'maintenance_logs' ),
			array_merge(
				array( 'duration_minutes' => null, 'notes' => '' ),
				$data,
				array(
					'created_by' => get_current_user_id(),
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);
		$id = (int) $wpdb->insert_id;
		self::save_links( $id, $data['site_id'], $in['event_ids'] ?? array() );
		WPCM_Audit::log( 'maintenance.created', 'maintenance', $id, $data['site_id'], array( 'category' => $data['category'], 'performer' => $data['performer_name'] ) );
		return self::maintenance_record( $id );
	}

	public static function update_maintenance( WP_REST_Request $r ) {
		global $wpdb;
		$existing = self::maintenance_record( (int) $r['id'] );
		if ( ! $existing ) {
			return self::not_found();
		}
		$in   = (array) $r->get_json_params();
		$data = self::sanitize_maintenance( $in, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['updated_at'] = WPCM_DB::now();
		$wpdb->update( WPCM_DB::t( 'maintenance_logs' ), $data, array( 'id' => $existing['id'] ) );

		$site_id = $data['site_id'] ?? $existing['site_id'];
		if ( array_key_exists( 'event_ids', $in ) || $site_id !== $existing['site_id'] ) {
			self::save_links( $existing['id'], $site_id, $in['event_ids'] ?? array() );
		}
		WPCM_Audit::log( 'maintenance.updated', 'maintenance', $existing['id'], $site_id, array( 'fields' => array_keys( $data ) ) );
		return self::maintenance_record( $existing['id'] );
	}

	public static function delete_maintenance( WP_REST_Request $r ) {
		global $wpdb;
		$existing = self::maintenance_record( (int) $r['id'] );
		if ( ! $existing ) {
			return self::not_found();
		}
		$wpdb->delete( WPCM_DB::t( 'maintenance_event_links' ), array( 'maintenance_id' => $existing['id'] ) );
		$wpdb->delete( WPCM_DB::t( 'maintenance_logs' ), array( 'id' => $existing['id'] ) );
		WPCM_Audit::log(
			'maintenance.deleted',
			'maintenance',
			$existing['id'],
			$existing['site_id'],
			array(
				'performed_at' => $existing['performed_at'],
				'performer'    => $existing['performer_name'],
				'description'  => wp_trim_words( $existing['description'], 20 ),
			)
		);
		return array( 'deleted' => true );
	}
}
