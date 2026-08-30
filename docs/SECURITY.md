# Security Model

## Administrative actions

All state-changing admin actions require:

- `manage_options` capability;
- a purpose-specific WordPress nonce;
- server-side sanitization/allowlisting;
- a safe redirect after completion.

## Filesystem

- Cache keys are SHA-256 hashes, not user-controlled file names.
- Local minification resolves files with `realpath()` and requires them to stay beneath `ABSPATH`.
- Image backups are derived only from validated WordPress upload paths.
- The advanced-cache installer refuses to overwrite a foreign drop-in.
- Uninstall deletes the drop-in only when the ApexPress ownership marker is present.

## Page-cache poisoning defenses

ApexPress does not cache authenticated requests, request bodies, most query-string requests, WooCommerce sessions, post-password requests, comment-author requests, or responses that set cookies/private cache headers.

## Database

Cleanup types are allowlisted. The user must explicitly select rows to clean. An authenticated-encryption snapshot is written before cleanup. Post/comment deletion then uses WordPress deletion APIs, and only IDs present in the snapshot plan are removed. No arbitrary SQL interface is exposed.

## RUM

The public RUM endpoint accepts a very small JSON body, validates metric bounds, strips query strings from paths, and stores no IP address or request headers.

## Credentials

Version 1.0.0 stores no third-party CDN or cloud API credentials. Generic CDN rewriting uses a public base URL only. Future provider tokens should be sourced from server-side constants/secrets or encrypted application storage rather than exposed to browser JavaScript.
