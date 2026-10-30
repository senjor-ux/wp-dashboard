<?php

defined( 'ABSPATH' ) || exit;

class WPCA_Connection {

	const OPTION    = 'wpca_connection';
	const LAST_SYNC = 'wpca_last_sync';

	public static function activate() {
		if ( ! wp_next_scheduled( 'wpca_sync' ) ) {
			wp_schedule_event( time() + 120, 'hourly', 'wpca_sync' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'wpca_sync' );
	}

	public static function schedule_soon() {
		if ( self::is_paired() && ! wp_next_scheduled( 'wpca_sync', array( 'soon' ) ) ) {
			wp_schedule_single_event( time() + 90, 'wpca_sync', array( 'soon' ) );
		}
	}

	public static function cron_push() {
		if ( self::is_paired() ) {
			self::push();
		}
	}

	public static function get() {
		$c = get_option( self::OPTION, array() );
		return is_array( $c ) ? $c : array();
	}

	public static function is_paired() {
		$c = self::get();
		return ! empty( $c['paired_at'] ) && ! empty( $c['secret'] );
	}

	public static function allow_insecure() {
		return defined( 'WPCA_ALLOW_INSECURE' ) && WPCA_ALLOW_INSECURE;
	}

	private static function crypto_key() {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|wpcontrol-agent', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function encrypt( $plain ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::crypto_key() ) );
	}

	private static function decrypt( $stored ) {
		if ( 0 !== strpos( (string) $stored, 'v1:' ) ) {
			return false;
		}
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		return sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::crypto_key()
		);
	}

	public static function secret() {
		$c = self::get();
		return empty( $c['secret'] ) ? false : self::decrypt( $c['secret'] );
	}

	public static function disconnect() {
		delete_option( self::OPTION );
		delete_option( self::LAST_SYNC );
	}

	public static function parse_token( $token ) {
		$token = trim( (string) $token );
		if ( 0 !== strpos( $token, 'wpc1.' ) ) {
			return new WP_Error( 'wpca_token', __( 'This is not a WP Control pairing token.', 'wp-control-agent' ) );
		}
		$b64  = strtr( substr( $token, 5 ), '-_', '+/' );
		$json = base64_decode( $b64 . str_repeat( '=', ( 4 - strlen( $b64 ) % 4 ) % 4 ), true );
		$data = $json ? json_decode( $json, true ) : null;

		if ( ! is_array( $data ) || empty( $data['api'] ) || empty( $data['site'] ) || empty( $data['key'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $data['key'] ) ) {
			return new WP_Error( 'wpca_token', __( 'The pairing token is incomplete or corrupted. Copy it again from the Manager.', 'wp-control-agent' ) );
		}
		$api = esc_url_raw( $data['api'], array( 'http', 'https' ) );
		if ( ! $api ) {
			return new WP_Error( 'wpca_token', __( 'The pairing token contains an invalid Manager URL.', 'wp-control-agent' ) );
		}
		if ( 0 !== strpos( $api, 'https://' ) && ! self::allow_insecure() ) {
			return new WP_Error( 'wpca_https', __( 'The Manager must use HTTPS. (For local development define WPCA_ALLOW_INSECURE in wp-config.php.)', 'wp-control-agent' ) );
		}
		return array(
			'api'  => $api,
			'site' => (int) $data['site'],
			'key'  => $data['key'],
		);
	}

	public static function pair( $token ) {
		$data = self::parse_token( $token );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$body     = wp_json_encode(
			array(
				'site_url'      => home_url(),
				'agent_api'     => get_rest_url(),
				'agent_version' => WPCA_VERSION,
				'wp_version'    => get_bloginfo( 'version' ),
				'php_version'   => PHP_VERSION,
			)
		);
		$response = self::request( $data['api'], $data['site'], $data['key'], '/wpcontrol/v1/agent/pair', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		update_option(
			self::OPTION,
			array(
				'api'          => $data['api'],
				'site_id'      => $data['site'],
				'secret'       => self::encrypt( $data['key'] ),
				'paired_at'    => time(),
				'manager_host' => wp_parse_url( $data['api'], PHP_URL_HOST ),
				'site_name'    => isset( $response['site_name'] ) ? sanitize_text_field( $response['site_name'] ) : '',
			),
			false
		);

		self::activate();
		$push = self::push();
		return is_wp_error( $push ) ? $push : true;
	}

	public static function push() {
		$c      = self::get();
		$secret = self::secret();
		if ( empty( $c['api'] ) || ! $secret ) {
			return new WP_Error( 'wpca_not_paired', __( 'This site is not connected to a Manager.', 'wp-control-agent' ) );
		}

		$snapshot = WPCA_Collector::snapshot( false );
		$response = self::request( $c['api'], $c['site_id'], $secret, '/wpcontrol/v1/agent/sync', wp_json_encode( $snapshot ) );
		self::record_sync( $response, 'push' );
		return $response;
	}

	public static function record_sync( $result, $direction ) {
		update_option(
			self::LAST_SYNC,
			array(
				'time'      => time(),
				'ok'        => ! is_wp_error( $result ),
				'direction' => $direction,
				'message'   => is_wp_error( $result ) ? $result->get_error_message() : ( isset( $result['message'] ) ? $result['message'] : 'OK' ),
			),
			false
		);
	}

	private static function request( $api, $site_id, $secret, $route, $body ) {
		$url      = $api . ltrim( $route, '/' );
		$response = wp_remote_post(
			$url,
			array(
				'timeout'            => 30,
				'body'               => $body,
				'headers'            => array_merge(
					array( 'Content-Type' => 'application/json' ),
					WPCA_Signature::headers( $secret, $site_id, 'POST', $route, $body )
				),
				'reject_unsafe_urls' => ! self::allow_insecure(),
				'sslverify'          => ! self::allow_insecure(),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new WP_Error( 'wpca_http', sprintf( __( 'Could not reach the Manager: %s', 'wp-control-agent' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : sprintf( 'HTTP %d', $code );
			/* translators: %s: error message */
			return new WP_Error( 'wpca_rejected', sprintf( __( 'Manager rejected the request: %s', 'wp-control-agent' ), $msg ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
