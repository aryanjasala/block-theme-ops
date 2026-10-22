<?php
/**
 * Pull requests on GitHub.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps\Git;

use AryanX\BlockThemeOps\Drift;
use AryanX\BlockThemeOps\Settings;
use AryanX\BlockThemeOps\Themes;
use const AryanX\BlockThemeOps\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Opens a pull request through the GitHub REST API, with all files in one
 * commit: read the base branch, add a tree, commit it, create the branch,
 * then open the pull request.
 */
class GitHub implements Provider {

	public const API = 'https://api.github.com';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Theme files.
	 *
	 * @var Themes
	 */
	private Themes $themes;

	/**
	 * Sets up the provider.
	 *
	 * @param Settings $settings Settings.
	 * @param Themes   $themes   Theme files.
	 */
	public function __construct( Settings $settings, Themes $themes ) {
		$this->settings = $settings;
		$this->themes   = $themes;
	}

	/**
	 * Name shown on screen.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'GitHub';
	}

	/**
	 * Whether a repository and a token are set.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->settings->get( 'repo' ) && '' !== $this->settings->token();
	}

	/**
	 * `owner/repo` and the base branch.
	 *
	 * @return string
	 */
	public function target(): string {
		return '' !== $this->settings->get( 'repo' ) ? $this->settings->get( 'repo' ) . ' (' . $this->settings->get( 'branch' ) . ')' : '';
	}

	/**
	 * Base address of the API. GitHub Enterprise Server and test doubles
	 * change it through the `block_theme_ops_github_api_url` filter.
	 *
	 * @return string
	 */
	public static function api_url(): string {
		/**
		 * Filters the GitHub API address, for GitHub Enterprise Server or a test double.
		 *
		 * @param string $url Default https://api.github.com.
		 */
		$url = (string) apply_filters( 'block_theme_ops_github_api_url', self::API );

		return preg_match( '#^https?://[^\s]+$#', $url ) ? untrailingslashit( $url ) : self::API;
	}

