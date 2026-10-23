<?php
/**
 * Inventory of Site Editor changes and their drift status.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every database copy of a template, template part or global styles
 * for the active theme, and works out how each one relates to the theme file.
 */
class Drift {

	public const SNAPSHOT_META = '_block_theme_ops_snapshot';
	public const KEPT_META     = '_block_theme_ops_kept';
	public const EDITOR_META   = '_block_theme_ops_editor';
	public const NOTIFIED_META = '_block_theme_ops_notified';

	public const CUSTOMIZED    = 'customized';
	public const THEME_CHANGED = 'theme-changed';
	public const DB_ONLY       = 'db-only';
	public const IDENTICAL     = 'identical';

	public const STATUSES = array( self::THEME_CHANGED, self::CUSTOMIZED, self::DB_ONLY, self::IDENTICAL );

	public const TYPES = array( Themes::TEMPLATE, Themes::TEMPLATE_PART, Themes::GLOBAL_STYLES );

	/**
	 * Marker stored by Keep on an item with no theme file.
	 */
	private const NO_FILE = 'none';

	/**
	 * Posts read per query.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Theme files.
	 *
	 * @var Themes
	 */
	private Themes $themes;

	/**
	 * Items found in this request, keyed by the theme they were found for.
	 *
	 * @var array<string, array[]>
	 */
	private array $cache = array();

	/**
	 * Sets up the inventory.
	 *
	 * @param Themes $themes Theme files.
	 */
	public function __construct( Themes $themes ) {
		$this->themes = $themes;
	}

	/**
	 * Theme files.
	 *
	 * @return Themes
	 */
	public function themes(): Themes {
		return $this->themes;
	}

