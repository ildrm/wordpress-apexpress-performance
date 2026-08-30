<?php
/**
 * Lightweight structured logger.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Logger {
	private const OPTION = 'apexpress_recent_log';
	private const LIMIT  = 100;

	public static function log( string $level, string $message, array $context = array() ): void {
		$allowed = array( 'debug', 'info', 'warning', 'error' );
		if ( ! in_array( $level, $allowed, true ) ) {
			$level = 'info';
		}

		$settings = ApexPress_Settings::get_all();
		if ( 'debug' === $level && empty( $settings['debug_logging'] ) ) {
			return;
		}

		$entry = array(
			'time'    => gmdate( 'c' ),
			'level'   => $level,
			'message' => sanitize_text_field( $message ),
			'context' => self::sanitize_context( $context ),
		);

		$log   = get_option( self::OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = $entry;
		$log   = array_slice( $log, - self::LIMIT );
		update_option( self::OPTION, $log, false );
	}

	public static function recent(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	private static function sanitize_context( array $context ): array {
		$out = array();
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( is_scalar( $value ) || null === $value ) {
				$clean = sanitize_text_field( (string) $value );
				$out[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $clean, 0, 500 ) : substr( $clean, 0, 500 );
			}
		}
		return $out;
	}
}
