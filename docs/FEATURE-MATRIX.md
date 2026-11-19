# ApexPress Performance — Feature Matrix

This matrix distinguishes **working local features in v1.0.0** from capabilities that need infrastructure outside an ordinary WordPress request lifecycle. No placeholder switch is presented in the UI as a completed feature.

## Working in this package

### Cache and delivery
- Anonymous filesystem page cache with SHA-256 keys, TTL metadata and atomic writes.
- Safe bypass for authenticated visitors, previews, searches, 404s, feeds, AJAX/JSON, non-GET/HEAD requests, configurable paths, personalized cookies and WooCommerce sessions.
- Canonical-host validation to prevent untrusted Host-header cache namespaces.
- Query-string caching is off by default and, when enabled, rejects sensitive query keys, oversized URLs and excessive parameters.
- Optional `advanced-cache.php` drop-in for pre-WordPress cache hits; foreign drop-ins are never overwritten.
- `HEAD` requests return headers without replaying a cached HTML body.
- Stale cached HTML can be served for an origin 5xx response within a bounded stale window.
- Targeted post/archive/taxonomy invalidation plus cache warming of affected URLs.
- Bounded WP-Cron cache warming queue.
- Generic CDN rewriting for local static assets.
- Cloudflare targeted URL purge and explicit purge-everything controls using server-side constants only.
- WordPress Core Speculation Rules configuration and exclusion support on compatible WordPress versions.
- Preconnect, DNS-prefetch and explicit preload controls.
- Apache browser-cache/compression marker management with encrypted pre-change backup.
- NGINX rules generator for manual server deployment.

### Frontend optimization
- HTML comment/whitespace minification with protected script/style/pre/textarea blocks and preserved inter-tag semantic whitespace.
- Local CSS minification cache (opt-in).
- Conservative JavaScript compaction (opt-in; intentionally does not perform unsafe parser-free rewrites).
- Explicit async handles.
- JavaScript defer engine (opt-in and off by default because dependency-sensitive changes require site testing).
- Third-party JavaScript interaction/timeout delay (opt-in).
- Lazy iframe loading.
- Attachment image loading/decoding optimization without overriding an explicit WordPress Core loading decision.
- Optional first-image fetch priority heuristic.
- Inline `@font-face` `font-display: swap` completion.

### Media
- Upload-time and queued bulk image optimization through `WP_Image_Editor`.
- Quality control and maximum-width resizing.
- WebP generation when supported by the host image editor.
- AVIF generation when supported and enabled.
- Browser Accept-based modern format delivery for WordPress attachment `src`/`srcset`.
- Reversible derivatives with optional encrypted original-image backups.
- Programmatic restore API for derivatives and readable backups from earlier versions.
- Bounded media optimization queue.

### WordPress and WooCommerce
- Emoji, embeds, RSD, WLW, shortlink and guest Dashicons controls.
- Heartbeat default/reduced/disabled controls with configurable interval.
- WooCommerce cache-safe mode for Cart, Checkout, My Account, sessions, add-to-cart and Woo API/Store API traffic.
- Optional WooCommerce asset unloading outside WooCommerce contexts (off by default).
- WooCommerce HPOS compatibility declaration.
- Detection of common optimization-plugin conflicts.
- Server/PHP/object-cache/media capability audit.

### Database and diagnostics
- Autoloaded-option byte analysis and largest-option report.
- Counts for revisions, auto drafts, trash, spam/trash comments and orphan postmeta.
- Explicit, bounded database cleanup.
- Encrypted pre-cleanup snapshot; if encryption/backup fails, cleanup is cancelled.
- Post/comment deletion via WordPress APIs so related core cleanup hooks run.
- Selected orphan-meta/transient cleanup only after the selected rows were snapshotted.
- Administrator toolbar request duration, query count and peak-memory profiler.
- Structured bounded event log with sanitized scalar context.

### Monitoring and APIs
- Privacy-conscious sampled RUM for LCP, CLS, INP-like event duration, FCP and TTFB.
- CLS session-window logic and interaction-duration aggregation in the browser.
- Server-side path classification into coarse buckets; raw URLs, query strings, IP addresses, cookies and form values are not persisted.
- Daily aggregate RUM table with bounded dimensions and retention cleanup.
- Authenticated REST status, purge and warm endpoints.
- Public bounded RUM ingestion endpoint.
- WP-CLI status, purge, warm, media queue and database analysis commands.
- Responsive, accessible admin application with modern dashboard, settings cards, status indicators, confirmations and reduced-motion support.

## Detected/integrated but dependent on server services

These can be surfaced or integrated when the service already exists, but ApexPress does not pretend to install infrastructure from PHP:

- Redis/Memcached persistent object caching: WordPress reports whether an external object-cache drop-in/backend is active.
- Brotli: Apache rules enable it only when the module exists; NGINX guidance is generated for administrators.
- AVIF/WebP: generated only when the selected `WP_Image_Editor` reports actual support.
- Cloudflare: purge operations require a valid API token and zone ID configured as server-side constants.

## Optional cloud/browser tier required for a correct implementation

The following planned Generation-4 capabilities require Chromium, an edge network, long-running workers, statistical processing, or fleet-wide state. They are intentionally **not** represented by fake local toggles:

- Browser-rendered critical CSS and full unused-CSS extraction.
- DOM-state-aware JavaScript dependency/runtime profiling across interactions.
- Screenshot visual-regression comparison.
- Automated browser functional replay of menus/forms/cart/checkout.
- Statistical A/B performance rollout and automatic promotion.
- True field percentile analytics across large RUM populations.
- Remote multi-region Lighthouse/browser benchmarking.
- Edge HTML cache/tag purge across multiple CDN vendors.
- Remote adaptive cache warming from multiple regions.
- Cloud image transformation/optimization workers.
- Fleet/agency SaaS dashboard and remote policy deployment.
- Cross-site machine-learned compatibility/conflict intelligence.

The local architecture exposes REST, hooks and modular service boundaries so these can be added without weakening the standalone plugin.
