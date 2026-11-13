<?php
/**
 * Export of database items as theme files: a zip or a pull request.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

use AryanX\Axle\BlockThemeOps\Git\GitHub;
use AryanX\Axle\BlockThemeOps\Git\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Turns database items back into files laid out like the theme:
 * `templates/*.html`, `parts/*.html` and `theme.json`.
 */
class Exporter {

	/**
	 * Inventory.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the exporter.
	 *
	 * @param Drift    $drift    Inventory.
	 * @param Settings $settings Settings.
	 */
	public function __construct( Drift $drift, Settings $settings ) {
		$this->drift    = $drift;
		$this->settings = $settings;
	}

	/**
	 * Files for the given items, by path inside the theme.
	 *
	 * Templates and parts are the database markup without the `theme`
	 * attribute the Site Editor adds. Global styles become the theme's
	 * theme.json with the changes merged in.
	 *
	 * @param array[] $items   Items.
	 * @param string  $context zip, pr, cli or ability.
	 * @return array<string, string>
	 */
	public function files( array $items, string $context ): array {
		$themes = $this->drift->themes();
		$files  = array();

		foreach ( $items as $item ) {
			$post = get_post( (int) $item['id'] );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$path = $themes->export_path( (string) $item['type'], (string) $item['slug'] );
			if ( Themes::GLOBAL_STYLES === $item['type'] ) {
				$theme_json     = '' !== $item['path'] ? StylesJson::decode( $themes->read( (string) $item['path'] ) ) : array();
				$files[ $path ] = StylesJson::pretty( StylesJson::merge( $theme_json, StylesJson::decode( (string) $post->post_content ) ) ) . "\n";
			} else {
				$files[ $path ] = rtrim( Markup::strip_theme_attribute( (string) $post->post_content ) ) . "\n";
			}
		}

		/**
		 * Filters the files an export writes, before the zip or pull request is made.
		 *
		 * Paths are relative to the theme folder. Paths that leave the theme
		 * folder, and anything that is not text, are dropped afterwards.
		 *
		 * @param array<string, string> $files   Path => contents.
		 * @param array[]               $items   The exported items.
		 * @param string                $context zip, pr, cli or ability.
		 */
		return self::clean_files( (array) apply_filters( 'block_theme_ops_export_files', $files, $items, $context ) );
	}

	/**
	 * Keeps only safe relative paths with text contents.
	 *
	 * @param array $files Path => contents.
	 * @return array<string, string>
	 */
	public static function clean_files( array $files ): array {
		$clean = array();
		foreach ( $files as $path => $contents ) {
			$path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
			if ( ! is_string( $contents ) || '' === $path || ! preg_match( '#^[A-Za-z0-9_.@/-]+$#', $path ) || preg_match( '#(^|/)\.\.?(/|$)#', $path ) ) {
				continue;
			}
			$clean[ $path ] = $contents;
		}
		ksort( $clean );

		return $clean;
	}

