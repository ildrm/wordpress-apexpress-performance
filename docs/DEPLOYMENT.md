# Deployment and Safe Rollout

## Recommended rollout sequence

1. Install on staging first.
2. Activate ApexPress and inspect **Dashboard → System Audit**.
3. Keep the default conservative profile initially. JavaScript defer/delay, CSS minification, AVIF, CDN rewriting and WooCommerce asset unloading are not aggressively enabled by default.
4. Browse representative page templates while logged out: home, post, archive, search, 404, forms and every custom post type.
5. On WooCommerce sites explicitly test product variations, add-to-cart, Cart, Checkout, My Account, payment return/callback flows and Store API interactions.
6. Enable the early cache only after adding `define( 'WP_CACHE', true );` to `wp-config.php`. ApexPress will refuse to replace a foreign `advanced-cache.php`.
7. Warm the cache through the bounded queue.
8. Enable frontend transformations one class at a time and retest dynamic behavior.
9. Enable bulk image optimization only after confirming encrypted backup creation succeeds on the host.
10. Apply Apache rules only if Apache is detected; for NGINX, copy the generated snippet into server configuration and validate/reload NGINX outside WordPress.

## Cloudflare

Set credentials outside the database, preferably in `wp-config.php` or an environment-to-constant bridge:

```php
define( 'APEXPRESS_CF_API_TOKEN', 'YOUR_SCOPED_TOKEN' );
define( 'APEXPRESS_CF_ZONE_ID', 'YOUR_32_CHARACTER_ZONE_ID' );
```

Use a narrowly scoped token with only the cache-purge permission required for the target zone. The plugin never renders the token into HTML/JavaScript and never stores it in the ApexPress settings option.

## Image backups

Operational backups use authenticated encryption (Sodium Secretbox when available, otherwise AES-256-GCM through OpenSSL). Their key is derived from the WordPress authentication salt. **Changing WordPress salts invalidates the ability to decrypt backups created under the old salts.** Restore or externally preserve required backups before rotating salts.

## Cache directory

Runtime data is written under:

`wp-content/cache/apexpress/`

The plugin owns only its own subtree. Uninstall removes it only when the user has explicitly enabled **Delete plugin data on uninstall**.

## Database cleanup

Cleanup is intentionally bounded per run. ApexPress selects rows first, creates an encrypted snapshot of exactly those selected records and related data, and only then deletes the selected rows. If backup creation fails, deletion does not begin.
