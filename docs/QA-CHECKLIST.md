# Production QA Checklist

Use this checklist after installation and after every significant theme/plugin update.

## Installation and lifecycle
- Plugin activates without fatal errors on the target PHP/WordPress versions.
- Deactivation removes scheduled ApexPress events without deleting configuration.
- Uninstall retains data unless the explicit delete-on-uninstall setting is enabled.
- A foreign `advanced-cache.php` is detected and left untouched.

## Cache correctness
- Anonymous GET requests become cache hits after the first eligible request.
- Logged-in requests are never served page cache.
- POST/AJAX/REST/search/preview/404 requests bypass page cache.
- Password-protected/comment-author/WooCommerce-session cookies bypass page cache.
- Cart, Checkout and My Account never return cached personalized HTML.
- Page edits invalidate the page and affected archive/taxonomy views.
- `HEAD` returns no HTML body.
- Requests with an unexpected Host header never read/write an ApexPress cache entry.

## Frontend behavior
- Header/navigation works on desktop and mobile.
- Forms submit and validation still works.
- Sliders, tabs, accordions, modals and popups work.
- No new JavaScript console errors appear after enabling defer/delay.
- CSS minification does not alter layout.
- HTML minification does not join text from adjacent inline elements.
- Third-party analytics/marketing behavior matches the selected delay policy.

## Media
- New image uploads complete without error.
- Original image backup is created before destructive optimization.
- WebP/AVIF siblings are created only when supported.
- Responsive image `srcset` remains valid.
- Existing explicit Core loading/fetch-priority decisions are not overwritten unnecessarily.
- Restoration is verified on at least one test attachment before bulk production use.

## WooCommerce
- Simple and variable product pages work.
- Add-to-cart works both synchronously and via AJAX.
- Cart quantities/coupons/totals update correctly.
- Checkout validation, shipping and payment methods work.
- Order placement and return/thank-you pages work.
- My Account is never cached.
- Store API and payment/webhook endpoints are not page cached.

## Database
- Review cleanup counts before selecting categories.
- Confirm encrypted backup storage is writable.
- Run a small cleanup category first.
- Verify posts/comments are deleted through normal WordPress behavior.
- Retain external database backups for production maintenance regardless of plugin snapshots.

## Observability
- RUM data begins appearing only for sampled logged-out traffic.
- No raw query strings, account values, IP addresses or form values are visible in stored RUM aggregates.
- Admin toolbar metrics appear only to administrators when enabled.
- Event log does not contain secrets or request bodies.
