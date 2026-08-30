<?php
/**
 * Plugin Name: ApexPress Performance
 * Plugin URI:  https://github.com/ildrm/wordpress-apexpress-performance
 * Description: A modular WordPress performance optimization suite with intelligent page caching, frontend optimization, media optimization, WooCommerce safeguards, RUM, diagnostics, database tools, and developer APIs.
 * Version:     1.0.0
 * Author:      Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * License:     MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: apexpress-performance
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 10.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'ApexPress Performance requires PHP 8.1 or newer.', 'apexpress-performance' ) . '</p></div>';
		}
	);
	return;
}

define( 'APEXPRESS_VERSION', '1.0.0' );
define( 'APEXPRESS_FILE', __FILE__ );
define( 'APEXPRESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'APEXPRESS_URL', plugin_dir_url( __FILE__ ) );
define( 'APEXPRESS_START', microtime( true ) );

$apexpress_files = array(
	'includes/class-apexpress-logger.php',
	'includes/class-apexpress-settings.php',
	'includes/class-apexpress-security.php',
	'includes/class-apexpress-secure-storage.php',
	'includes/class-apexpress-compatibility.php',
	'includes/class-apexpress-cloudflare.php',
	'includes/class-apexpress-server-config.php',
	'includes/class-apexpress-cache.php',
	'includes/class-apexpress-cache-warmer.php',
	'includes/class-apexpress-frontend-optimizer.php',
	'includes/class-apexpress-delivery.php',
	'includes/class-apexpress-image-optimizer.php',
	'includes/class-apexpress-database.php',
	'includes/class-apexpress-wordpress-optimizer.php',
	'includes/class-apexpress-woocommerce.php',
	'includes/class-apexpress-rum.php',
	'includes/class-apexpress-audit.php',
	'includes/class-apexpress-profiler.php',
	'includes/class-apexpress-rest.php',
	'includes/class-apexpress-cli.php',
	'admin/class-apexpress-admin.php',
	'includes/class-apexpress-activator.php',
	'includes/class-apexpress-plugin.php',
);

foreach ( $apexpress_files as $apexpress_file ) {
	require_once APEXPRESS_DIR . $apexpress_file;
}

register_activation_hook( __FILE__, array( 'ApexPress_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ApexPress_Activator', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

ApexPress_Plugin::instance()->boot();