	/**
	 * Builds a zip laid out like the theme, inside a folder named after it.
	 *
	 * Next to the files goes a small manifest, `block-theme-ops.json`, with what
	 * differs between sites: the site address, navigation menu slugs, media
	 * addresses and template part areas. Import reads it to map them.
	 *
	 * @param array<string, string> $files Path => contents.
	 * @param array[]               $items The exported items, for template part areas.
	 * @return string|\WP_Error Path of a temporary zip file. The caller deletes it.
	 */
	public function zip( array $files, array $items = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'block_theme_ops_no_zip', __( 'This server\'s PHP has no zip support (the ZipArchive class). Ask your host to enable the zip extension.', 'block-theme-ops' ) );
		}
		if ( ! $files ) {
			return new \WP_Error( 'block_theme_ops_nothing', __( 'There is nothing to export.', 'block-theme-ops' ) );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$path = wp_tempnam( 'block-theme-ops.zip' );
		$zip  = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'block_theme_ops_zip_failed', __( 'The zip file could not be created.', 'block-theme-ops' ) );
		}

		$folder = $this->drift->themes()->stylesheet();
		foreach ( $files as $file => $contents ) {
			$zip->addFromString( $folder . '/' . $file, $contents );
		}
		$zip->addFromString( $folder . '/' . Importer::MANIFEST, (string) wp_json_encode( $this->manifest( $files, $items ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		$zip->close();

		return $path;
	}

	/**
	 * What import needs to know about this site: the address, navigation menus
	 * and media the markup points at, and the area of each template part.
	 *
	 * @param array<string, string> $files Path => contents.
	 * @param array[]               $items The exported items.
	 * @return array
	 */
	public function manifest( array $files, array $items = array() ): array {
		$navigation = array();
		$media      = array();
		$attrs      = Importer::MEDIA_ATTRS;


		foreach ( $files as $path => $contents ) {
			if ( '.html' !== substr( (string) $path, -5 ) ) {
				continue;
			}
			Markup::map_blocks(
				(string) $contents,
				static function ( string $name, array $block ) use ( &$navigation, &$media, $attrs ): ?array {
					if ( 'core/navigation' === $name && ! empty( $block['ref'] ) ) {
						$menu = get_post( (int) $block['ref'] );
						if ( $menu instanceof \WP_Post && '' !== $menu->post_name ) {
							$navigation[ (int) $block['ref'] ] = (string) $menu->post_name;
						}
					} elseif ( isset( $attrs[ $name ], $block[ $attrs[ $name ] ] ) ) {
						$url = wp_get_attachment_url( (int) $block[ $attrs[ $name ] ] );
						if ( is_string( $url ) && '' !== $url ) {
							$media[ (int) $block[ $attrs[ $name ] ] ] = $url;
						}
					}

					return null;
				}
			);
		}

		$areas = array();
		foreach ( $items as $item ) {
			if ( Themes::TEMPLATE_PART !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			$terms = get_the_terms( (int) $item['id'], 'wp_template_part_area' );
			if ( is_array( $terms ) && isset( $terms[0]->name ) ) {
				$areas[ (string) $item['slug'] ] = (string) $terms[0]->name;
			}
		}

		return array(
			'format'     => 1,
			'theme'      => $this->drift->themes()->stylesheet(),
			'site_url'   => untrailingslashit( (string) home_url() ),
			'exported'   => gmdate( 'c' ),
			'navigation' => $navigation ? $navigation : new \stdClass(),
			'media'      => $media ? $media : new \stdClass(),
			'areas'      => $areas ? $areas : new \stdClass(),
		);
	}

	/**
	 * Name for the downloaded zip.
	 *
	 * @return string
	 */
	public function zip_name(): string {
		return sanitize_file_name( $this->drift->themes()->stylesheet() . '-site-editor-changes-' . gmdate( 'Y-m-d' ) . '.zip' );
	}

	/**
	 * Tells listeners an export happened.
	 *
	 * @param array<string, string> $files   Exported files.
	 * @param array[]               $items   Exported items.
	 * @param string                $context zip, pr, cli or ability.
	 */
	public function exported( array $files, array $items, string $context ): void {
		/**
		 * Fires after items are exported as theme files.
		 *
		 * @param array<string, string> $files   Path => contents.
		 * @param array[]               $items   The exported items.
		 * @param string                $context zip, pr, cli or ability.
		 */
		do_action( 'block_theme_ops_exported', $files, $items, $context );
	}

	/**
	 * The Git host pull requests go to.
	 *
	 * @return Provider
	 */
	public function provider(): Provider {
		$default = new GitHub( $this->settings, $this->drift->themes() );

		/**
		 * Filters the Git host that receives pull requests. Return another
		 * implementation of AryanX\Axle\BlockThemeOps\Git\Provider, say for GitLab.
		 *
		 * @param Provider $provider Default GitHub.
		 * @param Settings $settings Saved settings.
		 */
		$provider = apply_filters( 'block_theme_ops_git_provider', $default, $this->settings );

		return self::is_provider( $provider ) ? $provider : $default;
	}

	/**
	 * Whether a filter returned a usable Git host.
	 *
	 * @param mixed $provider Filter result.
	 * @return bool
	 */
	private static function is_provider( $provider ): bool {
		return $provider instanceof Provider;
	}

	/**
	 * Opens a pull request with the given items as theme files.
	 *
	 * @param array[] $items   Items.
	 * @param string  $context pr or cli.
	 * @return array{url: string, number: int, branch: string, files: array}|\WP_Error
	 */
	public function pull_request( array $items, string $context = 'pr' ) {
		if ( ! $items ) {
			return new \WP_Error( 'block_theme_ops_nothing', __( 'There is nothing to export.', 'block-theme-ops' ) );
		}

		$provider = $this->provider();
		if ( ! $provider->is_configured() ) {
			return new \WP_Error( 'block_theme_ops_not_configured', __( 'Set the repository and a token under Appearance > Theme Drift > Settings first.', 'block-theme-ops' ) );
		}

		$files = $this->files( $items, $context );
		if ( ! $files ) {
			return new \WP_Error( 'block_theme_ops_nothing', __( 'There is nothing to export.', 'block-theme-ops' ) );
		}

		$result = $provider->open_pull_request( $files, $items );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->exported( $files, $items, $context );

		/**
		 * Fires after a pull request with Site Editor changes is opened.
		 *
		 * @param string                $url   Pull request address.
		 * @param array[]               $items The exported items.
		 * @param array<string, string> $files Path inside the theme => contents.
		 */
		do_action( 'block_theme_ops_pull_request_opened', $result['url'], $items, $files );

		return $result + array( 'files' => $files );
	}
}
