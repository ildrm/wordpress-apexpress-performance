<?php
/**
 * Optional Cloudflare cache-purge integration using server-side constants.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Cloudflare {
	public static function configured(): bool {
		return defined( 'APEXPRESS_CF_API_TOKEN' ) && is_string( APEXPRESS_CF_API_TOKEN ) && '' !== trim( APEXPRESS_CF_API_TOKEN )
			&& defined( 'APEXPRESS_CF_ZONE_ID' ) && is_string( APEXPRESS_CF_ZONE_ID ) && preg_match( '/^[a-f0-9]{32}$/i', APEXPRESS_CF_ZONE_ID );
	}

	public static function enabled(): bool {
		return (bool) ApexPress_Settings::get( 'cloudflare_purge', 0 ) && self::configured();
	}

	public static function purge_urls( array $urls ): array {
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
		$urls = array_values( array_filter( $urls, array( 'ApexPress_Security', 'safe_local_url' ) ) );
		if ( empty( $urls ) ) {
			return array( 'ok' => false, 'message' => __( 'No valid local URLs were supplied to Cloudflare purge.', 'apexpress-performance' ) );
		}
		return self::request( array( 'files' => array_slice( $urls, 0, 30 ) ) );
	}

	public static function purge_everything(): array {
		return self::request( array( 'purge_everything' => true ) );
	}

	private static function request( array $body ): array {
		if ( ! self::configured() ) {
			return array( 'ok' => false, 'message' => __( 'Cloudflare constants are not configured.', 'apexpress-performance' ) );
		}
		$url = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( APEXPRESS_CF_ZONE_ID ) . '/purge_cache';
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . APEXPRESS_CF_API_TOKEN,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			ApexPress_Logger::log( 'warning', 'Cloudflare purge request failed.', array( 'error' => $response->get_error_message() ) );
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ok     = $status >= 200 && $status < 300 && is_array( $data ) && ! empty( $data['success'] );
		if ( ! $ok ) {
			$message = __( 'Cloudflare rejected the purge request.', 'apexpress-performance' );
			if ( is_array( $data ) && ! empty( $data['errors'][0]['message'] ) ) {
				$message = sanitize_text_field( (string) $data['errors'][0]['message'] );
			}
			ApexPress_Logger::log( 'warning', 'Cloudflare purge rejected.', array( 'status' => $status, 'message' => $message ) );
			return array( 'ok' => false, 'message' => $message );
		}
		ApexPress_Logger::log( 'info', 'Cloudflare cache purge completed.' );
		return array( 'ok' => true, 'message' => __( 'Cloudflare cache purge completed.', 'apexpress-performance' ) );
	}
}
