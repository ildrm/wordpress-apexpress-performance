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

spl_autoload_register(
	static function ( string $class ): void {
		static $files = array(
			'apexpress_activator'           => 'includes/class-apexpress-activator.php',
			'apexpress_admin'               => 'admin/class-apexpress-admin.php',
			'apexpress_audit'               => 'includes/class-apexpress-audit.php',
			'apexpress_cache'               => 'includes/class-apexpress-cache.php',
			'apexpress_cache_warmer'        => 'includes/class-apexpress-cache-warmer.php',
			'apexpress_cli'                 => 'includes/class-apexpress-cli.php',
			'apexpress_cloudflare'          => 'includes/class-apexpress-cloudflare.php',
			'apexpress_compatibility'       => 'includes/class-apexpress-compatibility.php',
			'apexpress_css'                 => 'includes/class-apexpress-css.php',
			'apexpress_database'            => 'includes/class-apexpress-database.php',
			'apexpress_delivery'            => 'includes/class-apexpress-delivery.php',
			'apexpress_frontend_optimizer'  => 'includes/class-apexpress-frontend-optimizer.php',
			'apexpress_image_optimizer'     => 'includes/class-apexpress-image-optimizer.php',
			'apexpress_image_queue'         => 'includes/class-apexpress-image-queue.php',
			'apexpress_logger'              => 'includes/class-apexpress-logger.php',
			'apexpress_plugin'              => 'includes/class-apexpress-plugin.php',
			'apexpress_profiler'            => 'includes/class-apexpress-profiler.php',
			'apexpress_rest'                => 'includes/class-apexpress-rest.php',
			'apexpress_rum'                 => 'includes/class-apexpress-rum.php',
			'apexpress_secure_storage'      => 'includes/class-apexpress-secure-storage.php',
			'apexpress_security'            => 'includes/class-apexpress-security.php',
			'apexpress_server_config'       => 'includes/class-apexpress-server-config.php',
			'apexpress_settings'            => 'includes/class-apexpress-settings.php',
			'apexpress_woocommerce'         => 'includes/class-apexpress-woocommerce.php',
			'apexpress_wordpress_optimizer' => 'includes/class-apexpress-wordpress-optimizer.php',
		);
		$key = strtolower( $class );
		if ( isset( $files[ $key ] ) ) {
			require_once APEXPRESS_DIR . $files[ $key ];
		}
	}
);

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
