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
	public static function activate( bool $network_wide = false ): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( APEXPRESS_FILE ) );
			wp_die( esc_html__( 'ApexPress Performance requires PHP 8.1 or newer.', 'apexpress-performance' ) );
		}
		if ( is_multisite() && $network_wide ) {
			self::for_network_sites( array( self::class, 'install_site' ) );
			return;
		}
		self::install_site();
	}

	public static function install_site(): void {
		ApexPress_Cache::ensure_dirs();
		ApexPress_Secure_Storage::ensure();
		if ( false === get_option( ApexPress_Settings::OPTION, false ) ) {
			add_option( ApexPress_Settings::OPTION, ApexPress_Settings::defaults(), '', false );
		}
		ApexPress_Cache::write_dropin_config_static();
		if ( ! self::create_tables() || ! ApexPress_Image_Queue::ensure( true ) ) {
			delete_option( 'apexpress_version' );
			self::clear_schedules();
			set_transient( 'apexpress_schema_retry', 1, MINUTE_IN_SECONDS );
			return;
		}
		delete_transient( 'apexpress_schema_retry' );
		self::schedule_events();
		update_option( 'apexpress_version', APEXPRESS_VERSION, false );
	}

	public static function deactivate( bool $network_wide = false ): void {
		ApexPress_Cache::remove_dropin();
		( new ApexPress_Cache() )->purge_all();
		if ( is_multisite() && $network_wide ) {
			self::for_network_sites( array( self::class, 'clear_schedules' ) );
			return;
		}
		self::clear_schedules();
	}

	private static function for_network_sites( callable $callback ): void {
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id() ) );
		foreach ( $sites as $site ) {
			switch_to_blog( $site );
			try {
				$callback();
			} finally {
				restore_current_blog();
			}
		}
	}

	private static function clear_schedules(): void {
		wp_clear_scheduled_hook( 'apexpress_process_warm_queue' );
		wp_clear_scheduled_hook( 'apexpress_process_image_queue' );
		wp_clear_scheduled_hook( 'apexpress_daily_maintenance' );
	}

	public static function schedule_events(): void {
		if ( ! wp_next_scheduled( 'apexpress_process_warm_queue' ) ) {
			wp_schedule_event( time() + 120, 'apexpress_five_minutes', 'apexpress_process_warm_queue' );
		}
		$image_schedule = wp_get_schedule( 'apexpress_process_image_queue' );
		if ( 'hourly' === $image_schedule ) {
			wp_clear_scheduled_hook( 'apexpress_process_image_queue' );
		}
		if ( 'apexpress_five_minutes' !== $image_schedule && ! wp_next_scheduled( 'apexpress_process_image_queue' ) ) {
			wp_schedule_event( time() + 180, 'apexpress_five_minutes', 'apexpress_process_image_queue' );
		}
		if ( ! wp_next_scheduled( 'apexpress_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'apexpress_daily_maintenance' );
		}
	}

	private static function create_tables(): bool {
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
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		return ! array_diff( array( 'id', 'day', 'path_hash', 'path_sample', 'device', 'metric', 'samples', 'total', 'minimum', 'maximum' ), $columns )
			&& self::has_index( $indexes, 'PRIMARY', array( 'id' ), true )
			&& self::has_index( $indexes, 'aggregate_key', array( 'day', 'path_hash', 'device', 'metric' ), true );
	}

	public static function has_index( array $indexes, string $name, array $columns, bool $unique ): bool {
		$actual = array();
		foreach ( $indexes as $row ) {
			if ( $name !== $row['Key_name'] ) {
				continue;
			}
			if ( $unique !== ( 0 === (int) $row['Non_unique'] ) || null !== $row['Sub_part'] ) {
				return false;
			}
			$actual[ (int) $row['Seq_in_index'] ] = $row['Column_name'];
		}
		ksort( $actual );
		return $columns === array_values( $actual );
	}
}
