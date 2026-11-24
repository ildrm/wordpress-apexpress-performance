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

	// These records own files or commerce data outside the core tables in the snapshot.
	private static function protected_post_types(): array {
		$types = array( 'attachment', 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_coupon' );
		return function_exists( 'wc_get_order_types' ) ? array_unique( array_merge( $types, wc_get_order_types() ) ) : $types;
	}

	private static function post_type_clause( array $protected ): string {
		global $wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $protected ), '%s' ) );
		return $wpdb->prepare( "post_type NOT IN ({$placeholders})", ...array_values( array_map( 'sanitize_key', $protected ) ) );
	}

	public static function analyze(): array {
		global $wpdb;
		$eligible = self::post_type_clause( self::protected_post_types() );
		$autoload = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY bytes DESC LIMIT 50",
			ARRAY_A
		);
		$total_autoload = (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')"
		);
		$counts = array(
			'revisions'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'auto_drafts'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND {$eligible} AND post_date < %s", current_datetime()->modify( '-7 days' )->format( 'Y-m-d H:i:s' ) ) ),
			'trash_posts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash' AND {$eligible}" ),
			'spam_comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
			'trash_comments'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ),
			'expired_transients' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) ),
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
		$protected = self::protected_post_types();
		$eligible = self::post_type_clause( $protected );
		$draft_cutoff = current_datetime()->modify( '-7 days' )->format( 'Y-m-d H:i:s' );
		foreach ( $types as $type ) {
			switch ( $type ) {
				case 'revisions':
					$plan[ $type ] = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' ORDER BY ID ASC LIMIT %d", self::POST_BATCH ) ) );
					break;
				case 'auto_drafts':
					$plan[ $type ] = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND {$eligible} AND post_date < %s ORDER BY ID ASC LIMIT %d", $draft_cutoff, self::POST_BATCH ) ) );
					break;
				case 'trash_posts':
					$plan[ $type ] = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' AND {$eligible} ORDER BY ID ASC LIMIT %d", self::POST_BATCH ) ) );
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
			if ( $wpdb->last_error ) {
				return array( 'ok' => false, 'message' => __( 'Cleanup cancelled because the selected records could not be read.', 'apexpress-performance' ) );
			}
		}

		try {
			$snapshot = self::build_snapshot( $plan );
		} catch ( RuntimeException $error ) {
			return array( 'ok' => false, 'message' => __( 'Cleanup cancelled because the backup data could not be read.', 'apexpress-performance' ) );
		}
		$backup   = self::write_snapshot( $snapshot );
		if ( false === $backup ) {
			return array( 'ok' => false, 'message' => __( 'Cleanup was cancelled because an encrypted backup snapshot could not be written.', 'apexpress-performance' ) );
		}

		$deleted = array();
		$failed = false;
		foreach ( $plan as $type => $items ) {
			$count = 0;
			switch ( $type ) {
				case 'revisions':
				case 'auto_drafts':
				case 'trash_posts':
					foreach ( $items as $post_id ) {
						$post = get_post( $post_id );
						if ( ! $post || ( 'revisions' === $type && 'revision' !== $post->post_type )
							|| ( 'trash_posts' === $type && ( 'trash' !== $post->post_status || in_array( $post->post_type, $protected, true ) ) )
							|| ( 'auto_drafts' === $type && ( 'auto-draft' !== $post->post_status || in_array( $post->post_type, $protected, true ) || $post->post_date >= $draft_cutoff ) ) ) {
							continue;
						}
						if ( wp_delete_post( $post_id, true ) instanceof WP_Post ) {
							++$count;
						} elseif ( get_post( $post_id ) ) {
							$failed = true;
						}
					}
					break;
				case 'spam_comments':
				case 'trash_comments':
					foreach ( $items as $comment_id ) {
						$status = 'spam_comments' === $type ? 'spam' : 'trash';
						if ( $status !== wp_get_comment_status( $comment_id ) ) {
							continue;
						}
						if ( wp_delete_comment( $comment_id, true ) ) {
							++$count;
						} elseif ( get_comment( $comment_id ) ) {
							$failed = true;
						}
					}
					break;
				case 'orphan_postmeta':
					if ( $items ) {
						$ids = implode( ',', array_map( 'absint', $items ) );
						$removed = $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_id IN ({$ids}) AND p.ID IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
						$failed = $failed || false === $removed;
						$count = (int) $removed;
					}
					break;
				case 'expired_transients':
					foreach ( $items as $timeout_name ) {
						$transient = substr( (string) $timeout_name, strlen( '_transient_timeout_' ) );
						$value_name = '_transient_' . $transient;
						// Check expiry and delete the pair together, including orphan timeout rows.
						$removed = $wpdb->query( $wpdb->prepare(
							"DELETE timeout_row, value_row FROM {$wpdb->options} timeout_row
							 LEFT JOIN {$wpdb->options} value_row ON value_row.option_name = %s
							 WHERE timeout_row.option_name = %s AND CAST(timeout_row.option_value AS UNSIGNED) < %d",
							$value_name, $timeout_name, time()
						) );
						if ( false === $removed ) {
							$failed = true;
						} elseif ( $removed > 0 ) {
							wp_cache_delete( $value_name, 'options' );
							wp_cache_delete( $timeout_name, 'options' );
							++$count;
						}
					}
					break;
			}
			$deleted[ $type ] = $count;
		}
		if ( $failed ) {
			ApexPress_Logger::log( 'warning', 'Database cleanup was incomplete.', array( 'backup' => basename( $backup ) ) );
			return array( 'ok' => false, 'deleted' => $deleted, 'backup' => basename( $backup ), 'message' => __( 'Some records could not be deleted. The pre-cleanup snapshot is available.', 'apexpress-performance' ) );
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
			// Core deletes child revisions and can reparent attachments and hierarchical posts.
			$children = self::snapshot_rows( "SELECT ID, post_type, post_parent FROM {$wpdb->posts} WHERE post_parent IN ({$ids})" );
			$snapshot['data']['child_parents'] = $children;
			$revision_ids = wp_list_pluck( wp_list_filter( $children, array( 'post_type' => 'revision' ) ), 'ID' );
			$ids = implode( ',', array_unique( array_map( 'absint', array_merge( $post_ids, $revision_ids ) ) ) );
			$snapshot['data']['posts'] = self::snapshot_rows( "SELECT * FROM {$wpdb->posts} WHERE ID IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$snapshot['data']['postmeta'] = self::snapshot_rows( "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$snapshot['data']['term_relationships'] = self::snapshot_rows( "SELECT * FROM {$wpdb->term_relationships} WHERE object_id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$snapshot['data']['post_comments'] = self::snapshot_rows( "SELECT * FROM {$wpdb->comments} WHERE comment_post_ID IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$comment_ids = array_map( 'absint', wp_list_pluck( $snapshot['data']['post_comments'], 'comment_ID' ) );
			if ( $comment_ids ) {
				$cids = implode( ',', $comment_ids );
				$snapshot['data']['post_commentmeta'] = self::snapshot_rows( "SELECT * FROM {$wpdb->commentmeta} WHERE comment_id IN ({$cids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		$comment_ids = array_values( array_unique( array_merge( $plan['spam_comments'] ?? array(), $plan['trash_comments'] ?? array() ) ) );
		if ( $comment_ids ) {
			$ids = implode( ',', array_map( 'absint', $comment_ids ) );
			$snapshot['data']['comments'] = self::snapshot_rows( "SELECT * FROM {$wpdb->comments} WHERE comment_ID IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$snapshot['data']['child_comment_parents'] = self::snapshot_rows( "SELECT comment_ID, comment_parent FROM {$wpdb->comments} WHERE comment_parent IN ({$ids})" );
			$snapshot['data']['commentmeta'] = self::snapshot_rows( "SELECT * FROM {$wpdb->commentmeta} WHERE comment_id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$meta_ids = $plan['orphan_postmeta'] ?? array();
		if ( $meta_ids ) {
			$ids = implode( ',', array_map( 'absint', $meta_ids ) );
			$snapshot['data']['orphan_postmeta'] = self::snapshot_rows( "SELECT * FROM {$wpdb->postmeta} WHERE meta_id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$timeouts = $plan['expired_transients'] ?? array();
		if ( $timeouts ) {
			$names = array();
			foreach ( $timeouts as $timeout_name ) {
				$transient = substr( (string) $timeout_name, strlen( '_transient_timeout_' ) );
				$names[] = (string) $timeout_name;
				$names[] = '_transient_' . $transient;
			}
			$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
			$sql = $wpdb->prepare( "SELECT * FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", ...$names ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$snapshot['data']['transients'] = self::snapshot_rows( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $snapshot;
	}

	private static function snapshot_rows( string $sql ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'Snapshot query failed.' );
		}
		return $rows;
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
