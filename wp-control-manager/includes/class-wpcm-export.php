<?php

defined( 'ABSPATH' ) || exit;

class WPCM_Export {

	public static function url( $type, $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'action'   => 'wpcontrol_export',
					'type'     => $type,
					'_wpnonce' => wp_create_nonce( 'wpcontrol_export' ),
				),
				$args
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function handle() {
		if ( ! current_user_can( WPCM_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to export WP Control data.', 'wp-control-manager' ), 403 );
		}
		check_admin_referer( 'wpcontrol_export' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$type   = sanitize_key( $_GET['type'] ?? '' );
		$filter = array(
			'client_id' => (int) ( $_GET['client_id'] ?? 0 ),
			'site_id'   => (int) ( $_GET['site_id'] ?? 0 ),
			'from'      => WPCM_DB::to_mysql( sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) ) ),
			'to'        => ! empty( $_GET['to'] ) ? WPCM_DB::to_mysql( sanitize_text_field( wp_unslash( $_GET['to'] ) ) . ' 23:59:59' ) : null,
		);
		// phpcs:enable

		switch ( $type ) {
			case 'sites':
				$rows = self::sites( $filter );
				break;
			case 'maintenance':
				$rows = self::maintenance( $filter );
				break;
			case 'events':
				$rows = self::events( $filter );
				break;
			default:
				wp_die( esc_html__( 'Unknown export type.', 'wp-control-manager' ), 400 );
		}

		WPCM_Audit::log( 'export.' . $type, 'export', null, $filter['site_id'] ? $filter['site_id'] : null, array_filter( $filter ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wpcontrol-' . $type . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel opens it correctly.
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'cell' ), $row ) );
		}
		fclose( $out );
		exit;
	}

	/** Neutralise spreadsheet formula injection. */
	private static function cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	private static function date( $iso ) {
		return $iso ? get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $iso ), 'Y-m-d H:i' ) : '';
	}

	private static function sites( $filter ) {
		$result = WPCM_Sites::query( array( 'client_id' => $filter['client_id'], 'per_page' => 500 ) );
		$rows   = array( array( 'Site', 'URL', 'Client', 'Status', 'WordPress', 'PHP', 'Pending updates', 'Open issues', 'Critical issues', 'Last backup', 'Last sync', 'Last maintenance', 'Last maintenance by' ) );
		foreach ( $result['items'] as $s ) {
			$rows[] = array(
				$s['name'],
				$s['url'],
				$s['client_name'],
				$s['status'],
				$s['wp_version'],
				$s['php_version'],
				$s['pending_updates'],
				$s['open_issues'],
				$s['critical_issues'],
				self::date( $s['last_backup_at'] ),
				self::date( $s['last_sync_at'] ),
				self::date( $s['last_maintenance_at'] ),
				$s['last_maintenance_by'],
			);
		}
		return $rows;
	}

	private static function maintenance( $filter ) {
		$result = WPCM_Rest_Admin::query_maintenance( array_merge( $filter, array( 'per_page' => 5000 ) ) );
		$rows   = array( array( 'Performed', 'Site', 'Client', 'Performer', 'Category', 'Description', 'Duration (min)', 'Notes', 'Linked changes' ) );
		foreach ( $result['items'] as $m ) {
			$rows[] = array(
				self::date( $m['performed_at'] ),
				$m['site_name'],
				$m['client_name'],
				$m['performer_name'],
				$m['category'],
				$m['description'],
				$m['duration_minutes'],
				$m['notes'],
				$m['linked_events'],
			);
		}
		return $rows;
	}

	private static function events( $filter ) {
		$result = WPCM_Rest_Admin::query_events( array_merge( $filter, array( 'per_page' => 5000 ) ) );
		$rows   = array( array( 'Detected', 'Site', 'Type', 'Component', 'Change', 'From', 'To', 'Documented by' ) );
		foreach ( $result['items'] as $e ) {
			$rows[] = array(
				self::date( $e['detected_at'] ),
				$e['site_name'],
				$e['component_type'],
				$e['component_name'],
				$e['event_type'],
				$e['old_version'],
				$e['new_version'],
				$e['maintenance'] ? implode( ', ', array_unique( wp_list_pluck( $e['maintenance'], 'performer_name' ) ) ) : 'Unattributed',
			);
		}
		return $rows;
	}
}
