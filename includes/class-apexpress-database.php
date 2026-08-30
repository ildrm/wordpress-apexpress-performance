<?php
/**
 * Database analysis and bounded, snapshotted cleanup.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_Database {
	private const POST_BATCH    = 500;
	private const COMMENT_BATCH = 1000;
	private const META_BATCH    = 5000;

	public static function analyze(): array {
		global $wpdb;
		$autoload = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY bytes DESC LIMIT 50",
			ARRAY_A
		);
		$total_autoload = (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')"
		);
		$counts = array(
			'revisions'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'auto_drafts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			'trash_posts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			'spam_comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
			'trash_comments'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ),
			'orphan_postmeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" ),
		);
		return array(
			'autoload_bytes' => $total_autoload,
			'autoload_top'   => is_array( $autoload ) ? $autoload : array(),
			'cleanup_counts' => $counts,
		);
	}

	public static function cleanup( array $types ): array {
		global $wpdb;
		$allowed = array( 'revisions', 'auto_drafts', 'trash_posts', 'spam_comments', 'trash_comments', 'expired_transients', 'orphan_postmeta' );
		$types   = array_values( array_intersect( $allowed, array_map( 'sanitize_key', $types ) ) );
		if ( empty( $types ) ) {
			return array( 'ok' => false, 'message' => __( 'No valid cleanup type selected.', 'apexpress-performance' ) );
		}

		$plan = array();
		foreach ( $types as $type ) {
			switch ( $type ) {
				case 'revisions':
					$plan[ $type ] = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' ORDER BY ID ASC LIMIT %d", self::POST_BATCH ) ) );
					break;
				case 'auto_drafts':
					$plan[ $type ] = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' ORDER BY ID ASC LIMIT %d", self::POST_BATCH ) ) );
					break;
				case 'trash_posts':
					$plan[ $type ] = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' ORDER BY ID ASC LIMIT %d", self::POST_BATCH ) ) );
					break;
				case 'spam_comments':
					$plan[ $type ] = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam' ORDER BY comment_ID ASC LIMIT %d", self::COMMENT_BATCH ) ) );
					break;
				case 'trash_comments':
					$plan[ $type ] = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash' ORDER BY comment_ID ASC LIMIT %d", self::COMMENT_BATCH ) ) );
					break;
				case 'orphan_postmeta':
					$plan[ $type ] = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT pm.meta_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL ORDER BY pm.meta_id ASC LIMIT %d", self::META_BATCH ) ) );
					break;
				case 'expired_transients':
					$plan[ $type ] = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_id ASC LIMIT %d",
							$wpdb->esc_like( '_transient_timeout_' ) . '%',
							time(),
							self::META_BATCH
						)
					);
					break;
			}
		}

		$snapshot = self::build_snapshot( $plan );
		$backup   = self::write_snapshot( $snapshot );
		if ( false === $backup ) {
			return array( 'ok' => false, 'message' => __( 'Cleanup was cancelled because an encrypted backup snapshot could not be written.', 'apexpress-performance' ) );
		}

		$deleted = array();
		foreach ( $plan as $type => $items ) {
			$count = 0;
			switch ( $type ) {
				case 'revisions':
				case 'auto_drafts':
				case 'trash_posts':
					foreach ( $items as $post_id ) {
						if ( $post_id && false !== wp_delete_post( (int) $post_id, true ) ) {
							++$count;
						}
					}
					break;
				case 'spam_comments':
				case 'trash_comments':
					foreach ( $items as $comment_id ) {
						if ( $comment_id && wp_delete_comment( (int) $comment_id, true ) ) {
							++$count;
						}
					}
					break;
				case 'orphan_postmeta':
					if ( $items ) {
						$ids = implode( ',', array_map( 'absint', $items ) );
						$count = (int) $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
					}
					break;
				case 'expired_transients':
					foreach ( $items as $timeout_name ) {
						$transient = str_replace( '_transient_timeout_', '', (string) $timeout_name );
						if ( $transient && delete_transient( $transient ) ) {
							++$count;
						}
					}
					break;
			}
			$deleted[ $type ] = $count;
		}
		ApexPress_Logger::log( 'info', 'Database cleanup completed.', array( 'backup' => basename( $backup ), 'types' => implode( ',', array_keys( $deleted ) ) ) );
		return array( 'ok' => true, 'deleted' => $deleted, 'backup' => basename( $backup ) );
	}

	private static function build_snapshot( array $plan ): array {
		global $wpdb;
		$snapshot = array(
			'created' => gmdate( 'c' ),
			'site'    => home_url( '/' ),
			'version' => APEXPRESS_VERSION,
			'plan'    => $plan,
			'data'    => array(),
		);

		$post_ids = array_values( array_unique( array_merge( $plan['revisions'] ?? array(), $plan['auto_drafts'] ?? array(), $plan['trash_posts'] ?? array() ) ) );
		if ( $post_ids ) {
			$ids = implode( ',', array_map( 'absint', $post_ids ) );
			$snapshot['data']['posts'] = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE ID IN ({$ids})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$snapshot['data']['postmeta'] = $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ({$ids})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$snapshot['data']['term_relationships'] = $wpdb->get_results( "SELECT * FROM {$wpdb->term_relationships} WHERE object_id IN ({$ids})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$comment_ids = array_map( 'absint', $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ({$ids})" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$snapshot['data']['post_comments'] = $wpdb->get_results( "SELECT * FROM {$wpdb->comments} WHERE comment_post_ID IN ({$ids})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $comment_ids ) {
				$cids = implode( ',', $comment_ids );
				$snapshot['data']['post_commentmeta'] = $wpdb->get_results( "SELECT * FROM {$wpdb->commentmeta} WHERE comment_id IN ({$cids})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		$comment_ids = array_values( array_unique( array_merge( $plan['spam_comments'] ?? array(), $plan['trash_comments'] ?? array() ) ) );
		if ( $comment_ids ) {
			$ids = implode( ',', array_map( 'absint', $comment_ids ) );
			$snapshot['data']['comments'] = $wpdb->get_results( "SELECT * FROM {$wpdb->comments} WHERE comment_ID IN ({$ids})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$snapshot['data']['commentmeta'] = $wpdb->get_results( "SELECT * FROM {$wpdb->commentmeta} WHERE comment_id IN ({$ids})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$meta_ids = $plan['orphan_postmeta'] ?? array();
		if ( $meta_ids ) {
			$ids = implode( ',', array_map( 'absint', $meta_ids ) );
			$snapshot['data']['orphan_postmeta'] = $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} WHERE meta_id IN ({$ids})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$timeouts = $plan['expired_transients'] ?? array();
		if ( $timeouts ) {
			$names = array();
			foreach ( $timeouts as $timeout_name ) {
				$transient = str_replace( '_transient_timeout_', '', (string) $timeout_name );
				$names[] = (string) $timeout_name;
				$names[] = '_transient_' . $transient;
			}
			$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
			$sql = $wpdb->prepare( "SELECT * FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", ...$names ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$snapshot['data']['transients'] = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $snapshot;
	}

	private static function write_snapshot( array $data ): string|false {
		$name = 'database-cleanup-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT );
		if ( false === $json ) {
			return false;
		}
		return ApexPress_Secure_Storage::save_named( $name, $json );
	}
}
