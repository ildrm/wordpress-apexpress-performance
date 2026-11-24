<?php
/**
 * Durable image jobs and conditional lease transitions.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Image_Queue {
	private const SCHEMA = 'apexpress_image_queue_schema';
	private const LEGACY = 'apexpress_image_queue';
	private const MAX_ATTEMPTS = 3;
	private const LEASE_SECONDS = 900;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'apexpress_image_jobs';
	}

	public static function schema_current(): bool {
		return 2 === (int) get_option( self::SCHEMA );
	}

	public static function ensure( bool $verify = false ): bool {
		global $wpdb;
		if ( ! $verify && self::schema_current() ) {
			return true;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				attachment_id bigint unsigned NOT NULL,
				attempts tinyint unsigned NOT NULL DEFAULT 0,
				available_at bigint unsigned NOT NULL DEFAULT 0,
				lease_expires bigint unsigned NOT NULL DEFAULT 0,
				lease_token char(36) DEFAULT NULL,
				PRIMARY KEY  (attachment_id),
				UNIQUE KEY lease_token (lease_token),
				KEY ready (available_at,lease_expires,attempts)
			) {$charset};"
		);
		$fields = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		if ( array_diff( array( 'attachment_id', 'attempts', 'available_at', 'lease_expires', 'lease_token' ), $fields )
			|| ! ApexPress_Activator::has_index( $indexes, 'PRIMARY', array( 'attachment_id' ), true )
			|| ! ApexPress_Activator::has_index( $indexes, 'lease_token', array( 'lease_token' ), true )
			|| ! ApexPress_Activator::has_index( $indexes, 'ready', array( 'available_at', 'lease_expires', 'attempts' ), false ) ) {
			return false;
		}
		$legacy = get_option( self::LEGACY, array() );
		$ids = is_array( $legacy ) ? array_filter( array_map( 'absint', $legacy ) ) : array();
		foreach ( array_chunk( $ids, 200 ) as $batch ) {
			$values = implode( ', ', array_fill( 0, count( $batch ), '(%d, UNIX_TIMESTAMP())' ) );
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (attachment_id,available_at) VALUES {$values}", array_values( $batch ) ) ) ) {
				return false;
			}
		}
		update_option( self::SCHEMA, 2, false );
		delete_option( self::LEGACY );
		return true;
	}

	public static function enqueue( int $attachment_id ): bool {
		global $wpdb;
		$table = self::table();
		return false !== $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (attachment_id,available_at) VALUES (%d, UNIX_TIMESTAMP())",
			$attachment_id
		) );
	}

	public static function enqueue_all(): int {
		global $wpdb;
		if ( ! self::ensure() ) {
			return 0;
		}
		$table = self::table();
		$added = $wpdb->query(
			"INSERT IGNORE INTO {$table} (attachment_id,available_at)
			 SELECT ID, UNIX_TIMESTAMP() FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_status = 'inherit'
			 AND post_mime_type IN ('image/jpeg','image/png','image/webp','image/avif')"
		);
		if ( false === $added ) {
			return 0;
		}
		$retried = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET attempts = 0, available_at = UNIX_TIMESTAMP(), lease_expires = 0, lease_token = NULL
			 WHERE attempts >= %d AND lease_expires <= UNIX_TIMESTAMP()",
			self::MAX_ATTEMPTS
		) );
		return (int) $added + (int) $retried;
	}

	public static function claim(): ?array {
		global $wpdb;
		$table = self::table();
		$token = wp_generate_uuid4();
		$claimed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET attempts = attempts + 1, lease_expires = UNIX_TIMESTAMP() + %d, lease_token = %s
			 WHERE available_at <= UNIX_TIMESTAMP() AND lease_expires <= UNIX_TIMESTAMP() AND attempts < %d
			 ORDER BY available_at, attachment_id LIMIT 1",
			self::LEASE_SECONDS,
			$token,
			self::MAX_ATTEMPTS
		) );
		if ( 1 !== $claimed ) {
			return null;
		}
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE lease_token = %s", $token ),
			ARRAY_A
		);
	}

	public static function finish( array $job, bool $success ): bool {
		global $wpdb;
		$table = self::table();
		$id = (int) $job['attachment_id'];
		$token = (string) $job['lease_token'];
		if ( $success ) {
			return 1 === $wpdb->delete( $table, array( 'attachment_id' => $id, 'lease_token' => $token ), array( '%d', '%s' ) );
		}
		$delay = 300 * ( 2 ** ( (int) $job['attempts'] - 1 ) );
		return 1 === $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET available_at = UNIX_TIMESTAMP() + %d, lease_expires = 0, lease_token = NULL
			 WHERE attachment_id = %d AND lease_token = %s",
			$delay,
			$id,
			$token
		) );
	}

	public static function is_current( array $job ): bool {
		global $wpdb;
		$table = self::table();
		return '1' === (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE attachment_id = %d AND lease_token = %s AND lease_expires > UNIX_TIMESTAMP()",
			(int) $job['attachment_id'],
			$job['lease_token']
		) );
	}

	public static function cancel( int $attachment_id ): void {
		global $wpdb;
		if ( get_option( self::SCHEMA ) ) {
			$wpdb->delete( self::table(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );
		}
	}

	public static function count( bool $failed = false ): int {
		global $wpdb;
		if ( ! self::ensure() ) {
			return 0;
		}
		$table = self::table();
		$sql = $failed
			? "SELECT COUNT(*) FROM {$table} WHERE attempts >= %d AND lease_expires <= UNIX_TIMESTAMP()"
			: "SELECT COUNT(*) FROM {$table} WHERE attempts < %d OR lease_expires > UNIX_TIMESTAMP()";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, self::MAX_ATTEMPTS ) );
	}
}
