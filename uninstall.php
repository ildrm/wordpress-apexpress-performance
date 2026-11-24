<?php
/**
 * ApexPress Performance uninstall routine.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-apexpress-cache.php';
ApexPress_Cache::remove_dropin();

function apexpress_uninstall_site(): ?bool {
	global $wpdb;
	wp_clear_scheduled_hook( 'apexpress_process_warm_queue' );
	wp_clear_scheduled_hook( 'apexpress_process_image_queue' );
	wp_clear_scheduled_hook( 'apexpress_daily_maintenance' );
	$settings = get_option( 'apexpress_settings', false );
	if ( false === $settings ) {
		return null;
	}
	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return false;
	}

	delete_option( 'apexpress_settings' );
	delete_option( 'apexpress_version' );
	delete_transient( 'apexpress_schema_retry' );
	delete_option( 'apexpress_warm_queue' );
	delete_option( 'apexpress_image_queue' );
	delete_option( 'apexpress_image_queue_schema' );
	delete_option( 'apexpress_cache_revision' );
	delete_option( 'apexpress_recent_log' );

	$table = $wpdb->prefix . 'apexpress_rum_daily';
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	$jobs = $wpdb->prefix . 'apexpress_image_jobs';
	$wpdb->query( "DROP TABLE IF EXISTS `{$jobs}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	return true;
}

if ( is_multisite() ) {
	$any_opt_in = false;
	$all_installed_opted_in = true;
	$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $sites as $site ) {
		switch_to_blog( $site );
		try {
			$decision = apexpress_uninstall_site();
			$any_opt_in = $any_opt_in || true === $decision;
			$all_installed_opted_in = $all_installed_opted_in && false !== $decision;
		} finally {
			restore_current_blog();
		}
	}
	$remove_shared_files = $any_opt_in && $all_installed_opted_in;
} else {
	$remove_shared_files = apexpress_uninstall_site();
}
if ( ! $remove_shared_files ) {
	return;
}

$cache_dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/apexpress';
if ( ! is_link( $cache_dir ) && ! is_link( dirname( $cache_dir ) ) && is_dir( $cache_dir ) ) {
	$root = realpath( WP_CONTENT_DIR );
	$real = realpath( $cache_dir );
	if ( $root && $real && str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
