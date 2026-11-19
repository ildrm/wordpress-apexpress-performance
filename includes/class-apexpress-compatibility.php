<?php
/**
 * Compatibility and conflict detection.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Compatibility {
	public static function conflicting_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$active  = array_unique( array_merge( $active, $network ) );

		$needles = array(
			'wp-rocket'        => 'WP Rocket',
			'litespeed-cache'  => 'LiteSpeed Cache',
			'w3-total-cache'   => 'W3 Total Cache',
			'wp-super-cache'   => 'WP Super Cache',
			'autoptimize'      => 'Autoptimize',
			'perfmatters'      => 'Perfmatters',
			'flying-press'     => 'FlyingPress',
			'sg-cachepress'    => 'SiteGround Optimizer',
			'breeze'           => 'Breeze',
		);

		$found = array();
		foreach ( $active as $file ) {
			foreach ( $needles as $needle => $label ) {
				if ( str_contains( strtolower( $file ), $needle ) ) {
					$found[ $file ] = $label;
			}
		}
		}
		return $found;
	}

	public static function server(): string {
		$software = strtolower( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
		if ( str_contains( $software, 'litespeed' ) ) {
			return 'LiteSpeed';
		}
		if ( str_contains( $software, 'nginx' ) ) {
			return 'NGINX';
		}
		if ( str_contains( $software, 'apache' ) ) {
			return 'Apache';
		}
		if ( str_contains( $software, 'iis' ) ) {
			return 'IIS';
		}
		return $software ? ucfirst( $software ) : 'Unknown';
	}

	public static function has_persistent_object_cache(): bool {
		return (bool) wp_using_ext_object_cache();
	}
}
