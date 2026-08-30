<?php
/**
 * Media compression, resizing, modern formats and restore support.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Image_Optimizer {
	private const QUEUE = 'apexpress_image_queue';
	private const HOOK  = 'apexpress_process_image_queue';

	public function hooks(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'optimize_on_upload' ), 50, 2 );
		add_action( self::HOOK, array( $this, 'process_queue' ) );
	}

	public function optimize_on_upload( array $metadata, int $attachment_id ): array {
		if ( ! ApexPress_Settings::get( 'image_optimize_uploads' ) ) {
			return $metadata;
		}
		$this->optimize_attachment( $attachment_id, $metadata );
		return $metadata;
	}

	public static function enqueue_all(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
			)
		);
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();
		$queue = array_values( array_unique( array_merge( $queue, array_map( 'absint', $ids ) ) ) );
		update_option( self::QUEUE, array_slice( $queue, 0, 5000 ), false );
		return count( $ids );
	}

	public function process_queue(): void {
		$queue = get_option( self::QUEUE, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}
		$batch = array_splice( $queue, 0, 3 );
		update_option( self::QUEUE, $queue, false );
		foreach ( $batch as $attachment_id ) {
			$metadata = wp_get_attachment_metadata( (int) $attachment_id );
			if ( is_array( $metadata ) ) {
				$this->optimize_attachment( (int) $attachment_id, $metadata );
			}
		}
	}

	public function optimize_attachment( int $attachment_id, array $metadata = array() ): array {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_file( $file ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return array( 'ok' => false, 'message' => 'Not an optimizable image.' );
		}
		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ), true ) ) {
			return array( 'ok' => false, 'message' => 'Unsupported image type.' );
		}
		if ( ApexPress_Settings::get( 'image_backup_originals' ) && ! $this->backup_file( $file ) ) {
			return array( 'ok' => false, 'message' => __( 'Image optimization was cancelled because the encrypted original backup could not be created.', 'apexpress-performance' ) );
		}
		$quality   = (int) ApexPress_Settings::get( 'image_quality', 82 );
		$max_width = (int) ApexPress_Settings::get( 'image_max_width', 2560 );
		$editor    = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return array( 'ok' => false, 'message' => $editor->get_error_message() );
		}
		$size = $editor->get_size();
		if ( ! empty( $size['width'] ) && $size['width'] > $max_width ) {
			$editor->resize( $max_width, null, false );
		}
		$editor->set_quality( $quality );
		$saved = $editor->save( $file );
		if ( is_wp_error( $saved ) ) {
			return array( 'ok' => false, 'message' => $saved->get_error_message() );
		}

		$files = array( $file );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $metadata['sizes'] as $data ) {
				if ( ! empty( $data['file'] ) ) {
					$files[] = trailingslashit( $dir ) . $data['file'];
				}
			}
		}
		foreach ( array_unique( $files ) as $image_file ) {
			$this->optimize_variant( $image_file, $quality );
			$this->generate_modern_formats( $image_file, $quality );
		}
		update_post_meta( $attachment_id, '_apexpress_optimized_at', time() );
		return array( 'ok' => true, 'message' => 'Optimized.' );
	}

	private function optimize_variant( string $file, int $quality ): void {
		if ( ! is_file( $file ) ) {
			return;
		}
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return;
		}
		$editor->set_quality( $quality );
		$editor->save( $file );
	}

	private function generate_modern_formats( string $file, int $quality ): void {
		$targets = array();
		if ( ApexPress_Settings::get( 'image_generate_webp' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$targets['image/webp'] = preg_replace( '/\.[^.]+$/', '.webp', $file );
		}
		if ( ApexPress_Settings::get( 'image_generate_avif' ) && wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			$targets['image/avif'] = preg_replace( '/\.[^.]+$/', '.avif', $file );
		}
		foreach ( $targets as $mime => $target ) {
			if ( ! $target || ( is_file( $target ) && filemtime( $target ) >= filemtime( $file ) ) ) {
				continue;
			}
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) {
				continue;
			}
			$editor->set_quality( $quality );
			$editor->save( $target, $mime );
		}
	}

	private function backup_file( string $file ): bool {
		if ( ! ApexPress_Settings::get( 'image_backup_originals' ) ) {
			return true;
		}
		$uploads = wp_get_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		$real    = realpath( $file );
		if ( ! $base || ! $real || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return false;
		}
		$relative = ltrim( substr( $real, strlen( $base ) ), DIRECTORY_SEPARATOR );
		if ( ApexPress_Secure_Storage::exists_for_key( 'image', $relative ) ) {
			return true;
		}
		$contents = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return false;
		}
		return false !== ApexPress_Secure_Storage::save_for_key( 'image', $relative, $contents );
	}

	public static function restore_attachment( int $attachment_id ): array {
		$file    = get_attached_file( $attachment_id );
		$uploads = wp_get_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		$real    = $file ? realpath( $file ) : false;
		if ( ! $base || ! $real || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return array( 'ok' => false, 'message' => __( 'Original path could not be validated.', 'apexpress-performance' ) );
		}
		$relative = ltrim( substr( $real, strlen( $base ) ), DIRECTORY_SEPARATOR );
		$contents = ApexPress_Secure_Storage::load_for_key( 'image', $relative );
		if ( false === $contents ) {
			return array( 'ok' => false, 'message' => __( 'No readable encrypted image backup was found.', 'apexpress-performance' ) );
		}
		if ( false === file_put_contents( $real, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return array( 'ok' => false, 'message' => __( 'The image could not be restored.', 'apexpress-performance' ) );
		}
		delete_post_meta( $attachment_id, '_apexpress_optimized_at' );
		return array( 'ok' => true, 'message' => __( 'Original image restored.', 'apexpress-performance' ) );
	}

	public static function prefer_modern_format_attributes( array $attr ): array {
		$accept = strtolower( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) );
		$target = str_contains( $accept, 'image/avif' ) ? 'avif' : ( str_contains( $accept, 'image/webp' ) ? 'webp' : '' );
		if ( ! $target || empty( $attr['src'] ) ) {
			return $attr;
		}
		$attr['src'] = self::modern_url_if_exists( (string) $attr['src'], $target );
		if ( ! empty( $attr['srcset'] ) ) {
			$parts = array_map( 'trim', explode( ',', (string) $attr['srcset'] ) );
			foreach ( $parts as &$part ) {
				$segments = preg_split( '/\s+/', $part, 2 );
				if ( ! empty( $segments[0] ) ) {
					$segments[0] = self::modern_url_if_exists( $segments[0], $target );
					$part = implode( ' ', $segments );
				}
			}
			unset( $part );
			$attr['srcset'] = implode( ', ', $parts );
		}
		return $attr;
	}

	private static function modern_url_if_exists( string $url, string $ext ): string {
		$uploads = wp_get_upload_dir();
		if ( ! str_starts_with( $url, $uploads['baseurl'] ) ) {
			return $url;
		}
		$relative = ltrim( substr( $url, strlen( $uploads['baseurl'] ) ), '/' );
		$path     = trailingslashit( $uploads['basedir'] ) . rawurldecode( $relative );
		$modern   = preg_replace( '/\.[^.]+$/', '.' . $ext, $path );
		if ( $modern && is_file( $modern ) ) {
			$modern_rel = ltrim( substr( $modern, strlen( trailingslashit( $uploads['basedir'] ) ) ), '/\\' );
			return trailingslashit( $uploads['baseurl'] ) . str_replace( DIRECTORY_SEPARATOR, '/', $modern_rel );
		}
		return $url;
	}

	public static function queue_size(): int {
		$queue = get_option( self::QUEUE, array() );
		return is_array( $queue ) ? count( $queue ) : 0;
	}
}
