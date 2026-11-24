<?php
/**
 * Run with wp eval-file on a disposable local WordPress installation.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	exit( 1 );
}

function apexpress_expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$saved = get_option( ApexPress_Settings::OPTION );
$queue = get_option( 'apexpress_warm_queue', array() );
$permalinks = get_option( 'permalink_structure' );
$post_id = 0;
$fixture_ids = array();
try {
	ApexPress_Settings::update( array(
		'page_cache' => 1, 'cache_preload' => 1, 'cache_query_strings' => 0,
		'cdn_enabled' => 1, 'cdn_url' => 'https://cdn.example.test', 'js_delay' => 1,
		'js_delay_patterns' => 'example.test', 'lazy_iframes' => 1,
	) );
	delete_option( 'apexpress_warm_queue' );
	$url = home_url( '/example/' );
	apexpress_expect( 1 === ApexPress_Cache_Warmer::enqueue_urls( array( $url, $url ) ), 'First enqueue should add one URL.' );
	apexpress_expect( 0 === ApexPress_Cache_Warmer::enqueue_urls( array( $url ) ), 'Repeated enqueue should add no URLs.' );
	$delivery = new ApexPress_Delivery();
	apexpress_expect( $delivery->rewrite_url( '/icon.svg?v=2#symbol' ) === 'https://cdn.example.test/icon.svg?v=2#symbol', 'SVG fragments and asset versions must survive rewriting.' );
	apexpress_expect( $delivery->rewrite_url( '/download' ) === '/download', 'Only approved file extensions can use the CDN.' );
	$optimizer = new ApexPress_Frontend_Optimizer();
	$src = 'https://example.test/metrics.js';
	$inline = '<script id="inline">var ready=true;</script>';
	$tag = $optimizer->optimize_script_tag( $inline . '<script src="' . $src . '"></script>', 'metrics', $src );
	apexpress_expect( str_starts_with( $tag, $inline ) && str_contains( $tag, 'data-apx-src=' ), 'Delay the external script and preserve its inline initializer.' );
	$iframe = "<iframe\nloading=\"eager\" src=\"about:blank\"></iframe>";
	apexpress_expect( $iframe === $optimizer->lazy_load_content_iframes( $iframe ), 'Explicit iframe loading must survive multiline markup.' );
	$not_markup = '<script>const example = "<iframe>";</script>';
	apexpress_expect( $not_markup === $optimizer->lazy_load_content_iframes( $not_markup ), 'Text inside a script must remain literal.' );

	update_option( '_transient_timeout_apx_test_transient_timeout_key', time() - 60, false );
	update_option( '_transient_apx_test_transient_timeout_key', 'stale', false );
	$result = ApexPress_Database::cleanup( array( 'expired_transients' ) );
	apexpress_expect( $result['ok'] && $result['deleted']['expired_transients'] > 0, 'Expired transient cleanup must create a backup and remove the pair.' );
	apexpress_expect( false === get_option( '_transient_apx_test_transient_timeout_key' ), 'Cleanup must preserve the exact transient name.' );
	$recent = wp_insert_post( array( 'post_title' => 'Editor in progress', 'post_status' => 'auto-draft' ) );
	$old = wp_insert_post( array( 'post_title' => 'Abandoned editor', 'post_status' => 'auto-draft', 'post_date' => current_datetime()->modify( '-8 days' )->format( 'Y-m-d H:i:s' ) ) );
	$fixture_ids = array( $recent, $old );
	$protect_draft = static fn( $result, $post ) => $post->ID === $old ? false : $result;
	add_filter( 'pre_delete_post', $protect_draft, 10, 2 );
	try {
		$blocked = ApexPress_Database::cleanup( array( 'auto_drafts' ) );
		apexpress_expect( ! $blocked['ok'] && ! empty( $blocked['backup'] ) && get_post( $old ), 'A refused deletion must report incomplete cleanup and keep the backup.' );
	} finally {
		remove_filter( 'pre_delete_post', $protect_draft );
	}
	$result = ApexPress_Database::cleanup( array( 'auto_drafts' ) );
	apexpress_expect( $recent && $old && $result['ok'] && get_post( $recent ) && ! get_post( $old ), 'Draft cleanup must preserve recent editor sessions and remove abandoned ones.' );
	if ( class_exists( 'WC_Product_Simple' ) ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Cleanup boundary' );
		$product_id = $product->save();
		$fixture_ids[] = $product_id;
		wp_trash_post( $product_id );
		$result = ApexPress_Database::cleanup( array( 'trash_posts' ) );
		apexpress_expect( $product_id && $result['ok'] && 'trash' === get_post_status( $product_id ), 'Generic cleanup must preserve WooCommerce records with dependent commerce data.' );
	}

	update_option( 'permalink_structure', '/%postname%/' );
	$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Cache flow check' ) );
	$path = ApexPress_Cache::path_for_key( ApexPress_Cache::cache_key_for_url( get_permalink( $post_id ) ) );
	wp_mkdir_p( dirname( $path ) );
	apexpress_expect( ApexPress_Cache::atomic_write( $path . '.html', 'original page' ), 'Cache fixture must be writable.' );
	wp_update_post( array( 'ID' => $post_id, 'post_name' => 'cache-flow-check-moved' ) );
	apexpress_expect( ! file_exists( $path . '.html' ), 'Renaming a post must invalidate the original permalink.' );
	$archive = ApexPress_Cache::path_for_key( ApexPress_Cache::cache_key_for_url( home_url( '/page/2/' ) ) );
	wp_mkdir_p( dirname( $archive ) );
	apexpress_expect( ApexPress_Cache::atomic_write( $archive . '.html', 'previously public excerpt' ), 'The archive fixture must be writable.' );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'private' ) );
	apexpress_expect( ! file_exists( $archive . '.html' ), 'Withdrawing a post must also invalidate archives that could contain its content.' );

	$hide_index = static function ( $sql ) {
		return 'SHOW INDEX FROM ' . ApexPress_RUM::table() === $sql ? $sql . " WHERE Key_name <> 'aggregate_key'" : $sql;
	};
	add_filter( 'query', $hide_index );
	try {
		ApexPress_Activator::install_site();
		apexpress_expect( false === get_option( 'apexpress_version' ) && get_transient( 'apexpress_schema_retry' ), 'An unverifiable unique index must leave the installer retryable.' );
		ApexPress_Plugin::instance()->ensure_schedules();
		apexpress_expect( ! wp_next_scheduled( 'apexpress_process_image_queue' ), 'A pending schema repair must keep workers paused.' );
	} finally {
		remove_filter( 'query', $hide_index );
		ApexPress_Activator::install_site();
	}
	apexpress_expect( APEXPRESS_VERSION === get_option( 'apexpress_version' ) && (bool) wp_next_scheduled( 'apexpress_process_image_queue' ), 'Restoring the schema must resume workers.' );
} finally {
	foreach ( array_filter( $fixture_ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}
	update_option( 'permalink_structure', $permalinks );
	ApexPress_Settings::update( $saved );
	update_option( 'apexpress_warm_queue', $queue, false );
}
WP_CLI::success( 'ApexPress integration flows passed.' );