	/**
	 * Post types Block Theme Ops tracks.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		/**
		 * Filters the post types Block Theme Ops tracks.
		 *
		 * Only the three Site Editor types are supported, so anything else is
		 * dropped. Return fewer to leave, say, global styles alone.
		 *
		 * @param string[] $post_types Default wp_template, wp_template_part and wp_global_styles.
		 */
		return array_values( array_intersect( self::TYPES, (array) apply_filters( 'block_theme_ops_post_types', self::TYPES ) ) );
	}

	/**
	 * Every database item for the active theme.
	 *
	 * @param array $args `status` to keep one status, `kept` true to list kept items only, false to leave them out.
	 * @return array[]
	 */
	public function items( array $args = array() ): array {
		$theme = $this->themes->stylesheet();
		if ( ! isset( $this->cache[ $theme ] ) ) {
			$this->cache[ $theme ] = $this->scan();
		}

		return array_values(
			array_filter(
				$this->cache[ $theme ],
				static function ( array $item ) use ( $args ): bool {
					if ( isset( $args['status'] ) && '' !== $args['status'] && $item['status'] !== $args['status'] ) {
						return false;
					}

					return ! isset( $args['kept'] ) || (bool) $args['kept'] === $item['kept'];
				}
			)
		);
	}

	/**
	 * Forgets the items found in this request, after something changed them.
	 */
	public function flush(): void {
		$this->cache = array();
	}

	/**
	 * One item by post ID, `templates/<slug>`, `parts/<slug>`, `styles` or a bare slug.
	 *
	 * @param int|string $ref Reference.
	 * @return array|null
	 */
	public function get( $ref ): ?array {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return null;
		}

		if ( ctype_digit( $ref ) ) {
			$post = get_post( (int) $ref );

			return $post instanceof \WP_Post ? $this->item_for_post( $post ) : null;
		}

		foreach ( $this->items() as $item ) {
			if ( $item['key'] === $ref ) {
				return $item;
			}
		}
		foreach ( $this->items() as $item ) {
			if ( $item['slug'] === $ref ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Counts per status, plus `kept` and `all` (everything not kept).
	 *
	 * @param array[]|null $items Items, or null for all of them.
	 * @return array<string, int>
	 */
	public function counts( ?array $items = null ): array {
		$counts = array_fill_keys( array_merge( self::STATUSES, array( 'kept', 'all' ) ), 0 );
		foreach ( null === $items ? $this->items() : $items as $item ) {
			if ( $item['kept'] ) {
				++$counts['kept'];
				continue;
			}
			++$counts[ $item['status'] ];
			++$counts['all'];
		}

		return $counts;
	}

	/**
	 * Builds the item for a database post, or null when it is not a tracked
	 * customization of the active theme.
	 *
	 * @param \WP_Post $post Template, template part or global styles post.
	 * @return array|null
	 */
	public function item_for_post( \WP_Post $post ): ?array {
		if ( ! in_array( $post->post_type, self::post_types(), true ) || 'publish' !== $post->post_status ) {
			return null;
		}

		$theme = $this->theme_of( $post );
		if ( $theme !== $this->themes->stylesheet() ) {
			return null;
		}

		$type   = (string) $post->post_type;
		$slug   = (string) $post->post_name;
		$styles = Themes::GLOBAL_STYLES === $type;
		$user   = $styles ? StylesJson::decode( (string) $post->post_content ) : array();
		if ( $styles && ! StylesJson::changes( $user ) ) {
			// Core creates an empty global styles post when the Site Editor opens. It overrides nothing.
			return null;
		}

		$file = $this->themes->locate( $type, $styles ? '' : $slug );
		if ( null === $file && 'plugin' === get_post_meta( $post->ID, 'origin', true ) ) {
			// A customized plugin template is not theme drift.
			return null;
		}

		$contents  = null !== $file ? $this->themes->read( $file['path'] ) : '';
		$file_hash = null !== $file ? Themes::hash( $type, $contents ) : '';
		$snapshot  = self::snapshot( (int) $post->ID );
		$modified  = (int) strtotime( $post->post_modified_gmt . ' UTC' );
		$mtime     = null !== $file ? $this->themes->mtime( $file['path'] ) : 0;

		$facts = array(
			'has_file'        => null !== $file,
			'identical'       => null !== $file && $this->is_identical( $type, (string) $post->post_content, $contents ),
			'snapshot_hash'   => $snapshot ? (string) $snapshot['hash'] : '',
			'snapshot_source' => $snapshot ? (string) $snapshot['source'] : '',
			'file_hash'       => $file_hash,
			'modified'        => $modified,
			'file_modified'   => $mtime,
			'overlaps'        => true,
		);
		if ( $styles && $snapshot && $facts['snapshot_hash'] !== $file_hash ) {
			$facts['overlaps'] = StylesJson::overlaps( StylesJson::decode( (string) $snapshot['content'] ), StylesJson::decode( $contents ), $user );
		}

		$decision = self::decide( $facts );
		$author   = self::editor( $post );
		$kept     = (string) get_post_meta( $post->ID, self::KEPT_META, true );

		$item = array(
			'id'            => (int) $post->ID,
			'key'           => self::key( $type, $slug ),
			'type'          => $type,
			'type_label'    => self::type_label( $type ),
			'slug'          => $slug,
			'title'         => $styles ? __( 'Global styles', 'block-theme-ops' ) : ( '' !== $post->post_title ? (string) $post->post_title : $slug ),
			'theme'         => $theme,
			'file'          => null !== $file ? $file['file'] : '',
			'file_theme'    => null !== $file ? $file['theme'] : '',
			'path'          => null !== $file ? $file['path'] : '',
			'file_hash'     => $file_hash,
			'status'        => $decision['status'],
			'probable'      => $decision['probable'],
			'kept'          => '' !== $kept && ( '' !== $file_hash ? $file_hash : self::NO_FILE ) === $kept,
			'snapshot'      => $snapshot ? (int) $snapshot['time'] : 0,
			'snapshot_kind' => $snapshot ? (string) $snapshot['source'] : '',
			'modified'      => $modified,
			'file_modified' => $mtime,
			'author'        => $author,
			'author_name'   => self::user_name( $author ),
		);

		/**
		 * Filters the drift status of one item.
		 *
		 * @param string $status customized, theme-changed, db-only or identical.
		 * @param array  $item   The item, with the status Block Theme Ops worked out.
		 */
		$status   = (string) apply_filters( 'block_theme_ops_status', $item['status'], $item );
		$has_file = '' !== $item['file'];
		if ( in_array( $status, self::STATUSES, true ) && ( self::DB_ONLY !== $status ) === $has_file ) {
			$item['status'] = $status;
		}
		if ( self::THEME_CHANGED !== $item['status'] ) {
			$item['probable'] = false;
		}

		return $item;
	}

	/**
	 * Works out the status from what is known about an item.
	 *
	 * @param array $facts has_file, identical, snapshot_hash, snapshot_source (save or baseline), file_hash, modified, file_modified, overlaps.
	 * @return array{status: string, probable: bool}
	 */
	public static function decide( array $facts ): array {
		$result = static function ( string $status, bool $probable = false ): array {
			return array(
				'status'   => $status,
				'probable' => $probable,
			);
		};

		if ( empty( $facts['has_file'] ) ) {
			return $result( self::DB_ONLY );
		}
		if ( ! empty( $facts['identical'] ) ) {
			return $result( self::IDENTICAL );
		}

		$snapshot = (string) ( $facts['snapshot_hash'] ?? '' );
		if ( '' !== $snapshot ) {
			if ( (string) ( $facts['file_hash'] ?? '' ) !== $snapshot ) {
				// The file changed after the database copy was saved, or after Block Theme Ops first saw it.
				return $result( ! isset( $facts['overlaps'] ) || $facts['overlaps'] ? self::THEME_CHANGED : self::CUSTOMIZED );
			}
			if ( 'save' === ( $facts['snapshot_source'] ?? '' ) ) {
				return $result( self::CUSTOMIZED );
			}
		}

		// No record from the time of the edit: compare dates, and say it is a guess.
		if ( (int) ( $facts['file_modified'] ?? 0 ) > (int) ( $facts['modified'] ?? 0 ) ) {
			return $result( self::THEME_CHANGED, true );
		}

		return $result( self::CUSTOMIZED );
	}

	/**
	 * Raw contents of the three versions of an item.
	 *
	 * @param array $item Item.
	 * @return array{snapshot: string|null, file: string|null, database: string}
	 */
	public function contents( array $item ): array {
		$post     = get_post( (int) $item['id'] );
		$snapshot = self::snapshot( (int) $item['id'] );

		return array(
			'snapshot' => $snapshot && '' !== (string) $snapshot['content'] ? (string) $snapshot['content'] : null,
			'file'     => '' !== $item['path'] ? $this->themes->read( $item['path'] ) : null,
			'database' => $post instanceof \WP_Post ? (string) $post->post_content : '',
		);
	}

	/**
	 * The three versions, formatted for a line diff. Global styles show the
	 * database side as theme.json with the user changes applied.
	 *
	 * @param array  $item   Item.
	 * @param string $indent One level of indentation. The diff table on screen
	 *                       collapses tabs and spaces, so it passes no-break spaces.
	 * @return array{snapshot: string|null, file: string|null, database: string}
	 */
	public function views( array $item, string $indent = "\t" ): array {
		$raw = $this->contents( $item );

		if ( Themes::GLOBAL_STYLES === $item['type'] ) {
			$theme = StylesJson::decode( (string) $raw['file'] );

			return array(
				'snapshot' => null === $raw['snapshot'] ? null : StylesJson::pretty( StylesJson::decode( $raw['snapshot'] ), $indent ),
				'file'     => null === $raw['file'] ? null : StylesJson::pretty( $theme, $indent ),
				'database' => StylesJson::pretty( StylesJson::merge( $theme, StylesJson::decode( $raw['database'] ) ), $indent ),
			);
		}

		return array(
			'snapshot' => null === $raw['snapshot'] ? null : Markup::pretty( $raw['snapshot'], $indent ),
			'file'     => null === $raw['file'] ? null : Markup::pretty( $raw['file'], $indent ),
			'database' => Markup::pretty( $raw['database'], $indent ),
		);
	}

	/**
	 * The snapshot of the theme file stored with a database item.
	 *
	 * @param int $post_id Post ID.
	 * @return array{hash: string, content: string, file: string, theme: string, time: int, source: string}|null
	 */
	public static function snapshot( int $post_id ): ?array {
		$snapshot = get_post_meta( $post_id, self::SNAPSHOT_META, true );
		if ( ! is_array( $snapshot ) || empty( $snapshot['hash'] ) ) {
			return null;
		}

		return array(
			'hash'    => (string) $snapshot['hash'],
			'content' => (string) ( $snapshot['content'] ?? '' ),
			'file'    => (string) ( $snapshot['file'] ?? '' ),
			'theme'   => (string) ( $snapshot['theme'] ?? '' ),
			'time'    => (int) ( $snapshot['time'] ?? 0 ),
			'source'  => 'baseline' === ( $snapshot['source'] ?? '' ) ? 'baseline' : 'save',
		);
	}

	/**
	 * Short reference to an item: `templates/<slug>`, `parts/<slug>` or `styles`.
	 *
	 * @param string $type Post type.
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function key( string $type, string $slug ): string {
		if ( Themes::GLOBAL_STYLES === $type ) {
			return 'styles';
		}

		return ( Themes::TEMPLATE_PART === $type ? 'parts/' : 'templates/' ) . $slug;
	}

	/**
	 * What Keep stores: the file hash, or a marker when there is no file.
	 *
	 * @param array $item Item.
	 * @return string
	 */
	public static function keep_marker( array $item ): string {
		return '' !== $item['file_hash'] ? (string) $item['file_hash'] : self::NO_FILE;
	}

	/**
	 * Human name of a post type.
	 *
	 * @param string $type Post type.
	 * @return string
	 */
	public static function type_label( string $type ): string {
		$labels = array(
			Themes::TEMPLATE      => __( 'Template', 'block-theme-ops' ),
			Themes::TEMPLATE_PART => __( 'Template part', 'block-theme-ops' ),
			Themes::GLOBAL_STYLES => __( 'Styles', 'block-theme-ops' ),
		);

		return $labels[ $type ] ?? $type;
	}

	/**
	 * Human name of a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			self::THEME_CHANGED => __( 'Theme changed', 'block-theme-ops' ),
			self::CUSTOMIZED    => __( 'Customized', 'block-theme-ops' ),
			self::DB_ONLY       => __( 'Database only', 'block-theme-ops' ),
			self::IDENTICAL     => __( 'Identical', 'block-theme-ops' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Display name of a user, or what stands in for one.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function user_name( int $user_id ): string {
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( $user ) {
			$name = (string) $user->display_name;
		} elseif ( ! $user_id ) {
			// Saved with no user logged in: WP-CLI, WP-Cron, or an undo from another plugin.
			$name = __( 'System', 'block-theme-ops' );
		} else {
			/* translators: %d: user ID. */
			$name = sprintf( __( 'Deleted user #%d', 'block-theme-ops' ), $user_id );
		}

		/**
		 * Filters the name shown for whoever saved, edited or reset an item.
		 *
		 * @param string $name    Display name, "System" when no user was logged in, or "Deleted user #ID".
		 * @param int    $user_id User ID, 0 for none.
		 */
		return self::text_or( apply_filters( 'block_theme_ops_user_name', $name, $user_id ), $name );
	}

	/**
	 * A filtered value when it is non-empty text, else the default.
	 *
	 * @param mixed  $value    Filtered value.
	 * @param string $fallback Default.
	 * @return string
	 */
	public static function text_or( $value, string $fallback ): string {
		return is_string( $value ) && '' !== trim( $value ) ? $value : $fallback;
	}

	/**
	 * Reads every tracked post for the active theme, a page at a time.
	 *
	 * @return array[]
	 */
	private function scan(): array {
		$types = self::post_types();
		if ( ! $types ) {
			return array();
		}

		$items = array();
		$page  = 1;
		do {
			$query = new \WP_Query(
				array(
					'post_type'              => $types,
					'post_status'            => 'publish',
					'posts_per_page'         => self::PAGE_SIZE,
					'paged'                  => $page,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Core keeps the theme of a template only in this taxonomy.
					'tax_query'              => array(
						array(
							'taxonomy' => 'wp_theme',
							'field'    => 'name',
							'terms'    => $this->themes->stylesheet(),
						),
					),
				)
			);

			foreach ( $query->posts as $post ) {
				$item = $post instanceof \WP_Post ? $this->item_for_post( $post ) : null;
				if ( null !== $item ) {
					$items[] = $item;
				}
			}
			++$page;
			$found = count( $query->posts );
		} while ( self::PAGE_SIZE === $found && $page <= 50 );

		$order = array_flip( self::STATUSES );
		usort(
			$items,
			static function ( array $a, array $b ) use ( $order ): int {
				return array( $order[ $a['status'] ], $a['type'], $a['slug'] ) <=> array( $order[ $b['status'] ], $b['type'], $b['slug'] );
			}
		);

		return $items;
	}

	/**
	 * The theme a database item belongs to.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function theme_of( \WP_Post $post ): string {
		$terms = get_the_terms( $post, 'wp_theme' );
		if ( is_array( $terms ) && isset( $terms[0]->name ) ) {
			return (string) $terms[0]->name;
		}

		return '';
	}

	/**
	 * Whether the database copy shows the same as the theme file.
	 *
	 * @param string $type     Post type.
	 * @param string $database Database copy.
	 * @param string $file     Theme file.
	 * @return bool
	 */
	private function is_identical( string $type, string $database, string $file ): bool {
		if ( Themes::GLOBAL_STYLES === $type ) {
			return StylesJson::is_noop( StylesJson::decode( $file ), StylesJson::decode( $database ) );
		}

		return Markup::same( $database, $file );
	}

	/**
	 * Who last saved a database item: recorded by Block Theme Ops, else the
	 * newest revision's author, else the post author.
	 *
	 * @param \WP_Post $post Post.
	 * @return int User ID.
	 */
	private static function editor( \WP_Post $post ): int {
		$editor = (int) get_post_meta( $post->ID, self::EDITOR_META, true );
		if ( $editor ) {
			return $editor;
		}

		$revisions = wp_get_post_revisions(
			$post->ID,
			array(
				'posts_per_page' => 1,
				'fields'         => 'all',
			)
		);
		$latest    = $revisions ? reset( $revisions ) : null;
		if ( $latest instanceof \WP_Post && (int) $latest->post_author ) {
			return (int) $latest->post_author;
		}

		return (int) $post->post_author;
	}
}
