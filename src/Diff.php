<?php
/**
 * Unified diffs, for abilities and WP-CLI.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Line diffs in the `diff -u` format, built on the diff engine that ships
 * with WordPress (the one behind the revisions screen).
 */
final class Diff {

	/**
	 * A unified diff between two texts, empty when they are the same.
	 *
	 * @param string $from       Old text.
	 * @param string $to         New text.
	 * @param string $from_label Label of the old text.
	 * @param string $to_label   Label of the new text.
	 * @return string
	 */
	public static function unified( string $from, string $to, string $from_label = 'a', string $to_label = 'b' ): string {
		if ( $from === $to ) {
			return '';
		}

		if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
			require ABSPATH . WPINC . '/wp-diff.php';
		}

		$from_lines = explode( "\n", str_replace( "\r\n", "\n", rtrim( $from ) ) );
		$to_lines   = explode( "\n", str_replace( "\r\n", "\n", rtrim( $to ) ) );
		$diff       = new \Text_Diff( 'auto', array( $from_lines, $to_lines ) );
		if ( $diff->isEmpty() ) {
			return '';
		}

		$renderer = new UnifiedRenderer();

		return '--- ' . $from_label . "\n+++ " . $to_label . "\n" . $renderer->render( $diff );
	}
}
