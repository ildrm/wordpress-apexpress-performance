# ApexPress Performance Architecture

## Design goals

1. **Safety before benchmark theater.** Personalized or session-bound responses are not cached. Risky transformations are opt-in or conservative.
2. **Reversible operations.** Image originals are backed up before replacement. Database cleanup writes an encrypted snapshot before deletion.
3. **WordPress-native integration.** Core APIs are preferred: `WP_Image_Editor`, `WP_HTML_Tag_Processor`, Speculation Rules filters, REST, WP-Cron, WP-CLI, capabilities and nonces.
4. **No hidden cloud dependency.** The local plugin performs all implemented local features without calling an ApexPress cloud service.
5. **Extensibility.** Public filters and REST endpoints allow future browser workers, edge providers, reporting services and agency tooling.

## Runtime pipeline

```text
advanced-cache.php (optional, very early)
        |
WordPress bootstrap
        |
template_redirect -100  page-cache read
        |
template_redirect -90   page-cache output capture
        |
template_redirect -80   frontend optimizer output capture
        |
WordPress render
        |
frontend optimizer transforms HTML
        |
page cache atomically persists final optimized HTML
```

This ordering ensures cache files contain optimized HTML, not the unoptimized source response.

## Cache safety

The cache bypasses:

- non-GET/HEAD requests;
- WordPress admin, AJAX, JSON, feeds, robots and trackbacks;
- logged-in users, previews, searches and 404s;
- query strings unless explicitly allowed;
- configurable path patterns;
- `DONOTCACHEPAGE`;
- WordPress login/post-password/comment cookies;
- WooCommerce cart/session cookies;
- WooCommerce Cart, Checkout, My Account, add-to-cart and WC API/Store API contexts;
- responses that emit `Set-Cookie` or private/no-store cache-control headers.

## Storage

Operational cache and backup data lives under:

`wp-content/cache/apexpress/`

Keyed page files are SHA-256-named and written atomically. The optional early-cache drop-in reads only cache metadata/config from this controlled directory.

## RUM privacy model

The browser sends only:

- pathname without query string;
- coarse device class (`mobile` / `desktop`);
- LCP, CLS, INP-like event duration, FCP and TTFB timing signals.

The server classifies paths into coarse template buckets (for example `front-page` or `post:product`) and stores only aggregated daily metrics for those buckets. IP addresses, cookies, form values, account identifiers and referrers are not stored by ApexPress RUM.

## Infrastructure boundary

The following features are intentionally not faked inside PHP because they require an actual browser, edge network or long-running service to be correct:

- browser-rendered critical CSS and unused-CSS extraction;
- visual regression screenshots and pixel comparison;
- browser-level functional replay;
- statistical A/B performance rollout;
- global edge HTML caching and tag purge across providers;
- remote Chromium cache warming;
- fleet-wide SaaS management;
- global machine-learned compatibility intelligence.

The plugin is structured so those capabilities can be attached later without weakening local-site security or making the local optimizer dependent on an external API.
