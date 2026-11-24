<?php
/**
 * Reversible media derivatives and browser format selection.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Image_Optimizer {
	private const META = '_apexpress_image_variants';
	private const HOOK = 'apexpress_process_image_queue';
	private const FORMATS = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	public function hooks(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'optimize_on_upload' ), 50, 2 );
		add_filter( 'wp_get_attachment_image', array( $this, 'render_variants' ), 20, 2 );
		add_action( 'delete_attachment', array( $this, 'delete_derivatives' ) );
		add_action( self::HOOK, array( $this, 'process_queue' ) );
	}

	public function optimize_on_upload( array $metadata, int $attachment_id ): array {
		if ( ApexPress_Settings::get( 'image_optimize_uploads' )
			&& isset( self::FORMATS[ get_post_mime_type( $attachment_id ) ] )
			&& ApexPress_Image_Queue::ensure() ) {
			ApexPress_Image_Queue::enqueue( $attachment_id );
		}
		return $metadata;
	}

	public static function enqueue_all(): int {
		return ApexPress_Image_Queue::enqueue_all();
	}

	public function process_queue(): void {
		if ( ! ApexPress_Image_Queue::ensure() ) {
			return;
		}
		$changed = false;
		for ( $i = 0; $i < 3; ++$i ) {
			$job = ApexPress_Image_Queue::claim();
			if ( null === $job ) {
				break;
			}
			$id = (int) $job['attachment_id'];
			$metadata = wp_get_attachment_metadata( $id );
			$result = $this->optimize_attachment( $id, is_array( $metadata ) ? $metadata : array(), $job );
			$finished = ApexPress_Image_Queue::finish( $job, ! empty( $result['ok'] ) || ! get_post( $id ) );
			if ( ! $finished || empty( $result['ok'] ) ) {
				ApexPress_Logger::log( 'warning', 'Image optimization job did not finish successfully.', array( 'attachment' => $id, 'attempt' => (int) $job['attempts'] ) );
			}
			$changed = $changed || ! empty( $result['ok'] );
		}
		if ( $changed ) {
			( new ApexPress_Cache() )->purge_all();
		}
	}

	public function optimize_attachment( int $attachment_id, array $metadata = array(), ?array $job = null ): array {
		return self::with_attachment_lock( $attachment_id, function () use ( $attachment_id, $metadata, $job ): array {
			if ( null !== $job && ! ApexPress_Image_Queue::is_current( $job ) ) {
				return array( 'ok' => false, 'message' => 'The image job lease expired.' );
			}
			return $this->build_derivatives( $attachment_id, $metadata );
		} );
	}

	private function build_derivatives( int $attachment_id, array $metadata ): array {
		$source = self::attachment_path( $attachment_id );
		if ( ! $source || ! isset( self::FORMATS[ get_post_mime_type( $attachment_id ) ] ) ) {
			return array( 'ok' => false, 'message' => 'Not an optimizable local image.' );
		}
		$directory = dirname( $source );
		$base = realpath( wp_get_upload_dir()['basedir'] );
		$relative_directory = trailingslashit( ltrim( wp_normalize_path( substr( $directory, strlen( $base ) ) ), '/' ) );
		$files = array( basename( $source ) => $source );
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			$name = $size['file'] ?? '';
			if ( $name && basename( $name ) === $name ) {
				$path = $directory . DIRECTORY_SEPARATOR . $name;
				if ( is_file( $path ) && ! is_link( $path ) ) {
					$files[ $name ] = $path;
				}
			}
		}

		$settings = ApexPress_Settings::get_all();
		$optional_formats = array();
		foreach ( array( 'image/avif' => 'image_generate_avif', 'image/webp' => 'image_generate_webp' ) as $mime => $setting ) {
			if ( ! empty( $settings[ $setting ] ) && wp_image_editor_supports( array( 'mime_type' => $mime ) ) ) {
				$optional_formats[] = $mime;
			}
		}
		$previous = get_post_meta( $attachment_id, self::META, true );
		$backup_key = ltrim( substr( $source, strlen( $base ) ), DIRECTORY_SEPARATOR );
		$legacy = ! is_array( $previous ) && get_post_meta( $attachment_id, '_apexpress_optimized_at', true );
		$legacy_backup = $previous['legacy_backup'] ?? ( $legacy && ApexPress_Secure_Storage::exists_for_key( 'image', $backup_key ) );
		if ( $legacy && ! self::sync_original_metadata( $attachment_id, $source ) ) {
			return array( 'ok' => false, 'message' => 'Could not reconcile dimensions for the existing optimized image.' );
		}
		if ( ! $this->backup_file( $source ) ) {
			return array( 'ok' => false, 'message' => 'The encrypted original image backup could not be created.' );
		}

		$manifest = array( 'directory' => $relative_directory, 'images' => array(), 'legacy_backup' => $legacy_backup );
		foreach ( $files as $name => $path ) {
			$variants = $this->generate_variants( $attachment_id, $path, $optional_formats, (int) $settings['image_quality'], (int) $settings['image_max_width'] );
			if ( is_wp_error( $variants ) ) {
				return array( 'ok' => false, 'message' => $variants->get_error_message() );
			}
			$manifest['images'][ $name ] = $variants;
		}

		if ( $manifest !== $previous && false === update_post_meta( $attachment_id, self::META, $manifest ) ) {
			return array( 'ok' => false, 'message' => 'Could not save image derivative metadata.' );
		}
		update_post_meta( $attachment_id, '_apexpress_optimized_at', time() );
		return array( 'ok' => true, 'message' => 'Optimized.' );
	}

	private function generate_variants( int $attachment_id, string $source, array $optional_formats, int $quality, int $max_width ): array|WP_Error {
		$mime = wp_get_image_mime( $source );
		$signature = hash_file( 'sha256', $source );
		if ( ! isset( self::FORMATS[ $mime ] ) || false === $signature ) {
			return new WP_Error( 'apexpress_image_source', 'Could not read the source image.' );
		}
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$size = $editor->get_size();
		$resized = $size['width'] > $max_width;
		if ( $resized ) {
			$result = $editor->resize( $max_width, null, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$result = $editor->set_quality( $quality );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$variants = array( 'mime' => $mime, 'files' => array() );
		foreach ( array_unique( array_merge( array( $mime ), $optional_formats ) ) as $format ) {
			$key = hash( 'sha256', implode( '|', array( basename( $source ), $signature, $format, $quality, $max_width ) ) );
			$name = 'apexpress-' . $attachment_id . '-' . $key . '.' . self::FORMATS[ $format ];
			$destination = dirname( $source ) . DIRECTORY_SEPARATOR . $name;
			if ( ! is_file( $destination ) ) {
				$temporary = wp_tempnam( $name, dirname( $source ) );
				if ( ! $temporary ) {
					return new WP_Error( 'apexpress_image_temp', 'Could not create a temporary image file.' );
				}
				$staged = $temporary . '.' . self::FORMATS[ $format ];
				try {
					$saved = $editor->save( $staged, $format );
					if ( is_wp_error( $saved ) ) {
						return $saved;
					}
					if ( wp_normalize_path( $saved['path'] ) !== wp_normalize_path( $staged ) || $saved['mime-type'] !== $format ) {
						return new WP_Error( 'apexpress_image_format', 'The image editor returned an unexpected output format.' );
					}
					if ( $format === $mime && ! $resized && filesize( $staged ) >= filesize( $source ) ) {
						continue;
					}
					if ( ! @rename( $staged, $destination ) ) {
						return new WP_Error( 'apexpress_image_save', 'Could not publish the image derivative.' );
					}
				} finally {
					wp_delete_file( $temporary );
					wp_delete_file( $staged );
				}
			}
			$actual = wp_getimagesize( $destination );
			if ( is_link( $destination ) || ! $actual || ( $actual['mime'] ?? '' ) !== $format ) {
				return new WP_Error( 'apexpress_image_invalid', 'The saved image derivative is invalid.' );
			}
			$variants['files'][ $format ] = array( 'file' => $name, 'width' => $actual[0], 'height' => $actual[1] );
		}
		return $variants;
	}

	public function render_variants( string $html, int $attachment_id ): string {
		if ( '' === $html || is_admin() ) {
			return $html;
		}
		$manifest = get_post_meta( $attachment_id, self::META, true );
		if ( ! is_array( $manifest ) || empty( $manifest['images'] ) ) {
			return $html;
		}
		$processor = new WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( 'IMG' ) ) {
			return $html;
		}
		$src = $processor->get_attribute( 'src' );
		if ( ! is_string( $src ) || ! $this->variant_from_url( $src, $manifest ) ) {
			return $html;
		}
		$srcset = $processor->get_attribute( 'srcset' );
		$srcset = is_string( $srcset ) ? $srcset : '';
		$fallback = $this->variant_url( $src, $manifest, '' );
		if ( $fallback ) {
			$processor->set_attribute( 'src', $fallback['url'] );
		}
		if ( '' !== $srcset ) {
			$processor->set_attribute( 'srcset', $this->variant_srcset( $srcset, $manifest, '' ) );
		}
		$sources = '';
		$sizes = $processor->get_attribute( 'sizes' );
		foreach ( array( 'image/avif' => 'image_generate_avif', 'image/webp' => 'image_generate_webp' ) as $format => $setting ) {
			if ( ! ApexPress_Settings::get( $setting ) ) {
				continue;
			}
			$variant = '' === $srcset ? $this->variant_url( $src, $manifest, $format ) : null;
			$set = $variant['url'] ?? $this->variant_srcset( $srcset, $manifest, $format );
			if ( '' !== $set ) {
				$sources .= '<source type="' . esc_attr( $format ) . '" srcset="' . esc_attr( $set ) . '"';
				$sources .= is_string( $sizes ) && '' !== $srcset ? ' sizes="' . esc_attr( $sizes ) . '">' : '>';
			}
		}
		$html = $processor->get_updated_html();
		return '' === $sources ? $html : '<picture>' . $sources . $html . '</picture>';
	}

	private function variant_srcset( string $srcset, array $manifest, string $format ): string {
		$items = array();
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			$segments = preg_split( '/\s+/', $candidate, 2 );
			$variant = $this->variant_url( $segments[0], $manifest, $format );
			if ( $variant ) {
				$descriptor = $segments[1] ?? '';
				if ( str_ends_with( $descriptor, 'w' ) ) {
					$descriptor = $variant['width'] . 'w';
				}
				$items[ $descriptor ] = trim( $variant['url'] . ' ' . $descriptor );
			} elseif ( '' === $format ) {
				$items[ $segments[1] ?? '' ] = $candidate;
			}
		}
		return implode( ', ', $items );
	}

	private function variant_url( string $url, array $manifest, string $format ): ?array {
		$image = $this->variant_from_url( $url, $manifest );
		$file = $image['files'][ $format ?: ( $image['mime'] ?? '' ) ] ?? null;
		if ( ! $file ) {
			return null;
		}
		$directory = wp_get_upload_dir()['basedir'] . '/' . ltrim( $manifest['directory'], '/' );
		if ( ! is_file( $directory . $file['file'] ) ) {
			return null;
		}
		return array( 'url' => substr( $url, 0, strrpos( $url, '/' ) + 1 ) . $file['file'], 'width' => (int) $file['width'] );
	}

	private function variant_from_url( string $url, array $manifest ): ?array {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['path'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$base_url = trailingslashit( wp_get_upload_dir()['baseurl'] );
		$delivery = new ApexPress_Delivery();
		$path = rawurldecode( $parts['path'] );
		$asset_url = $delivery->rewrite_url( $base_url . basename( $parts['path'] ) );
		$delivery_base = substr( $asset_url, 0, strrpos( $asset_url, '/' ) + 1 );
		foreach ( array_unique( array( $base_url, $delivery_base ) ) as $base ) {
			$uploads = wp_parse_url( $base );
			if ( isset( $parts['host'] ) && ( 0 !== strcasecmp( $parts['host'], $uploads['host'] ?? '' ) || ( $parts['port'] ?? null ) !== ( $uploads['port'] ?? null ) ) ) {
				continue;
			}
			$directory = trailingslashit( rawurldecode( $uploads['path'] ?? '' ) ) . ltrim( $manifest['directory'], '/' );
			if ( $directory === substr( $path, 0, strrpos( $path, '/' ) + 1 ) ) {
				return $manifest['images'][ basename( $path ) ] ?? null;
			}
		}
		return null;
	}

	private static function attachment_path( int $id ): string|false {
		$file = get_attached_file( $id );
		$base = realpath( wp_get_upload_dir()['basedir'] );
		$real = is_string( $file ) && ! is_link( $file ) ? realpath( $file ) : false;
		return $base && $real && is_file( $real ) && str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ? $real : false;
	}

	private function backup_file( string $file ): bool {
		if ( ! ApexPress_Settings::get( 'image_backup_originals' ) ) {
			return true;
		}
		$base = realpath( wp_get_upload_dir()['basedir'] );
		$key = ltrim( substr( $file, strlen( $base ) ), DIRECTORY_SEPARATOR );
		if ( ApexPress_Secure_Storage::exists_for_key( 'image', $key ) ) {
			return true;
		}
		$contents = file_get_contents( $file );
		return false !== $contents && false !== ApexPress_Secure_Storage::save_for_key( 'image', $key, $contents );
	}

	public function delete_derivatives( int $attachment_id ): void {
		ApexPress_Image_Queue::cancel( $attachment_id );
		$manifest = get_post_meta( $attachment_id, self::META, true );
		delete_post_meta( $attachment_id, self::META );
		$base = realpath( wp_get_upload_dir()['basedir'] );
		$directory = $base && is_array( $manifest ) ? realpath( $base . '/' . ltrim( $manifest['directory'] ?? '', '/' ) ) : false;
		$source = self::attachment_path( $attachment_id );
		$directories = array_unique( array_filter( array( $directory, $source ? dirname( $source ) : false ) ) );
		foreach ( $directories as $directory ) {
			if ( $directory !== $base && ! str_starts_with( $directory, $base . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			foreach ( glob( $directory . '/apexpress-' . $attachment_id . '-*' ) ?: array() as $file ) {
				if ( $file !== $source && ! is_link( $file ) && preg_match( '/^apexpress-\d+-[a-f0-9]{64}\.(?:jpg|png|webp|avif)$/D', basename( $file ) ) ) {
					wp_delete_file( $file );
				}
			}
		}
	}

	public static function restore_attachment( int $attachment_id ): array {
		return self::with_attachment_lock( $attachment_id, static function () use ( $attachment_id ): array {
			return self::restore_original( $attachment_id );
		} );
	}

	private static function restore_original( int $attachment_id ): array {
		$manifest = get_post_meta( $attachment_id, self::META, true );
		if ( is_array( $manifest ) && empty( $manifest['legacy_backup'] ) ) {
			( new self() )->delete_derivatives( $attachment_id );
			delete_post_meta( $attachment_id, '_apexpress_optimized_at' );
			( new ApexPress_Cache() )->purge_all();
			return array( 'ok' => true, 'message' => __( 'Original image restored.', 'apexpress-performance' ) );
		}

		// Older versions replaced the file in place and stored only an encrypted copy.
		$file = self::attachment_path( $attachment_id );
		$base = realpath( wp_get_upload_dir()['basedir'] );
		if ( ! $file || ! $base ) {
			return array( 'ok' => false, 'message' => __( 'Original path could not be validated.', 'apexpress-performance' ) );
		}
		$key = ltrim( substr( $file, strlen( $base ) ), DIRECTORY_SEPARATOR );
		$contents = ApexPress_Secure_Storage::load_for_key( 'image', $key );
		$size = is_string( $contents ) ? getimagesizefromstring( $contents ) : false;
		if ( ! $size || ! ApexPress_Cache::atomic_write( $file, $contents ) ) {
			return array( 'ok' => false, 'message' => __( 'No readable encrypted image backup could be restored.', 'apexpress-performance' ) );
		}
		if ( ! self::sync_original_metadata( $attachment_id, $file ) ) {
			return array( 'ok' => false, 'message' => __( 'The original file was restored but its attachment metadata could not be updated.', 'apexpress-performance' ) );
		}
		( new self() )->delete_derivatives( $attachment_id );
		delete_post_meta( $attachment_id, '_apexpress_optimized_at' );
		( new ApexPress_Cache() )->purge_all();
		return array( 'ok' => true, 'message' => __( 'Original image restored.', 'apexpress-performance' ) );
	}

	private static function sync_original_metadata( int $attachment_id, string $file ): bool {
		$metadata = wp_get_attachment_metadata( $attachment_id, true );
		if ( ! is_array( $metadata ) ) {
			return true;
		}
		clearstatcache( true, $file );
		$size = wp_getimagesize( $file );
		if ( ! $size ) {
			return false;
		}
		$metadata['width'] = $size[0];
		$metadata['height'] = $size[1];
		$metadata['filesize'] = filesize( $file );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		return $metadata === wp_get_attachment_metadata( $attachment_id, true );
	}

	private static function with_attachment_lock( int $attachment_id, callable $callback ): array {
		global $wpdb;
		$key = hash( 'sha256', DB_NAME . '|' . $wpdb->posts . '|apexpress-image|' . $attachment_id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ) {
			return array( 'ok' => false, 'message' => __( 'The image is already being processed.', 'apexpress-performance' ) );
		}
		try {
			return $callback();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	public static function queue_size(): int {
		return ApexPress_Image_Queue::count();
	}
}
