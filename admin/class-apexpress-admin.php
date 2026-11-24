<?php
/**
 * Admin dashboard and secure actions.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Admin {
	private const PAGE = 'apexpress-performance';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_apexpress_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_apexpress_purge_cache', array( $this, 'purge_cache' ) );
		add_action( 'admin_post_apexpress_warm_cache', array( $this, 'warm_cache' ) );
		add_action( 'admin_post_apexpress_optimize_images', array( $this, 'optimize_images' ) );
		add_action( 'admin_post_apexpress_database_cleanup', array( $this, 'database_cleanup' ) );
		add_action( 'admin_post_apexpress_install_dropin', array( $this, 'install_dropin' ) );
		add_action( 'admin_post_apexpress_purge_cloudflare', array( $this, 'purge_cloudflare' ) );
		add_action( 'admin_post_apexpress_clear_log', array( $this, 'clear_log' ) );
		add_action( 'admin_post_apexpress_install_apache_rules', array( $this, 'install_apache_rules' ) );
		add_action( 'admin_post_apexpress_remove_apache_rules', array( $this, 'remove_apache_rules' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( APEXPRESS_FILE ), array( $this, 'plugin_links' ) );
	}

	public function menu(): void {
		add_menu_page(
			__( 'ApexPress Performance', 'apexpress-performance' ),
			__( 'ApexPress', 'apexpress-performance' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-performance',
			58
		);
	}

	public function plugin_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Dashboard', 'apexpress-performance' ) . '</a>' );
		return $links;
	}

	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'apexpress-admin', APEXPRESS_URL . 'assets/css/admin.css', array(), APEXPRESS_VERSION );
		wp_enqueue_script( 'apexpress-admin', APEXPRESS_URL . 'assets/js/admin.js', array(), APEXPRESS_VERSION, true );
		wp_localize_script(
			'apexpress-admin',
			'ApexPressAdmin',
			array( 'working' => __( 'Working…', 'apexpress-performance' ) )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$allowed_tabs = array( 'dashboard', 'cache', 'frontend', 'media', 'wordpress', 'database', 'monitoring', 'tools' );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		if ( ! in_array( $tab, $allowed_tabs, true ) ) {
			$tab = 'dashboard';
		}
		$audit    = ApexPress_Audit::run();
		$settings = ApexPress_Settings::get_all();
		?>
		<div class="wrap apx-wrap">
			<?php $this->render_header( $audit ); ?>
			<?php $this->render_notice(); ?>
			<nav class="apx-tabs" aria-label="<?php esc_attr_e( 'ApexPress sections', 'apexpress-performance' ); ?>">
				<?php
				$tabs = array(
					'dashboard'  => __( 'Dashboard', 'apexpress-performance' ),
					'cache'      => __( 'Cache', 'apexpress-performance' ),
					'frontend'   => __( 'Frontend', 'apexpress-performance' ),
					'media'      => __( 'Media', 'apexpress-performance' ),
					'wordpress'  => __( 'WordPress', 'apexpress-performance' ),
					'database'   => __( 'Database', 'apexpress-performance' ),
					'monitoring' => __( 'Monitoring', 'apexpress-performance' ),
					'tools'      => __( 'Tools', 'apexpress-performance' ),
				);
				foreach ( $tabs as $key => $label ) {
					$url = add_query_arg( array( 'page' => self::PAGE, 'tab' => $key ), admin_url( 'admin.php' ) );
					printf( '<a class="%1$s" href="%2$s"%4$s>%3$s</a>', esc_attr( $key === $tab ? 'is-active' : '' ), esc_url( $url ), esc_html( $label ), $key === $tab ? ' aria-current="page"' : '' );
				}
				?>
			</nav>
			<main class="apx-main">
				<?php
				switch ( $tab ) {
					case 'cache':
						$this->render_cache( $settings );
						break;
					case 'frontend':
						$this->render_frontend( $settings );
						break;
					case 'media':
						$this->render_media( $settings );
						break;
					case 'wordpress':
						$this->render_wordpress( $settings );
						break;
					case 'database':
						$this->render_database();
						break;
					case 'monitoring':
						$this->render_monitoring( $settings );
						break;
					case 'tools':
						$this->render_tools( $settings );
						break;
					default:
						$this->render_dashboard( $audit );
				}
				?>
			</main>
		</div>
		<?php
	}

	private function render_header( array $audit ): void {
		$grade = $audit['score'] >= 90 ? 'Excellent' : ( $audit['score'] >= 75 ? 'Good' : ( $audit['score'] >= 55 ? 'Needs work' : 'Critical' ) );
		?>
		<header class="apx-hero">
			<div>
				<div class="apx-eyebrow">APEXPRESS PERFORMANCE SUITE</div>
				<h1><?php esc_html_e( 'Performance, without guesswork.', 'apexpress-performance' ); ?></h1>
				<p><?php esc_html_e( 'Cache, optimize, measure and protect WordPress performance from one control plane.', 'apexpress-performance' ); ?></p>
			</div>
			<div class="apx-score" aria-label="<?php echo esc_attr( sprintf( __( 'Performance health score: %d of 100', 'apexpress-performance' ), $audit['score'] ) ); ?>">
				<strong><?php echo esc_html( (string) $audit['score'] ); ?></strong><span>/100</span>
				<small><?php echo esc_html( $grade ); ?></small>
			</div>
		</header>
		<?php
	}

	private function render_notice(): void {
		$key = 'apexpress_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$class = ! empty( $notice['ok'] ) ? 'apx-notice-success' : 'apx-notice-error';
		printf( '<div class="apx-notice %1$s"><strong>%2$s</strong></div>', esc_attr( $class ), esc_html( (string) ( $notice['message'] ?? '' ) ) );
	}

	private function render_dashboard( array $audit ): void {
		$db  = ApexPress_Database::analyze();
		$rum = ApexPress_RUM::summary( 7 );
		$dropin = ApexPress_Cache::dropin_status();
		?>
		<section class="apx-grid apx-grid-4">
			<?php $this->metric_card( __( 'Server', 'apexpress-performance' ), $audit['server'], __( 'Detected web server', 'apexpress-performance' ) ); ?>
			<?php $this->metric_card( __( 'Autoload', 'apexpress-performance' ), size_format( (int) $db['autoload_bytes'] ), __( 'Loaded on every request', 'apexpress-performance' ) ); ?>
			<?php $this->metric_card( __( 'Warm queue', 'apexpress-performance' ), (string) $audit['warm_queue'], __( 'URLs waiting', 'apexpress-performance' ) ); ?>
			<?php $this->metric_card( __( 'Early cache', 'apexpress-performance' ), ! empty( $dropin['owned'] ) && ! empty( $dropin['wp_cache'] ) ? __( 'Active', 'apexpress-performance' ) : __( 'Fallback', 'apexpress-performance' ), __( 'Pre-WordPress cache serving', 'apexpress-performance' ) ); ?>
		</section>

		<section class="apx-grid apx-grid-2 apx-section-gap">
			<div class="apx-card">
				<div class="apx-card-head"><div><span class="apx-kicker"><?php esc_html_e( 'SYSTEM AUDIT', 'apexpress-performance' ); ?></span><h2><?php esc_html_e( 'Optimization readiness', 'apexpress-performance' ); ?></h2></div></div>
				<div class="apx-checks">
					<?php foreach ( $audit['checks'] as $check ) : ?>
						<div class="apx-check">
							<span class="apx-status <?php echo esc_attr( $check['pass'] ? 'ok' : 'warn' ); ?>" aria-hidden="true"></span>
							<div><strong><?php echo esc_html( $check['label'] ); ?></strong><small><?php echo esc_html( $check['value'] ); ?></small></div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="apx-card">
				<div class="apx-card-head"><div><span class="apx-kicker"><?php esc_html_e( 'REAL USERS', 'apexpress-performance' ); ?></span><h2><?php esc_html_e( '7-day field signals', 'apexpress-performance' ); ?></h2></div></div>
				<?php if ( empty( $rum ) ) : ?>
					<div class="apx-empty"><strong><?php esc_html_e( 'Collecting baseline data', 'apexpress-performance' ); ?></strong><p><?php esc_html_e( 'RUM is privacy-conscious and stores aggregated metrics only. Data appears after sampled visitors browse the site.', 'apexpress-performance' ); ?></p></div>
				<?php else : ?>
					<div class="apx-table-wrap"><table class="apx-table"><thead><tr><th><?php esc_html_e( 'Metric', 'apexpress-performance' ); ?></th><th><?php esc_html_e( 'Device', 'apexpress-performance' ); ?></th><th><?php esc_html_e( 'Average', 'apexpress-performance' ); ?></th><th><?php esc_html_e( 'Samples', 'apexpress-performance' ); ?></th></tr></thead><tbody>
					<?php foreach ( $rum as $row ) : ?><tr><td><?php echo esc_html( strtoupper( $row['metric'] ) ); ?></td><td><?php echo esc_html( ucfirst( $row['device'] ) ); ?></td><td><?php echo esc_html( $this->format_rum_value( $row['metric'], (float) $row['average'] ) ); ?></td><td><?php echo esc_html( (string) $row['samples'] ); ?></td></tr><?php endforeach; ?>
					</tbody></table></div>
				<?php endif; ?>
			</div>
		</section>

		<?php if ( ! empty( $audit['conflicts'] ) ) : ?>
			<div class="apx-card apx-section-gap apx-warning-card"><span class="apx-kicker"><?php esc_html_e( 'CONFLICT CHECK', 'apexpress-performance' ); ?></span><h2><?php esc_html_e( 'Other performance plugins detected', 'apexpress-performance' ); ?></h2><p><?php echo esc_html( implode( ', ', array_values( $audit['conflicts'] ) ) ); ?></p><p><?php esc_html_e( 'Avoid enabling duplicate page-cache or asset-rewrite features in multiple plugins at the same time.', 'apexpress-performance' ); ?></p></div>
		<?php endif; ?>
		<?php
	}

	private function render_cache( array $s ): void {
		$dropin = ApexPress_Cache::dropin_status();
		$this->settings_open( 'cache' );
		?>
		<div class="apx-grid apx-grid-2">
			<div class="apx-card">
				<?php $this->card_title( 'PAGE CACHE', __( 'Fast HTML delivery', 'apexpress-performance' ), __( 'Static HTML cache with safe exclusions, atomic writes and targeted invalidation.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'page_cache', __( 'Enable page cache', 'apexpress-performance' ), $s['page_cache'], __( 'Caches anonymous GET requests only. Logged-in, preview, search, 404 and WooCommerce session requests are bypassed.', 'apexpress-performance' ) ); ?>
				<?php $this->number( 'cache_ttl', __( 'Cache TTL (seconds)', 'apexpress-performance' ), $s['cache_ttl'], 60, 604800 ); ?>
				<?php $this->toggle( 'cache_mobile_variant', __( 'Separate mobile cache', 'apexpress-performance' ), $s['cache_mobile_variant'], __( 'Use only when the rendered HTML differs by device; otherwise it reduces hit ratio.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'cache_query_strings', __( 'Cache query strings', 'apexpress-performance' ), $s['cache_query_strings'], __( 'Off by default to prevent cache fragmentation and accidental personalization caching.', 'apexpress-performance' ) ); ?>
				<?php $this->textarea( 'cache_excludes', __( 'Excluded paths', 'apexpress-performance' ), $s['cache_excludes'], __( 'One path pattern per line. * is supported.', 'apexpress-performance' ) ); ?>
			</div>
			<div class="apx-card">
				<?php $this->card_title( 'DELIVERY', __( 'Warm and serve safely', 'apexpress-performance' ), __( 'Control cache warming, response headers and the optional early drop-in.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'cache_preload', __( 'Cache preloading', 'apexpress-performance' ), $s['cache_preload'], __( 'Processes a bounded queue through WP-Cron instead of flooding the origin.', 'apexpress-performance' ) ); ?>
				<?php $this->number( 'cache_preload_batch', __( 'URLs per warm batch', 'apexpress-performance' ), $s['cache_preload_batch'], 1, 20 ); ?>
				<?php $this->toggle( 'cache_browser_headers', __( 'HTML revalidation headers', 'apexpress-performance' ), $s['cache_browser_headers'], __( 'Cached HTML is sent with must-revalidate to avoid stale personalized browser copies.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'cache_stale_if_error', __( 'Serve stale cache on origin errors', 'apexpress-performance' ), $s['cache_stale_if_error'], __( 'If WordPress returns a 5xx response, a cached copy expired within the last 24 hours can be served as a resilience fallback.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'cloudflare_purge', __( 'Synchronize targeted Cloudflare purges', 'apexpress-performance' ), $s['cloudflare_purge'], __( 'Requires APEXPRESS_CF_API_TOKEN and APEXPRESS_CF_ZONE_ID constants. Tokens are never stored in WordPress options.', 'apexpress-performance' ) ); ?>
				<div class="apx-inline-status"><span class="apx-status <?php echo ApexPress_Cloudflare::configured() ? 'ok' : 'warn'; ?>"></span><div><strong><?php esc_html_e( 'Cloudflare API', 'apexpress-performance' ); ?></strong><small><?php echo esc_html( ApexPress_Cloudflare::configured() ? __( 'Server-side credentials detected', 'apexpress-performance' ) : __( 'Optional constants are not configured', 'apexpress-performance' ) ); ?></small></div></div>
				<div class="apx-inline-status"><span class="apx-status <?php echo esc_attr( ! empty( $dropin['owned'] ) && ! empty( $dropin['wp_cache'] ) ? 'ok' : 'warn' ); ?>"></span><div><strong><?php esc_html_e( 'Early cache drop-in', 'apexpress-performance' ); ?></strong><small><?php echo esc_html( ! empty( $dropin['owned'] ) && ! empty( $dropin['wp_cache'] ) ? __( 'Active before WordPress boots', 'apexpress-performance' ) : __( 'Fallback cache is active; install the drop-in for maximum TTFB reduction.', 'apexpress-performance' ) ); ?></small></div></div>
			</div>
		</div>
		<?php $this->settings_close(); ?>
		<div class="apx-actions apx-section-gap">
			<?php $this->action_form( 'apexpress_purge_cache', __( 'Purge page cache', 'apexpress-performance' ), 'secondary' ); ?>
			<?php $this->action_form( 'apexpress_warm_cache', __( 'Queue cache warmup', 'apexpress-performance' ), 'secondary' ); ?>
			<?php if ( empty( $dropin['owned'] ) || empty( $dropin['wp_cache'] ) ) : ?><?php $this->action_form( 'apexpress_install_dropin', __( 'Install early cache', 'apexpress-performance' ), 'primary' ); ?><?php endif; ?>
			<?php if ( ApexPress_Cloudflare::configured() ) : ?><?php $this->action_form( 'apexpress_purge_cloudflare', __( 'Purge all Cloudflare cache', 'apexpress-performance' ), 'secondary' ); ?><?php endif; ?>
		</div>
		<?php
	}

	private function render_frontend( array $s ): void {
		$this->settings_open( 'frontend' );
		?>
		<div class="apx-grid apx-grid-2">
			<div class="apx-card">
				<?php $this->card_title( 'MARKUP & ASSETS', __( 'Reduce transfer and blocking work', 'apexpress-performance' ), __( 'Conservative transformations avoid rewriting dynamic inline application code.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'html_minify', __( 'Minify HTML', 'apexpress-performance' ), $s['html_minify'], __( 'Preserves scripts, styles, preformatted text, textareas and WordPress block comments.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'css_minify', __( 'Minify local CSS', 'apexpress-performance' ), $s['css_minify'], __( 'Creates versioned minified copies under wp-content/cache/apexpress/assets.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'js_minify', __( 'Conservative local JS compaction', 'apexpress-performance' ), $s['js_minify'], __( 'Removes blank lines/trailing whitespace only. It intentionally avoids unsafe regex-based JavaScript rewriting.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'font_display_swap', __( 'Inject font-display: swap', 'apexpress-performance' ), $s['font_display_swap'], __( 'Adds swap only to inline @font-face blocks that do not already define font-display.', 'apexpress-performance' ) ); ?>
			</div>
			<div class="apx-card">
				<?php $this->card_title( 'JAVASCRIPT', __( 'Execution strategy', 'apexpress-performance' ), __( 'Defer is skipped for handles with WordPress inline before/after data to reduce dependency breakage.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'js_defer', __( 'Defer eligible JavaScript', 'apexpress-performance' ), $s['js_defer'], __( 'jQuery is excluded by default. Add more handles below when needed.', 'apexpress-performance' ) ); ?>
				<?php $this->textarea( 'js_defer_excludes', __( 'Defer exclusions (handles)', 'apexpress-performance' ), $s['js_defer_excludes'], __( 'One WordPress script handle per line.', 'apexpress-performance' ) ); ?>
				<?php $this->textarea( 'js_async_handles', __( 'Async handles', 'apexpress-performance' ), $s['js_async_handles'], __( 'Use only for fully independent scripts with no ordering requirements.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'js_delay', __( 'Delay selected third-party scripts', 'apexpress-performance' ), $s['js_delay'], __( 'Loads matched scripts after interaction or a 5 second safety timeout.', 'apexpress-performance' ) ); ?>
				<?php $this->textarea( 'js_delay_patterns', __( 'Delay URL patterns', 'apexpress-performance' ), $s['js_delay_patterns'], __( 'One substring per line.', 'apexpress-performance' ) ); ?>
			</div>
			<div class="apx-card">
				<?php $this->card_title( 'DELIVERY', __( 'CDN & connection hints', 'apexpress-performance' ), __( 'Rewrite eligible local static assets to a generic CDN and manage targeted preconnect/preload hints.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'cdn_enabled', __( 'Enable generic CDN rewriting', 'apexpress-performance' ), $s['cdn_enabled'], __( 'Only same-site static asset URLs with allowed extensions are rewritten.', 'apexpress-performance' ) ); ?>
				<?php $this->text( 'cdn_url', __( 'CDN base URL', 'apexpress-performance' ), $s['cdn_url'], 'https://cdn.example.com' ); ?>
				<?php $this->text( 'cdn_extensions', __( 'CDN extensions', 'apexpress-performance' ), $s['cdn_extensions'], 'css,js,jpg,jpeg,png,webp,avif,svg,woff,woff2' ); ?>
				<?php $this->textarea( 'preconnect_origins', __( 'Preconnect origins', 'apexpress-performance' ), $s['preconnect_origins'], __( 'One full HTTPS origin per line. Use sparingly; excessive preconnects waste sockets.', 'apexpress-performance' ) ); ?>
				<?php $this->textarea( 'preload_resources', __( 'Explicit preload resources', 'apexpress-performance' ), $s['preload_resources'], __( 'One full resource URL per line. ApexPress infers the as= type from the extension.', 'apexpress-performance' ) ); ?>
			</div>
			<div class="apx-card">
				<?php $this->card_title( 'SPECULATION RULES', __( 'Near-instant next navigations', 'apexpress-performance' ), __( 'Uses WordPress Core speculative loading instead of injecting a competing implementation.', 'apexpress-performance' ) ); ?>
				<div class="apx-field"><label for="speculation_mode"><?php esc_html_e( 'Mode', 'apexpress-performance' ); ?></label><select id="speculation_mode" name="settings[speculation_mode]"><option value="off" <?php selected( $s['speculation_mode'], 'off' ); ?>><?php esc_html_e( 'Off', 'apexpress-performance' ); ?></option><option value="auto" <?php selected( $s['speculation_mode'], 'auto' ); ?>><?php esc_html_e( 'WordPress automatic', 'apexpress-performance' ); ?></option><option value="prefetch" <?php selected( $s['speculation_mode'], 'prefetch' ); ?>><?php esc_html_e( 'Prefetch', 'apexpress-performance' ); ?></option><option value="prerender" <?php selected( $s['speculation_mode'], 'prerender' ); ?>><?php esc_html_e( 'Prerender', 'apexpress-performance' ); ?></option></select></div>
				<div class="apx-field"><label for="speculation_eagerness"><?php esc_html_e( 'Eagerness', 'apexpress-performance' ); ?></label><select id="speculation_eagerness" name="settings[speculation_eagerness]"><option value="auto" <?php selected( $s['speculation_eagerness'], 'auto' ); ?>><?php esc_html_e( 'WordPress automatic', 'apexpress-performance' ); ?></option><option value="conservative" <?php selected( $s['speculation_eagerness'], 'conservative' ); ?>><?php esc_html_e( 'Conservative', 'apexpress-performance' ); ?></option><option value="moderate" <?php selected( $s['speculation_eagerness'], 'moderate' ); ?>><?php esc_html_e( 'Moderate', 'apexpress-performance' ); ?></option><option value="eager" <?php selected( $s['speculation_eagerness'], 'eager' ); ?>><?php esc_html_e( 'Eager', 'apexpress-performance' ); ?></option></select></div>
				<?php $this->textarea( 'speculation_excludes', __( 'Speculation exclusions', 'apexpress-performance' ), $s['speculation_excludes'], __( 'One root-relative path pattern per line. WooCommerce dynamic pages are added automatically in safe mode.', 'apexpress-performance' ) ); ?>
			</div>
		</div>
		<?php $this->settings_close(); ?>
		<?php
	}

	private function render_media( array $s ): void {
		$failed = ApexPress_Image_Queue::count( true );
		$this->settings_open( 'media' );
		?>
		<div class="apx-grid apx-grid-2">
			<div class="apx-card">
				<?php $this->card_title( 'IMAGE PIPELINE', __( 'Smaller, modern images', 'apexpress-performance' ), __( 'Uses the WordPress image editor abstraction, so Imagick/GD capability is detected instead of assumed.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'image_optimize_uploads', __( 'Optimize new uploads', 'apexpress-performance' ), $s['image_optimize_uploads'], __( 'Queues JPEG, PNG, WebP and AVIF uploads for optimization. WordPress keeps its original files.', 'apexpress-performance' ) ); ?>
				<?php $this->number( 'image_quality', __( 'Image quality', 'apexpress-performance' ), $s['image_quality'], 40, 100 ); ?>
				<?php $this->number( 'image_max_width', __( 'Maximum optimized width', 'apexpress-performance' ), $s['image_max_width'], 640, 7680 ); ?>
				<?php $this->toggle( 'image_generate_webp', __( 'Generate WebP', 'apexpress-performance' ), $s['image_generate_webp'], __( 'Generated only when the active WordPress image editor supports WebP.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'image_generate_avif', __( 'Generate AVIF', 'apexpress-performance' ), $s['image_generate_avif'], __( 'Generated only when the active image editor supports AVIF.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'image_backup_originals', __( 'Backup originals', 'apexpress-performance' ), $s['image_backup_originals'], __( 'Strongly recommended. Original files are copied to a non-public operational backup directory before optimization.', 'apexpress-performance' ) ); ?>
			</div>
			<div class="apx-card">
				<?php $this->card_title( 'LOADING', __( 'Prioritize what the visitor sees', 'apexpress-performance' ), __( 'ApexPress complements WordPress Core loading attributes rather than replacing them globally.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'lazy_images', __( 'Lazy-load non-first attachment images', 'apexpress-performance' ), $s['lazy_images'], __( 'Adds lazy/async attributes after the first attachment image.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'lazy_iframes', __( 'Lazy-load content iframes', 'apexpress-performance' ), $s['lazy_iframes'], __( 'Adds loading=lazy only when an iframe does not already define a loading strategy.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'first_image_high_priority', __( 'Boost first attachment image', 'apexpress-performance' ), $s['first_image_high_priority'], __( 'Optional heuristic for hero-heavy sites. Keep off if WordPress Core already selects the correct LCP image.', 'apexpress-performance' ) ); ?>
				<div class="apx-inline-status"><span class="apx-status <?php echo wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'ok' : 'warn'; ?>"></span><div><strong><?php esc_html_e( 'Editor capability', 'apexpress-performance' ); ?></strong><small><?php echo esc_html( wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ? 'WebP + AVIF' : ( wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'WebP supported' : 'Modern formats unavailable' ) ); ?></small></div></div>
			</div>
		</div>
		<?php $this->settings_close(); ?>
		<?php if ( $failed ) : ?>
			<p><?php echo esc_html( sprintf( _n( '%s image job reached its retry limit. Queue the media library to retry it.', '%s image jobs reached their retry limit. Queue the media library to retry them.', $failed, 'apexpress-performance' ), number_format_i18n( $failed ) ) ); ?></p>
		<?php endif; ?>
		<div class="apx-actions apx-section-gap"><?php $this->action_form( 'apexpress_optimize_images', __( 'Queue media library optimization', 'apexpress-performance' ), 'primary' ); ?></div>
		<?php
	}

	private function render_wordpress( array $s ): void {
		$this->settings_open( 'wordpress' );
		?>
		<div class="apx-grid apx-grid-2">
			<div class="apx-card">
				<?php $this->card_title( 'WORDPRESS BLOAT', __( 'Remove optional frontend work', 'apexpress-performance' ), __( 'Each switch targets a narrow WordPress feature and avoids deleting content or settings.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'remove_emojis', __( 'Remove legacy emoji assets', 'apexpress-performance' ), $s['remove_emojis'], '' ); ?>
				<?php $this->toggle( 'remove_embeds', __( 'Remove WordPress embed script/discovery', 'apexpress-performance' ), $s['remove_embeds'], __( 'Do not enable if your site relies on WordPress oEmbed discovery/hosting.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'remove_rsd', __( 'Remove RSD link', 'apexpress-performance' ), $s['remove_rsd'], '' ); ?>
				<?php $this->toggle( 'remove_wlw', __( 'Remove WLW manifest link', 'apexpress-performance' ), $s['remove_wlw'], '' ); ?>
				<?php $this->toggle( 'remove_shortlink', __( 'Remove shortlink headers', 'apexpress-performance' ), $s['remove_shortlink'], '' ); ?>
				<?php $this->toggle( 'disable_dashicons_guests', __( 'Remove Dashicons for guests', 'apexpress-performance' ), $s['disable_dashicons_guests'], __( 'Leave off if the theme or a frontend plugin uses Dashicons.', 'apexpress-performance' ) ); ?>
			</div>
			<div class="apx-card">
				<?php $this->card_title( 'RUNTIME', __( 'Heartbeat & commerce safety', 'apexpress-performance' ), __( 'Reduce recurring browser traffic without blindly disabling critical workflows.', 'apexpress-performance' ) ); ?>
				<div class="apx-field"><label for="heartbeat_mode"><?php esc_html_e( 'Heartbeat mode', 'apexpress-performance' ); ?></label><select id="heartbeat_mode" name="settings[heartbeat_mode]"><option value="default" <?php selected( $s['heartbeat_mode'], 'default' ); ?>><?php esc_html_e( 'WordPress default', 'apexpress-performance' ); ?></option><option value="reduce" <?php selected( $s['heartbeat_mode'], 'reduce' ); ?>><?php esc_html_e( 'Reduce frequency', 'apexpress-performance' ); ?></option><option value="disable" <?php selected( $s['heartbeat_mode'], 'disable' ); ?>><?php esc_html_e( 'Disable (advanced)', 'apexpress-performance' ); ?></option></select></div>
				<?php $this->number( 'heartbeat_interval', __( 'Heartbeat interval (seconds)', 'apexpress-performance' ), $s['heartbeat_interval'], 15, 120 ); ?>
				<?php $this->toggle( 'woocommerce_safe_mode', __( 'WooCommerce safe mode', 'apexpress-performance' ), $s['woocommerce_safe_mode'], __( 'Bypasses Cart, Checkout, My Account, session cookies, add-to-cart, WC API and Store API contexts.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'woocommerce_unload_nonshop', __( 'Unload common WooCommerce assets off-store', 'apexpress-performance' ), $s['woocommerce_unload_nonshop'], __( 'Advanced: themes/plugins may intentionally use WooCommerce components outside store templates.', 'apexpress-performance' ) ); ?>
			</div>
		</div>
		<?php $this->settings_close(); ?>
		<?php
	}

	private function render_database(): void {
		$db = ApexPress_Database::analyze();
		?>
		<div class="apx-grid apx-grid-2">
			<div class="apx-card">
				<?php $this->card_title( 'AUTOLOAD', __( 'Options loaded on every request', 'apexpress-performance' ), __( 'Large autoload payloads increase PHP/database work before the page can render.', 'apexpress-performance' ) ); ?>
				<div class="apx-big-number"><?php echo esc_html( size_format( (int) $db['autoload_bytes'] ) ); ?></div>
				<div class="apx-table-wrap"><table class="apx-table"><thead><tr><th><?php esc_html_e( 'Option', 'apexpress-performance' ); ?></th><th><?php esc_html_e( 'Size', 'apexpress-performance' ); ?></th></tr></thead><tbody><?php foreach ( array_slice( $db['autoload_top'], 0, 12 ) as $row ) : ?><tr><td><code><?php echo esc_html( $row['option_name'] ); ?></code></td><td><?php echo esc_html( size_format( (int) $row['bytes'] ) ); ?></td></tr><?php endforeach; ?></tbody></table></div>
			</div>
			<div class="apx-card">
				<?php $this->card_title( 'SAFE CLEANUP', __( 'Reclaim stale database rows', 'apexpress-performance' ), __( 'Cleanup runs only after explicit confirmation and creates an encrypted snapshot of selected row types before deletion.', 'apexpress-performance' ) ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="apx-cleanup-form" data-apx-confirm="<?php esc_attr_e( 'An encrypted backup snapshot will be created, then only the snapshotted rows will be deleted. Continue?', 'apexpress-performance' ); ?>">
					<input type="hidden" name="action" value="apexpress_database_cleanup"><?php wp_nonce_field( 'apexpress_database_cleanup', '_apexpress_nonce' ); ?>
					<?php foreach ( $db['cleanup_counts'] as $type => $count ) : ?><label class="apx-clean-row"><input type="checkbox" name="types[]" value="<?php echo esc_attr( $type ); ?>"><span><?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?></span><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong></label><?php endforeach; ?>
					<button class="button button-primary apx-button-danger" type="submit"><?php esc_html_e( 'Encrypt snapshot & clean selected', 'apexpress-performance' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_monitoring( array $s ): void {
		$rum = ApexPress_RUM::summary( 30 );
		$this->settings_open( 'monitoring' );
		?>
		<div class="apx-grid apx-grid-2">
			<div class="apx-card">
				<?php $this->card_title( 'REAL USER MONITORING', __( 'Measure field performance', 'apexpress-performance' ), __( 'Samples anonymous page-performance signals and aggregates them by day, path hash, device and metric. No IP address is stored.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'rum_enabled', __( 'Enable RUM', 'apexpress-performance' ), $s['rum_enabled'], '' ); ?>
				<?php $this->number( 'rum_sample_rate', __( 'Sample rate (%)', 'apexpress-performance' ), $s['rum_sample_rate'], 1, 100 ); ?>
				<?php $this->number( 'rum_retention_days', __( 'Retention (days)', 'apexpress-performance' ), $s['rum_retention_days'], 1, 365 ); ?>
				<?php $this->toggle( 'admin_toolbar_metrics', __( 'Admin toolbar profiler', 'apexpress-performance' ), $s['admin_toolbar_metrics'], __( 'Shows request time, database query count and peak memory to administrators on the frontend.', 'apexpress-performance' ) ); ?>
			</div>
			<div class="apx-card">
				<?php $this->card_title( 'FIELD DATA', __( '30-day aggregates', 'apexpress-performance' ), __( 'Averages are diagnostic signals; percentile-based external analytics can be layered on through the REST API.', 'apexpress-performance' ) ); ?>
				<?php if ( empty( $rum ) ) : ?><div class="apx-empty"><p><?php esc_html_e( 'No RUM samples yet.', 'apexpress-performance' ); ?></p></div><?php else : ?><div class="apx-table-wrap"><table class="apx-table"><thead><tr><th>Metric</th><th>Device</th><th>Average</th><th>Min</th><th>Max</th><th>N</th></tr></thead><tbody><?php foreach ( $rum as $row ) : ?><tr><td><?php echo esc_html( strtoupper( $row['metric'] ) ); ?></td><td><?php echo esc_html( ucfirst( $row['device'] ) ); ?></td><td><?php echo esc_html( $this->format_rum_value( $row['metric'], (float) $row['average'] ) ); ?></td><td><?php echo esc_html( $this->format_rum_value( $row['metric'], (float) $row['minimum'] ) ); ?></td><td><?php echo esc_html( $this->format_rum_value( $row['metric'], (float) $row['maximum'] ) ); ?></td><td><?php echo esc_html( $row['samples'] ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
			</div>
		</div>
		<?php $this->settings_close(); ?>
		<?php
	}

	private function render_tools( array $s ): void {
		$logs = array_reverse( ApexPress_Logger::recent() );
		$dropin = ApexPress_Cache::dropin_status();
		$this->settings_open( 'tools' );
		?>
		<div class="apx-grid apx-grid-2">
			<div class="apx-card">
				<?php $this->card_title( 'DEVELOPER', __( 'Diagnostics & lifecycle', 'apexpress-performance' ), __( 'Operational controls intended for staging, development and advanced administration.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'debug_logging', __( 'Debug logging', 'apexpress-performance' ), $s['debug_logging'], __( 'Stores the latest 100 structured ApexPress events in the WordPress options table.', 'apexpress-performance' ) ); ?>
				<?php $this->toggle( 'delete_data_on_uninstall', __( 'Delete plugin data on uninstall', 'apexpress-performance' ), $s['delete_data_on_uninstall'], __( 'When enabled, uninstall removes ApexPress settings, queues, RUM table and cache files.', 'apexpress-performance' ) ); ?>
				<div class="apx-inline-status"><span class="apx-status <?php echo esc_attr( ! empty( $dropin['owned'] ) && ! empty( $dropin['wp_cache'] ) ? 'ok' : 'warn' ); ?>"></span><div><strong><?php esc_html_e( 'Drop-in status', 'apexpress-performance' ); ?></strong><small><?php echo esc_html( wp_json_encode( $dropin ) ); ?></small></div></div>
				<div class="apx-code-block"><code>wp apexpress status<br>wp apexpress purge<br>wp apexpress warm<br>wp apexpress images<br>wp apexpress database</code></div>
			</div>
			<div class="apx-card">
				<?php $this->card_title( 'SERVER DELIVERY', __( 'Browser cache & compression', 'apexpress-performance' ), __( 'Apache rules can be installed through WordPress markers after an encrypted backup. NGINX configuration is generated for manual server deployment.', 'apexpress-performance' ) ); ?>
				<p><strong><?php esc_html_e( 'Detected server:', 'apexpress-performance' ); ?></strong> <?php echo esc_html( ApexPress_Compatibility::server() ); ?></p>
				<div class="apx-code-block"><code><?php echo esc_html( ApexPress_Server_Config::nginx_rules() ); ?></code></div>
				<div class="apx-actions apx-section-gap"><?php $this->action_form( 'apexpress_install_apache_rules', __( 'Install Apache rules', 'apexpress-performance' ), 'primary' ); ?><?php $this->action_form( 'apexpress_remove_apache_rules', __( 'Remove Apache rules', 'apexpress-performance' ), 'secondary' ); ?></div>
			</div>
			<div class="apx-card">
				<?php $this->card_title( 'EVENT LOG', __( 'Recent ApexPress activity', 'apexpress-performance' ), __( 'Sensitive request bodies, credentials and IP addresses are not logged.', 'apexpress-performance' ) ); ?>
				<div class="apx-log"><?php if ( empty( $logs ) ) : ?><div class="apx-empty"><p><?php esc_html_e( 'No events recorded.', 'apexpress-performance' ); ?></p></div><?php else : ?><?php foreach ( array_slice( $logs, 0, 20 ) as $row ) : ?><div class="apx-log-row"><time><?php echo esc_html( $row['time'] ); ?></time><span class="apx-log-level"><?php echo esc_html( strtoupper( $row['level'] ) ); ?></span><p><?php echo esc_html( $row['message'] ); ?></p></div><?php endforeach; ?><?php endif; ?></div>
			</div>
		</div>
		<?php $this->settings_close(); ?>
		<div class="apx-actions apx-section-gap"><?php $this->action_form( 'apexpress_clear_log', __( 'Clear event log', 'apexpress-performance' ), 'secondary' ); ?></div>
		<?php
	}

	private function settings_open( string $tab ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="apx-settings-form">
			<input type="hidden" name="action" value="apexpress_save_settings"><input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>"><?php wp_nonce_field( 'apexpress_save_settings', '_apexpress_nonce' ); ?>
		<?php
	}

	private function settings_close(): void {
		?><div class="apx-savebar"><div><strong><?php esc_html_e( 'Safe configuration', 'apexpress-performance' ); ?></strong><span><?php esc_html_e( 'Changes take effect after validation and cache configuration is refreshed.', 'apexpress-performance' ); ?></span></div><button class="button button-primary" type="submit"><?php esc_html_e( 'Save changes', 'apexpress-performance' ); ?></button></div></form><?php
	}

	private function card_title( string $kicker, string $title, string $description ): void {
		?><div class="apx-card-head"><div><span class="apx-kicker"><?php echo esc_html( $kicker ); ?></span><h2><?php echo esc_html( $title ); ?></h2><?php if ( $description ) : ?><p><?php echo esc_html( $description ); ?></p><?php endif; ?></div></div><?php
	}

	private function toggle( string $name, string $label, mixed $value, string $help ): void {
		$id = 'apx_' . $name;
		?><div class="apx-setting"><div><label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><?php if ( $help ) : ?><p><?php echo esc_html( $help ); ?></p><?php endif; ?></div><label class="apx-switch"><input type="hidden" name="settings[<?php echo esc_attr( $name ); ?>]" value="0"><input id="<?php echo esc_attr( $id ); ?>" type="checkbox" name="settings[<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( (bool) $value ); ?>><span aria-hidden="true"></span></label></div><?php
	}

	private function number( string $name, string $label, mixed $value, int $min, int $max ): void {
		?><div class="apx-field"><label for="apx_<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label><input id="apx_<?php echo esc_attr( $name ); ?>" type="number" name="settings[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>"></div><?php
	}

	private function textarea( string $name, string $label, mixed $value, string $help ): void {
		?><div class="apx-field"><label for="apx_<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label><textarea id="apx_<?php echo esc_attr( $name ); ?>" name="settings[<?php echo esc_attr( $name ); ?>]" rows="5" spellcheck="false"><?php echo esc_textarea( (string) $value ); ?></textarea><?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?></div><?php
	}

	private function text( string $name, string $label, mixed $value, string $placeholder = '' ): void {
		?><div class="apx-field"><label for="apx_<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label><input id="apx_<?php echo esc_attr( $name ); ?>" type="text" name="settings[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"></div><?php
	}

	private function metric_card( string $label, string $value, string $help ): void {
		?><div class="apx-metric"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ); ?></strong><small><?php echo esc_html( $help ); ?></small></div><?php
	}

	private function action_form( string $action, string $label, string $style ): void {
		?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>"><?php wp_nonce_field( $action, '_apexpress_nonce' ); ?><button type="submit" class="button <?php echo 'primary' === $style ? 'button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button></form><?php
	}

	public function save_settings(): void {
		ApexPress_Security::require_admin( 'apexpress_save_settings' );
		$settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		ApexPress_Settings::update( $settings );
		( new ApexPress_Cache() )->purge_all();
		$this->notice_redirect( true, __( 'Settings saved and page cache refreshed.', 'apexpress-performance' ), sanitize_key( wp_unslash( $_POST['tab'] ?? 'dashboard' ) ) );
	}

	public function purge_cache(): void {
		ApexPress_Security::require_admin( 'apexpress_purge_cache' );
		( new ApexPress_Cache() )->purge_all();
		$this->notice_redirect( true, __( 'Page cache purged.', 'apexpress-performance' ), 'cache' );
	}

	public function warm_cache(): void {
		ApexPress_Security::require_admin( 'apexpress_warm_cache' );
		$count = ApexPress_Cache_Warmer::seed_site();
		$this->notice_redirect( true, sprintf( __( '%d URLs queued for controlled cache warming.', 'apexpress-performance' ), $count ), 'cache' );
	}

	public function optimize_images(): void {
		ApexPress_Security::require_admin( 'apexpress_optimize_images' );
		$count = ApexPress_Image_Optimizer::enqueue_all();
		$this->notice_redirect( true, sprintf( __( '%d media images queued for optimization.', 'apexpress-performance' ), $count ), 'media' );
	}

	public function database_cleanup(): void {
		ApexPress_Security::require_admin( 'apexpress_database_cleanup' );
		$types  = isset( $_POST['types'] ) && is_array( $_POST['types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['types'] ) ) : array();
		$result = ApexPress_Database::cleanup( $types );
		$message = ! empty( $result['ok'] ) ? __( 'Database cleanup completed and an encrypted pre-cleanup snapshot was written.', 'apexpress-performance' ) : (string) ( $result['message'] ?? __( 'Database cleanup failed.', 'apexpress-performance' ) );
		$this->notice_redirect( ! empty( $result['ok'] ), $message, 'database' );
	}

	public function install_dropin(): void {
		ApexPress_Security::require_admin( 'apexpress_install_dropin' );
		$result = ApexPress_Cache::install_dropin();
		$this->notice_redirect( ! empty( $result['ok'] ), (string) $result['message'], 'cache' );
	}

	public function purge_cloudflare(): void {
		ApexPress_Security::require_admin( 'apexpress_purge_cloudflare' );
		$result = ApexPress_Cloudflare::purge_everything();
		$this->notice_redirect( ! empty( $result['ok'] ), (string) $result['message'], 'cache' );
	}

	public function install_apache_rules(): void {
		ApexPress_Security::require_admin( 'apexpress_install_apache_rules' );
		$result = ApexPress_Server_Config::install_apache();
		$this->notice_redirect( ! empty( $result['ok'] ), (string) $result['message'], 'tools' );
	}

	public function remove_apache_rules(): void {
		ApexPress_Security::require_admin( 'apexpress_remove_apache_rules' );
		$result = ApexPress_Server_Config::remove_apache();
		$this->notice_redirect( ! empty( $result['ok'] ), (string) $result['message'], 'tools' );
	}

	public function clear_log(): void {
		ApexPress_Security::require_admin( 'apexpress_clear_log' );
		ApexPress_Logger::clear();
		$this->notice_redirect( true, __( 'Event log cleared.', 'apexpress-performance' ), 'tools' );
	}

	private function notice_redirect( bool $ok, string $message, string $tab ): never {
		set_transient( 'apexpress_notice_' . get_current_user_id(), array( 'ok' => $ok, 'message' => $message ), 60 );
		$url = add_query_arg( array( 'page' => self::PAGE, 'tab' => $tab ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function format_rum_value( string $metric, float $value ): string {
		return 'cls' === $metric ? number_format_i18n( $value, 3 ) : number_format_i18n( $value, 0 ) . ' ms';
	}
}
