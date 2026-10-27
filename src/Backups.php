<?php
/**
 * Backups of database copies, kept so a Reset can be undone.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Stores each reset item in a private post, and removes backups once their
 * undo window has passed.
 */
class Backups implements Service {

	public const POST_TYPE   = 'bto_backup';
	public const DATA_META   = '_block_theme_ops_backup';
	public const RESET_BY    = '_block_theme_ops_reset_by';
	public const AUTHOR_META = '_block_theme_ops_author';
	public const UNDO_UNTIL  = '_block_theme_ops_undo_until';

	/**
	 * Registers the private post type.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * The backup post type: private, no screens, not exported.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'           => array(
					'name'          => __( 'Theme Drift backups', 'block-theme-ops' ),
					'singular_name' => __( 'Theme Drift backup', 'block-theme-ops' ),
				),
				'public'           => false,
				'show_ui'          => false,
				'show_in_rest'     => false,
				'rewrite'          => false,
				'query_var'        => false,
				'can_export'       => false,
				'delete_with_user' => false,
				'supports'         => array( 'title' ),
			)
		);
	}

	/**
	 * Days a backup is kept, so a Reset can be undone.
	 *
	 * @return int
	 */
	public static function days(): int {
		/**
		 * Filters how many days a Reset can be undone.
		 *
		 * @param int $days Default 30. Kept between 1 and 3650.
		 */
		$days = (int) apply_filters( 'block_theme_ops_backup_days', 30 );

		return max( 1, min( 3650, $days ) );
	}

	/**
	 * Saves a backup of a database item before it is reset.
	 *
	 * @param array    $item Item.
	 * @param \WP_Post $post The template, template part or global styles post.
	 * @return int|\WP_Error Backup ID.
	 */
	public function create( array $item, \WP_Post $post ) {
		$area = '';
		if ( Themes::TEMPLATE_PART === $post->post_type ) {
			$terms = get_the_terms( $post, 'wp_template_part_area' );
			$area  = is_array( $terms ) && isset( $terms[0]->name ) ? (string) $terms[0]->name : '';
		}

		$data = array(
			'post_type'        => (string) $post->post_type,
			'slug'             => (string) $post->post_name,
			'title'            => (string) $post->post_title,
			'excerpt'          => (string) $post->post_excerpt,
			'content'          => (string) $post->post_content,
			'theme'            => (string) $item['theme'],
			'area'             => $area,
			'original_id'      => (int) $post->ID,
			'author'           => (int) $post->post_author,
			'editor'           => (int) $item['author'],
			'modified_gmt'     => (string) $post->post_modified_gmt,
			'origin'           => (string) get_post_meta( $post->ID, 'origin', true ),
			'is_wp_suggestion' => (string) get_post_meta( $post->ID, 'is_wp_suggestion', true ),
			'snapshot'         => Drift::snapshot( (int) $post->ID ),
			'item'             => array(
				'key'    => (string) $item['key'],
				'title'  => (string) $item['title'],
				'status' => (string) $item['status'],
				'file'   => (string) $item['file'],
			),
			'reset_at'         => time(),
		);

		// Everything goes through wp_slash(): wp_insert_post() and post meta unslash, and block markup has backslashes.
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'private',
					'post_title'  => $item['title'] . ' (' . $item['key'] . ')',
					'post_author' => get_current_user_id(),
					'meta_input'  => array(
						self::DATA_META   => $data,
						self::RESET_BY    => get_current_user_id(),
						self::AUTHOR_META => (int) $item['author'],
						self::UNDO_UNTIL  => time() + self::days() * DAY_IN_SECONDS,
					),
				)
			),
			true
		);

		return is_wp_error( $id ) ? $id : (int) $id;
	}

	/**
	 * One backup.
	 *
	 * @param int $id Backup ID.
	 * @return array|null Stored data plus id, reset_by and undo_until.
	 */
	public function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$data = get_post_meta( $id, self::DATA_META, true );
		if ( ! is_array( $data ) || empty( $data['post_type'] ) ) {
			return null;
		}

		return array_merge(
			$data,
			array(
				'id'         => $id,
				'reset_by'   => (int) get_post_meta( $id, self::RESET_BY, true ),
				'author'     => (int) get_post_meta( $id, self::AUTHOR_META, true ),
				'undo_until' => (int) get_post_meta( $id, self::UNDO_UNTIL, true ),
			)
		);
	}

	/**
	 * Backups that can still be undone, newest first.
	 *
	 * @param int $limit Most to return.
	 * @return array[]
	 */
	public function all( int $limit = 50 ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'private',
				'posts_per_page'         => max( 1, min( 100, $limit ) ),
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$backups = array();
		foreach ( $query->posts as $id ) {
			$backup = $this->get( (int) $id );
			if ( $backup && $backup['undo_until'] > time() ) {
				$backups[] = $backup;
			}
		}

		return $backups;
	}

	/**
	 * Deletes a backup.
	 *
	 * @param int $id Backup ID.
	 */
	public function delete( int $id ): void {
		if ( $this->get( $id ) ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Deletes backups whose undo window has passed, a batch at a time.
	 *
	 * @return int[] Deleted backup IDs.
	 */
	public function cleanup(): array {
		/**
		 * Filters how many expired backups one cleanup run deletes.
		 *
		 * @param int $batch Default 50. Kept between 1 and 500.
		 */
		$batch = max( 1, min( 500, (int) apply_filters( 'block_theme_ops_cleanup_batch', 50 ) ) );

		$query = new \WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => $batch,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small private post type, read once a day.
				'meta_query'             => array(
					array(
						'key'     => self::UNDO_UNTIL,
						'value'   => time(),
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$deleted = array();
		foreach ( $query->posts as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) {
				$deleted[] = (int) $id;
			}
		}

		if ( $deleted ) {
			/**
			 * Fires after expired backups are deleted. Those resets can no longer be undone.
			 *
			 * @param int[] $deleted Backup IDs.
			 */
			do_action( 'block_theme_ops_backups_expired', $deleted );
		}

		return $deleted;
	}
}
