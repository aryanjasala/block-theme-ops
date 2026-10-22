<?php
/**
 * The theme side: where the active theme keeps its files.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Finds and reads the theme file behind a template, template part or
 * global styles, in the active theme and then its parent, the way core
 * looks them up.
 */
class Themes {

	public const TEMPLATE      = 'wp_template';
	public const TEMPLATE_PART = 'wp_template_part';
	public const GLOBAL_STYLES = 'wp_global_styles';

	/**
	 * Folder names core uses when a theme does not say.
	 */
	private const DEFAULT_FOLDERS = array(
		self::TEMPLATE      => 'templates',
		self::TEMPLATE_PART => 'parts',
	);

	/**
	 * The active theme's folder name.
	 *
	 * @return string
	 */
	public function stylesheet(): string {
		return (string) get_stylesheet();
	}

	/**
	 * The parent theme's folder name, or the active theme's when it has no parent.
	 *
	 * @return string
	 */
	public function template(): string {
		return (string) get_template();
	}

	/**
	 * The theme folders to look in: the active theme first, then its parent.
	 *
	 * @return array<string, string> Theme folder name => absolute directory.
	 */
	public function directories(): array {
		$dirs = array( $this->stylesheet() => (string) get_stylesheet_directory() );
		if ( $this->template() !== $this->stylesheet() ) {
			$dirs[ $this->template() ] = (string) get_template_directory();
		}

		return $dirs;
	}

	/**
	 * Template and template part folder names of a theme: `templates` and
	 * `parts`, or the older `block-templates` and `block-template-parts`.
	 *
	 * @param string $theme Theme folder name.
	 * @return array<string, string>
	 */
	public function folders( string $theme ): array {
		$folders = function_exists( 'get_block_theme_folders' ) ? (array) get_block_theme_folders( $theme ) : array();

		return array_merge( self::DEFAULT_FOLDERS, array_intersect_key( $folders, self::DEFAULT_FOLDERS ) );
	}

	/**
	 * The theme file behind a database item.
	 *
	 * @param string $type Post type.
	 * @param string $slug Template slug. Ignored for global styles.
	 * @return array{path: string, file: string, theme: string}|null Absolute path, path inside the theme and the theme folder, or null when there is no file.
	 */
	public function locate( string $type, string $slug ): ?array {
		if ( self::GLOBAL_STYLES === $type ) {
			$relative = 'theme.json';
		} else {
			if ( ! isset( self::DEFAULT_FOLDERS[ $type ] ) || ! self::is_safe_slug( $slug ) ) {
				return null;
			}
			$relative = '';
		}

		foreach ( $this->directories() as $theme => $dir ) {
			$file = '' !== $relative ? $relative : $this->folders( $theme )[ $type ] . '/' . $slug . '.html';
			$path = trailingslashit( $dir ) . $file;
			if ( is_file( $path ) && is_readable( $path ) ) {
				return array(
					'path'  => $path,
					'file'  => $file,
					'theme' => (string) $theme,
				);
			}
		}

		return null;
	}

	/**
	 * Where a database item goes in the active theme, for exports.
	 *
	 * @param string $type Post type.
	 * @param string $slug Template slug.
	 * @return string Path inside the theme.
	 */
	public function export_path( string $type, string $slug ): string {
		if ( self::GLOBAL_STYLES === $type ) {
			return 'theme.json';
		}

		return $this->folders( $this->stylesheet() )[ $type ] . '/' . $slug . '.html';
	}

	/**
	 * Contents of a theme file.
	 *
	 * @param string $path Absolute path.
	 * @return string Empty when it cannot be read.
	 */
	public function read( string $path ): string {
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local theme file, the same way core reads templates.
		$contents = file_get_contents( $path );

		return false === $contents ? '' : $contents;
	}

	/**
	 * When a theme file last changed.
	 *
	 * @param string $path Absolute path.
	 * @return int Unix time, 0 when unknown.
	 */
	public function mtime( string $path ): int {
		if ( '' === $path ) {
			return 0;
		}

		clearstatcache( true, $path );
		$time = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file just means no time.

		return false === $time ? 0 : (int) $time;
	}

	/**
	 * Hash of a theme file's contents that ignores formatting.
	 *
	 * @param string $type     Post type.
	 * @param string $contents File contents.
	 * @return string
	 */
	public static function hash( string $type, string $contents ): string {
		return self::GLOBAL_STYLES === $type ? StylesJson::hash( $contents ) : Markup::hash( $contents );
	}

	/**
	 * Whether a slug can be part of a file name: no folders, no dots at the start.
	 *
	 * @param string $slug Template slug.
	 * @return bool
	 */
	public static function is_safe_slug( string $slug ): bool {
		return '' !== $slug && (bool) preg_match( '/^[A-Za-z0-9_%-][A-Za-z0-9_.%-]*$/', $slug ) && false === strpos( $slug, '..' );
	}
}
