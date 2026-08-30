# ApexPress Performance

> A modular, security-conscious WordPress performance optimization suite built for fast page delivery, safer frontend optimization, modern image formats, WooCommerce-aware caching, real-user monitoring, database diagnostics, CDN integration, REST automation, and WP-CLI workflows.

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759B.svg)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588A.svg)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)

## Overview

**ApexPress Performance** is a WordPress performance plugin designed around a simple principle:

> **Optimize aggressively only where it is safe, measurable, and reversible.**

Instead of treating every WordPress site as identical, ApexPress separates caching, frontend optimization, media processing, WordPress cleanup, WooCommerce safeguards, delivery/CDN controls, monitoring, database diagnostics, and developer tooling into independent modules.

The plugin works locally without requiring an ApexPress cloud service. Features that genuinely require Chromium workers, an edge network, long-running services, or fleet-wide state are intentionally separated from the standalone plugin rather than being represented by non-functional switches.

---

## Key Goals

- **Fast by default** — anonymous page caching, cache warming, HTML optimization, modern image formats, resource hints, and browser-cache controls.
- **Safe by default** — personalized, authenticated, WooCommerce session, API, preview, search, and sensitive requests are excluded from page caching.
- **Production-oriented** — bounded queues, atomic writes, targeted invalidation, encrypted backups, conservative JavaScript behavior, and reversible destructive operations.
- **WooCommerce-aware** — Cart, Checkout, My Account, sessions, add-to-cart flows, Store API traffic, and related dynamic contexts are protected automatically.
- **Privacy-conscious** — RUM stores aggregated performance metrics without persisting IP addresses, query strings, cookies, referrers, or form values.
- **Developer-friendly** — REST endpoints, WP-CLI commands, modular service classes, structured diagnostics, and documented architecture.
- **Extensible** — designed to support future browser workers, edge caching providers, visual regression testing, agency tooling, and advanced performance intelligence.

---

## Feature Highlights

### 1. Page Cache & Delivery

- Anonymous filesystem page cache.
- SHA-256 cache keys.
- Atomic cache writes.
- Configurable TTL.
- Optional mobile cache variants.
- Query-string caching disabled by default.
- Sensitive query protection when query caching is enabled.
- Path-based cache exclusions.
- Cookie/session-aware bypass rules.
- WooCommerce-aware bypass logic.
- `Set-Cookie`, private, and `no-store` response protection.
- Stale cached HTML fallback for bounded origin `5xx` failures.
- Targeted invalidation for posts, archives, and taxonomies.
- Cache warming after content invalidation.
- Bounded WP-Cron cache warming queue.
- Browser cache headers.
- Generic CDN URL rewriting for local static assets.
- WordPress Core Speculation Rules integration.
- Preconnect, DNS-prefetch, and preload controls.
- Cloudflare targeted URL purge integration.
- Explicit Cloudflare full-cache purge support.
- Apache browser-cache/compression rule management.
- Generated NGINX configuration guidance.

### 2. Optional Early Page Cache

ApexPress includes an optional `advanced-cache.php` drop-in for serving eligible cached pages before the normal WordPress bootstrap completes.

Safety behavior includes:

- Foreign `advanced-cache.php` files are never overwritten silently.
- Canonical-host validation prevents arbitrary Host-header cache namespaces.
- `HEAD` requests return headers without replaying the HTML body.
- Authenticated, personalized, query-sensitive, and WooCommerce session traffic is bypassed.
- The drop-in is removed on uninstall only when ApexPress can verify ownership.

For the early cache path, WordPress must have:

```php
define( 'WP_CACHE', true );
```

in `wp-config.php`.

### 3. HTML, CSS & JavaScript Optimization

#### HTML

- Comment removal where safe.
- Whitespace minification.
- Protected handling for:
  - `<script>`
  - `<style>`
  - `<pre>`
  - `<textarea>`
- Preservation of WordPress block comments.
- Preservation of meaningful inter-element whitespace.

#### CSS

- Local CSS minification cache.
- Opt-in behavior for safer staged rollout.

#### JavaScript

