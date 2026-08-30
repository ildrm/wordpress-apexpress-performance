<?php
/**
 * Safe server configuration helpers for browser caching and compression.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Server_Config {
	private const MARKER = 'ApexPress Performance';

	public static function apache_rules(): array {
		return array(
			'<IfModule mod_expires.c>',
			'ExpiresActive On',
			'ExpiresByType image/jpeg "access plus 1 year"',
			'ExpiresByType image/png "access plus 1 year"',
			'ExpiresByType image/gif "access plus 1 year"',
			'ExpiresByType image/webp "access plus 1 year"',
			'ExpiresByType image/avif "access plus 1 year"',
			'ExpiresByType image/svg+xml "access plus 1 year"',
			'ExpiresByType font/woff2 "access plus 1 year"',
			'ExpiresByType text/css "access plus 1 month"',
			'ExpiresByType application/javascript "access plus 1 month"',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'<FilesMatch "\\.(?:jpg|jpeg|png|gif|webp|avif|svg|woff2)$">',
			'Header set Cache-Control "public, max-age=31536000"',
			'</FilesMatch>',
			'<FilesMatch "\\.(?:css|js)$">',
			'Header set Cache-Control "public, max-age=2592000"',
			'</FilesMatch>',
			'</IfModule>',
			'<IfModule mod_brotli.c>',
			'AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css text/javascript application/javascript application/json application/xml image/svg+xml',
			'</IfModule>',
			'<IfModule mod_deflate.c>',
			'AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript application/javascript application/json application/xml image/svg+xml',
			'</IfModule>',
		);
	}

	public static function nginx_rules(): string {
		return <<<'NGINX'
# ApexPress Performance - add inside the relevant server {} block.
location ~* \.(?:jpg|jpeg|png|gif|webp|avif|svg|woff2)$ {
    expires 1y;
    add_header Cache-Control "public, max-age=31536000" always;
}
location ~* \.(?:css|js)$ {
    expires 30d;
    add_header Cache-Control "public, max-age=2592000" always;
}
gzip on;
gzip_vary on;
gzip_types text/plain text/css application/javascript application/json application/xml image/svg+xml;
# Enable Brotli only when the nginx Brotli module is installed:
# brotli on;
# brotli_types text/plain text/css application/javascript application/json application/xml image/svg+xml;
NGINX;
	}

	public static function install_apache(): array {
		if ( 'Apache' !== ApexPress_Compatibility::server() && ! str_contains( strtolower( ApexPress_Compatibility::server() ), 'apache' ) ) {
			return array( 'ok' => false, 'message' => __( 'Apache was not detected. Use the generated server snippet instead of modifying .htaccess.', 'apexpress-performance' ) );
		}
		$path = ABSPATH . '.htaccess';
		if ( file_exists( $path ) && ! is_writable( $path ) ) {
			return array( 'ok' => false, 'message' => __( '.htaccess is not writable.', 'apexpress-performance' ) );
		}
		if ( file_exists( $path ) ) {
			$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $content || false === ApexPress_Secure_Storage::save_named( 'htaccess-before-server-rules-' . gmdate( 'Ymd-His' ), $content ) ) {
				return array( 'ok' => false, 'message' => __( 'Server rules were not changed because the encrypted .htaccess backup could not be created.', 'apexpress-performance' ) );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$ok = insert_with_markers( $path, self::MARKER, self::apache_rules() );
		return array(
			'ok'      => (bool) $ok,
			'message' => $ok ? __( 'Apache browser-cache and compression rules installed.', 'apexpress-performance' ) : __( 'WordPress could not update .htaccess.', 'apexpress-performance' ),
		);
	}

	public static function remove_apache(): array {
		$path = ABSPATH . '.htaccess';
		if ( ! file_exists( $path ) ) {
			return array( 'ok' => true, 'message' => __( 'No .htaccess file exists.', 'apexpress-performance' ) );
		}
		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content || false === ApexPress_Secure_Storage::save_named( 'htaccess-before-rule-removal-' . gmdate( 'Ymd-His' ), $content ) ) {
			return array( 'ok' => false, 'message' => __( 'Server rules were not changed because the encrypted .htaccess backup could not be created.', 'apexpress-performance' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$ok = insert_with_markers( $path, self::MARKER, array() );
		return array(
			'ok'      => (bool) $ok,
			'message' => $ok ? __( 'ApexPress Apache rules removed.', 'apexpress-performance' ) : __( 'WordPress could not update .htaccess.', 'apexpress-performance' ),
		);
	}
}
