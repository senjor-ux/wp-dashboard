<?php

defined( 'ABSPATH' ) || exit;

class WPCM_DB {

	const TABLES = array(
		'clients',
		'sites',
		'agents',
		'components',
		'update_offers',
		'component_events',
		'maintenance_logs',
		'maintenance_event_links',
		'site_checks',
		'backups',
		'sync_runs',
		'audit_log',
		'notes',
	);

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wpc_' . $name;
	}

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function ago( $seconds ) {
		return gmdate( 'Y-m-d H:i:s', time() - (int) $seconds );
	}

	/** Convert a stored UTC datetime to ISO 8601 for the API (null-safe). */
	public static function iso( $mysql ) {
		if ( empty( $mysql ) || '0000-00-00 00:00:00' === $mysql ) {
			return null;
		}
		return str_replace( ' ', 'T', $mysql ) . 'Z';
	}

	/** Parse an ISO / date string from the API into a UTC MySQL datetime. */
	public static function to_mysql( $value ) {
		if ( empty( $value ) ) {
			return null;
		}
		if ( is_numeric( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}
		$ts = strtotime( (string) $value );
		return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
	}

	/** Convert all *_at keys of a row to ISO and cast numeric ids. */
	public static function format_row( $row ) {
		if ( ! $row ) {
			return $row;
		}
		$row = (array) $row;
		foreach ( $row as $key => $value ) {
			if ( '_at' === substr( $key, -3 ) ) {
				$row[ $key ] = self::iso( $value );
			} elseif ( ( 'id' === $key || '_id' === substr( $key, -3 ) ) && is_numeric( $value ) && 'external_id' !== $key ) {
				$row[ $key ] = (int) $value;
			}
		}
		return $row;
	}

	public static function format_rows( $rows ) {
		return array_map( array( __CLASS__, 'format_row' ), (array) $rows );
	}
}
