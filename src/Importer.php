<?php
/**
 * Import of Site Editor changes from a zip, with a preview first and one Undo.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

use AryanX\Axle\BlockThemeOps\Admin\DriftPage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the zip this plugin exports (or a plain theme-shaped zip), shows what
 * would change on this site, and writes the chosen items into the database.
 *
 * The zip is only ever read as text. Nothing in it is run, included or
 * unpacked to disk.
 */
class Importer {

	public const MANIFEST = 'block-theme-ops.json';

	public const NEW           = 'new';
	public const CHANGED       = 'changed';
	public const IDENTICAL     = 'identical';
	public const OTHER_THEME   = 'other-theme';
	public const THEME_MATCH   = 'match';
	public const THEME_UNKNOWN = 'unknown';
	public const THEME_RELATED = 'related';
	public const THEME_OTHER   = 'other';

	/**
	 * Folder names an export can use, and the one each stands for.
	 */
	private const FOLDERS = array(
		'templates'            => Themes::TEMPLATE,
		'block-templates'      => Themes::TEMPLATE,
		'parts'                => Themes::TEMPLATE_PART,
		'block-template-parts' => Themes::TEMPLATE_PART,
	);

	/**
	 * Block attributes that hold a media ID, by block.
	 */
	public const MEDIA_ATTRS = array(
		'core/image'      => 'id',
		'core/cover'      => 'id',
		'core/video'      => 'id',
		'core/audio'      => 'id',
		'core/file'       => 'id',
		'core/media-text' => 'mediaId',
		'core/site-logo'  => 'logo',
	);

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
	 * Pending alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Sets up the importer.
	 *
	 * @param Drift   $drift   Inventory.
	 * @param Backups $backups Backups.
	 * @param Alerts  $alerts  Pending alerts.
	 */
	public function __construct( Drift $drift, Backups $backups, Alerts $alerts ) {
		$this->drift   = $drift;
		$this->backups = $backups;
		$this->alerts  = $alerts;
	}

	/**
	 * Capability needed to import.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to import Site Editor changes, on the screen and in abilities.
		 * Each item is also checked against the post it writes.
		 *
		 * @param string $capability Default the same as Theme Drift itself, edit_theme_options.
		 */
		$capability = (string) apply_filters( 'block_theme_ops_import_capability', DriftPage::capability() );