- Conservative JavaScript compaction.
- Optional `defer` engine.
- Explicit async script handles.
- Defer exclusion list.
- Optional third-party script delay.
- Interaction/timeout-oriented delay strategy.
- Configurable third-party patterns.

JavaScript defer is deliberately **disabled by default** because dependency-sensitive script changes can break menus, page builders, forms, carts, checkout flows, and inline dependencies on real-world WordPress sites.

### 4. Images & Media

- Upload-time image optimization.
- Queue-based bulk media optimization.
- Configurable image quality.
- Configurable maximum image width.
- Image resizing through WordPress `WP_Image_Editor`.
- WebP generation when supported by the active image editor.
- Optional AVIF generation when supported by the host.
- Browser `Accept`-aware modern image delivery.
- WordPress attachment `src`/`srcset` integration.
- Encrypted original-image backup before destructive replacement.
- Programmatic original-image restore support.
- Lazy image loading support.
- Lazy iframe loading.
- Image decoding/loading attribute optimization.
- Optional first-image priority heuristic.

### 5. Font & Resource Optimization

- `font-display: swap` completion for inline `@font-face` rules.
- Preconnect origin management.
- DNS-prefetch support.
- Explicit preload resources.
- WordPress Speculation Rules configuration.
- Speculation exclusion patterns.
- CDN asset extension allowlist.

### 6. WordPress Optimization

Controls are available for:

- WordPress emoji assets.
- WordPress embeds.
- RSD link output.
- Windows Live Writer manifest output.
- Shortlink output.
- Dashicons for logged-out visitors.
- Heartbeat behavior.
- Heartbeat interval.

Heartbeat modes:

- Default.
- Reduced frequency.
- Disabled.

ApexPress uses conservative defaults because WordPress autosave, post locking, dashboards, plugins, and editors can depend on Heartbeat.

### 7. WooCommerce Optimization & Safety

WooCommerce is treated as a first-class dynamic application rather than a static page set.

ApexPress automatically protects contexts such as:

- Cart.
- Checkout.
- My Account.
- WooCommerce sessions.
- Add-to-cart requests.
- WC API traffic.
- Store API traffic.
- Personalized WooCommerce cookies.

Additional capabilities:

- WooCommerce-safe cache mode enabled by default.
- Optional unloading of WooCommerce assets outside WooCommerce contexts.
- HPOS compatibility declaration.

> **Recommendation:** keep WooCommerce asset unloading disabled until the complete storefront, cart, checkout, account, variation, payment-return, and integration flows have been tested on staging.

### 8. Database Diagnostics & Cleanup

#### Analysis

- Autoloaded options total size.
- Largest autoloaded option report.
- Revision counts.
- Auto-draft counts.
- Trash counts.
- Spam/trash comment counts.
- Orphaned post-meta counts.

#### Cleanup Safety

Database cleanup is explicit and bounded.

Before destructive cleanup ApexPress:

1. Determines the exact records selected for removal.
2. Creates an authenticated encrypted snapshot.
3. Cancels cleanup if the backup cannot be created.
4. Deletes only the selected records.
5. Uses WordPress deletion APIs for posts/comments so normal cleanup hooks execute.

There is no arbitrary SQL execution interface.

### 9. Real User Monitoring (RUM)

ApexPress can collect sampled real-world browser performance signals including:

- LCP.
- CLS.
- INP-like interaction duration.
- FCP.
- TTFB.

The browser sends only a small bounded payload containing performance measurements, a pathname without query parameters, and a coarse device category.

The server aggregates results into daily buckets.

ApexPress RUM does **not** intentionally persist:

- IP addresses.
- Cookies.
- Query strings.
- Form values.
- Referrers.
- Account identifiers.

Configurable options include:

- Enable/disable RUM.
- Sample rate.
- Retention period.

### 10. Diagnostics & Profiling

- Environment/performance audit.
- PHP/server capability detection.
- Object-cache detection.
- Media format capability checks.
- Performance-plugin conflict detection.
- Administrator toolbar performance metrics.
- Request duration.
- Database query count.
- Peak PHP memory usage.
- Structured bounded event logging.

