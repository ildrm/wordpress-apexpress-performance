<?php
/**
 * Main application container.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Plugin {
	private static ?self $instance = null;
	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'ensure_schedules' ), 20 );

		( new ApexPress_Cache() )->hooks();
		( new ApexPress_Cache_Warmer() )->hooks();
		( new ApexPress_Frontend_Optimizer() )->hooks();
		( new ApexPress_Delivery() )->hooks();
		( new ApexPress_Image_Optimizer() )->hooks();
		( new ApexPress_WordPress_Optimizer() )->hooks();
		( new ApexPress_WooCommerce() )->hooks();
		( new ApexPress_RUM() )->hooks();
		( new ApexPress_Profiler() )->hooks();
		( new ApexPress_REST() )->hooks();
		if ( is_admin() ) {
			( new ApexPress_Admin() )->hooks();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			ApexPress_CLI::register();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'apexpress-performance', false, dirname( plugin_basename( APEXPRESS_FILE ) ) . '/languages' );
	}

	public function ensure_schedules(): void {
		ApexPress_Cache::upgrade_dropin();
		ApexPress_Activator::schedule_events();
	}
}
