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

	public static function enqueue_urls( array $urls ): int {
		if ( ! ApexPress_Settings::get( 'page_cache' ) || ! ApexPress_Settings::get( 'cache_preload' ) ) {
			return 0;
		}
		$valid = array();
		foreach ( $urls as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( $url && ApexPress_Security::safe_local_url( $url ) ) {
				$valid[] = $url;
			}
		}
		$added = self::update_queue( static function ( array &$queue ) use ( $valid ): array {
			$new = array_slice( array_values( array_diff( array_unique( $valid ), $queue ) ), 0, max( 0, 5000 - count( $queue ) ) );
			$queue = array_merge( $queue, $new );
			return $new;
		} );
		return count( $added );
	}

	// Writers and cron workers share one option. Hold the lock only for the local update.
	private static function update_queue( callable $change ): array {
		global $wpdb;
		$key = hash( 'sha256', DB_NAME . '|' . $wpdb->options . '|' . self::QUEUE );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $key ) ) ) {
			return array();
		}
		try {
			wp_cache_delete( self::QUEUE, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$stored = get_option( self::QUEUE, array() );
			$queue = is_array( $stored ) ? $stored : array();
			$changed = $change( $queue );
			return $changed && update_option( self::QUEUE, $queue, false ) ? $changed : array();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	public static function seed_site(): int {
		if ( ! ApexPress_Settings::get( 'page_cache' ) || ! ApexPress_Settings::get( 'cache_preload' ) ) {
			return 0;
		}
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
		return self::enqueue_urls( $urls );
	}

	public function process(): void {
		if ( ! ApexPress_Settings::get( 'page_cache' ) || ! ApexPress_Settings::get( 'cache_preload' ) ) {
			return;
		}
		$batch = min( 20, max( 1, (int) ApexPress_Settings::get( 'cache_preload_batch', 5 ) ) );
		$take = self::update_queue( static function ( array &$queue ) use ( $batch ): array {
			return array_splice( $queue, 0, $batch );
		} );

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