### 11. CDN & Cloudflare

#### Generic CDN

ApexPress can rewrite eligible local static asset URLs to a configured CDN base URL.

Supported asset extensions are configurable and include common formats such as:

```text
css, js, jpg, jpeg, png, gif, webp, avif, svg, woff, woff2
```

#### Cloudflare

Cloudflare cache purge support is optional.

Credentials are intentionally **not stored in normal WordPress plugin options**. Configure them server-side:

```php
define( 'APEXPRESS_CF_API_TOKEN', 'YOUR_SCOPED_API_TOKEN' );
define( 'APEXPRESS_CF_ZONE_ID', 'YOUR_ZONE_ID' );
```

Use the narrowest possible Cloudflare token scope required for cache purging.

---

## Requirements

| Component | Requirement |
|---|---|
| WordPress | 6.5 or newer |
| PHP | 8.1 or newer |
| WooCommerce | Optional; 8.0+ when used |
| WooCommerce HPOS | Declared compatible |
| WebP | Requires image-editor support on the host |
| AVIF | Requires image-editor support on the host |
| WP-CLI | Optional |
| Cloudflare | Optional |
| Redis / Memcached | Optional external infrastructure |

Plugin version: **1.0.0**

---

## Installation

### WordPress Admin

1. Download the ApexPress Performance ZIP package.
2. Open **WordPress Admin → Plugins → Add Plugin → Upload Plugin**.
3. Upload the ZIP.
4. Install and activate **ApexPress Performance**.
5. Open the ApexPress administration screen.
6. Review the system audit before enabling more aggressive optimization features.

### Manual Installation

Copy the plugin directory to:

```text
/wp-content/plugins/apexpress-performance/
```

Then activate **ApexPress Performance** from the WordPress Plugins screen.

---

## Recommended Production Rollout

Performance optimization can affect JavaScript execution, CSS delivery, checkout behavior, cache correctness, and image assets. A staging-first rollout is strongly recommended.

1. Install ApexPress on staging.
2. Review **Dashboard → System Audit**.
3. Start with the conservative defaults.
4. Test representative page types while logged out.
5. Test forms, search, archives, custom post types, login-sensitive pages, and AJAX functionality.
6. For WooCommerce, test products, variations, add-to-cart, Cart, Checkout, My Account, payment callbacks/returns, and Store API interactions.
7. Enable the early page cache only after normal page caching is verified.
8. Warm the cache.
9. Enable CSS/JavaScript transformations individually.
10. Retest after each risk-sensitive optimization.
11. Enable bulk image optimization only after encrypted backup creation succeeds on the host.
12. Validate Apache/NGINX/CDN behavior separately from normal WordPress functionality.

See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for the full deployment guide.

---

## Default Optimization Profile

The initial configuration intentionally favors safety.

### Enabled by default

- Page cache.
- Cache stale-on-error behavior.
- Browser cache headers.
- Cache preloading.
- HTML minification.
- Lazy iframes.
- Lazy images.
- Upload-time image optimization.
- WebP generation where supported.
- Original image backups.
- `font-display: swap` support.
- Emoji removal.
- RSD removal.
- WLW removal.
- Shortlink removal.
- Reduced Heartbeat frequency.
- WooCommerce-safe mode.
- RUM.
- Administrator toolbar metrics.

### Disabled by default

- Query-string page caching.
- Separate mobile cache variant.
- CSS minification.
- JavaScript minification.
- JavaScript defer.
- Third-party JavaScript delay.
- First-image priority heuristic.
- AVIF generation.
- CDN rewriting.
- Cloudflare purge integration.
- WooCommerce asset unloading outside shop contexts.
- Debug logging.
- Delete-all-plugin-data-on-uninstall.

---

## WP-CLI

ApexPress registers the root command:

```bash
wp apexpress
```

### Environment / performance audit

```bash
wp apexpress status
```

### Purge all ApexPress page cache

```bash
wp apexpress purge
```

### Queue public URLs for cache warming

```bash
wp apexpress warm
```

