=== ApexPress Performance ===
Contributors: ildrm
Tags: performance, cache, optimization, webp, avif, woocommerce, core web vitals
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: MIT

A modular performance suite for WordPress: safe page caching, cache warming, frontend optimization, image compression and modern formats, WooCommerce safeguards, RUM, database diagnostics, CDN rewriting, Speculation Rules, REST and WP-CLI.

== Description ==

ApexPress Performance is designed around safe defaults and measurable optimization rather than aggressive one-size-fits-all rewriting.

Core capabilities in this package:

* Anonymous file page cache with atomic writes, TTL, targeted invalidation and cookie/query/path safeguards.
* Optional advanced-cache.php drop-in for pre-WordPress cache serving. It never overwrites a foreign drop-in.
* Bounded cache warming queue processed through WP-Cron.
* Conservative HTML compaction that preserves body text, attributes, scripts and comments.
* Local CSS minification and conservative JavaScript compaction.
* JavaScript defer, explicit async handles, and opt-in third-party interaction delay.
* Queued image compression and resizing to derivative files, leaving WordPress originals and their dimensions intact.
* Typed picture sources for WebP and AVIF when supported by the host, with distinct URLs and shared cached HTML.
* Image/iframe loading attributes with optional first-image priority heuristic.
* WordPress bloat controls and Heartbeat tuning.
* WooCommerce-safe page-cache bypass for Cart, Checkout, My Account, sessions, add-to-cart and API contexts.
* Generic CDN asset URL rewriting, preconnect, preload and WordPress Core Speculation Rules controls.

* Cloudflare targeted purge integration using server-side constants; credentials are never stored in WordPress options.
* Apache browser-cache/compression marker management with encrypted `.htaccess` backup plus generated NGINX rules.
* Privacy-conscious RUM stored as aggregated daily metrics without IP addresses.
* Autoload analysis and explicit database cleanup with an encrypted pre-cleanup snapshot.
* Conflict detection for common performance plugins.
* Admin toolbar request time/query count/peak memory profiler.
* Authenticated REST status/cache endpoints and anonymous bounded RUM ingestion.
* WP-CLI status, purge, warm, image queue and database analysis commands.
* Modern responsive admin dashboard with accessible controls and reduced-motion support.

== Installation ==

1. Upload the `apexpress-performance` directory to `/wp-content/plugins/` or install the ZIP through Plugins > Add New.
2. Activate ApexPress Performance.
3. Open ApexPress in the WordPress admin.
4. Review the system audit and save the desired settings.
5. For the fastest page-cache path, add `define( 'WP_CACHE', true );` to `wp-config.php`, then use Cache > Install early cache. ApexPress will not overwrite another plugin's advanced-cache.php.

Cloudflare purge integration is optional. Configure `APEXPRESS_CF_API_TOKEN` and `APEXPRESS_CF_ZONE_ID` as server-side constants; do not place credentials in plugin settings.

Detailed rollout, QA and feature-status documentation is included under the plugin's `docs/` directory.

== Security ==

Administrative mutations require `manage_options` plus WordPress nonces. Settings are sanitized, output is escaped contextually, cache requests bypass logged-in/session/personalized contexts, local asset paths are constrained to ABSPATH, and database cleanup requires explicit user action and creates an encrypted snapshot first.

ApexPress deliberately does not execute shell commands, `eval()`, arbitrary PHP, or arbitrary filesystem paths.

== Notes on infrastructure-dependent features ==

Some high-end optimization techniques require infrastructure that cannot be safely embedded in an ordinary WordPress plugin alone. Headless-browser critical CSS/unused-CSS extraction, visual regression screenshots, global edge HTML cache, global image-processing workers, statistical A/B testing and multi-site SaaS fleet management should run in an optional external service. The local plugin remains fully usable without such a service and exposes REST/developer surfaces for future integration.

== Changelog ==

= 1.0.0 =
* Initial modular release.
