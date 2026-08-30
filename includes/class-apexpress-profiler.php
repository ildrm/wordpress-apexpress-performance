<?php
/**
 * Lightweight request profiler and admin toolbar status.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Profiler {
	public function hooks(): void {
		if ( ApexPress_Settings::get( 'admin_toolbar_metrics' ) ) {
			add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 999 );
		}
	}

	public function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) || is_admin() ) {
			return;
		}
		global $wpdb;
		$elapsed = max( 0, ( microtime( true ) - APEXPRESS_START ) * 1000 );
		$memory  = size_format( memory_get_peak_usage( true ) );
		$queries = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
		$title   = sprintf(
			/* translators: 1: milliseconds, 2: query count, 3: memory. */
			__( 'ApexPress: %1$dms · %2$dQ · %3$s', 'apexpress-performance' ),
			(int) round( $elapsed ),
			$queries,
			$memory
		);
		$bar->add_node(
			array(
				'id'    => 'apexpress-performance',
				'title' => esc_html( $title ),
				'href'  => admin_url( 'admin.php?page=apexpress-performance' ),
			)
		);
	}
}