### Queue media-library images for optimization

```bash
wp apexpress images
```

### Analyze database optimization opportunities

```bash
wp apexpress database
```

---

## REST API

ApexPress registers endpoints under:

```text
/wp-json/apexpress/v1/
```

### Authenticated administrative endpoints

These require a WordPress user with the `manage_options` capability.

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/apexpress/v1/status` | Return audit, RUM summary, and database analysis |
| `POST` | `/apexpress/v1/cache/purge` | Purge ApexPress page cache |
| `POST` | `/apexpress/v1/cache/warm` | Queue cache warming |

### RUM ingestion endpoint

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/apexpress/v1/rum` | Receive a bounded browser performance payload |

The RUM endpoint is public by design because metrics are submitted by anonymous site visitors. Payload size and values are validated server-side before aggregation.

---

## Architecture

ApexPress uses independent service classes instead of one monolithic plugin class.

```text
apexpress-performance/
├── apexpress-performance.php
├── admin/
│   └── class-apexpress-admin.php
├── assets/
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── admin.js
├── dropins/
│   └── advanced-cache.php
├── includes/
│   ├── class-apexpress-activator.php
│   ├── class-apexpress-audit.php
│   ├── class-apexpress-cache-warmer.php
│   ├── class-apexpress-cache.php
│   ├── class-apexpress-cli.php
│   ├── class-apexpress-cloudflare.php
│   ├── class-apexpress-compatibility.php
│   ├── class-apexpress-database.php
│   ├── class-apexpress-delivery.php
│   ├── class-apexpress-frontend-optimizer.php
│   ├── class-apexpress-image-optimizer.php
│   ├── class-apexpress-logger.php
│   ├── class-apexpress-plugin.php
│   ├── class-apexpress-profiler.php
│   ├── class-apexpress-rest.php
│   ├── class-apexpress-rum.php
│   ├── class-apexpress-secure-storage.php
│   ├── class-apexpress-security.php
│   ├── class-apexpress-server-config.php
│   ├── class-apexpress-settings.php
│   ├── class-apexpress-woocommerce.php
│   └── class-apexpress-wordpress-optimizer.php
├── docs/
│   ├── ARCHITECTURE.md
│   ├── DEPLOYMENT.md
│   ├── FEATURE-MATRIX.md
│   ├── QA-CHECKLIST.md
│   └── SECURITY.md
├── README.md
├── readme.txt
└── uninstall.php
```

### Runtime Pipeline

```text
advanced-cache.php (optional early cache)
        ↓
WordPress bootstrap
        ↓
page-cache lookup
        ↓
page-cache output capture
        ↓
frontend optimizer output capture
        ↓
WordPress render
        ↓
HTML/frontend transformations
        ↓
atomic persistence of final optimized HTML
```

The ordering is intentional: the page cache persists the final optimized response rather than caching an unoptimized intermediate representation.

