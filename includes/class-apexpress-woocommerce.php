<?php
/**
 * WooCommerce-specific safeguards and asset optimization.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_WooCommerce {
	public function hooks(): void {
		if ( ApexPress_Settings::get( 'woocommerce_unload_nonshop' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'maybe_unload_assets' ), 1000 );
		}
		add_filter( 'apexpress_cacheable_request', array( $this, 'cacheable_filter' ), 10, 2 );
	}

	public static function available(): bool {
		return class_exists( 'WooCommerce' );
	}

	public static function must_bypass_cache(): bool {
		if ( ! ApexPress_Settings::get( 'woocommerce_safe_mode' ) || ! self::available() ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}
		if ( isset( $_GET['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request eligibility check.
			return true;
		}
		$path = ApexPress_Security::current_request_path();
		if ( str_contains( $path, '/wc-api/' ) || str_contains( $path, '/wp-json/wc/' ) || str_contains( $path, '/wp-json/wc/store/' ) ) {
			return true;
		}
		return false;
	}

	public function cacheable_filter( bool $cacheable, string $path ): bool {
		return self::must_bypass_cache() ? false : $cacheable;
	}

	public function maybe_unload_assets(): void {
		if ( ! self::available() || $this->is_woocommerce_context() ) {
			return;
		}
		$styles = array( 'woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen', 'wc-blocks-style', 'wc-blocks-packages-style' );
		foreach ( $styles as $handle ) {
			wp_dequeue_style( $handle );
		}
		$scripts = array( 'wc-cart-fragments', 'woocommerce', 'wc-add-to-cart', 'wc-checkout', 'wc-add-to-cart-variation' );
		foreach ( $scripts as $handle ) {
			wp_dequeue_script( $handle );
		}
	}

	private function is_woocommerce_context(): bool {
		if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
			return true;
		}
		return ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() );
	}
}