		return '' !== $capability ? $capability : DriftPage::capability();
	}

	/**
	 * Size limits for an import.
	 *
	 * @return array{files: int, file: int, total: int, ratio: int}
	 */
	public static function limits(): array {
		/**
		 * Filters the most files a zip may hold.
		 *
		 * @param int $files Default 200. Kept between 1 and 2000.
		 */
		$files = (int) apply_filters( 'block_theme_ops_import_max_files', 200 );
		/**
		 * Filters the largest a single file in the zip may be, in bytes, once unpacked.
		 *
		 * @param int $bytes Default 1 MiB. Kept between 1 KiB and 16 MiB.
		 */
		$file = (int) apply_filters( 'block_theme_ops_import_max_file_size', MB_IN_BYTES );
		/**
		 * Filters the most all files of a zip may add up to, in bytes, once unpacked.
		 *
		 * @param int $bytes Default 4 MiB. Kept between 1 KiB and 64 MiB.
		 */
		$total = (int) apply_filters( 'block_theme_ops_import_max_size', 4 * MB_IN_BYTES );
		/**
		 * Filters the largest unpacked-to-packed size ratio of a file, which stops zip bombs.
		 *
		 * @param int $ratio Default 100. Kept between 2 and 1000.
		 */
		$ratio = (int) apply_filters( 'block_theme_ops_import_max_ratio', 100 );

		return array(
			'files' => max( 1, min( 2000, $files ) ),
			'file'  => max( KB_IN_BYTES, min( 16 * MB_IN_BYTES, $file ) ),
			'total' => max( KB_IN_BYTES, min( 64 * MB_IN_BYTES, $total ) ),
			'ratio' => max( 2, min( 1000, $ratio ) ),
		);
	}

	/**
	 * Reads and checks a zip. Files that are not allowed are listed, not fatal.
	 *
	 * @param string $path Path of the zip on disk.
	 * @return array{theme: string, files: array<string, string>, manifest: array, rejected: array<string, string>}|\WP_Error
	 */
	public function read_zip( string $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'block_theme_ops_no_zip', __( 'This server\'s PHP has no zip support (the ZipArchive class). Ask your host to enable the zip extension.', 'block-theme-ops' ) );
		}

		$limits = self::limits();
		$zip    = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CHECKCONS ) ) {
			return new \WP_Error( 'block_theme_ops_bad_zip', __( 'That is not a zip file, or it is damaged. Export a new one and try again.', 'block-theme-ops' ) );
		}

		if ( $zip->count() > $limits['files'] ) {
			$zip->close();
			/* translators: %d: number of files. */
			return new \WP_Error( 'block_theme_ops_too_many', sprintf( __( 'The zip holds more than %d files. A Site Editor export has a handful.', 'block-theme-ops' ), $limits['files'] ) );
		}

		$entries  = array();
		$rejected = array();
		$total    = 0;

		$count = $zip->count();
		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) ) {
				continue;
			}

			$name = str_replace( '\\', '/', (string) $stat['name'] );
			if ( '' === $name || '/' === substr( $name, -1 ) || 0 === strpos( $name, '__MACOSX/' ) || '.DS_Store' === basename( $name ) ) {
				continue;
			}

			$reason = self::path_problem( $name );
			if ( '' === $reason ) {
				$opsys = 0;
				$attr  = 0;
				if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) && \ZipArchive::OPSYS_UNIX === $opsys && 0120000 === ( ( $attr >> 16 ) & 0170000 ) ) {
					$reason = __( 'It is a symbolic link.', 'block-theme-ops' );
				}
			}
			if ( '' === $reason && (int) $stat['size'] > $limits['file'] ) {
				$reason = __( 'It is too large.', 'block-theme-ops' );
			}
			if ( '' === $reason && (int) $stat['comp_size'] > 0 && (int) $stat['size'] / (int) $stat['comp_size'] > $limits['ratio'] ) {
				$reason = __( 'It is packed far smaller than it should be.', 'block-theme-ops' );
			}
			if ( '' !== $reason ) {
				$rejected[ $name ] = $reason;
				continue;
			}

			$total += (int) $stat['size'];
			if ( $total > $limits['total'] ) {
				$zip->close();
				return new \WP_Error( 'block_theme_ops_too_large', __( 'The files in the zip add up to more than the size limit.', 'block-theme-ops' ) );
			}
			$entries[ $name ] = $i;
		}

		$folder = self::common_folder( array_keys( $entries ) );
		$files  = array();
		$theme  = $folder;

		foreach ( $entries as $name => $index ) {
			$relative = '' !== $folder ? substr( $name, strlen( $folder ) + 1 ) : $name;
			$target   = self::target( $relative );
			if ( '' === $target ) {
				$rejected[ $name ] = __( 'It is not a template, a template part or theme.json.', 'block-theme-ops' );
				continue;
			}

			$contents = $zip->getFromIndex( $index );
			if ( ! is_string( $contents ) ) {
				$rejected[ $name ] = __( 'It could not be read.', 'block-theme-ops' );
				continue;
			}

			$problem = self::contents_problem( $target, $contents );
			if ( '' !== $problem ) {
				$rejected[ $name ] = $problem;
				continue;
			}
			$files[ $target ] = $contents;
		}
		$zip->close();

		$manifest = array();
		if ( isset( $files[ self::MANIFEST ] ) ) {
			$manifest = self::clean_manifest( StylesJson::decode( $files[ self::MANIFEST ] ) );
			unset( $files[ self::MANIFEST ] );
			if ( '' !== $manifest['theme'] ) {
				$theme = $manifest['theme'];
			}
		}

		if ( ! $files ) {
			return new \WP_Error( 'block_theme_ops_empty_zip', __( 'The zip has no templates, template parts or theme.json that can be imported.', 'block-theme-ops' ) );
		}

		return array(
			'theme'    => $theme,
			'files'    => $files,
			'manifest' => $manifest,
			'rejected' => $rejected,
		);
	}

	/**
	 * What would change on this site if the files were imported.
	 *
	 * @param array $payload From read_zip().
	 * @return array{theme: string, theme_status: string, entries: array[], notes: array, rejected: array<string, string>, stylesheet: string}
	 */
	public function plan( array $payload ): array {
		$themes   = $this->drift->themes();
		$manifest = (array) ( $payload['manifest'] ?? array() );
		$status   = $this->theme_status( (string) ( $payload['theme'] ?? '' ) );
		$notes    = array(
			'navigation' => array(),
			'media'      => array(),
			'site_url'   => array(),
		);
		$entries  = array();

		foreach ( (array) $payload['files'] as $path => $contents ) {
			$path = (string) $path;
			if ( Themes::GLOBAL_STYLES === self::type_of( $path ) ) {
				$entry = $this->plan_styles( $contents );
			} else {
				$entry = $this->plan_template( $path, $contents, $manifest, $notes );
			}
			if ( self::THEME_OTHER === $status ) {
				$entry['status'] = self::OTHER_THEME;
			}
			$entry['path'] = $path;
			$entries[]     = $entry;
		}

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return array( $a['type'], $a['slug'] ) <=> array( $b['type'], $b['slug'] );
			}
		);

		$notes['navigation'] = array_values( array_unique( $notes['navigation'] ) );
		$notes['media']      = array_values( array_unique( $notes['media'] ) );

		/**
		 * Filters the preview of an import before it is shown or applied.
		 *
		 * Each entry has `key`, `type`, `slug`, `status` (new, changed, identical or other-theme),
		 * `content` (what would be written) and `live` (what the site has now, or null).
		 *
		 * @param array[] $entries The entries.
		 * @param array   $payload What was read from the zip.
		 */
		$entries = (array) apply_filters( 'block_theme_ops_import_entries', $entries, $payload );

		return array(
			'theme'        => (string) ( $payload['theme'] ?? '' ),
			'theme_status' => $status,
			'entries'      => array_values( array_filter( $entries, 'is_array' ) ),
			'notes'        => $notes,
			'rejected'     => (array) ( $payload['rejected'] ?? array() ),
			'stylesheet'   => $themes->stylesheet(),
		);
	}

	/**
	 * How the theme the files came from relates to the active theme.
	 *
	 * @param string $theme Theme folder name from the zip, empty when it has none.
	 * @return string match, unknown (plain zip with no folder), related (a child and its parent) or other.
	 */
	public function theme_status( string $theme ): string {
		$themes = $this->drift->themes();
		if ( '' === $theme ) {
			return self::THEME_UNKNOWN;
		}
		if ( $theme === $themes->stylesheet() ) {
			return self::THEME_MATCH;
		}

		$related = $theme === $themes->template();
		if ( ! $related && function_exists( 'wp_get_theme' ) ) {
			$other   = wp_get_theme( $theme );
			$related = $other->exists() && $other->get_template() === $themes->stylesheet();
		}

		/**
		 * Filters whether a zip made for another theme may be imported after the person confirms.
		 *
		 * By default that is a child theme and its parent, in either direction.
		 *
		 * @param bool   $related Whether the two themes are a child and its parent.
		 * @param string $theme   Theme folder name in the zip.
		 * @param string $active  The active theme's folder name.
		 */
		$related = (bool) apply_filters( 'block_theme_ops_import_related_theme', $related, $theme, $themes->stylesheet() );

		return $related ? self::THEME_RELATED : self::THEME_OTHER;
	}

	/**
	 * Writes the chosen items into the database, keeping a backup of everything it changes.
	 *
	 * @param array    $payload From read_zip().
	 * @param string[] $keys    Item keys to import (`parts/header`, `styles`). Empty means every new or changed item.
	 * @param array    $options `confirm_theme` to import a zip made for a related theme, `check_caps` to check each post's capability.
	 * @return array{backup: int, created: string[], updated: string[], notes: array}|\WP_Error
	 */
	public function apply( array $payload, array $keys = array(), array $options = array() ) {
		$plan = $this->plan( $payload );

		if ( self::THEME_OTHER === $plan['theme_status'] ) {
			/* translators: 1: theme in the zip, 2: active theme. */
			return new \WP_Error( 'block_theme_ops_other_theme', sprintf( __( 'This zip is for the theme %1$s. The active theme is %2$s, so nothing was imported.', 'block-theme-ops' ), $plan['theme'], $plan['stylesheet'] ) );
		}
		if ( self::THEME_RELATED === $plan['theme_status'] && empty( $options['confirm_theme'] ) ) {
			/* translators: 1: theme in the zip, 2: active theme. */
			return new \WP_Error( 'block_theme_ops_confirm_theme', sprintf( __( 'This zip is for the theme %1$s, not the active theme, %2$s. Confirm that they belong together to import it.', 'block-theme-ops' ), $plan['theme'], $plan['stylesheet'] ) );
		}

		$entries = array_values(
			array_filter(
				$plan['entries'],
				static function ( array $entry ) use ( $keys ): bool {
					return in_array( $entry['status'], array( self::NEW, self::CHANGED ), true ) && ( ! $keys || in_array( $entry['key'], $keys, true ) );
				}
			)
		);
		if ( ! $entries ) {
			return new \WP_Error( 'block_theme_ops_nothing', __( 'There is nothing to import: every chosen item is the same as on this site.', 'block-theme-ops' ) );
		}

		$check = ! empty( $options['check_caps'] );
		foreach ( $entries as $entry ) {
			if ( $check && ! $this->may_write( $entry ) ) {
				/* translators: %s: item name. */
				return new \WP_Error( 'block_theme_ops_forbidden', sprintf( __( 'You are not allowed to change %s. Nothing was imported.', 'block-theme-ops' ), $entry['key'] ), array( 'status' => 403 ) );
			}
		}

		/**
		 * Fires before an import writes anything.
		 *
		 * @param array[] $entries The entries about to be written.
		 * @param array   $plan    The preview they came from.
		 */
		do_action( 'block_theme_ops_before_import', $entries, $plan );

		$records = array();
		foreach ( $entries as $entry ) {
			$record = Themes::GLOBAL_STYLES === $entry['type'] ? $this->write_styles( $entry ) : $this->write_template( $entry );
			if ( is_wp_error( $record ) ) {
				$this->roll_back( $records );
				return $record;
			}
			$records[] = $record;
		}

		$backup = $this->backups->create_import( $plan['stylesheet'], $records );
		if ( is_wp_error( $backup ) ) {
			$this->roll_back( $records );
			return new \WP_Error( 'block_theme_ops_backup_failed', __( 'The backup could not be saved, so nothing was imported.', 'block-theme-ops' ) );
		}

		foreach ( $records as $record ) {
			$this->alerts->remove( (int) $record['post_id'] );
		}
		$this->drift->flush();

		$result = array(
			'backup'  => $backup,
			'created' => $this->keys_of( $records, 'created' ),
			'updated' => $this->keys_of( $records, 'updated' ),
			'notes'   => $plan['notes'],
		);

		/**
		 * Fires after Site Editor changes are imported.
		 *
		 * @param array $result `backup` (the ID Undo uses), `created` and `updated` (item keys) and `notes`.
		 * @param array $plan   The preview the import was made from.
		 */
		do_action( 'block_theme_ops_imported', $result, $plan );

		return $result;
	}

	/**
	 * Puts back what an import wrote: created items are deleted, changed items get their old content.
	 * An item someone changed again since the import is left alone and listed.
	 *
	 * @param array $backup A backup made by apply().
	 * @return array{undone: string[], skipped: array<string, string>}|\WP_Error
	 */
	public function undo( array $backup ) {
		if ( ( $backup['undo_until'] ?? 0 ) <= time() ) {
			return new \WP_Error( 'block_theme_ops_expired', __( 'This import is too old to undo.', 'block-theme-ops' ) );
		}

		$undone  = array();
		$skipped = array();
		foreach ( array_reverse( (array) ( $backup['records'] ?? array() ) ) as $record ) {
			$key  = (string) $record['key'];
			$post = get_post( (int) $record['post_id'] );

			if ( ! $post instanceof \WP_Post ) {
				$skipped[ $key ] = __( 'It no longer exists.', 'block-theme-ops' );
				continue;
			}
			if ( ! self::same_content( (string) $record['post_type'], (string) $post->post_content, (string) $record['after'] ) ) {
				$skipped[ $key ] = __( 'Someone changed it again since the import.', 'block-theme-ops' );
				continue;
			}

			if ( 'created' === $record['action'] ) {
				$done = (bool) wp_delete_post( $post->ID, true );
			} else {
				$done = ! is_wp_error( wp_update_post( wp_slash( $this->content_args( $post->ID, (string) $record['before'] ) ), true ) );
			}

			if ( $done ) {
				$undone[] = $key;
			} else {
				$skipped[ $key ] = __( 'WordPress could not change it.', 'block-theme-ops' );
			}
		}

		if ( ! $undone && $skipped ) {
			return new \WP_Error( 'block_theme_ops_cannot_undo', __( 'Nothing could be undone: every item was changed again since the import.', 'block-theme-ops' ) );
		}

		Operations::clean_styles_cache();
		$this->backups->delete( (int) $backup['id'] );
		$this->drift->flush();

		$result = array(
			'undone'  => array_reverse( $undone ),
			'skipped' => $skipped,
		);

		/**
		 * Fires after an import is undone.
		 *
		 * @param array $result `undone` (item keys) and `skipped` (key => reason).
		 * @param array $backup The backup it came from.
		 */
		do_action( 'block_theme_ops_import_undone', $result, $backup );

		return $result;
	}

	/**
	 * Keeps a validated zip for a while, so the preview and the apply step read the same files.
	 *
	 * @param array $payload From read_zip().
	 * @return string Token.
	 */
	public function stash( array $payload ): string {
		$token = strtolower( wp_generate_password( 20, false, false ) );

		/**
		 * Filters how long, in seconds, an uploaded zip waits for the person to review it.
		 *
		 * @param int $seconds Default one hour. Kept between 5 minutes and one day.
		 */
		$ttl = max( 5 * MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, (int) apply_filters( 'block_theme_ops_import_ttl', HOUR_IN_SECONDS ) ) );
		set_transient( self::stash_key( $token ), $payload, $ttl );

		return $token;
	}

	/**
	 * A stashed zip.
	 *
	 * @param string $token Token from stash().
	 * @return array|null
	 */
	public function stashed( string $token ): ?array {
		if ( ! preg_match( '/^[a-z0-9]{20}$/', $token ) ) {
			return null;
		}
		$payload = get_transient( self::stash_key( $token ) );

		return is_array( $payload ) && isset( $payload['files'] ) ? $payload : null;
	}

	/**
	 * Drops a stashed zip.
	 *
	 * @param string $token Token.
	 */
	public function forget( string $token ): void {
		if ( preg_match( '/^[a-z0-9]{20}$/', $token ) ) {
			delete_transient( self::stash_key( $token ) );
		}
	}

	/**
	 * Which template, part or styles an archive path is, or empty when it is none of them.
	 *
	 * @param string $relative Path inside the theme folder.
	 * @return string Normalised path (`templates/x.html`, `parts/x.html`, `theme.json`, the manifest), or empty.
	 */
	public static function target( string $relative ): string {
		if ( 'theme.json' === $relative || self::MANIFEST === $relative ) {
			return $relative;
		}

		$bits = explode( '/', $relative );
		if ( 2 !== count( $bits ) || ! isset( self::FOLDERS[ $bits[0] ] ) || '.html' !== substr( $bits[1], -5 ) ) {
			return '';
		}

		$slug = substr( $bits[1], 0, -5 );
		if ( ! Themes::is_safe_slug( $slug ) ) {
			return '';
		}

		return ( Themes::TEMPLATE === self::FOLDERS[ $bits[0] ] ? 'templates/' : 'parts/' ) . $slug . '.html';
	}

	/**
	 * The post type a normalised path is for.
	 *
	 * @param string $path Path from target().
	 * @return string
	 */
	public static function type_of( string $path ): string {
		if ( 'theme.json' === $path ) {
			return Themes::GLOBAL_STYLES;
		}

		return 0 === strpos( $path, 'parts/' ) ? Themes::TEMPLATE_PART : Themes::TEMPLATE;
	}

	/**
	 * Why a path inside a zip is not safe, or empty when it is.
	 *
	 * @param string $name Path as stored in the zip.
	 * @return string
	 */
	public static function path_problem( string $name ): string {
		if ( '/' === $name[0] || preg_match( '#^[A-Za-z]:#', $name ) ) {
			return __( 'It has an absolute path.', 'block-theme-ops' );
		}
		if ( preg_match( '#(^|/)\.\.?(/|$)#', $name ) ) {
			return __( 'Its path leaves the theme folder.', 'block-theme-ops' );
		}
		if ( ! preg_match( '#^[A-Za-z0-9_.@/ -]+$#', $name ) ) {
			return __( 'Its name has characters that are not allowed.', 'block-theme-ops' );
		}

		return '';
	}

	/**
	 * Why the contents of a file cannot be imported, or empty when they can.
	 *
	 * @param string $target   Path from target().
	 * @param string $contents File contents.
	 * @return string
	 */
	public static function contents_problem( string $target, string $contents ): string {
		if ( false !== strpos( $contents, "\0" ) || ! preg_match( '//u', $contents ) ) {
			return __( 'It is not UTF-8 text.', 'block-theme-ops' );
		}
		if ( 'theme.json' === $target || self::MANIFEST === $target ) {
			$data = json_decode( $contents, true );

			return is_array( $data ) ? '' : __( 'It is not valid JSON.', 'block-theme-ops' );
		}

		return Markup::problem( $contents );
	}

	/**
	 * The folder every file sits in, when there is one that is not a template folder.
	 *
	 * @param string[] $names Paths.
	 * @return string
	 */
	private static function common_folder( array $names ): string {
		$first = '';
		foreach ( $names as $name ) {
			$bits = explode( '/', $name, 2 );
			if ( 2 !== count( $bits ) ) {
				return '';
			}
			if ( '' === $first ) {
				$first = $bits[0];
			} elseif ( $first !== $bits[0] ) {
				return '';
			}
		}

		return isset( self::FOLDERS[ $first ] ) ? '' : $first;
	}

	/**
	 * Keeps only the manifest fields this plugin reads.
	 *
	 * @param array $raw Decoded manifest.
	 * @return array{theme: string, site_url: string, navigation: array<int, string>, media: array<int, string>, areas: array<string, string>}
	 */
	private static function clean_manifest( array $raw ): array {
		$clean = array(
			'theme'      => isset( $raw['theme'] ) && is_string( $raw['theme'] ) && Themes::is_safe_slug( $raw['theme'] ) ? $raw['theme'] : '',
			'site_url'   => isset( $raw['site_url'] ) && is_string( $raw['site_url'] ) ? esc_url_raw( $raw['site_url'] ) : '',
			'navigation' => array(),
			'media'      => array(),
			'areas'      => array(),
		);

		foreach ( (array) ( $raw['navigation'] ?? array() ) as $id => $slug ) {
			if ( is_string( $slug ) && Themes::is_safe_slug( $slug ) ) {
				$clean['navigation'][ (int) $id ] = $slug;
			}
		}
		foreach ( (array) ( $raw['media'] ?? array() ) as $id => $url ) {
			if ( is_string( $url ) && '' !== esc_url_raw( $url ) ) {
				$clean['media'][ (int) $id ] = esc_url_raw( $url );
			}
		}
		foreach ( (array) ( $raw['areas'] ?? array() ) as $slug => $area ) {
			if ( is_string( $area ) && Themes::is_safe_slug( (string) $slug ) && preg_match( '/^[a-z0-9_-]+$/', $area ) ) {
				$clean['areas'][ (string) $slug ] = $area;
			}
		}

		return $clean;
	}

	/**
	 * Preview entry for a template or template part.
	 *
	 * @param string $path     Path from target().
	 * @param string $contents Incoming markup.
	 * @param array  $manifest Manifest of the zip.
	 * @param array  $notes    Notes collected so far, added to.
	 * @return array
	 */
	private function plan_template( string $path, string $contents, array $manifest, array &$notes ): array {
		$type    = self::type_of( $path );
		$slug    = basename( $path, '.html' );
		$content = $this->rewrite( $contents, $manifest, $notes );
		$post    = $this->find_post( $type, $slug );
		$themes  = $this->drift->themes();
		$file    = $themes->locate( $type, $slug );
		$theme   = null !== $file ? $themes->read( $file['path'] ) : null;
		$live    = $post instanceof \WP_Post ? (string) $post->post_content : $theme;

		if ( $post instanceof \WP_Post ) {
			$status = Markup::same( $live, $content ) ? self::IDENTICAL : self::CHANGED;
		} else {
			$status = null !== $theme && Markup::same( $theme, $content ) ? self::IDENTICAL : self::NEW;
		}

		return array(
			'key'         => Drift::key( $type, $slug ),
			'type'        => $type,
			'type_label'  => Drift::type_label( $type ),
			'slug'        => $slug,
			'title'       => $post instanceof \WP_Post && '' !== $post->post_title ? (string) $post->post_title : self::humanize( $slug ),
			'status'      => $status,
			'post_id'     => $post instanceof \WP_Post ? (int) $post->ID : 0,
			'content'     => $content,
			'live'        => $live,
			'theme_file'  => $theme,
			'customized'  => $post instanceof \WP_Post && ( null === $theme || ! Markup::same( $theme, $live ) ),
			'area'        => (string) ( $manifest['areas'][ $slug ] ?? '' ),
			'file_exists' => null !== $file,
		);
	}

	/**
	 * Preview entry for the global styles.
	 *
	 * @param string $contents Incoming theme.json.
	 * @return array
	 */
	private function plan_styles( string $contents ): array {
		$themes   = $this->drift->themes();
		$file     = $themes->locate( Themes::GLOBAL_STYLES, '' );
		$theme    = null !== $file ? StylesJson::decode( $themes->read( $file['path'] ) ) : array();
		$incoming = StylesJson::derive( $theme, StylesJson::decode( $contents ) );
		$post     = $this->find_post( Themes::GLOBAL_STYLES, '' );
		$live     = $post instanceof \WP_Post ? StylesJson::changes( StylesJson::decode( (string) $post->post_content ) ) : array();
		$target   = StylesJson::changes( StylesJson::merge( $live, $incoming ) );

		if ( StylesJson::same_changes( $live, $target ) ) {
			$status = self::IDENTICAL;
		} elseif ( $live ) {
			$status = self::CHANGED;
		} else {
			$status = self::NEW;
		}

		return array(
			'key'         => 'styles',
			'type'        => Themes::GLOBAL_STYLES,
			'type_label'  => Drift::type_label( Themes::GLOBAL_STYLES ),
			'slug'        => '',
			'title'       => __( 'Global styles', 'block-theme-ops' ),
			'status'      => $status,
			'post_id'     => $post instanceof \WP_Post ? (int) $post->ID : 0,
			'content'     => StylesJson::pretty( StylesJson::merge( $theme, $target ) ),
			'live'        => StylesJson::pretty( StylesJson::merge( $theme, $live ) ),
			'theme_file'  => null !== $file ? StylesJson::pretty( $theme ) : null,
			'customized'  => (bool) $live,
			'changes'     => $target,
			'area'        => '',
			'file_exists' => null !== $file,
		);
	}

	/**
	 * Fixes what differs between sites in incoming markup: navigation menu IDs, media IDs and the site address.
	 *
	 * @param string $markup   Incoming markup.
	 * @param array  $manifest Manifest of the zip.
	 * @param array  $notes    Notes, added to: navigation, media and site_url.
	 * @return string
	 */
	private function rewrite( string $markup, array $manifest, array &$notes ): string {
		$navigation = (array) ( $manifest['navigation'] ?? array() );
		$media      = (array) ( $manifest['media'] ?? array() );
		$classes    = array();

		$markup = Markup::map_blocks(
			$markup,
			function ( string $name, array $attrs ) use ( $navigation, $media, $manifest, &$notes, &$classes ): ?array {
				if ( 'core/navigation' === $name && isset( $attrs['ref'] ) ) {
					$old  = (int) $attrs['ref'];
					$slug = (string) ( $navigation[ $old ] ?? '' );
					$id   = '' !== $slug ? $this->navigation_id( $slug ) : 0;
					if ( $id ) {
						$attrs['ref'] = $id;
					} else {
						unset( $attrs['ref'] );
						$notes['navigation'][] = '' !== $slug ? $slug : '#' . $old;
					}

					return $attrs;
				}

				$attr = self::MEDIA_ATTRS[ $name ] ?? '';
				if ( '' === $attr || ! isset( $attrs[ $attr ] ) ) {
					return null;
				}

				$old = (int) $attrs[ $attr ];
				$url = (string) ( $media[ $old ] ?? '' );
				$id  = '' !== $url ? $this->attachment_id( $url, (string) ( $manifest['site_url'] ?? '' ) ) : 0;
				if ( $id ) {
					$attrs[ $attr ]  = $id;
					$classes[ $old ] = $id;
				} else {
					unset( $attrs[ $attr ] );
					$classes[ $old ]  = 0;
					$notes['media'][] = '' !== $url ? $url : '#' . $old;
				}

				return $attrs;
			}
		);

		if ( $classes ) {
			$markup = (string) preg_replace_callback(
				'/\bwp-image-(\d+)\b/',
				static function ( array $found ) use ( $classes ): string {
					$old = (int) $found[1];
					if ( ! array_key_exists( $old, $classes ) ) {
						return $found[0];
					}

					return $classes[ $old ] ? 'wp-image-' . $classes[ $old ] : 'wp-image-missing';
				},
				$markup
			);
		}

		$from    = untrailingslashit( (string) ( $manifest['site_url'] ?? '' ) );
		$to      = untrailingslashit( (string) home_url() );
		$escaped = str_replace( '/', '\\/', $from );
		if ( '' !== $from && $from !== $to && ( false !== stripos( $markup, $from ) || false !== stripos( $markup, $escaped ) ) ) {
			$markup            = str_replace( array( $from, $escaped ), array( $to, str_replace( '/', '\\/', $to ) ), $markup );
			$notes['site_url'] = array( $from, $to );
		}

		return $markup;
	}

	/**
	 * The ID of the navigation menu with a slug on this site.
	 *
	 * @param string $slug Menu slug.
	 * @return int 0 when there is none.
	 */
	private function navigation_id( string $slug ): int {
		$found = get_posts(
			array(
				'post_type'        => 'wp_navigation',
				'post_status'      => 'publish',
				'name'             => $slug,
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		return $found ? (int) reset( $found ) : 0;
	}

	/**
	 * The ID of the attachment behind a media address of the other site.
	 *
	 * @param string $url      Address on the other site.
	 * @param string $site_url The other site's address.
	 * @return int 0 when this site has no such file.
	 */
	private function attachment_id( string $url, string $site_url ): int {
		$uploads = wp_get_upload_dir();
		$marker  = '/' . trim( (string) wp_basename( (string) $uploads['baseurl'] ), '/' ) . '/';
		$at      = strpos( $url, $marker );
		$local   = false !== $at ? trailingslashit( (string) $uploads['baseurl'] ) . substr( $url, $at + strlen( $marker ) ) : $url;
		if ( '' !== $site_url && 0 === strpos( $local, untrailingslashit( $site_url ) ) ) {
			$local = untrailingslashit( (string) home_url() ) . substr( $local, strlen( untrailingslashit( $site_url ) ) );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.attachment_url_to_postid_attachment_url_to_postid -- Runs once per image in a preview or an import the person asked for, not on page views.
		return (int) attachment_url_to_postid( $local );
	}

	/**
	 * The database copy of a template, part or the global styles of the active theme.
	 *
	 * @param string $type Post type.
	 * @param string $slug Slug. Ignored for global styles.
	 * @return \WP_Post|null
	 */
	private function find_post( string $type, string $slug ): ?\WP_Post {
		$args = array(
			'post_type'              => $type,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Core keeps the theme of a template only in this taxonomy.
			'tax_query'              => array(
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => $this->drift->themes()->stylesheet(),
				),
			),
		);
		if ( Themes::GLOBAL_STYLES !== $type ) {
			$args['name'] = $slug;
		}

		$query = new \WP_Query( $args );
		$post  = $query->posts ? reset( $query->posts ) : null;

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Whether the current user may write the post an entry changes or creates.
	 *
	 * @param array $entry Entry.
	 * @return bool
	 */
	private function may_write( array $entry ): bool {
		if ( $entry['post_id'] ) {
			return current_user_can( 'edit_post', (int) $entry['post_id'] );
		}

		$object = get_post_type_object( (string) $entry['type'] );

		return $object && isset( $object->cap->create_posts ) ? current_user_can( $object->cap->create_posts ) : current_user_can( self::capability() );
	}

	/**
	 * Writes a template or template part.
	 *
	 * @param array $entry Entry.
	 * @return array|\WP_Error The record for the backup.
	 */
	private function write_template( array $entry ) {
		$stylesheet = $this->drift->themes()->stylesheet();

		if ( $entry['post_id'] ) {
			$post   = get_post( (int) $entry['post_id'] );
			$before = $post instanceof \WP_Post ? (string) $post->post_content : '';
			$done   = wp_update_post( wp_slash( $this->content_args( (int) $entry['post_id'], (string) $entry['content'] ) ), true );
			if ( is_wp_error( $done ) ) {
				return $this->write_failed( $entry, $done );
			}

			return $this->record( $entry, 'updated', (int) $entry['post_id'], $before );
		}

		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $entry['type'],
					'post_status'  => 'publish',
					'post_name'    => $entry['slug'],
					'post_title'   => $this->title_for( $entry ),
					'post_content' => $entry['content'],
					'post_author'  => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $this->write_failed( $entry, $id );
		}

		wp_set_object_terms( (int) $id, $stylesheet, 'wp_theme' );
		if ( Themes::TEMPLATE_PART === $entry['type'] ) {
			wp_set_object_terms( (int) $id, $this->area_for( $entry ), 'wp_template_part_area' );
		}
		clean_post_cache( (int) $id );

		return $this->record( $entry, 'created', (int) $id, '' );
	}

	/**
	 * Writes the global styles: the incoming values go over the ones already there.
	 *
	 * @param array $entry Entry.
	 * @return array|\WP_Error
	 */
	private function write_styles( array $entry ) {
		$changes = (array) ( $entry['changes'] ?? array() );

		if ( $entry['post_id'] ) {
			$post   = get_post( (int) $entry['post_id'] );
			$before = $post instanceof \WP_Post ? (string) $post->post_content : '';
			$user   = StylesJson::decode( $before );
			$after  = $this->styles_json( $user, $changes );
			$done   = wp_update_post( wp_slash( $this->content_args( (int) $entry['post_id'], $after ) ), true );
			if ( is_wp_error( $done ) ) {
				return $this->write_failed( $entry, $done );
			}
			Operations::clean_styles_cache();

			return $this->record( $entry, 'updated', (int) $entry['post_id'], $before, $after );
		}

		$after = $this->styles_json( array(), $changes );
		$id    = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => Themes::GLOBAL_STYLES,
					'post_status'  => 'publish',
					'post_name'    => 'wp-global-styles-' . rawurlencode( $this->drift->themes()->stylesheet() ),
					'post_title'   => __( 'Custom Styles', 'block-theme-ops' ),
					'post_content' => $after,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $this->write_failed( $entry, $id );
		}
		wp_set_object_terms( (int) $id, $this->drift->themes()->stylesheet(), 'wp_theme' );
		Operations::clean_styles_cache();

		return $this->record( $entry, 'created', (int) $id, '', $after );
	}

	/**
	 * A user layer with new `styles` and `settings`.
	 *
	 * @param array $user    Decoded user layer being replaced.
	 * @param array $changes The styles and settings it should have.
	 * @return string JSON.
	 */
	private function styles_json( array $user, array $changes ): string {
		unset( $user['styles'], $user['settings'] );
		$user = array_merge(
			array(
				'version'                     => 3,
				'isGlobalStylesUserThemeJSON' => true,
			),
			$user,
			$changes
		);

		return (string) wp_json_encode( $user );
	}

	/**
	 * One record for the backup.
	 *
	 * @param array       $entry  Entry.
	 * @param string      $action created or updated.
	 * @param int         $id     Post ID.
	 * @param string      $before Content before.
	 * @param string|null $after  Content after, or null for the entry's content.
	 * @return array
	 */
	private function record( array $entry, string $action, int $id, string $before, ?string $after = null ): array {
		return array(
			'key'       => (string) $entry['key'],
			'post_type' => (string) $entry['type'],
			'post_id'   => $id,
			'action'    => $action,
			'before'    => $before,
			'after'     => null === $after ? (string) $entry['content'] : $after,
		);
	}

	/**
	 * Error for a write that failed.
	 *
	 * @param array $entry Entry.
	 * @param mixed $error What the write returned.
	 * @return \WP_Error
	 */
	private function write_failed( array $entry, $error ): \WP_Error {
		return new \WP_Error(
			'block_theme_ops_import_failed',
			sprintf(
				/* translators: 1: item name, 2: reason. */
				__( 'WordPress could not save %1$s: %2$s Everything this import changed was put back.', 'block-theme-ops' ),
				$entry['key'],
				is_wp_error( $error ) ? $error->get_error_message() : __( 'no reason given.', 'block-theme-ops' )
			)
		);
	}

	/**
	 * Puts back what the records say was written, after a later write failed.
	 *
	 * @param array[] $records Records written so far.
	 */
	private function roll_back( array $records ): void {
		foreach ( array_reverse( $records ) as $record ) {
			if ( 'created' === $record['action'] ) {
				wp_delete_post( (int) $record['post_id'], true );
			} else {
				wp_update_post( wp_slash( $this->content_args( (int) $record['post_id'], (string) $record['before'] ) ) );
			}
		}
		Operations::clean_styles_cache();
		$this->drift->flush();
	}

	/**
	 * Arguments that change only the content of a post.
	 *
	 * @param int    $id      Post ID.
	 * @param string $content New content.
	 * @return array
	 */
	private function content_args( int $id, string $content ): array {
		return array(
			'ID'           => $id,
			'post_content' => $content,
		);
	}

	/**
	 * Item keys of the records with an action.
	 *
	 * @param array[] $records Records.
	 * @param string  $action  created or updated.
	 * @return string[]
	 */
	private function keys_of( array $records, string $action ): array {
		$keys = array();
		foreach ( $records as $record ) {
			if ( $action === $record['action'] ) {
				$keys[] = (string) $record['key'];
			}
		}

		return $keys;
	}

	/**
	 * Title for a new template: the theme's own, else made from the slug.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	private function title_for( array $entry ): string {
		if ( function_exists( 'get_block_template' ) ) {
			$template = get_block_template( $this->drift->themes()->stylesheet() . '//' . $entry['slug'], (string) $entry['type'] );
			if ( is_object( $template ) && ! empty( $template->title ) ) {
				return (string) $template->title;
			}
		}

		return self::humanize( (string) $entry['slug'] );
	}

	/**
	 * Area for a new template part.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	private function area_for( array $entry ): string {
		if ( '' !== $entry['area'] ) {
			return (string) $entry['area'];
		}
		if ( function_exists( 'get_block_template' ) ) {
			$template = get_block_template( $this->drift->themes()->stylesheet() . '//' . $entry['slug'], Themes::TEMPLATE_PART );
			if ( is_object( $template ) && ! empty( $template->area ) ) {
				return (string) $template->area;
			}
		}

		return 'uncategorized';
	}

	/**
	 * Whether two contents are the same for a post type.
	 *
	 * @param string $type Post type.
	 * @param string $a    Content.
	 * @param string $b    Content.
	 * @return bool
	 */
	private static function same_content( string $type, string $a, string $b ): bool {
		if ( Themes::GLOBAL_STYLES === $type ) {
			return StylesJson::same_changes( StylesJson::decode( $a ), StylesJson::decode( $b ) );
		}

		return Markup::same( $a, $b );
	}

	/**
	 * A readable title from a slug.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function humanize( string $slug ): string {
		return ucfirst( trim( str_replace( array( '-', '_' ), ' ', $slug ) ) );
	}

	/**
	 * Option name that holds a stashed zip for the current user.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function stash_key( string $token ): string {
		return 'block_theme_ops_import_' . get_current_user_id() . '_' . $token;
	}
}
