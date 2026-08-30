<?php
/**
 * Encrypted operational backup storage.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Secure_Storage {
	private const FORMAT = 1;

	public static function dir(): string {
		return ApexPress_Cache::base_dir() . 'backups/';
	}

	public static function ensure(): void {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$webconfig = $dir . 'web.config';
		if ( ! file_exists( $webconfig ) ) {
			@file_put_contents( $webconfig, "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\nhttp_response_code(404);\nexit;\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	public static function save_named( string $name, string $plaintext ): string|false {
		self::ensure();
		$name = sanitize_file_name( $name );
		if ( '' === $name ) {
			return false;
		}
		$payload = self::encrypt( $plaintext );
		if ( false === $payload ) {
			return false;
		}
		$path = self::dir() . $name . '.apxbak';
		return false !== file_put_contents( $path, $payload, LOCK_EX ) ? $path : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function save_for_key( string $prefix, string $key, string $plaintext ): string|false {
		$name = sanitize_key( $prefix ) . '-' . hash( 'sha256', $key );
		return self::save_named( $name, $plaintext );
	}

	public static function load_for_key( string $prefix, string $key ): string|false {
		$name = sanitize_key( $prefix ) . '-' . hash( 'sha256', $key ) . '.apxbak';
		$path = self::dir() . $name;
		if ( ! is_readable( $path ) ) {
			return false;
		}
		$payload = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $payload ? false : self::decrypt( $payload );
	}

	public static function exists_for_key( string $prefix, string $key ): bool {
		$name = sanitize_key( $prefix ) . '-' . hash( 'sha256', $key ) . '.apxbak';
		return is_file( self::dir() . $name );
	}

	private static function key(): string {
		$material = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : ABSPATH );
		return hash( 'sha256', $material . '|apexpress-secure-storage', true );
	}

	private static function encrypt( string $plaintext ): string|false {
		$key = self::key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return (string) wp_json_encode(
				array(
					'v' => self::FORMAT,
					'a' => 'sodium-secretbox',
					'n' => base64_encode( $nonce ),
					'c' => base64_encode( $cipher ),
				)
			);
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false === $cipher ) {
				return false;
			}
			return (string) wp_json_encode(
				array(
					'v' => self::FORMAT,
					'a' => 'aes-256-gcm',
					'i' => base64_encode( $iv ),
					't' => base64_encode( $tag ),
					'c' => base64_encode( $cipher ),
				)
			);
		}
		return false;
	}

	private static function decrypt( string $payload ): string|false {
		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) || (int) ( $data['v'] ?? 0 ) !== self::FORMAT ) {
			return false;
		}
		$key = self::key();
		if ( 'sodium-secretbox' === ( $data['a'] ?? '' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce  = base64_decode( (string) ( $data['n'] ?? '' ), true );
			$cipher = base64_decode( (string) ( $data['c'] ?? '' ), true );
			if ( false === $nonce || false === $cipher ) {
				return false;
			}
			return sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		}
		if ( 'aes-256-gcm' === ( $data['a'] ?? '' ) && function_exists( 'openssl_decrypt' ) ) {
			$iv     = base64_decode( (string) ( $data['i'] ?? '' ), true );
			$tag    = base64_decode( (string) ( $data['t'] ?? '' ), true );
			$cipher = base64_decode( (string) ( $data['c'] ?? '' ), true );
			if ( false === $iv || false === $tag || false === $cipher ) {
				return false;
			}
			return openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		}
		return false;
	}
}
