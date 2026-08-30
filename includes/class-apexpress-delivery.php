<?php
/**
 * CDN rewriting, resource hints and WordPress Core speculation configuration.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Delivery {
	public function hooks(): void {
		add_filter( 'script_loader_src', array( $this, 'rewrite_url' ), 50 );
		add_filter( 'style_loader_src', array( $this, 'rewrite_url' ), 50 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'rewrite_image_attributes' ), 100 );
		add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 20, 2 );
		add_action( 'wp_head', array( $this, 'print_preloads' ), 2 );
		add_filter( 'wp_speculation_rules_configuration', array( $this, 'speculation_configuration' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'speculation_exclusions' ), 20, 2 );
	}

	public function rewrite_url( string $url ): string {
		if ( ! ApexPress_Settings::get( 'cdn_enabled' ) ) {
			return $url;
		}
		$cdn = untrailingslashit( (string) ApexPress_Settings::get( 'cdn_url', '' ) );
		if ( '' === $cdn ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( empty( $parts['path'] ) || empty( $home['host'] ) ) {
			return $url;
		}
		if ( ! empty( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( $home['host'] ) ) {
			return $url;
		}
		$ext = strtolower( pathinfo( $parts['path'], PATHINFO_EXTENSION ) );
		$allowed = array_map( 'trim', explode( ',', (string) ApexPress_Settings::get( 'cdn_extensions', 'css,js,jpg,jpeg,png,gif,webp,avif,svg,woff,woff2' ) ) );
		if ( $ext && ! in_array( $ext, $allowed, true ) ) {
			return $url;
		}
		$rewritten = $cdn . '/' . ltrim( $parts['path'], '/' );
		if ( ! empty( $parts['query'] ) ) {
			$rewritten .= '?' . $parts['query'];
		}
		return esc_url_raw( $rewritten );
	}

	public function rewrite_image_attributes( array $attr ): array {
		if ( ! ApexPress_Settings::get( 'cdn_enabled' ) ) {
			return $attr;
		}
		if ( ! empty( $attr['src'] ) ) {
			$attr['src'] = $this->rewrite_url( (string) $attr['src'] );
		}
		if ( ! empty( $attr['srcset'] ) ) {
			$parts = array_map( 'trim', explode( ',', (string) $attr['srcset'] ) );
			foreach ( $parts as &$part ) {
				$segments = preg_split( '/\s+/', $part, 2 );
				if ( ! empty( $segments[0] ) ) {
					$segments[0] = $this->rewrite_url( $segments[0] );
					$part = implode( ' ', $segments );
				}
			}
			unset( $part );
			$attr['srcset'] = implode( ', ', $parts );
		}
		return $attr;
	}

	public function resource_hints( array $urls, string $relation_type ): array {
		$origins = ApexPress_Settings::lines( 'preconnect_origins' );
		if ( 'preconnect' === $relation_type ) {
			foreach ( $origins as $origin ) {
				$origin = esc_url_raw( $origin );
				if ( $origin ) {
					$urls[] = array( 'href' => $origin, 'crossorigin' => 'anonymous' );
				}
			}
		}
		if ( 'dns-prefetch' === $relation_type ) {
			foreach ( $origins as $origin ) {
				$host = wp_parse_url( $origin, PHP_URL_HOST );
				if ( $host ) {
					$urls[] = '//' . $host;
				}
			}
		}
		return $urls;
	}

	public function print_preloads(): void {
		foreach ( ApexPress_Settings::lines( 'preload_resources' ) as $url ) {
			$url = esc_url( $url );
			if ( ! $url ) {
				continue;
			}
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$as   = match ( $ext ) {
				'woff', 'woff2', 'ttf', 'otf' => 'font',
				'css' => 'style',
				'js', 'mjs' => 'script',
				'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg' => 'image',
				default => '',
			};
			if ( ! $as ) {
				continue;
			}
			$crossorigin = 'font' === $as ? ' crossorigin' : '';
			echo '<link rel="preload" href="' . esc_url( $url ) . '" as="' . esc_attr( $as ) . '"' . $crossorigin . '>\n'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $crossorigin is a fixed literal.
		}
	}

	public function speculation_configuration( ?array $config ): ?array {
		$mode = (string) ApexPress_Settings::get( 'speculation_mode', 'auto' );
		if ( 'off' === $mode ) {
			return null;
		}
		$eagerness = (string) ApexPress_Settings::get( 'speculation_eagerness', 'auto' );
		if ( ! in_array( $mode, array( 'auto', 'prefetch', 'prerender' ), true ) ) {
			$mode = 'auto';
		}
		if ( ! in_array( $eagerness, array( 'auto', 'conservative', 'moderate', 'eager' ), true ) ) {
			$eagerness = 'auto';
		}
		if ( null === $config ) {
			return null;
		}
		return array( 'mode' => $mode, 'eagerness' => $eagerness );
	}

	public function speculation_exclusions( array $paths, string $mode ): array {
		$extra = ApexPress_Settings::lines( 'speculation_excludes' );
		if ( ApexPress_WooCommerce::available() && ApexPress_Settings::get( 'woocommerce_safe_mode' ) ) {
			$extra = array_merge( $extra, array( '/cart/*', '/checkout/*', '/my-account/*' ) );
		}
		return array_values( array_unique( array_merge( $paths, $extra ) ) );
	}
}
