<?php
/**
 * Merge a theme update into a customized template.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Plans and applies the three-way merge of an item, and undoes it.
 *
 * The block-level work is in BlockMerge. This class finds the three versions,
 * checks that a merge makes sense, takes a backup and writes the result.
 */
class Merger {

	/**
	 * Backup kind of a merge.
	 */
	public const KIND = 'merge';

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
	 * Sets up the merger.
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
	 * Why an item cannot be merged, or an empty string when it can.
	 *
	 * @param array $item Item.
	 * @return string Plain-text reason.
	 */
	public function blocker( array $item ): string {
		if ( ! in_array( $item['type'], array( Themes::TEMPLATE, Themes::TEMPLATE_PART ), true ) ) {
			return __( 'Only templates and template parts can be merged. Global styles can be reset or kept.', 'block-theme-ops' );
		}
		if ( '' === $item['file'] ) {
			return __( 'This item has no theme file to merge with.', 'block-theme-ops' );
		}

		$snapshot = Drift::snapshot( (int) $item['id'] );
		if ( ! $snapshot || '' === $snapshot['content'] ) {
			return __( 'There is no copy of the theme file from the time of the edit, so changes by the theme and by the site cannot be told apart. Reset or keep this item instead.', 'block-theme-ops' );
		}

		$file = $this->drift->themes()->read( (string) $item['path'] );
		if ( '' === $file ) {
			return __( 'The theme file cannot be read.', 'block-theme-ops' );
		}
		if ( Themes::hash( (string) $item['type'], $file ) === $snapshot['hash'] ) {
			return __( 'The theme file has not changed since this item was saved, so there is nothing to merge.', 'block-theme-ops' );
		}

		/**
		 * Filters why an item cannot be merged. Return text to block it, or an empty string to allow it.
		 *
		 * @param string $reason Default empty: the item can be merged.
		 * @param array  $item   The item.
		 */
		return Drift::text_or( apply_filters( 'block_theme_ops_merge_blocker', '', $item ), '' );
	}

