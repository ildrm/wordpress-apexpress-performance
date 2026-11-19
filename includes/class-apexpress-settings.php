<?php
/**
 * Settings repository and sanitization.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Settings {
	public const OPTION = 'apexpress_settings';

	public static function defaults(): array {
		return array(
			'page_cache'                     => 0,
			'cache_ttl'                      => 3600,
			'cache_mobile_variant'           => 0,
			'cache_query_strings'            => 0,
			'cache_excludes'                 => "/wp-admin/*\n/wp-login.php\n/cart/*\n/checkout/*\n/my-account/*",
			'cache_stale_if_error'           => 0,
			'cache_browser_headers'          => 0,
			'cache_preload'                  => 0,
			'cache_preload_batch'            => 5,
			'html_minify'                    => 0,
			'css_minify'                     => 0,
			'js_minify'                      => 0,
			'js_defer'                       => 0,
			'js_async_handles'               => '',
			'js_defer_excludes'              => "jquery\njquery-core\njquery-migrate",
			'js_delay'                       => 0,
			'js_delay_patterns'              => "googletagmanager.com\ngoogle-analytics.com\nconnect.facebook.net\nhotjar.com",
			'lazy_iframes'                   => 0,
			'lazy_images'                    => 0,
			'first_image_high_priority'      => 0,
			'image_optimize_uploads'         => 0,
			'image_quality'                  => 82,
			'image_max_width'                => 2560,
			'image_generate_webp'            => 0,
			'image_generate_avif'            => 0,
			'image_backup_originals'         => 1,
			'font_display_swap'              => 0,
			'cdn_enabled'                    => 0,
			'cloudflare_purge'               => 0,
			'cdn_url'                        => '',
			'cdn_extensions'                 => 'css,js,jpg,jpeg,png,gif,webp,avif,svg,woff,woff2',
			'preconnect_origins'             => '',
			'preload_resources'              => '',
			'speculation_mode'               => 'off',
			'speculation_eagerness'          => 'auto',
			'speculation_excludes'           => "/cart/*\n/checkout/*\n/my-account/*",
			'remove_emojis'                  => 1,
			'remove_embeds'                  => 0,
			'remove_rsd'                     => 1,
			'remove_wlw'                     => 1,
			'remove_shortlink'               => 0,
			'disable_dashicons_guests'       => 0,
			'heartbeat_mode'                 => 'default',
			'heartbeat_interval'             => 60,
			'woocommerce_safe_mode'          => 1,
			'woocommerce_unload_nonshop'     => 0,
			'rum_enabled'                    => 0,
			'rum_sample_rate'                => 10,
			'rum_retention_days'             => 30,
			'admin_toolbar_metrics'          => 1,
			'debug_logging'                  => 0,
			'delete_data_on_uninstall'       => 0,
		);
	}

	public static function get_all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return wp_parse_args( $stored, self::defaults() );
	}

	public static function get( string $key, mixed $default = null ): mixed {
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}
		return self::defaults()[ $key ] ?? $default;
	}

	public static function update( array $input ): array {
		$merged    = array_replace( self::get_all(), $input );
		$sanitized = self::sanitize( $merged );
		update_option( self::OPTION, $sanitized, false );
		ApexPress_Cache::write_dropin_config_static( $sanitized );
		return $sanitized;
	}

	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$out      = $defaults;

		$booleans = array(
			'page_cache', 'cache_mobile_variant', 'cache_query_strings', 'cache_stale_if_error',
			'cache_browser_headers', 'cache_preload', 'html_minify', 'css_minify', 'js_minify',
			'js_defer', 'js_delay', 'lazy_iframes', 'lazy_images', 'first_image_high_priority',
			'image_optimize_uploads', 'image_generate_webp', 'image_generate_avif', 'image_backup_originals',
			'font_display_swap', 'cdn_enabled', 'cloudflare_purge', 'remove_emojis', 'remove_embeds', 'remove_rsd', 'remove_wlw',
			'remove_shortlink', 'disable_dashicons_guests', 'woocommerce_safe_mode',
			'woocommerce_unload_nonshop', 'rum_enabled', 'admin_toolbar_metrics', 'debug_logging',
			'delete_data_on_uninstall',
		);

		foreach ( $booleans as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$out['cache_ttl']          = min( DAY_IN_SECONDS * 7, max( 60, absint( $input['cache_ttl'] ?? $defaults['cache_ttl'] ) ) );
		$out['cache_preload_batch'] = min( 20, max( 1, absint( $input['cache_preload_batch'] ?? 5 ) ) );
		$out['image_quality']      = min( 100, max( 40, absint( $input['image_quality'] ?? 82 ) ) );
		$out['image_max_width']    = min( 7680, max( 640, absint( $input['image_max_width'] ?? 2560 ) ) );
		$out['heartbeat_interval'] = min( 120, max( 15, absint( $input['heartbeat_interval'] ?? 60 ) ) );
		$out['rum_sample_rate']    = min( 100, max( 1, absint( $input['rum_sample_rate'] ?? 10 ) ) );
		$out['rum_retention_days'] = min( 365, max( 1, absint( $input['rum_retention_days'] ?? 30 ) ) );

		$heartbeat = sanitize_key( $input['heartbeat_mode'] ?? $defaults['heartbeat_mode'] );
		$out['heartbeat_mode'] = in_array( $heartbeat, array( 'default', 'reduce', 'disable' ), true ) ? $heartbeat : $defaults['heartbeat_mode'];

		$textareas = array( 'cache_excludes', 'js_async_handles', 'js_defer_excludes', 'js_delay_patterns', 'preconnect_origins', 'preload_resources', 'speculation_excludes' );
		foreach ( $textareas as $key ) {
			$out[ $key ] = self::sanitize_lines( $input[ $key ] ?? $defaults[ $key ] );
		}

		$out['cdn_url'] = esc_url_raw( trim( (string) ( $input['cdn_url'] ?? '' ) ) );
		$out['cdn_extensions'] = sanitize_text_field( (string) ( $input['cdn_extensions'] ?? $defaults['cdn_extensions'] ) );
		$mode = sanitize_key( (string) ( $input['speculation_mode'] ?? $defaults['speculation_mode'] ) );
		$out['speculation_mode'] = in_array( $mode, array( 'off', 'auto', 'prefetch', 'prerender' ), true ) ? $mode : $defaults['speculation_mode'];
		$eagerness = sanitize_key( (string) ( $input['speculation_eagerness'] ?? 'auto' ) );
		$out['speculation_eagerness'] = in_array( $eagerness, array( 'auto', 'conservative', 'moderate', 'eager' ), true ) ? $eagerness : 'auto';

		return $out;
	}

	public static function lines( string $key ): array {
		$value = (string) self::get( $key, '' );
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $value ) ?: array() ) ) );
	}

	private static function sanitize_lines( mixed $value ): string {
		$lines = preg_split( '/\R/', (string) wp_unslash( $value ) ) ?: array();
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			if ( '' !== $line ) {
				$out[] = function_exists( 'mb_substr' ) ? mb_substr( $line, 0, 300 ) : substr( $line, 0, 300 );
			}
		}
		return implode( "\n", array_unique( $out ) );
	}
}
