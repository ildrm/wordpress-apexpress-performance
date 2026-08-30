<?php
/**
 * Site performance capability audit.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Audit {
	public static function run(): array {
		global $wpdb;
		$checks = array();
		$checks[] = self::check( 'php', __( 'Modern PHP runtime', 'apexpress-performance' ), PHP_VERSION_ID >= 80300, PHP_VERSION, 10, PHP_VERSION_ID >= 80100 );
		$checks[] = self::check( 'opcache', __( 'PHP OPcache', 'apexpress-performance' ), function_exists( 'opcache_get_status' ) && (bool) ini_get( 'opcache.enable' ), ini_get( 'opcache.enable' ) ? 'Enabled' : 'Disabled', 10 );
		$checks[] = self::check( 'object_cache', __( 'Persistent object cache', 'apexpress-performance' ), wp_using_ext_object_cache(), wp_using_ext_object_cache() ? 'Enabled' : 'Not detected', 12 );
		$checks[] = self::check( 'page_cache', __( 'ApexPress page cache', 'apexpress-performance' ), (bool) ApexPress_Settings::get( 'page_cache' ), ApexPress_Settings::get( 'page_cache' ) ? 'Enabled' : 'Disabled', 12 );
		$dropin   = ApexPress_Cache::dropin_status();
		$checks[] = self::check( 'early_cache', __( 'Early cache drop-in', 'apexpress-performance' ), ! empty( $dropin['owned'] ) && ! empty( $dropin['wp_cache'] ), ! empty( $dropin['owned'] ) ? ( ! empty( $dropin['wp_cache'] ) ? 'Active' : 'WP_CACHE disabled' ) : 'Not installed', 8 );
		$checks[] = self::check( 'https', __( 'HTTPS', 'apexpress-performance' ), str_starts_with( home_url(), 'https://' ), home_url(), 8 );
		$checks[] = self::check( 'db', __( 'Database server', 'apexpress-performance' ), true, $wpdb->db_version(), 5 );
		$checks[] = self::check( 'cron', __( 'WordPress cron', 'apexpress-performance' ), ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ), defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Disabled: verify system cron' : 'WP-Cron enabled', 5, true );
		$checks[] = self::check( 'images', __( 'Modern image format generation', 'apexpress-performance' ), wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ), wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ? 'WebP + AVIF' : ( wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'WebP' : 'Unavailable' ), 10 );
		$checks[] = self::check( 'woocommerce', __( 'WooCommerce cache safeguards', 'apexpress-performance' ), ! ApexPress_WooCommerce::available() || (bool) ApexPress_Settings::get( 'woocommerce_safe_mode' ), ApexPress_WooCommerce::available() ? ( ApexPress_Settings::get( 'woocommerce_safe_mode' ) ? 'Protected' : 'Disabled' ) : 'WooCommerce not active', 10 );
		$checks[] = self::check( 'rum', __( 'Real User Monitoring', 'apexpress-performance' ), (bool) ApexPress_Settings::get( 'rum_enabled' ), ApexPress_Settings::get( 'rum_enabled' ) ? 'Enabled' : 'Disabled', 10 );

		$score = 0;
		$max   = 0;
		foreach ( $checks as $check ) {
			$max += $check['weight'];
			if ( $check['pass'] ) {
				$score += $check['weight'];
			} elseif ( $check['partial'] ) {
				$score += (int) round( $check['weight'] * 0.5 );
			}
		}
		return array(
			'score'       => $max ? (int) round( 100 * $score / $max ) : 0,
			'checks'      => $checks,
			'server'      => ApexPress_Compatibility::server(),
			'wordpress'   => get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'memory'      => wp_convert_hr_to_bytes( WP_MEMORY_LIMIT ),
			'conflicts'   => ApexPress_Compatibility::conflicting_plugins(),
			'warm_queue'  => ApexPress_Cache_Warmer::queue_size(),
			'image_queue' => ApexPress_Image_Optimizer::queue_size(),
		);
	}

	private static function check( string $id, string $label, bool $pass, string $value, int $weight, bool $partial = false ): array {
		return compact( 'id', 'label', 'pass', 'value', 'weight', 'partial' );
	}
}
