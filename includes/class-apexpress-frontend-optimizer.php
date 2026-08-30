<?php
/**
 * Frontend HTML, asset, font and loading optimization.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Frontend_Optimizer {
	private int $image_counter = 0;

	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'start_output_buffer' ), -80 );
		add_filter( 'script_loader_src', array( $this, 'maybe_minify_js_url' ), 20, 2 );
		add_filter( 'style_loader_src', array( $this, 'maybe_minify_css_url' ), 20, 2 );
		add_filter( 'script_loader_tag', array( $this, 'optimize_script_tag' ), 20, 3 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'optimize_image_attributes' ), 20, 3 );
		add_filter( 'the_content', array( $this, 'lazy_load_content_iframes' ), 20 );
		add_action( 'wp_footer', array( $this, 'print_delayed_script_loader' ), 1000 );
	}

	public function start_output_buffer(): void {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() ) {
			return;
		}
		if ( ApexPress_Settings::get( 'html_minify' ) || ApexPress_Settings::get( 'font_display_swap' ) ) {
			ob_start( array( $this, 'optimize_html' ) );
		}
	}

	public function optimize_html( string $html ): string {
		if ( '' === trim( $html ) || ! str_contains( strtolower( $html ), '<html' ) ) {
			return $html;
		}
		if ( ApexPress_Settings::get( 'font_display_swap' ) ) {
			$html = preg_replace_callback(
				'/@font-face\s*\{[^}]*\}/is',
				static function ( array $match ): string {
					$block = $match[0];
					if ( stripos( $block, 'font-display:' ) === false ) {
						$block = preg_replace( '/}\s*$/', 'font-display:swap;}', $block ) ?? $block;
					}
					return $block;
				},
				$html
			) ?? $html;
		}
		if ( ! ApexPress_Settings::get( 'html_minify' ) ) {
			return $html;
		}

		$protected = array();
		$html = preg_replace_callback(
			'#<(pre|textarea|script|style)(?:\s[^>]*)?>.*?</\1>#is',
			static function ( array $match ) use ( &$protected ): string {
				$key               = '___APEXPRESS_BLOCK_' . count( $protected ) . '___';
				$protected[ $key ] = $match[0];
				return $key;
			},
			$html
		) ?? $html;
		$html = preg_replace( '/<!--(?!\[if|<!|\s*wp:|\s*\/wp:)[\s\S]*?-->/', '', $html ) ?? $html;
		$html = preg_replace( '/>\s+</', '> <', $html ) ?? $html;
		$html = preg_replace( '/[\t ]{2,}/', ' ', $html ) ?? $html;
		foreach ( $protected as $key => $block ) {
			$html = str_replace( $key, $block, $html );
		}
		return trim( $html );
	}

	public function maybe_minify_css_url( string $src, string $handle ): string {
		if ( ! ApexPress_Settings::get( 'css_minify' ) || str_contains( $src, '.min.css' ) ) {
			return $src;
		}
		return $this->minified_asset_url( $src, 'css' ) ?: $src;
	}

	public function maybe_minify_js_url( string $src, string $handle ): string {
		if ( ! ApexPress_Settings::get( 'js_minify' ) || str_contains( $src, '.min.js' ) ) {
			return $src;
		}
		return $this->minified_asset_url( $src, 'js' ) ?: $src;
	}

	public function optimize_script_tag( string $tag, string $handle, string $src ): string {
		if ( is_admin() || '' === $src ) {
			return $tag;
		}
		$processor = new WP_HTML_Tag_Processor( $tag );
		if ( ! $processor->next_tag( 'script' ) ) {
			return $tag;
		}

		if ( ApexPress_Settings::get( 'js_delay' ) && $this->matches_any( $src, ApexPress_Settings::lines( 'js_delay_patterns' ) ) ) {
			$processor->set_attribute( 'type', 'text/apexpress-delayed' );
			$processor->set_attribute( 'data-apx-src', $src );
			$processor->remove_attribute( 'src' );
			return $processor->get_updated_html();
		}

		$async_handles = ApexPress_Settings::lines( 'js_async_handles' );
		if ( in_array( $handle, $async_handles, true ) ) {
			$processor->set_attribute( 'async', 'async' );
			return $processor->get_updated_html();
		}

		if ( ApexPress_Settings::get( 'js_defer' ) && ! in_array( $handle, ApexPress_Settings::lines( 'js_defer_excludes' ), true ) ) {
			global $wp_scripts;
			$before = $wp_scripts instanceof WP_Scripts ? $wp_scripts->get_data( $handle, 'before' ) : false;
			$after  = $wp_scripts instanceof WP_Scripts ? $wp_scripts->get_data( $handle, 'after' ) : false;
			if ( empty( $before ) && empty( $after ) ) {
				$processor->set_attribute( 'defer', 'defer' );
			}
		}
		return $processor->get_updated_html();
	}

	public function optimize_image_attributes( array $attr, WP_Post $attachment, string|array $size ): array {
		if ( is_admin() ) {
			return $attr;
		}
		$this->image_counter++;
		if ( ApexPress_Settings::get( 'lazy_images' ) && $this->image_counter > 1 ) {
			if ( empty( $attr['loading'] ) ) {
				$attr['loading'] = 'lazy';
			}
			if ( empty( $attr['decoding'] ) ) {
				$attr['decoding'] = 'async';
			}
		}
		if ( ApexPress_Settings::get( 'first_image_high_priority' ) && 1 === $this->image_counter ) {
			$attr['loading'] = 'eager';
			if ( empty( $attr['fetchpriority'] ) ) {
				$attr['fetchpriority'] = 'high';
			}
		}
		return ApexPress_Image_Optimizer::prefer_modern_format_attributes( $attr );
	}

	public function lazy_load_content_iframes( string $content ): string {
		if ( ! ApexPress_Settings::get( 'lazy_iframes' ) || is_admin() ) {
			return $content;
		}
		return preg_replace_callback(
			'/<iframe\b[^>]*>/i',
			static function ( array $match ): string {
				$tag = $match[0];
				if ( stripos( $tag, ' loading=' ) !== false ) {
					return $tag;
				}
				return preg_replace( '/<iframe\b/i', '<iframe loading="lazy"', $tag, 1 ) ?? $tag;
			},
			$content
		) ?? $content;
	}

	public function print_delayed_script_loader(): void {
		if ( ! ApexPress_Settings::get( 'js_delay' ) ) {
			return;
		}
		?>
		<script id="apexpress-delayed-js-loader">
		(function(){let done=false;function run(){if(done)return;done=true;document.querySelectorAll('script[type="text/apexpress-delayed"][data-apx-src]').forEach(function(old){const s=document.createElement('script');Array.from(old.attributes).forEach(function(a){if(a.name!=='type'&&a.name!=='data-apx-src'){s.setAttribute(a.name,a.value);}});s.src=old.getAttribute('data-apx-src');old.replaceWith(s);});['pointerdown','keydown','touchstart','scroll'].forEach(function(e){window.removeEventListener(e,run,{passive:true});});}['pointerdown','keydown','touchstart','scroll'].forEach(function(e){window.addEventListener(e,run,{passive:true,once:true});});window.addEventListener('load',function(){setTimeout(run,5000);},{once:true});})();
		</script>
		<?php
	}

	private function minified_asset_url( string $src, string $type ): string|false {
		$path = $this->local_asset_path( $src );
		if ( ! $path || ! is_readable( $path ) || filesize( $path ) > 2 * MB_IN_BYTES ) {
			return false;
		}
		ApexPress_Cache::ensure_dirs();
		$mtime = (int) filemtime( $path );
		$key   = hash( 'sha256', $path . '|' . $mtime . '|' . APEXPRESS_VERSION );
		$file  = ApexPress_Cache::base_dir() . 'assets/' . $key . '.min.' . $type;
		if ( ! file_exists( $file ) ) {
			$code = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $code ) {
				return false;
			}
			$minified = 'css' === $type ? $this->minify_css( $code ) : $this->minify_js_conservative( $code );
			if ( false === file_put_contents( $file, $minified, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return false;
			}
		}
		$content_url = content_url( 'cache/apexpress/assets/' . basename( $file ) );
		return add_query_arg( 'v', $mtime, $content_url );
	}

	private function local_asset_path( string $src ): string|false {
		$src = html_entity_decode( $src, ENT_QUOTES );
		$url = wp_parse_url( $src );
		if ( empty( $url['path'] ) ) {
			return false;
		}
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! empty( $url['host'] ) && ! empty( $home['host'] ) && strtolower( $url['host'] ) !== strtolower( $home['host'] ) ) {
			return false;
		}
		$root = realpath( ABSPATH );
		$path = realpath( ABSPATH . ltrim( $url['path'], '/' ) );
		if ( ! $root || ! $path || ! str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) ) {
			return false;
		}
		return $path;
	}

	private function minify_css( string $css ): string {
		$css = preg_replace( '!/\*[^!*][\s\S]*?\*/!', '', $css ) ?? $css;
		$css = preg_replace( '/\s+/', ' ', $css ) ?? $css;
		$css = preg_replace( '/\s*([{}:;,>])\s*/', '$1', $css ) ?? $css;
		$css = str_replace( ';}', '}', $css );
		return trim( $css );
	}

	private function minify_js_conservative( string $js ): string {
		$lines = preg_split( '/\R/', $js ) ?: array();
		$out   = array();
		foreach ( $lines as $line ) {
			$line = rtrim( $line );
			if ( '' !== trim( $line ) ) {
				$out[] = $line;
			}
		}
		return implode( "\n", $out );
	}

	private function matches_any( string $value, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( '' !== $pattern && stripos( $value, $pattern ) !== false ) {
				return true;
			}
		}
		return false;
	}
}
