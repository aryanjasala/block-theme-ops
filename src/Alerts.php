<?php
/**
 * Items a check found drifting, waiting for someone to look at them.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * The admin notice and the menu count read this option, so they never need
 * to scan theme files on an ordinary page load.
 */
class Alerts {

	public const OPTION = 'block_theme_ops_alerts';

	/**
	 * Post IDs of items that went theme-changed and nobody has dealt with yet.
	 *
	 * @return int[]
	 */
	public function ids(): array {
		$saved = get_option( self::OPTION, array() );
		$ids   = is_array( $saved ) && isset( $saved['ids'] ) && is_array( $saved['ids'] ) ? $saved['ids'] : array();

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * What found them: upgrade, cron or cli.
	 *
	 * @return string
	 */
	public function context(): string {
		$saved = get_option( self::OPTION, array() );

		return is_array( $saved ) && isset( $saved['context'] ) ? (string) $saved['context'] : '';
	}

	/**
	 * Adds items to the alert.
	 *
	 * @param int[]  $ids     Post IDs.
	 * @param string $context What found them.
	 */
	public function add( array $ids, string $context ): void {
		$this->save( array_merge( $this->ids(), $ids ), $context );
	}

	/**
	 * Removes one item, after it was reset or kept.
	 *
	 * @param int $id Post ID.
	 */
	public function remove( int $id ): void {
		$ids = $this->ids();
		if ( in_array( $id, $ids, true ) ) {
			$this->save( array_diff( $ids, array( $id ) ), $this->context() );
		}
	}

	/**
	 * Dismisses the alert.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Writes the option, or removes it when nothing is left.
	 *
	 * @param int[]  $ids     Post IDs.
	 * @param string $context What found them.
	 */
	private function save( array $ids, string $context ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			$this->clear();
			return;
		}

		update_option(
			self::OPTION,
			array(
				'ids'     => $ids,
				'context' => $context,
				'time'    => time(),
			),
			true
		);
	}
}
