<?php
/**
 * Contract for Git hosts that take pull requests.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps\Git;

defined( 'ABSPATH' ) || exit;

/**
 * A Git host. GitHub ships with the plugin; others plug in through the
 * `block_theme_ops_git_provider` filter.
 */
interface Provider {

	/**
	 * Name shown on screen, such as "GitHub".
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Whether a repository and a token are set.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Where pull requests go, such as `owner/repo`, for the screen.
	 *
	 * @return string
	 */
	public function target(): string;

	/**
	 * Commits the files to a new branch and opens a pull request.
	 *
	 * @param array<string, string> $files Path inside the theme => contents.
	 * @param array[]               $items The exported items, for the description.
	 * @return array{url: string, number: int, branch: string}|\WP_Error
	 */
	public function open_pull_request( array $files, array $items );
}
