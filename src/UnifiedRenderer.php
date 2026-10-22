<?php
/**
 * Unified diff renderer for core's Text_Diff.
 *
 * Only loaded by Diff::unified(), after core's diff classes.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a Text_Diff as `@@ -a,b +c,d @@` hunks with three lines of context.
 */
final class UnifiedRenderer extends \Text_Diff_Renderer {

	/**
	 * Context lines before a change.
	 *
	 * @var int
	 */
	public $_leading_context_lines = 3; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Name set by the parent class.

	/**
	 * Context lines after a change.
	 *
	 * @var int
	 */
	public $_trailing_context_lines = 3; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Name set by the parent class.

	/**
	 * Hunk header.
	 *
	 * @param int $xbeg First old line.
	 * @param int $xlen Old line count.
	 * @param int $ybeg First new line.
	 * @param int $ylen New line count.
	 * @return string
	 */
	public function _blockHeader( $xbeg, $xlen, $ybeg, $ylen ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Overrides the parent.
		$old = 1 === (int) $xlen ? (string) $xbeg : $xbeg . ',' . $xlen;
		$new = 1 === (int) $ylen ? (string) $ybeg : $ybeg . ',' . $ylen;

		return '@@ -' . $old . ' +' . $new . ' @@';
	}

	/**
	 * Unchanged lines.
	 *
	 * @param string[] $lines Lines.
	 * @return string
	 */
	public function _context( $lines ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Overrides the parent.
		return $this->_lines( $lines, ' ' );
	}

	/**
	 * Added lines.
	 *
	 * @param string[] $lines Lines.
	 * @return string
	 */
	public function _added( $lines ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Overrides the parent.
		return $this->_lines( $lines, '+' );
	}

	/**
	 * Removed lines.
	 *
	 * @param string[] $lines Lines.
	 * @return string
	 */
	public function _deleted( $lines ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Overrides the parent.
		return $this->_lines( $lines, '-' );
	}

	/**
	 * Changed lines: the old ones removed, the new ones added.
	 *
	 * @param string[] $old_lines Old lines.
	 * @param string[] $new_lines New lines.
	 * @return string
	 */
	public function _changed( $old_lines, $new_lines ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Overrides the parent.
		return $this->_deleted( $old_lines ) . $this->_added( $new_lines );
	}
}
