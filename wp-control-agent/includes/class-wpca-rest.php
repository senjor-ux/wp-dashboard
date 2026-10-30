<?php

defined( 'ABSPATH' ) || exit;

class WPCA_Rest {

	public static function register_routes() {
		register_rest_route(
			'wpcontrol-agent/v1',
			'/snapshot',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'snapshot' ),
				'permission_callback' => array( __CLASS__, 'authenticate' ),
			)
		);
	}

	public static function authenticate( WP_REST_Request $request ) {
		if ( ! is_ssl() && ! WPCA_Connection::allow_insecure() ) {
			return new WP_Error( 'wpc_https_required', 'HTTPS required.', array( 'status' => 403 ) );
		}
		$conn   = WPCA_Connection::get();
		$secret = WPCA_Connection::secret();
		if ( ! WPCA_Connection::is_paired() || ! $secret ) {
			return new WP_Error( 'wpc_not_paired', 'This site is not paired with a Manager.', array( 'status' => 401 ) );
		}
		if ( (int) $request->get_header( 'x_wpc_site' ) !== (int) $conn['site_id'] ) {
			return new WP_Error( 'wpc_unknown_site', 'Site mismatch.', array( 'status' => 401 ) );
		}
		return WPCA_Signature::verify( $request, $secret );
	}

	public static function snapshot( WP_REST_Request $request ) {
		$snapshot = WPCA_Collector::snapshot( (bool) $request->get_param( 'refresh' ) );
		WPCA_Connection::record_sync( array( 'message' => 'Snapshot requested by Manager' ), 'pull' );
		return rest_ensure_response( $snapshot );
	}
}
