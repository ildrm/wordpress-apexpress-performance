<?php
/**
 * Adaptive cache warmer using a bounded WP-Cron queue.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Cache_Warmer {
	private const QUEUE = 'apexpress_warm_queue';
	private const HOOK  = 'apexpress_process_warm_queue';

	public function hooks(): void {
		add_action( self::HOOK, array( $this, 'process' ) );
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
	}

	public function cron_schedules( array $schedules ): array {
		$schedules['apexpress_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes (ApexPress)', 'apexpress-performance' ),
		);
		return $schedules;
	}

	public static function enqueue_urls( array $urls ): void {
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();
		foreach ( $urls as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( $url && ApexPress_Security::safe_local_url( $url ) ) {
				$queue[] = $url;
			}
		}
		$queue = array_slice( array_values( array_unique( $queue ) ), 0, 5000 );
		update_option( self::QUEUE, $queue, false );
	}

	public static function seed_site(): int {
		$urls = array( home_url( '/' ) );
		$posts = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ) ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		foreach ( $posts as $post_id ) {
			$url = get_permalink( $post_id );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		self::enqueue_urls( $urls );
		return count( array_unique( $urls ) );
	}

	public function process(): void {
		if ( ! ApexPress_Settings::get( 'cache_preload' ) ) {
			return;
		}
		$queue = get_option( self::QUEUE, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}
		$batch = min( 20, max( 1, (int) ApexPress_Settings::get( 'cache_preload_batch', 5 ) ) );
		$take  = array_splice( $queue, 0, $batch );
		update_option( self::QUEUE, $queue, false );

		foreach ( $take as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 12,
					'redirection' => 2,
					'user-agent'  => 'ApexPress-Cache-Warmer/' . APEXPRESS_VERSION,
					'headers'     => array( 'X-ApexPress-Warm' => '1' ),
					'sslverify'   => true,
				)
			);
			if ( is_wp_error( $response ) ) {
				ApexPress_Logger::log( 'warning', 'Cache warm request failed.', array( 'url' => $url, 'error' => $response->get_error_message() ) );
			}
		}
	}

	public static function queue_size(): int {
		$queue = get_option( self::QUEUE, array() );
		return is_array( $queue ) ? count( $queue ) : 0;
	}
}
