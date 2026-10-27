<?php
/**
 * Privacy: policy text, personal data export and erasure.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Block Theme Ops stores user IDs: who last saved a template, and who reset
 * one (in the backup, for 30 days). Both take part in the core privacy tools.
 */
final class Privacy implements Service {

	private const PAGE = 20;

	/**
	 * Hooks the privacy tools.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * Suggested text for the privacy policy.
	 */
	public function add_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Block Theme Ops', 'block-theme-ops' ),
			'<p>' . esc_html__( 'When a template, template part or the global styles are saved in the Site Editor, Block Theme Ops records the user ID of the person who saved them. When one is reset to the theme file, a backup keeps that user ID and the ID of the person who reset it, for 30 days by default, so the reset can be undone.', 'block-theme-ops' ) . '</p>'
			. '<p>' . esc_html__( 'If an administrator opens a GitHub pull request from Theme Drift, the exported template files and the display names of the people who last edited them are sent to GitHub.', 'block-theme-ops' ) . '</p>'
		);
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( array $exporters ): array {
		$exporters['block-theme-ops'] = array(
			'exporter_friendly_name' => __( 'Block Theme Ops', 'block-theme-ops' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( array $erasers ): array {
		$erasers['block-theme-ops'] = array(
			'eraser_friendly_name' => __( 'Block Theme Ops', 'block-theme-ops' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports what Block Theme Ops stores about one person.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$ids  = $this->find( (int) $user->ID, (int) $page );
		$data = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$fields = array(
				array(
					'name'  => __( 'Item', 'block-theme-ops' ),
					'value' => $post->post_title,
				),
			);
			if ( Backups::POST_TYPE === $post->post_type ) {
				$fields[] = array(
					'name'  => __( 'Reset by this person', 'block-theme-ops' ),
					'value' => (int) get_post_meta( $id, Backups::RESET_BY, true ) === (int) $user->ID ? __( 'Yes', 'block-theme-ops' ) : __( 'No', 'block-theme-ops' ),
				);
				$fields[] = array(
					'name'  => __( 'Last edited by this person', 'block-theme-ops' ),
					'value' => (int) get_post_meta( $id, Backups::AUTHOR_META, true ) === (int) $user->ID ? __( 'Yes', 'block-theme-ops' ) : __( 'No', 'block-theme-ops' ),
				);
			} else {
				$fields[] = array(
					'name'  => __( 'Last saved in the Site Editor by this person', 'block-theme-ops' ),
					'value' => (string) $post->post_modified_gmt,
				);
			}

			$data[] = array(
				'group_id'    => 'block-theme-ops',
				'group_label' => __( 'Theme Drift', 'block-theme-ops' ),
				'item_id'     => 'block-theme-ops-' . $id,
				'data'        => $fields,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PAGE,
		);
	}

	/**
	 * Removes one person's user ID from everything Block Theme Ops stores.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1. Erased records drop out of the query, so this always reads the first page.
	 * @return array
	 */
	public function erase( $email, $page = 1 ): array {
		$user    = get_user_by( 'email', (string) $email );
		$removed = false;
		$ids     = $user ? $this->find( (int) $user->ID, 1 ) : array();

		foreach ( $ids as $id ) {
			$user_id = (int) $user->ID;
			if ( (int) get_post_meta( $id, Drift::EDITOR_META, true ) === $user_id ) {
				delete_post_meta( $id, Drift::EDITOR_META );
			}
			foreach ( array( Backups::RESET_BY, Backups::AUTHOR_META ) as $key ) {
				if ( (int) get_post_meta( $id, $key, true ) === $user_id ) {
					update_post_meta( $id, $key, 0 );
				}
			}

			$data = get_post_meta( $id, Backups::DATA_META, true );
			if ( is_array( $data ) ) {
				foreach ( array( 'author', 'editor' ) as $key ) {
					if ( isset( $data[ $key ] ) && (int) $data[ $key ] === $user_id ) {
						$data[ $key ] = 0;
					}
				}
				update_post_meta( $id, Backups::DATA_META, wp_slash( $data ) );
			}
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PAGE,
		);
	}

	/**
	 * Posts that hold a user's ID: the editor of a template, or the person
	 * named in a backup.
	 *
	 * @param int $user_id User ID.
	 * @param int $page    Page, from 1.
	 * @return int[]
	 */
	private function find( int $user_id, int $page ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => array_merge( Drift::TYPES, array( Backups::POST_TYPE ) ),
				'post_status'            => 'any',
				'posts_per_page'         => self::PAGE,
				'paged'                  => max( 1, $page ),
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only runs from the core privacy tools.
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'   => Drift::EDITOR_META,
						'value' => $user_id,
					),
					array(
						'key'   => Backups::RESET_BY,
						'value' => $user_id,
					),
					array(
						'key'   => Backups::AUTHOR_META,
						'value' => $user_id,
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
