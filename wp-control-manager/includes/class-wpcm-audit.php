<?php

defined( 'ABSPATH' ) || exit;

class WPCM_Audit {

	/**
	 * @param string     $action      e.g. 'site.created', 'maintenance.updated'.
	 * @param string     $object_type e.g. 'site'.
	 * @param int|null   $object_id
	 * @param int|null   $site_id
	 * @param array      $details     Free-form context, stored as JSON.
	 * @param string|null $actor      Overrides the current user's display name (e.g. 'Agent').
	 */
	public static function log( $action, $object_type = '', $object_id = null, $site_id = null, $details = array(), $actor = null ) {
		global $wpdb;

		$user_id = get_current_user_id();
		if ( null === $actor ) {
			$user  = $user_id ? get_userdata( $user_id ) : null;
			$actor = $user ? $user->display_name : 'System';
		}

		$wpdb->insert(
			WPCM_DB::t( 'audit_log' ),
			array(
				'user_id'     => $user_id ? $user_id : null,
				'actor'       => $actor,
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id ? (int) $object_id : null,
				'site_id'     => $site_id ? (int) $site_id : null,
				'details'     => $details ? wp_json_encode( $details ) : null,
				'ip'          => self::ip(),
				'created_at'  => WPCM_DB::now(),
			)
		);
	}

	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 64 );
	}

	public static function query( $args ) {
		global $wpdb;
		$t       = WPCM_DB::t( 'audit_log' );
		$s       = WPCM_DB::t( 'sites' );
		$where   = array( '1=1' );
		$params  = array();

		if ( ! empty( $args['site_id'] ) ) {
			$where[]  = 'a.site_id = %d';
			$params[] = (int) $args['site_id'];
		}
		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'a.action LIKE %s';
			$params[] = $wpdb->esc_like( $args['action'] ) . '%';
		}

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM $t a WHERE $where_sql";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$sql  = "SELECT a.*, s.name AS site_name FROM $t a LEFT JOIN $s s ON s.id = a.site_id WHERE $where_sql ORDER BY a.created_at DESC, a.id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ) );

		$items = array();
		foreach ( WPCM_DB::format_rows( $rows ) as $row ) {
			$row['details'] = $row['details'] ? json_decode( $row['details'], true ) : null;
			unset( $row['ip'] );
			$items[] = $row;
		}

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}
}
