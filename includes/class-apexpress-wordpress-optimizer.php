<?php
/**
 * WordPress-specific bloat and runtime tuning.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_WordPress_Optimizer {
	public function hooks(): void {
		if ( ApexPress_Settings::get( 'remove_emojis' ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		}
		if ( ApexPress_Settings::get( 'remove_embeds' ) ) {
			add_action( 'wp_footer', array( $this, 'dequeue_embed' ), 1 );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		}
		if ( ApexPress_Settings::get( 'remove_rsd' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}
		if ( ApexPress_Settings::get( 'remove_wlw' ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}
		if ( ApexPress_Settings::get( 'remove_shortlink' ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}
		if ( ApexPress_Settings::get( 'disable_dashicons_guests' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_dashicons' ), 100 );
		}
		add_filter( 'heartbeat_settings', array( $this, 'heartbeat_settings' ) );
		add_action( 'init', array( $this, 'maybe_disable_heartbeat' ), 100 );
	}

	public function dequeue_embed(): void {
		wp_dequeue_script( 'wp-embed' );
	}

	public function dequeue_dashicons(): void {
		if ( ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}

	public function heartbeat_settings( array $settings ): array {
		if ( 'reduce' === ApexPress_Settings::get( 'heartbeat_mode' ) ) {
			$settings['interval'] = (int) ApexPress_Settings::get( 'heartbeat_interval', 60 );
		}
		return $settings;
	}

	public function maybe_disable_heartbeat(): void {
		if ( 'disable' === ApexPress_Settings::get( 'heartbeat_mode' ) ) {
			wp_deregister_script( 'heartbeat' );
		}
	}
}
