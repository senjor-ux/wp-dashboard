<?php

defined( 'ABSPATH' ) || exit;

class WPCM_Settings {

	const OPTION = 'wpcm_settings';

	public static function defaults() {
		return array(
			'offline_after_hours'   => 3,
			'backup_stale_days'     => 2,
			'require_https'         => true,
			'maintenance_categories' => array( 'Updates', 'Backup', 'Security', 'Performance', 'Content', 'Support', 'Other' ),
			'delete_data_on_uninstall' => false,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $input ) {
		$current = self::all();

		if ( isset( $input['offline_after_hours'] ) ) {
			$current['offline_after_hours'] = max( 1, min( 168, (int) $input['offline_after_hours'] ) );
		}
		if ( isset( $input['backup_stale_days'] ) ) {
			$current['backup_stale_days'] = max( 1, min( 90, (int) $input['backup_stale_days'] ) );
		}
		if ( isset( $input['require_https'] ) ) {
			$current['require_https'] = (bool) $input['require_https'];
		}
		if ( isset( $input['delete_data_on_uninstall'] ) ) {
			$current['delete_data_on_uninstall'] = (bool) $input['delete_data_on_uninstall'];
		}
		if ( isset( $input['maintenance_categories'] ) && is_array( $input['maintenance_categories'] ) ) {
			$cats = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $input['maintenance_categories'] ) ) ) );
			if ( $cats ) {
				$current['maintenance_categories'] = array_slice( $cats, 0, 30 );
			}
		}

		update_option( self::OPTION, $current, false );
		return $current;
	}
}