	/**
	 * Commits the files to a new branch and opens a pull request.
	 *
	 * @param array<string, string> $files Path inside the theme => contents.
	 * @param array[]               $items The exported items.
	 * @return array{url: string, number: int, branch: string}|\WP_Error
	 */
	public function open_pull_request( array $files, array $items ) {
		$repo = (string) $this->settings->get( 'repo' );
		$base = (string) $this->settings->get( 'branch' );
		$root = (string) $this->settings->get( 'path' );
		if ( '' === $repo || '' === $this->settings->token() ) {
			return new \WP_Error( 'block_theme_ops_not_configured', __( 'Set the repository and a token under Appearance > Theme Drift > Settings first.', 'block-theme-ops' ) );
		}

		$prefix = 'repos/' . $repo;
		$ref    = $this->request( 'GET', $prefix . '/git/ref/heads/' . self::encode_ref( $base ) );
		if ( is_wp_error( $ref ) ) {
			return $ref;
		}
		$base_sha = (string) ( $ref['object']['sha'] ?? '' );

		$commit = $this->request( 'GET', $prefix . '/git/commits/' . rawurlencode( $base_sha ) );
		if ( is_wp_error( $commit ) ) {
			return $commit;
		}

		$tree = array();
		foreach ( $files as $path => $contents ) {
			$tree[] = array(
				'path'    => ( '' !== $root ? $root . '/' : '' ) . $path,
				'mode'    => '100644',
				'type'    => 'blob',
				'content' => $contents,
			);
		}

		$new_tree = $this->request(
			'POST',
			$prefix . '/git/trees',
			array(
				'base_tree' => (string) ( $commit['tree']['sha'] ?? '' ),
				'tree'      => $tree,
			)
		);
		if ( is_wp_error( $new_tree ) ) {
			return $new_tree;
		}

		$title      = $this->title( $items );
		$new_commit = $this->request(
			'POST',
			$prefix . '/git/commits',
			array(
				'message' => $title,
				'tree'    => (string) ( $new_tree['sha'] ?? '' ),
				'parents' => array( $base_sha ),
			)
		);
		if ( is_wp_error( $new_commit ) ) {
			return $new_commit;
		}

		$branch  = $this->branch( $items );
		$created = $this->request(
			'POST',
			$prefix . '/git/refs',
			array(
				'ref' => 'refs/heads/' . $branch,
				'sha' => (string) ( $new_commit['sha'] ?? '' ),
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$pull = $this->request(
			'POST',
			$prefix . '/pulls',
			array(
				'title' => $title,
				'head'  => $branch,
				'base'  => $base,
				'body'  => $this->body( $items, $files ),
			)
		);
		if ( is_wp_error( $pull ) ) {
			return $pull;
		}

		return array(
			'url'    => esc_url_raw( (string) ( $pull['html_url'] ?? '' ) ),
			'number' => (int) ( $pull['number'] ?? 0 ),
			'branch' => $branch,
		);
	}

	/**
	 * Pull request title, also the commit message.
	 *
	 * @param array[] $items Items.
	 * @return string
	 */
	public function title( array $items ): string {
		$default = sprintf(
			/* translators: %s: site name. */
			__( 'Site Editor changes from %s', 'block-theme-ops' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
		);

		/**
		 * Filters the pull request title, which is also the commit message.
		 *
		 * @param string  $title Default "Site Editor changes from <site name>".
		 * @param array[] $items The exported items.
		 */
		$title = trim( (string) apply_filters( 'block_theme_ops_pr_title', $default, $items ) );

		return '' !== $title ? $title : $default;
	}

	/**
	 * Pull request description: what changed, who changed it and when.
	 *
	 * @param array[]               $items Items.
	 * @param array<string, string> $files Path inside the theme => contents.
	 * @return string
	 */
	public function body( array $items, array $files = array() ): string {
		$lines   = array();
		$lines[] = sprintf(
			/* translators: 1: site address, 2: theme folder name. */
			__( 'Changes made in the Site Editor on %1$s, for the theme `%2$s`. Merging this puts them in the theme files, so the database copies can be reset on the site afterwards.', 'block-theme-ops' ),
			home_url( '/' ),
			$this->themes->stylesheet()
		);
		$lines[] = '';
		$lines[] = '| ' . __( 'Item', 'block-theme-ops' ) . ' | ' . __( 'File', 'block-theme-ops' ) . ' | ' . __( 'Status', 'block-theme-ops' ) . ' | ' . __( 'Last edited by', 'block-theme-ops' ) . ' | ' . __( 'When (UTC)', 'block-theme-ops' ) . ' |';
		$lines[] = '| --- | --- | --- | --- | --- |';
		foreach ( $items as $item ) {
			$lines[] = sprintf(
				'| %s (%s) | `%s` | %s | %s | %s |',
				self::cell( (string) $item['title'] ),
				self::cell( (string) $item['type_label'] ),
				self::cell( $this->themes->export_path( (string) $item['type'], (string) $item['slug'] ) ),
				self::cell( Drift::status_label( (string) $item['status'] ) ),
				self::cell( (string) $item['author_name'] ),
				$item['modified'] ? gmdate( 'Y-m-d H:i', (int) $item['modified'] ) : ''
			);
		}
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %d: number of files. */
			_n( '%d file. Opened by Block Theme Ops.', '%d files. Opened by Block Theme Ops.', count( $files ), 'block-theme-ops' ),
			count( $files )
		);

		/**
		 * Filters the pull request description (Markdown).
		 *
		 * @param string                $body  Default: a table of items, files, status, editor and date.
		 * @param array[]               $items The exported items.
		 * @param array<string, string> $files Path inside the theme => contents.
		 */
		return (string) apply_filters( 'block_theme_ops_pr_body', implode( "\n", $lines ), $items, $files );
	}

	/**
	 * Name of the new branch.
	 *
	 * @param array[] $items Items.
	 * @return string
	 */
	public function branch( array $items ): string {
		$default = 'block-theme-ops/' . sanitize_title( $this->themes->stylesheet() ) . '-' . gmdate( 'Ymd-His' );

		/**
		 * Filters the name of the branch the pull request comes from.
		 *
		 * Names Git would refuse fall back to the default.
		 *
		 * @param string  $branch Default block-theme-ops/<theme>-<date>-<time>.
		 * @param array[] $items  The exported items.
		 */
		$branch = Settings::clean_ref( (string) apply_filters( 'block_theme_ops_branch_name', $default, $items ) );

		return '' !== $branch ? $branch : $default;
	}

	/**
	 * Calls the API.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $endpoint Path after the API address, without a leading slash.
	 * @param array|null $body     JSON body.
	 * @return array|\WP_Error Decoded response.
	 */
	public function request( string $method, string $endpoint, ?array $body = null ) {
		$args = array(
			'method'  => $method,
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Only runs when an admin opens a pull request. GitHub can take a few seconds to build a tree.
			'timeout' => 15,
			'headers' => array(
				'Accept'               => 'application/vnd.github+json',
				'Authorization'        => 'Bearer ' . $this->settings->token(),
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'block-theme-ops/' . VERSION,
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		/**
		 * Filters the arguments of every GitHub API request, such as the timeout or headers.
		 *
		 * @param array  $args     wp_remote_request() arguments.
		 * @param string $method   HTTP method.
		 * @param string $endpoint Path after the API address, such as repos/owner/repo/pulls.
		 */
		$filtered = (array) apply_filters( 'block_theme_ops_github_request_args', $args, $method, $endpoint );
		if ( $filtered ) {
			$args           = $filtered;
			$args['method'] = $method;
		}

		$response = wp_remote_request( self::api_url() . '/' . $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'block_theme_ops_github',
				/* translators: %s: error message. */
				sprintf( __( 'Could not reach GitHub: %s', 'block-theme-ops' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['message'] ) ? (string) $data['message'] : '';

			return new \WP_Error(
				'block_theme_ops_github',
				/* translators: 1: GitHub's error message, 2: HTTP status code, 3: API path. */
				sprintf( __( 'GitHub refused the request: %1$s (HTTP %2$d on %3$s)', 'block-theme-ops' ), '' !== $message ? $message : __( 'no message', 'block-theme-ops' ), $code, $endpoint ),
				array( 'status' => $code )
			);
		}

		return $data;
	}

	/**
	 * Encodes a branch name for a URL path, keeping its slashes.
	 *
	 * @param string $ref Branch name.
	 * @return string
	 */
	private static function encode_ref( string $ref ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $ref ) ) );
	}

	/**
	 * Text safe inside a Markdown table cell.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function cell( string $text ): string {
		return str_replace( array( '|', "\n", "\r" ), array( '\\|', ' ', '' ), $text );
	}
}
