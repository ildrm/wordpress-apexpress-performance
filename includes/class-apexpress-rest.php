<?php
/**
 * REST API endpoints.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_REST {
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'apexpress/v1',
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'manage_permission' ),
			)
		);
		register_rest_route(
			'apexpress/v1',
			'/cache/purge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'purge' ),
				'permission_callback' => array( $this, 'manage_permission' ),
			)
		);
		register_rest_route(
			'apexpress/v1',
			'/cache/warm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'warm' ),
				'permission_callback' => array( $this, 'manage_permission' ),
			)
		);
		register_rest_route(
			'apexpress/v1',
			'/rum',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rum' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'path' => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
	}

	public function manage_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function status(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'audit'    => ApexPress_Audit::run(),
				'rum'      => ApexPress_RUM::summary( 7 ),
				'database' => ApexPress_Database::analyze(),
			),
			200
		);
	}

	public function purge(): WP_REST_Response {
		( new ApexPress_Cache() )->purge_all();
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public function warm(): WP_REST_Response {
		$count = ApexPress_Cache_Warmer::seed_site();
		return new WP_REST_Response( array( 'ok' => true, 'queued' => $count ), 200 );
	}

	public function rum( WP_REST_Request $request ): WP_REST_Response {
		if ( ! ApexPress_Settings::get( 'rum_enabled' ) ) {
			return new WP_REST_Response( null, 204 );
		}
		$raw = $request->get_body();
		if ( strlen( $raw ) > 4096 ) {
			return new WP_REST_Response( array( 'ok' => false ), 413 );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = $request->get_json_params();
		}
		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$path = isset( $data['path'] ) ? (string) $data['path'] : '/';
		if ( strlen( $path ) > 250 || str_contains( $path, '?' ) ) {
			$data['path'] = (string) wp_parse_url( $path, PHP_URL_PATH );
		}
		$ok = ApexPress_RUM::ingest( $data );
		return new WP_REST_Response( array( 'ok' => $ok ), $ok ? 202 : 500 );
	}
}
