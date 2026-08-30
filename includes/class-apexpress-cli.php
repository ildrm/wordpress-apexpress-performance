<?php
/**
 * WP-CLI integration.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_CLI {
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'apexpress', self::class );
	}

	/**
	 * Show an environment/performance audit.
	 *
	 * ## EXAMPLES
	 *
	 *     wp apexpress status
	 */
	public function status(): void {
		WP_CLI::print_value( ApexPress_Audit::run(), array( 'format' => 'json' ) );
	}

	/** Purge the ApexPress page cache. */
	public function purge(): void {
		( new ApexPress_Cache() )->purge_all();
		WP_CLI::success( 'ApexPress page cache purged.' );
	}

	/** Queue recent public URLs for cache warming. */
	public function warm(): void {
		$count = ApexPress_Cache_Warmer::seed_site();
		WP_CLI::success( sprintf( 'Queued %d URLs.', $count ) );
	}

	/** Queue media-library images for optimization. */
	public function images(): void {
		$count = ApexPress_Image_Optimizer::enqueue_all();
		WP_CLI::success( sprintf( 'Queued %d images.', $count ) );
	}

	/** Print database optimization opportunities. */
	public function database(): void {
		WP_CLI::print_value( ApexPress_Database::analyze(), array( 'format' => 'json' ) );
	}
}