	/**
	 * Works out the merge without writing anything.
	 *
	 * @param array                 $item    Item.
	 * @param array<string, string> $choices Conflict ID => `ours` or `theirs`.
	 * @return array|\WP_Error markup, result (BlockMerge), base, ours, theirs (markup), applied, conflicts, resolved, key.
	 */
	public function plan( array $item, array $choices = array() ) {
		$reason = $this->blocker( $item );
		if ( '' !== $reason ) {
			return new \WP_Error( 'block_theme_ops_no_merge', $reason );
		}

		$post = get_post( (int) $item['id'] );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'block_theme_ops_not_found', __( 'That item no longer exists.', 'block-theme-ops' ) );
		}

		$snapshot = Drift::snapshot( (int) $item['id'] );
		$base     = (string) ( $snapshot['content'] ?? '' );
		$ours     = (string) $post->post_content;
		$theirs   = $this->drift->themes()->read( (string) $item['path'] );

		$result = BlockMerge::merge( parse_blocks( $base ), parse_blocks( $ours ), parse_blocks( $theirs ), $choices );

		return array(
			'item'      => $item,
			'markup'    => serialize_blocks( $result['blocks'] ),
			'result'    => $result,
			'base'      => $base,
			'ours'      => $ours,
			'theirs'    => $theirs,
			'applied'   => $result['applied'],
			'conflicts' => array_values( $result['conflicts'] ),
			'resolved'  => $result['resolved'],
			// Changes when either side changes, so a merge applies only what was previewed.
			'key'       => md5( $ours . "\0" . $theirs ),
		);
	}

	/**
	 * Merges, after a backup. Every conflict needs a choice.
	 *
	 * @param array                 $item     Item.
	 * @param array<string, string> $choices  Conflict ID => `ours` or `theirs`.
	 * @param string                $expected Key of the plan the person looked at, empty to skip the check.
	 * @return array{item: array, backup: int, plan: array}|\WP_Error
	 */
	public function apply( array $item, array $choices, string $expected = '' ) {
		$plan = $this->plan( $item, $choices );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( '' !== $expected && $expected !== $plan['key'] ) {
			return new \WP_Error( 'block_theme_ops_merge_stale', __( 'The template or the theme file changed while you were looking at the preview. Look at the new preview and merge again.', 'block-theme-ops' ) );
		}
		if ( ! $plan['resolved'] ) {
			return new \WP_Error( 'block_theme_ops_merge_conflicts', __( 'Choose a version for every conflict first. Nothing was changed.', 'block-theme-ops' ) );
		}

		$post   = get_post( (int) $item['id'] );
		$backup = $this->backups->create(
			$item,
			$post,
			array(
				'kind'   => self::KIND,
				'merged' => $plan['markup'],
			)
		);
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => (int) $item['id'],
					'post_content' => $plan['markup'],
				)
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			$this->backups->delete( $backup );
			return new \WP_Error( 'block_theme_ops_merge_failed', __( 'WordPress could not save the merged template. Nothing was changed.', 'block-theme-ops' ) );
		}

		// The theme file as of now becomes the new base for the next theme update.
		$this->snapshots->store(
			(int) $item['id'],
			array(
				'hash'    => Themes::hash( (string) $item['type'], (string) $plan['theirs'] ),
				'content' => (string) $plan['theirs'],
				'file'    => (string) $item['file'],
				'theme'   => (string) $item['theme'],
				'time'    => time(),
				'source'  => 'save',
			)
		);
		delete_post_meta( (int) $item['id'], Drift::KEPT_META );
		delete_post_meta( (int) $item['id'], Drift::NOTIFIED_META );
		$this->alerts->remove( (int) $item['id'] );
		$this->drift->flush();

		/**
		 * Fires after a theme update is merged into a database copy.
		 *
		 * @param array $item      The item as it was before the merge.
		 * @param int   $backup_id Backup that Undo restores from.
		 * @param array $plan      The merge: markup, applied changes and conflicts with their choices.
		 */
		do_action( 'block_theme_ops_merged', $item, $backup, $plan );

		return array(
			'item'   => $item,
			'backup' => $backup,
			'plan'   => $plan,
		);
	}

	/**
	 * Brings the database copy back as it was before a merge.
	 *
	 * @param array $backup Backup of kind merge.
	 * @return int|\WP_Error The post ID.
	 */
	public function undo( array $backup ) {
		if ( $backup['undo_until'] <= time() ) {
			return new \WP_Error( 'block_theme_ops_expired', __( 'This merge is too old to undo.', 'block-theme-ops' ) );
		}

		$post = get_post( (int) $backup['original_id'] );
		if ( $post instanceof \WP_Post && ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'block_theme_ops_forbidden', __( 'You are not allowed to change this item.', 'block-theme-ops' ) );
		}
		if ( ! $post instanceof \WP_Post || $post->post_type !== $backup['post_type'] ) {
			return new \WP_Error( 'block_theme_ops_exists', __( 'The merged template no longer exists, so there is nothing to put back. Reset or restore it instead.', 'block-theme-ops' ) );
		}
		if ( ! Markup::same( (string) $post->post_content, (string) ( $backup['merged'] ?? '' ) ) ) {
			return new \WP_Error( 'block_theme_ops_exists', __( 'Someone customized this item again since the merge. Undo would lose that work. Reset it first, or leave it.', 'block-theme-ops' ) );
		}

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => (string) $backup['content'],
				)
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return new \WP_Error( 'block_theme_ops_undo_failed', __( 'WordPress could not put the earlier copy back. Nothing was changed.', 'block-theme-ops' ) );
		}

		if ( ! empty( $backup['snapshot'] ) && is_array( $backup['snapshot'] ) ) {
			$this->snapshots->store( (int) $post->ID, $backup['snapshot'] );
		}
		$this->backups->delete( (int) $backup['id'] );
		$this->drift->flush();

		/**
		 * Fires after a merge is undone and the earlier database copy is back.
		 *
		 * @param array $backup  The backup it came from.
		 * @param int   $post_id The post.
		 */
		do_action( 'block_theme_ops_merge_undone', $backup, (int) $post->ID );

		return (int) $post->ID;
	}

	/**
	 * Rendered HTML of a block list, for the preview.
	 *
	 * @param array[] $blocks Blocks.
	 * @return string
	 */
	public static function render( array $blocks ): string {
		$html = '';
		foreach ( $blocks as $block ) {
			$html .= render_block( $block );
		}

		return $html;
	}

	/**
	 * A complete HTML document of the preview, for a sandboxed frame: block styles, global styles, the rendered blocks.
	 *
	 * @param string $markup Merged template markup.
	 * @return string
	 */
	public static function preview_document( string $markup ): string {
		$styles = function_exists( 'wp_get_global_stylesheet' ) ? (string) wp_get_global_stylesheet() : '';
		$html   = self::render( parse_blocks( $markup ) );

		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<link rel="stylesheet" href="' . esc_url( includes_url( 'css/dist/block-library/style.min.css' ) ) . '">'
			. '<style>' . str_replace( '</style', '<\/style', $styles ) . ' body{margin:0;padding:16px}</style>'
			. '</head><body class="wp-site-blocks">' . $html . '</body></html>';
	}
}
