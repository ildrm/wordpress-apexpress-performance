<?php
/**
 * ApexPress Performance uninstall routine.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'apexpress_settings', array() );
if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

wp_clear_scheduled_hook( 'apexpress_process_warm_queue' );
wp_clear_scheduled_hook( 'apexpress_process_image_queue' );
wp_clear_scheduled_hook( 'apexpress_daily_maintenance' );

delete_option( 'apexpress_settings' );
delete_option( 'apexpress_version' );
delete_option( 'apexpress_warm_queue' );
delete_option( 'apexpress_image_queue' );
delete_option( 'apexpress_recent_log' );

$table = $wpdb->prefix . 'apexpress_rum_daily';
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
if ( is_file( $dropin ) ) {
	$content = file_get_contents( $dropin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false !== $content && str_contains( $content, 'APEXPRESS_ADVANCED_CACHE' ) ) {
		@unlink( $dropin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}

$cache_dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/apexpress';
if ( is_dir( $cache_dir ) ) {
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
