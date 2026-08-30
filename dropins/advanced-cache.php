<?php
/**
 * APEXPRESS_ADVANCED_CACHE
 * Early page cache drop-in for ApexPress Performance.
 */

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	return;
}

$apx_base   = rtrim( WP_CONTENT_DIR, '/\\' ) . '/cache/apexpress/';
$apx_config = $apx_base . 'config.json';
if ( ! is_readable( $apx_config ) ) {
	return;
}
$apx_cfg = json_decode( (string) file_get_contents( $apx_config ), true );
if ( ! is_array( $apx_cfg ) || empty( $apx_cfg['enabled'] ) ) {
	return;
}
$apx_method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
if ( 'GET' !== $apx_method && 'HEAD' !== $apx_method ) {
	return;
}
$apx_uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
$apx_path = (string) parse_url( $apx_uri, PHP_URL_PATH );
$apx_path = '/' . ltrim( $apx_path, '/' );
if ( empty( $apx_cfg['query_strings'] ) && str_contains( $apx_uri, '?' ) ) {
	return;
}
if ( ! empty( $apx_cfg['query_strings'] ) && str_contains( $apx_uri, '?' ) ) {
	if ( strlen( $apx_uri ) > 2048 ) {
		return;
	}
	$apx_query_data = array();
	parse_str( (string) parse_url( $apx_uri, PHP_URL_QUERY ), $apx_query_data );
	if ( count( $apx_query_data ) > 8 ) {
		return;
	}
	$apx_sensitive = array( '_wpnonce', 'nonce', 'token', 'password', 'pass', 'key', 'signature', 'preview_id', 'preview_nonce' );
	foreach ( array_keys( $apx_query_data ) as $apx_query_key ) {
		if ( in_array( strtolower( (string) $apx_query_key ), $apx_sensitive, true ) ) {
			return;
		}
	}
}
foreach ( (array) ( $apx_cfg['excludes'] ?? array() ) as $apx_pattern ) {
	$apx_pattern = trim( (string) $apx_pattern );
	if ( '' === $apx_pattern ) {
		continue;
	}
	$apx_regex = '~^' . str_replace( '\\*', '.*', preg_quote( $apx_pattern, '~' ) ) . '$~i';
	if ( preg_match( $apx_regex, $apx_path ) ) {
		return;
	}
}
foreach ( array_keys( $_COOKIE ) as $apx_cookie ) {
	if ( preg_match( '/^(wordpress_logged_in_|wp-postpass_|comment_author_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_)/i', (string) $apx_cookie ) ) {
		return;
	}
}
$apx_host_raw = (string) ( $_SERVER['HTTP_HOST'] ?? '' );
$apx_host = strtolower( (string) parse_url( 'http://' . $apx_host_raw, PHP_URL_HOST ) );
$apx_home_host = strtolower( (string) ( $apx_cfg['home_host'] ?? '' ) );
if ( '' === $apx_host || '' === $apx_home_host || ! hash_equals( $apx_home_host, $apx_host ) ) {
	return;
}
$apx_normalized = $apx_host . $apx_path;
if ( ! empty( $apx_cfg['query_strings'] ) ) {
	$apx_query = (string) parse_url( $apx_uri, PHP_URL_QUERY );
	if ( $apx_query ) {
		$apx_normalized .= '?' . $apx_query;
	}
}
if ( ! empty( $apx_cfg['mobile_variant'] ) ) {
	$apx_ua     = strtolower( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
	$apx_mobile = (bool) preg_match( '/android|iphone|ipad|ipod|mobile|opera mini|iemobile/', $apx_ua );
	$apx_normalized .= $apx_mobile ? '|mobile' : '|desktop';
}
$apx_key  = hash( 'sha256', $apx_normalized );
$apx_file = $apx_base . 'page/' . substr( $apx_key, 0, 2 ) . '/' . substr( $apx_key, 2, 2 ) . '/' . $apx_key;
$apx_html = $apx_file . '.html';
$apx_meta = $apx_file . '.json';
if ( ! is_readable( $apx_html ) || ! is_readable( $apx_meta ) ) {
	return;
}
$apx_data = json_decode( (string) file_get_contents( $apx_meta ), true );
if ( ! is_array( $apx_data ) || empty( $apx_data['expires'] ) || time() > (int) $apx_data['expires'] ) {
	return;
}
header( 'X-ApexPress-Cache: HIT; early' );
header( 'Content-Type: text/html; charset=UTF-8' );
if ( 'HEAD' !== $apx_method ) {
	readfile( $apx_html );
}
exit;
