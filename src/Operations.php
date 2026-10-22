<?php
/**
 * Reset, undo and keep.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Changes database items: back to the theme file (with a backup), back
 * from the backup, or marked as kept on purpose.
 */
class Operations {

	/**
	 * Inventory.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * Backups.
	 *
	 * @var Backups
	 */
	private Backups $backups;

	/**
	 * Snapshots.
	 *
	 * @var Snapshots
	 */
	private Snapshots $snapshots;

	/**
	 * Pending alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Sets up the operations.
	 *
	 * @param Drift     $drift     Inventory.
	 * @param Backups   $backups   Backups.
	 * @param Snapshots $snapshots Snapshots.
	 * @param Alerts    $alerts    Pending alerts.
	 */
	public function __construct( Drift $drift, Backups $backups, Snapshots $snapshots, Alerts $alerts ) {
		$this->drift     = $drift;
		$this->backups   = $backups;
		$this->snapshots = $snapshots;
		$this->alerts    = $alerts;
	}

	/**
	 * Throws away the database copy so the site uses the theme file again.
	 * Templates and parts are deleted; global styles are emptied. A backup
	 * is kept first, so this can be undone.
	 *
	 * @param array $item Item.
	 * @return array{item: array, backup: int}|\WP_Error
	 */
	public function reset( array $item ) {
		if ( '' === $item['file'] ) {
			return new \WP_Error( 'block_theme_ops_no_file', __( 'This item has no theme file to go back to. Export it instead.', 'block-theme-ops' ) );
		}

		$post = get_post( (int) $item['id'] );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'block_theme_ops_not_found', __( 'That item no longer exists.', 'block-theme-ops' ) );
		}

		$backup = $this->backups->create( $item, $post );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		if ( Themes::GLOBAL_STYLES === $post->post_type ) {
			foreach ( array( Drift::SNAPSHOT_META, Drift::KEPT_META, Drift::NOTIFIED_META ) as $key ) {
				delete_post_meta( $post->ID, $key );
			}
			$updated = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post->ID,
						'post_content' => StylesJson::empty_layer( StylesJson::decode( (string) $post->post_content ) ),
					)
				),
				true
			);
			$done    = ! is_wp_error( $updated );
			self::clean_styles_cache();
		} else {
			$done = (bool) wp_delete_post( $post->ID, true );
		}

		if ( ! $done ) {
			$this->backups->delete( $backup );
			return new \WP_Error( 'block_theme_ops_reset_failed', __( 'WordPress could not reset this item. Nothing was changed.', 'block-theme-ops' ) );
		}

		$this->alerts->remove( (int) $item['id'] );
		$this->drift->flush();

		/**
		 * Fires after a database item is reset to the theme file.
		 *
		 * @param array $item      The item as it was before the reset.
		 * @param int   $backup_id Backup that Undo restores from.
		 */
		do_action( 'block_theme_ops_reset', $item, $backup );

		return array(
			'item'   => $item,
			'backup' => $backup,
		);
	}

	/**
	 * Resets every item whose database copy matches its theme file.
	 *
	 * @return array{reset: array[], failed: array[]}
	 */
	public function reset_identical(): array {
		$result = array(
			'reset'  => array(),
			'failed' => array(),
		);

		foreach ( $this->drift->items( array( 'status' => Drift::IDENTICAL ) ) as $item ) {
			$done = $this->reset( $item );
			if ( is_wp_error( $done ) ) {
				$result['failed'][] = $item;
			} else {
				$result['reset'][] = $done;
			}
		}

		return $result;
	}

	/**
	 * Brings a reset item back from its backup.
	 *
	 * @param int $backup_id Backup ID.
	 * @return int|\WP_Error The restored post ID.
	 */
	public function undo( int $backup_id ) {
		$backup = $this->backups->get( $backup_id );
		if ( null === $backup ) {
			return new \WP_Error( 'block_theme_ops_no_backup', __( 'That backup no longer exists.', 'block-theme-ops' ) );
		}
		if ( $backup['undo_until'] <= time() ) {
			return new \WP_Error( 'block_theme_ops_expired', __( 'This reset is too old to undo.', 'block-theme-ops' ) );
		}

		$post_id = Themes::GLOBAL_STYLES === $backup['post_type'] ? $this->restore_styles( $backup ) : $this->restore_template( $backup );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! empty( $backup['snapshot'] ) && is_array( $backup['snapshot'] ) ) {
			$this->snapshots->store( $post_id, $backup['snapshot'] );
		}
		if ( ! empty( $backup['editor'] ) ) {
			update_post_meta( $post_id, Drift::EDITOR_META, (int) $backup['editor'] );
		}

		$this->backups->delete( $backup_id );
		$this->drift->flush();

		/**
		 * Fires after a reset is undone and the database copy is back.
		 *
		 * @param array $backup  The backup it came from.
		 * @param int   $post_id The restored post.
		 */
		do_action( 'block_theme_ops_reset_undone', $backup, $post_id );

		return $post_id;
	}

	/**
	 * Marks an item as kept on purpose. It drops out of the list, the notice
	 * and the email until its theme file changes again.
	 *
	 * @param array $item Item.
	 * @return bool
	 */
	public function keep( array $item ): bool {
		update_post_meta( (int) $item['id'], Drift::KEPT_META, Drift::keep_marker( $item ) );
		$this->alerts->remove( (int) $item['id'] );
		$this->drift->flush();

		/**
		 * Fires after someone chooses to keep a database copy as it is.
		 *
		 * @param array $item The item.
		 */
		do_action( 'block_theme_ops_acknowledged', $item );

		return true;
	}

	/**
	 * Lists a kept item again.
	 *
	 * @param array $item Item.
	 * @return bool
	 */
	public function unkeep( array $item ): bool {
		delete_post_meta( (int) $item['id'], Drift::KEPT_META );
		$this->drift->flush();

		return true;
	}

	/**
	 * Puts a template or template part back as a database post.
	 *
	 * @param array $backup Backup.
	 * @return int|\WP_Error
	 */
	private function restore_template( array $backup ) {
		$existing = new \WP_Query(
			array(
				'post_type'              => $backup['post_type'],
				'post_status'            => array( 'publish', 'draft', 'auto-draft' ),
				'name'                   => $backup['slug'],
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Core keeps the theme of a template only in this taxonomy.
				'tax_query'              => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $backup['theme'],
					),
				),
				'update_post_term_cache' => false,
			)
		);
		if ( $existing->posts ) {
			return new \WP_Error( 'block_theme_ops_exists', __( 'Someone customized this item again since the reset. Reset that copy first, then undo.', 'block-theme-ops' ) );
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'import_id'    => (int) $backup['original_id'],
					'post_type'    => $backup['post_type'],
					'post_status'  => 'publish',
					'post_name'    => $backup['slug'],
					'post_title'   => $backup['title'],
					'post_excerpt' => $backup['excerpt'],
					'post_content' => $backup['content'],
					'post_author'  => (int) $backup['author'],
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;
		wp_set_object_terms( $post_id, (string) $backup['theme'], 'wp_theme' );
		if ( '' !== (string) $backup['area'] ) {
			wp_set_object_terms( $post_id, (string) $backup['area'], 'wp_template_part_area' );
		}
		foreach ( array( 'origin', 'is_wp_suggestion' ) as $key ) {
			if ( '' !== (string) ( $backup[ $key ] ?? '' ) ) {
				update_post_meta( $post_id, $key, (string) $backup[ $key ] );
			}
		}
		// Bump the cache so core sees the template's terms straight away.
		clean_post_cache( $post_id );

		return $post_id;
	}

	/**
	 * Puts global styles changes back into the theme's global styles post.
	 *
	 * @param array $backup Backup.
	 * @return int|\WP_Error
	 */
	private function restore_styles( array $backup ) {
		$post = get_post( (int) $backup['original_id'] );
		if ( $post instanceof \WP_Post && Themes::GLOBAL_STYLES === $post->post_type ) {
			if ( StylesJson::changes( StylesJson::decode( (string) $post->post_content ) ) ) {
				return new \WP_Error( 'block_theme_ops_exists', __( 'Someone changed the global styles again since the reset. Reset them first, then undo.', 'block-theme-ops' ) );
			}
			$done = wp_update_post(
				wp_slash(
					array(
						'ID'           => $post->ID,
						'post_content' => $backup['content'],
					)
				),
				true
			);
		} else {
			$done = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => Themes::GLOBAL_STYLES,
						'post_status'  => 'publish',
						'post_name'    => $backup['slug'],
						'post_title'   => $backup['title'],
						'post_content' => $backup['content'],
					)
				),
				true
			);
			if ( ! is_wp_error( $done ) ) {
				wp_set_object_terms( (int) $done, (string) $backup['theme'], 'wp_theme' );
			}
		}

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		self::clean_styles_cache();

		return (int) $done;
	}

	/**
	 * Clears core's cached global styles after they change.
	 */
	private static function clean_styles_cache(): void {
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
	}
}