Read [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for additional details.

---

## Security Model

Security is a primary design requirement because performance plugins interact with HTML output, caches, media files, database records, server configuration, and third-party services.

### Administrative Security

State-changing administrative actions require:

- `manage_options` capability checks.
- Purpose-specific WordPress nonces.
- Server-side sanitization.
- Allowlists for constrained values.
- Context-aware escaping.

### Filesystem Security

- Cache file names derive from SHA-256 keys rather than raw user input.
- Local minification resolves paths with `realpath()`.
- Local files must remain beneath `ABSPATH`.
- Image backup paths are derived from validated WordPress upload locations.
- Foreign cache drop-ins are not overwritten automatically.
- Uninstall removes the cache drop-in only when an ApexPress ownership marker is present.

### Cache Safety

ApexPress bypasses page caching for situations including:

- Non-`GET`/`HEAD` requests.
- WordPress admin.
- AJAX.
- JSON/API requests.
- Feeds.
- Logged-in visitors.
- Previews.
- Searches.
- 404 responses.
- Sensitive query strings.
- Configured excluded paths.
- `DONOTCACHEPAGE`.
- Login/post-password/comment cookies.
- WooCommerce session/cart cookies.
- WooCommerce dynamic pages and API flows.
- Responses setting cookies or private/no-store cache headers.

### Database Safety

- Cleanup operations are allowlisted.
- Cleanup is explicit rather than automatic.
- Exact selected records are snapshotted before deletion.
- Snapshots use authenticated encryption.
- Cleanup aborts if backup creation fails.
- No arbitrary SQL execution interface is exposed.

### Dangerous Execution Paths

ApexPress does not intentionally provide facilities for:

- `eval()` execution.
- Arbitrary PHP execution.
- Shell-command execution.
- Arbitrary filesystem paths.
- Arbitrary SQL execution.

See [`docs/SECURITY.md`](docs/SECURITY.md) for the complete security model.

---

## Encrypted Operational Backups

Operational backups are stored under:

```text
wp-content/cache/apexpress/
```

Encryption uses:

1. Sodium Secretbox when available.
2. AES-256-GCM through OpenSSL as the fallback.

The encryption key is derived from the WordPress authentication salt.

> **Important:** rotating WordPress salts can make backups created with the previous salts impossible to decrypt. Restore or externally preserve any required ApexPress backups before rotating salts.

---

## Data Storage & Uninstall

Runtime cache and operational backup data is stored under:

```text
wp-content/cache/apexpress/
```

ApexPress owns only its own subtree.

By default, plugin data is **not** aggressively deleted on uninstall. Full plugin data removal must be explicitly enabled through the **Delete plugin data on uninstall** setting.

---

## Compatibility & Conflict Detection

ApexPress detects common WordPress performance/optimization plugins to reduce the risk of duplicated caching or competing frontend transformations.

If another cache/optimization system is active, review overlapping features before enabling equivalent ApexPress modules.

ApexPress is intended to coexist with external infrastructure when responsibilities are clearly separated—for example:

- Managed-hosting page cache.
- Redis object cache.
- CDN edge cache.
- Cloudflare.
- Server-level Brotli.
- NGINX FastCGI cache.

It does not pretend that a PHP plugin can install or safely manage every server-level service by itself.

---

## Infrastructure-Dependent Capabilities

The standalone plugin can detect or integrate with capabilities already provided by the host, but it does not install infrastructure such as Redis, Memcached, Brotli modules, or an edge network.

Examples:

- **Redis / Memcached** — ApexPress can report external persistent object-cache availability; the server/backend must already exist.
- **WebP / AVIF** — generation occurs only when the active `WP_Image_Editor` supports the requested format.
- **Brotli** — Apache configuration is used only where supported; NGINX configuration must be deployed at the server level.
- **Cloudflare** — API purge requires explicitly configured server-side credentials.

---

## Generation-4 / Optional Cloud Tier

Several advanced performance capabilities cannot be implemented correctly inside an ordinary PHP request lifecycle alone. They require actual browser execution, edge infrastructure, long-running workers, statistical processing, or multi-site state.

The following capabilities are therefore considered future optional infrastructure rather than fake local features:

- Browser-rendered critical CSS generation.
- Full DOM/state-aware unused-CSS extraction.
- Browser-level JavaScript dependency/runtime profiling.
- Screenshot-based visual regression testing.
- Automated functional replay of menus and forms.
- Automated WooCommerce cart/checkout browser testing.
- Statistical A/B performance rollout.
- Automatic candidate promotion based on measured field performance.
- Multi-region Lighthouse/browser benchmarking.
- Multi-provider edge HTML caching and tag purging.
- Remote browser-based cache warming.
- Cloud image-transformation workers.
- Agency/fleet management SaaS.
- Cross-site machine-learned compatibility intelligence.

The local plugin exposes modular boundaries, REST surfaces, and service abstractions so these capabilities can be added later without making basic site optimization dependent on an external service.

For a precise status of each feature, see [`docs/FEATURE-MATRIX.md`](docs/FEATURE-MATRIX.md).

---

## Testing & QA

Before deploying to production, validate at minimum:

- Homepage.
- Posts/pages.
- Archives/taxonomies.
- Search.
- 404 pages.
- Forms.
- Authentication-sensitive pages.
- AJAX functionality.
- REST/API functionality.
- Custom post types.
- Mobile rendering.
- Logged-in vs logged-out behavior.
- Cache hit/miss behavior.
- Purge and cache warming behavior.
- Image backups and restoration.
- Browser console errors after JS optimization.

For WooCommerce also validate:

- Simple products.
- Variable products.
- Variation switching.
- Add to cart.
- Mini cart/cart fragments where used.
- Cart.
- Checkout.
- My Account.
- Coupons.
- Shipping calculations.
- Payment gateways.
- Payment return/callback flows.
- Store API interactions.

A detailed checklist is available in [`docs/QA-CHECKLIST.md`](docs/QA-CHECKLIST.md).

---

## Documentation

| Document | Purpose |
|---|---|
| [`docs/FEATURE-MATRIX.md`](docs/FEATURE-MATRIX.md) | Exact implemented vs infrastructure-dependent feature status |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Runtime architecture and design decisions |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Security boundaries and controls |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Recommended staging/production rollout |
| [`docs/QA-CHECKLIST.md`](docs/QA-CHECKLIST.md) | Production validation checklist |
| [`readme.txt`](readme.txt) | WordPress-style plugin readme |

---

## Development Principles

ApexPress follows these engineering principles:

1. **Safety before benchmark theater.**
2. **Do not cache personalized content.**
3. **Do not silently overwrite another plugin's infrastructure.**
4. **Do not make destructive changes without a recovery path.**
5. **Use WordPress APIs instead of bypassing the platform unnecessarily.**
6. **Do not expose infrastructure credentials in browser-visible configuration.**
7. **Use bounded background queues on ordinary hosting.**
8. **Treat WooCommerce as a dynamic application.**
9. **Keep risky optimizations opt-in until site-specific testing succeeds.**
10. **Do not advertise browser/cloud functionality that the local plugin cannot actually execute.**

---

## Roadmap Direction

Future development can extend ApexPress toward a complete WordPress Performance Intelligence platform with:

- Browser workers.
- Critical/used CSS generation.
- Visual regression testing.
- Automated functional verification.
- Performance regression monitoring.
- Historical RUM percentiles.
- Performance budgets.
- Plugin/theme/hook attribution.
- Advanced query/index advisory.
- Multi-CDN edge caching.
- Browser-based cache warming.
- Automated optimization candidates and rollback.
- Agency management.
- White-label reporting.
- Cross-site compatibility intelligence.

The architectural goal is not simply to expose more optimization switches. The long-term direction is:

```text
Analyze
   ↓
Understand the page
   ↓
Generate a safe optimization strategy
   ↓
Test it
   ↓
Measure the result
   ↓
Deploy only when beneficial
   ↓
Monitor real users
   ↓
Detect regressions
   ↓
Adapt safely
```

---

## Changelog

### 1.0.0

- Initial modular release.
- Page cache and optional early-cache drop-in.
- Cache warming and targeted invalidation.
- HTML/CSS/JavaScript optimization controls.
- Image optimization and modern formats.
- Encrypted media backup support.
- WordPress optimization controls.
- WooCommerce-safe caching.
- CDN and Cloudflare purge integration.
- Database diagnostics and bounded cleanup.
- Privacy-conscious RUM.
- REST API.
- WP-CLI commands.
- Admin performance profiler.
- Security and deployment documentation.

---

## License

ApexPress Performance is licensed under the **GNU General Public License v2.0 or later**.

See: <https://www.gnu.org/licenses/gpl-2.0.html>

---

## Author

**Shahin Ilderemi**

- GitHub: <https://github.com/ildrm>

---

## Important Production Notice

A performance plugin changes how WordPress responses, assets, caches, images, and sometimes server rules behave. No optimizer can guarantee compatibility with every theme, plugin, payment gateway, consent manager, page builder, custom script, reverse proxy, or hosting stack without site-specific validation.

**Use staging, keep recoverable backups, enable higher-risk optimizations incrementally, and execute the QA checklist before production rollout.**
