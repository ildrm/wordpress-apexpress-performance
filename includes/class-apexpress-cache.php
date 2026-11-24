<?php
/**
 * File page cache and invalidation engine.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Cache {
	private array $previous_urls = array();

	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 98 );
		add_action( 'template_redirect', array( $this, 'start_capture' ), 99 );
		add_action( 'update_option_active_plugins', array( self::class, 'refresh_dropin_config' ), 10, 0 );
		add_action( 'pre_post_update', array( $this, 'remember_published_url' ) );
		add_action( 'save_post', array( $this, 'purge_related_post' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'purge_all' ) );
		add_action( 'edited_term', array( $this, 'purge_all' ) );
		add_action( 'created_term', array( $this, 'purge_all' ) );
		add_action( 'delete_term', array( $this, 'purge_all' ) );
		add_action( 'comment_post', array( $this, 'purge_comment_post' ), 20, 3 );
		add_action( 'wp_set_comment_status', array( $this, 'purge_comment_status' ), 20, 2 );
	}

	public static function base_dir(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'cache/apexpress/';
	}

	public static function page_dir(): string {
		return self::base_dir() . 'page/';
	}

	public static function ensure_dirs(): void {
		$dirs = array( self::base_dir(), self::page_dir(), self::base_dir() . 'assets/', self::base_dir() . 'tmp/' );
		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}
		$index = self::base_dir() . 'index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	public function maybe_serve(): void {
		if ( ! ApexPress_Settings::get( 'page_cache' ) || ! $this->is_cacheable_request() || $this->response_requires_bypass() ) {
			return;
		}
		$paths = $this->paths_for_current_request();
		if ( ! is_readable( $paths['html'] ) || ! is_readable( $paths['meta'] ) ) {
			header( 'X-ApexPress-Cache: MISS' );
			return;
		}

		$meta = json_decode( (string) file_get_contents( $paths['meta'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $meta ) || empty( $meta['expires'] ) ) {
			return;
		}
		$expired = time() > (int) $meta['expires'];
		if ( $expired ) {
			return;
		}

		$content = file_get_contents( $paths['html'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		header( 'X-ApexPress-Cache: HIT' );
		if ( ApexPress_Settings::get( 'cache_browser_headers' ) ) {
			header( 'Cache-Control: public, max-age=0, must-revalidate' );
		}
		if ( ApexPress_Settings::get( 'cache_mobile_variant' ) ) {
			header( 'Vary: Sec-CH-UA-Mobile, User-Agent', false );
		}
		if ( 'HEAD' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cached trusted rendered HTML.
		}
		exit;
	}

	public function start_capture(): void {
		if ( ! ApexPress_Settings::get( 'page_cache' ) || ! $this->is_cacheable_request() || $this->response_requires_bypass() ) {
			return;
		}
		if ( headers_sent() || 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			return;
		}
		ob_start( array( $this, 'capture' ) );
	}

	public function capture( string $html ): string {
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return $html;
		}
		if ( $this->response_requires_bypass() ) {
			return $html;
		}
		if ( ApexPress_Settings::get( 'cache_mobile_variant' ) ) {
			header( 'Vary: Sec-CH-UA-Mobile, User-Agent', false );
		}
		$status = http_response_code();
		if ( $status >= 500 && ApexPress_Settings::get( 'cache_stale_if_error' ) ) {
			$stale = $this->stale_for_current_request();
			if ( false !== $stale ) {
				status_header( 200 );
				header( 'X-ApexPress-Cache: STALE' );
				return $stale;
			}
		}
		if ( '' === trim( $html ) || 200 !== $status || ! str_contains( strtolower( $html ), '<html' ) ) {
			return $html;
		}
		$paths = $this->paths_for_current_request();
		wp_mkdir_p( dirname( $paths['html'] ) );
		$ttl  = (int) ApexPress_Settings::get( 'cache_ttl', 3600 );
		$meta = array(
			'url'     => $this->current_url(),
			'created' => time(),
			'expires' => time() + $ttl,
			'bytes'   => strlen( $html ),
		);
		if ( self::atomic_write( $paths['html'], $html ) && self::atomic_write( $paths['meta'], (string) wp_json_encode( $meta ) ) ) {
			header( 'X-ApexPress-Cache: MISS; stored' );
		}
		return $html;
	}

	private function response_requires_bypass(): bool {
		foreach ( headers_list() as $header_line ) {
			$header_lower = strtolower( $header_line );
			if ( str_starts_with( $header_lower, 'set-cookie:' ) || str_starts_with( $header_lower, 'vary:' ) ) {
				return true;
			}
			if ( str_starts_with( $header_lower, 'cache-control:' ) && preg_match( '/\b(?:no-store|no-cache|private)\b/', $header_lower ) ) {
				return true;
			}
		}
		return false;
	}

	public function is_cacheable_request(): bool {
		if ( ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || is_robots() || is_trackback() ) {
			return false;
		}
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) || isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) || isset( $_SERVER['HTTP_RANGE'] ) ) {
			return false;
		}
		$home = wp_parse_url( home_url( '/' ) );
		$authority = strtolower( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( '' === $authority || $authority !== strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) )
			|| ( is_ssl() ? 'https' : 'http' ) !== ( $home['scheme'] ?? '' ) ) {
			return false;
		}
		if ( is_user_logged_in() || is_preview() || is_search() || is_404() || ( is_singular() && post_password_required() ) ) {
			return false;
		}
		if ( ! ApexPress_Settings::get( 'cache_query_strings' ) && ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cache eligibility check.
			return false;
		}
		if ( ApexPress_Settings::get( 'cache_query_strings' ) && ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cache eligibility check.
			$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
			if ( strlen( $uri ) > 2048 || count( $_GET ) > 8 ) {
				return false;
			}
			$sensitive = array( '_wpnonce', 'nonce', 'token', 'password', 'pass', 'key', 'signature', 'preview_id', 'preview_nonce' );
			foreach ( array_keys( $_GET ) as $query_key ) {
				if ( in_array( strtolower( (string) $query_key ), $sensitive, true ) ) {
					return false;
				}
			}
		}

		$path = ApexPress_Security::current_request_path();
		if ( ApexPress_Security::path_matches( $path, ApexPress_Settings::lines( 'cache_excludes' ) ) ) {
			return false;
		}
		if ( ApexPress_WooCommerce::must_bypass_cache() ) {
			return false;
		}
		foreach ( array_keys( $_COOKIE ) as $cookie ) {
			if ( preg_match( '/^(wordpress_logged_in_|wp-postpass_|comment_author_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_)/i', (string) $cookie ) ) {
				return false;
			}
		}
		return (bool) apply_filters( 'apexpress_cacheable_request', true, $path );
	}

	public function remember_published_url( int $post_id ): void {
		if ( 'publish' === get_post_status( $post_id ) ) {
			$this->previous_urls[ $post_id ] = get_permalink( $post_id );
		}
	}

	public function purge_related_post( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		$urls = array( home_url( '/' ) );
		$link = get_permalink( $post_id );
		$previous = $this->previous_urls[ $post_id ] ?? false;
		unset( $this->previous_urls[ $post_id ] );
		if ( $previous ) {
			$urls[] = $previous;
		}
		if ( $link ) {
			$urls[] = $link;
		}
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[] = $archive;
		}
		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$term_link = get_term_link( $term );
				if ( ! is_wp_error( $term_link ) ) {
					$urls[] = $term_link;
				}
			}
		}
		$urls = array_values( array_unique( $urls ) );
		$restricted = 'publish' !== $post->post_status || '' !== $post->post_password;
		if ( ApexPress_Settings::get( 'cache_query_strings' ) || ( $previous && ( $restricted || ( is_post_type_hierarchical( $post->post_type ) && $link !== $previous ) ) ) ) {
			$this->purge_all();
		} else {
			foreach ( $urls as $url ) {
				$this->purge_url( $url, false );
			}
		}
		if ( ApexPress_Cloudflare::enabled() ) {
			ApexPress_Cloudflare::purge_urls( $urls );
		}
		$skip_warming = $restricted ? array( $link, $previous ) : ( $link !== $previous ? array( $previous ) : array() );
		ApexPress_Cache_Warmer::enqueue_urls( array_diff( $urls, $skip_warming ) );
	}

	public function purge_comment_post( int $comment_id, int|string $approved, array $commentdata ): void {
		$post_id = isset( $commentdata['comment_post_ID'] ) ? absint( $commentdata['comment_post_ID'] ) : 0;
		if ( $post_id ) {
			$this->purge_url( (string) get_permalink( $post_id ) );
		}
	}

	public function purge_comment_status( int $comment_id, string $status ): void {
		$comment = get_comment( $comment_id );
		if ( $comment instanceof WP_Comment ) {
			$this->purge_url( (string) get_permalink( (int) $comment->comment_post_ID ) );
		}
	}

	public function purge_url( string $url, bool $remote = true ): void {
		if ( ! ApexPress_Security::safe_local_url( $url ) ) {
			return;
		}
		if ( ApexPress_Settings::get( 'cache_query_strings' ) ) {
			$this->purge_all();
		} else {
			foreach ( array( false, true ) as $mobile ) {
				$key  = self::cache_key_for_url( $url, $mobile );
				$base = self::path_for_key( $key );
				foreach ( array( $base . '.html', $base . '.json' ) as $file ) {
					if ( is_file( $file ) ) {
						@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
					}
				}
			}
		}
		if ( $remote && ApexPress_Cloudflare::enabled() ) {
			ApexPress_Cloudflare::purge_urls( array( $url ) );
		}
	}

	public function purge_all(): void {
		self::delete_tree_contents( self::page_dir() );
		ApexPress_Logger::log( 'info', 'Page cache purged.' );
	}

	public static function cache_key_for_url( string $url, bool $mobile = false ): string {
		$parts = wp_parse_url( $url );
		$origin = strtolower( ( $parts['scheme'] ?? '' ) . '://' . ( $parts['host'] ?? '' ) ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$normalized = 'v3|' . $origin . '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );
		$query      = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( ApexPress_Settings::get( 'cache_query_strings' ) && '' !== $query ) {
			$normalized .= '?' . $query;
		}
		if ( ApexPress_Settings::get( 'cache_mobile_variant' ) ) {
			$normalized .= $mobile ? '|mobile' : '|desktop';
		}
		return hash( 'sha256', $normalized );
	}

	public static function path_for_key( string $key ): string {
		return self::page_dir() . substr( $key, 0, 2 ) . '/' . substr( $key, 2, 2 ) . '/' . $key;
	}

	private function stale_for_current_request(): string|false {
		$paths = $this->paths_for_current_request();
		if ( ! is_readable( $paths['html'] ) || ! is_readable( $paths['meta'] ) ) {
			return false;
		}
		$meta = json_decode( (string) file_get_contents( $paths['meta'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $meta ) || empty( $meta['expires'] ) || time() > ( (int) $meta['expires'] + DAY_IN_SECONDS ) ) {
			return false;
		}
		$content = file_get_contents( $paths['html'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $content ? false : $content;
	}

	private function paths_for_current_request(): array {
		$key  = self::cache_key_for_url( $this->current_url(), wp_is_mobile() );
		$base = self::path_for_key( $key );
		return array( 'html' => $base . '.html', 'meta' => $base . '.json' );
	}

	private function current_url(): string {
		$home = (array) wp_parse_url( home_url( '/' ) );
		$scheme = isset( $home['scheme'] ) ? (string) $home['scheme'] : ( is_ssl() ? 'https' : 'http' );
		$host   = isset( $home['host'] ) ? (string) $home['host'] : '';
		$port   = isset( $home['port'] ) ? ':' . absint( $home['port'] ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return esc_url_raw( $scheme . '://' . $host . $port . $uri );
	}

	public static function atomic_write( string $path, string $contents ): bool {
		$tmp = $path . '.' . wp_generate_password( 8, false, false ) . '.tmp';
		if ( strlen( $contents ) === @file_put_contents( $tmp, $contents, LOCK_EX ) && @rename( $tmp, $path ) ) {
			return true;
		}
		if ( is_file( $tmp ) ) {
			@unlink( $tmp );
		}
		return false;
	}

	public static function refresh_dropin_config(): void {
		self::write_dropin_config_static();
	}

	public static function early_cache_allowed(): bool {
		// AegisGuard's request checks and response headers run during WordPress bootstrap.
		return ! is_multisite() && ( ! defined( 'AEGISGUARD_BASENAME' )
			|| ! in_array( AEGISGUARD_BASENAME, (array) get_option( 'active_plugins', array() ), true ) );
	}

	public static function write_dropin_config_static( ?array $settings = null ): bool {
		self::ensure_dirs();
		$settings = $settings ?? ApexPress_Settings::get_all();
		$home = wp_parse_url( home_url( '/' ) );
		$config   = array(
			'version'        => 3,
			'enabled'        => self::early_cache_allowed() && ! empty( $settings['page_cache'] ),
			'authority'      => strtolower( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ),
			'scheme'         => $home['scheme'] ?? '',
			'mobile_variant' => ! empty( $settings['cache_mobile_variant'] ),
			'browser_headers' => ! empty( $settings['cache_browser_headers'] ),
			'charset'        => get_option( 'blog_charset', 'UTF-8' ),
			'query_strings'  => ! empty( $settings['cache_query_strings'] ),
			'excludes'       => array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $settings['cache_excludes'] ) ?: array() ) ) ),
		);
		return self::atomic_write( self::base_dir() . 'config.json', (string) wp_json_encode( $config ) );
	}

	public static function install_dropin(): array {
		if ( is_multisite() ) {
			return array( 'ok' => false, 'message' => __( 'The early page cache drop-in requires a single-site installation.', 'apexpress-performance' ) );
		}
		if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
			return array( 'ok' => false, 'message' => __( 'WP_CACHE is not enabled in wp-config.php. Add define(\'WP_CACHE\', true); and try again.', 'apexpress-performance' ) );
		}
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		$source = APEXPRESS_DIR . 'dropins/advanced-cache.php';
		if ( ( file_exists( $target ) || is_link( $target ) ) && ! self::owns_dropin( $target ) ) {
			return array( 'ok' => false, 'message' => __( 'Another advanced-cache.php drop-in already exists. ApexPress did not overwrite it.', 'apexpress-performance' ) );
		}
		$contents = file_get_contents( $source );
		if ( false === $contents || ! self::write_dropin_config_static() || ! self::atomic_write( $target, $contents ) ) {
			return array( 'ok' => false, 'message' => __( 'Could not write advanced-cache.php. Check wp-content permissions.', 'apexpress-performance' ) );
		}
		return array( 'ok' => true, 'message' => __( 'Early page cache drop-in installed.', 'apexpress-performance' ) );
	}

	public static function dropin_status(): array {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! file_exists( $target ) && ! is_link( $target ) ) {
			return array( 'installed' => false, 'owned' => false, 'wp_cache' => defined( 'WP_CACHE' ) && WP_CACHE );
		}
		return array(
			'installed' => true,
			'owned'     => self::owns_dropin( $target ),
			'wp_cache'  => defined( 'WP_CACHE' ) && WP_CACHE,
		);
	}

	public static function remove_dropin(): void {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( ! self::owns_dropin( $target ) ) {
			return;
		}
		// Disable reads before removing the entry point, even if wp-content is read-only.
		$config = self::base_dir() . 'config.json';
		if ( is_file( $config ) && ! @unlink( $config ) ) {
			self::atomic_write( $config, '{"enabled":false}' );
		}
		@unlink( $target );
	}

	public static function upgrade_dropin(): void {
		if ( (int) get_option( 'apexpress_cache_revision' ) >= 3 ) {
			return;
		}
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( self::owns_dropin( $target ) ) {
			( new self() )->purge_all();
			if ( is_multisite() ) {
				self::remove_dropin();
			} elseif ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
				self::remove_dropin();
			} elseif ( empty( self::install_dropin()['ok'] ) ) {
				return;
			}
		}
		if ( self::write_dropin_config_static() ) {
			update_option( 'apexpress_cache_revision', 3, false );
		}
	}

	private static function owns_dropin( string $path ): bool {
		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$header = file_get_contents( $path, false, null, 0, 256 );
		return is_string( $header ) && 1 === preg_match( '~\A<\?php\R/\*\*\R \* APEXPRESS_ADVANCED_CACHE\R \* Early page cache drop-in for ApexPress Performance\.\R~', $header );
	}

	public static function delete_tree_contents( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() ) {
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}
}
