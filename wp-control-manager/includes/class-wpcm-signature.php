<?php
/**
 * HMAC-SHA256 request signing shared by Manager and Agent.
 *
 * String to sign:  METHOD \n /rest/route \n timestamp \n nonce \n sha256(body)
 * Headers:         X-WPC-Site, X-WPC-Timestamp, X-WPC-Nonce, X-WPC-Signature
 *
 * Requests older than WINDOW seconds are rejected and each nonce is accepted only once.
 */

defined( 'ABSPATH' ) || exit;

class WPCM_Signature {

	const WINDOW = 300;

	public static function string_to_sign( $method, $route, $timestamp, $nonce, $body ) {
		return strtoupper( $method ) . "\n" . $route . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', (string) $body );
	}

	public static function headers( $secret, $site_id, $method, $route, $body = '' ) {
		$ts    = (string) time();
		$nonce = bin2hex( random_bytes( 16 ) );
		return array(
			'X-WPC-Site'      => (string) $site_id,
			'X-WPC-Timestamp' => $ts,
			'X-WPC-Nonce'     => $nonce,
			'X-WPC-Signature' => hash_hmac( 'sha256', self::string_to_sign( $method, $route, $ts, $nonce, $body ), $secret ),
		);
	}

	public static function verify( WP_REST_Request $request, $secret ) {
		$ts    = (string) $request->get_header( 'x_wpc_timestamp' );
		$nonce = (string) $request->get_header( 'x_wpc_nonce' );
		$sig   = (string) $request->get_header( 'x_wpc_signature' );
		$site  = (string) $request->get_header( 'x_wpc_site' );

		if ( '' === $ts || '' === $nonce || '' === $sig || ! ctype_digit( $ts ) || ! preg_match( '/^[a-f0-9]{16,64}$/', $nonce ) ) {
			return new WP_Error( 'wpc_unsigned', 'Missing or malformed signature headers.', array( 'status' => 401 ) );
		}
		if ( abs( time() - (int) $ts ) > self::WINDOW ) {
			return new WP_Error( 'wpc_expired', 'Request timestamp outside the allowed window. Check server clocks.', array( 'status' => 401 ) );
		}

		$expected = hash_hmac( 'sha256', self::string_to_sign( $request->get_method(), $request->get_route(), $ts, $nonce, $request->get_body() ), $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'wpc_bad_signature', 'Invalid request signature.', array( 'status' => 401 ) );
		}

		$nonce_key = 'wpc_n_' . md5( $site . '|' . $nonce );
		if ( get_transient( $nonce_key ) ) {
			return new WP_Error( 'wpc_replay', 'Request nonce already used.', array( 'status' => 401 ) );
		}
		set_transient( $nonce_key, 1, self::WINDOW * 2 );

		return true;
	}
}
