<?php
/**
 * Installation and lifecycle operations.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Activator {
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( APEXPRESS_FILE ) );
			wp_die( esc_html__( 'ApexPress Performance requires PHP 8.1 or newer.', 'apexpress-performance' ) );
		}
		ApexPress_Cache::ensure_dirs();
		ApexPress_Secure_Storage::ensure();
		if ( false === get_option( ApexPress_Settings::OPTION, false ) ) {
			add_option( ApexPress_Settings::OPTION, ApexPress_Settings::defaults(), '', false );
		}
		ApexPress_Cache::write_dropin_config_static();
		self::create_tables();
		self::schedule_events();
		update_option( 'apexpress_version', APEXPRESS_VERSION, false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'apexpress_process_warm_queue' );
		wp_clear_scheduled_hook( 'apexpress_process_image_queue' );
		wp_clear_scheduled_hook( 'apexpress_daily_maintenance' );
	}

	public static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'apexpress_process_warm_queue' ) ) {
			wp_schedule_event( time() + 120, 'apexpress_five_minutes', 'apexpress_process_warm_queue' );
		}
		if ( ! wp_next_scheduled( 'apexpress_process_image_queue' ) ) {
			wp_schedule_event( time() + 180, 'hourly', 'apexpress_process_image_queue' );
		}
		if ( ! wp_next_scheduled( 'apexpress_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'apexpress_daily_maintenance' );
		}
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = ApexPress_RUM::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			day date NOT NULL,
			path_hash char(64) NOT NULL,
			path_sample varchar(250) NOT NULL DEFAULT '',
			device varchar(12) NOT NULL,
			metric varchar(12) NOT NULL,
			samples bigint unsigned NOT NULL DEFAULT 0,
			total double NOT NULL DEFAULT 0,
			minimum double NOT NULL DEFAULT 0,
			maximum double NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY aggregate_key (day,path_hash,device,metric),
			KEY metric_day (metric,day)
		) {$charset};";
		dbDelta( $sql );
	}
}
