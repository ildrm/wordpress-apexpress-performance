<?php
/**
 * Security helpers.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Security {
	public static function require_admin( string $nonce_action, string $nonce_field = '_apexpress_nonce' ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'apexpress-performance' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed. Refresh the page and try again.', 'apexpress-performance' ), '', array( 'response' => 403 ) );
		}
	}

	public static function current_request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return '/' . ltrim( $path, '/' );
	}

	public static function path_matches( string $path, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = trim( $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			$quoted = preg_quote( $pattern, '~' );
			$regex  = '~^' . str_replace( '\*', '.*', $quoted ) . '$~i';
			if ( preg_match( $regex, $path ) ) {
				return true;
			}
		}
		return false;
	}

	public static function safe_local_url( string $url ): bool {
		$home = wp_parse_url( home_url( '/' ) );
		$test = wp_parse_url( $url );
		if ( empty( $home['host'] ) || empty( $test['host'] ) ) {
			return false;
		}
		return strtolower( $home['host'] ) === strtolower( $test['host'] );
	}
}
