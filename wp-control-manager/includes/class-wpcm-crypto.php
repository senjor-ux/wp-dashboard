<?php
/**
 * Encrypts per-site agent secrets at rest using libsodium (bundled with WordPress via sodium_compat).
 * The key is derived from the site's AUTH salt, so a database dump alone does not reveal secrets.
 */

defined( 'ABSPATH' ) || exit;

class WPCM_Crypto {

	private static function key() {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|wpcontrol-manager', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( $plain ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	public static function decrypt( $stored ) {
		if ( 0 !== strpos( (string) $stored, 'v1:' ) ) {
			return false;
		}
		$raw = base64_decode( substr( $stored, 3 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
	}

	public static function new_secret() {
		return bin2hex( random_bytes( 32 ) );
	}
}
