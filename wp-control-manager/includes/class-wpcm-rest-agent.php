<?php
/**
 * Endpoints called by WP Control Agents. Authenticated with per-site HMAC signatures,
 * not WordPress cookies.
 */

defined( 'ABSPATH' ) || exit;

class WPCM_Rest_Agent {

	const NS = 'wpcontrol/v1';

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/agent/pair',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pair' ),
				'permission_callback' => array( __CLASS__, 'authenticate' ),
			)
		);
		register_rest_route(
			self::NS,
			'/agent/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'sync' ),
				'permission_callback' => array( __CLASS__, 'authenticate' ),
			)
		);
	}

	public static function authenticate( WP_REST_Request $request ) {
		if ( WPCM_Settings::get( 'require_https' ) && ! is_ssl() ) {
			return new WP_Error( 'wpc_https_required', 'Agent requests must use HTTPS.', array( 'status' => 403 ) );
		}

		$site_id = (int) $request->get_header( 'x_wpc_site' );
		$agent   = $site_id ? WPCM_Sites::get_agent( $site_id ) : null;
		if ( ! $agent || 'revoked' === $agent->status ) {
			return new WP_Error( 'wpc_unknown_site', 'Unknown site.', array( 'status' => 401 ) );
		}
		// Only the pairing call is allowed before the agent has paired.
		if ( 'paired' !== $agent->status && '/' . self::NS . '/agent/pair' !== $request->get_route() ) {
			return new WP_Error( 'wpc_not_paired', 'Agent is not paired. Re-enter the pairing token.', array( 'status' => 401 ) );
		}

		$secret = WPCM_Sites::agent_secret( $agent );
		if ( ! $secret ) {
			return new WP_Error( 'wpc_secret', 'Site credentials unavailable. Regenerate the pairing token.', array( 'status' => 401 ) );
		}

		$verified = WPCM_Signature::verify( $request, $secret );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		return true;
	}

	public static function pair( WP_REST_Request $request ) {
		global $wpdb;
		$site_id = (int) $request->get_header( 'x_wpc_site' );
		$body    = $request->get_json_params();

		$agent_api = isset( $body['agent_api'] ) ? esc_url_raw( $body['agent_api'], array( 'http', 'https' ) ) : '';
		if ( ! $agent_api ) {
			return new WP_Error( 'wpc_invalid', 'agent_api is required.', array( 'status' => 400 ) );
		}
		if ( WPCM_Settings::get( 'require_https' ) && 0 !== strpos( $agent_api, 'https://' ) ) {
			return new WP_Error( 'wpc_https_required', 'The client site must use HTTPS.', array( 'status' => 400 ) );
		}

		$now = WPCM_DB::now();
		$wpdb->update(
			WPCM_DB::t( 'agents' ),
			array(
				'status'        => 'paired',
				'agent_api'     => $agent_api,
				'agent_version' => substr( sanitize_text_field( $body['agent_version'] ?? '' ), 0, 32 ),
				'paired_at'     => $now,
				'last_seen_at'  => $now,
			),
			array( 'site_id' => $site_id )
		);

		$site_url = isset( $body['site_url'] ) ? esc_url_raw( $body['site_url'] ) : '';
		if ( $site_url ) {
			$wpdb->update( WPCM_DB::t( 'sites' ), array( 'reported_url' => $site_url ), array( 'id' => $site_id ) );
		}

		WPCM_Audit::log( 'agent.paired', 'site', $site_id, $site_id, array( 'site_url' => $site_url, 'agent_version' => $body['agent_version'] ?? '' ), 'Agent' );

		$site = WPCM_Sites::get( $site_id );
		return rest_ensure_response(
			array(
				'paired'    => true,
				'site_id'   => $site_id,
				'site_name' => $site ? $site['name'] : '',
			)
		);
	}

	public static function sync( WP_REST_Request $request ) {
		$snapshot = $request->get_json_params();
		if ( ! is_array( $snapshot ) ) {
			return new WP_Error( 'wpc_invalid', 'Snapshot body must be JSON.', array( 'status' => 400 ) );
		}
		$result = WPCM_Sync::ingest( (int) $request->get_header( 'x_wpc_site' ), $snapshot, 'push' );
		return rest_ensure_response( array( 'ok' => true ) + $result );
	}
}
