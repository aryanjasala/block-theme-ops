<?php
/**
 * Abilities: the drift report, diffs, reset and export for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

use AryanX\Axle\BlockThemeOps\Admin\DriftPage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Block Theme Ops abilities on WordPress 6.9 and later. On older
 * WordPress nothing here runs.
 */
final class Abilities implements Service {

	public const CATEGORY = 'block-theme-ops';

	/**
	 * Inventory.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * Reset, undo and keep.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Export.
	 *
	 * @var Exporter
	 */
	private Exporter $exporter;

	/**
	 * Sets up the abilities.
	 *
	 * @param Drift      $drift      Inventory.
	 * @param Operations $operations Reset, undo and keep.
	 * @param Exporter   $exporter   Export.
	 */
	public function __construct( Drift $drift, Operations $operations, Exporter $exporter ) {
		$this->drift      = $drift;
		$this->operations = $operations;
		$this->exporter   = $exporter;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the Block Theme Ops category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Block Theme Ops', 'block-theme-ops' ),
				'description' => __( 'Find Site Editor changes that override theme files, compare them, reset them and export them as theme files.', 'block-theme-ops' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$permission = static function (): bool {
			return current_user_can( DriftPage::capability() );
		};
		$ref        = array(
			'type'        => array( 'string', 'integer' ),
			'description' => __( 'Post ID, templates/<slug>, parts/<slug>, styles, or a bare slug.', 'block-theme-ops' ),
		);

		$this->ability(
			'get-drift-report',
			__( 'Get the drift report', 'block-theme-ops' ),
			__( 'Lists every template, template part and global styles change saved in the Site Editor for the active theme, with its status: customized, theme-changed (the theme file changed after the edit, so the site ignores the newer file), db-only or identical. Check this after a theme deploy.', 'block-theme-ops' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'status'       => array(
						'type'        => 'string',
						'enum'        => array_merge( array( '' ), Drift::STATUSES ),
						'description' => __( 'Only items with this status.', 'block-theme-ops' ),
					),
					'include_kept' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Also list items someone chose to keep.', 'block-theme-ops' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'theme'  => array( 'type' => 'string' ),
					'counts' => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'integer' ),
					),
					'items'  => array(
						'type'  => 'array',
						'items' => self::item_schema(),
					),
				),
			),
			array( $this, 'get_drift_report' ),
			$permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'get-item-diff',
			__( 'Get the diff of one item', 'block-theme-ops' ),
			__( 'The three versions of one item (theme file at the time of the edit, theme file now, database copy) and unified diffs between them. Global styles are shown as theme.json with the changes applied.', 'block-theme-ops' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'item' => $ref ),
				'required'             => array( 'item' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'item'     => self::item_schema(),
					'snapshot' => array( 'type' => array( 'string', 'null' ) ),
					'file'     => array( 'type' => array( 'string', 'null' ) ),
					'database' => array( 'type' => 'string' ),
					'diffs'    => array(
						'type'       => 'object',
						'properties' => array(
							'reset'         => array(
								'type'        => 'string',
								'description' => 'Theme file now to database copy: what Reset throws away.',
							),
							'theme_changes' => array(
								'type'        => 'string',
								'description' => 'Theme file at the snapshot to now: what the site ignores.',
							),
							'customization' => array(
								'type'        => 'string',
								'description' => 'Theme file at the snapshot to database copy: what was changed in the Site Editor.',
							),
						),
					),
				),
			),
			array( $this, 'get_item_diff' ),
			$permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'reset-to-theme',
			__( 'Reset to the theme file', 'block-theme-ops' ),
			__( 'Throws away the database copy of one item, or of every identical item, so the site uses the theme file again. A backup is kept and the reset can be undone from Appearance > Theme Drift > Backups for 30 days.', 'block-theme-ops' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'item'      => $ref,
					'identical' => array(
						'type'        => 'boolean',
						'description' => __( 'Reset every item whose database copy matches its theme file, instead of one item.', 'block-theme-ops' ),
					),
				),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'reset'      => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'key'       => array( 'type' => 'string' ),
								'backup_id' => array( 'type' => 'integer' ),
							),
						),
					),
					'undo_until' => array(
						'type'        => 'string',
						'description' => 'ISO 8601, UTC.',
					),
				),
			),
			array( $this, 'reset_to_theme' ),
			$permission,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'export-changes',
			__( 'Export changes as theme files', 'block-theme-ops' ),
			__( 'Returns Site Editor changes as theme files (templates/*.html, parts/*.html, theme.json), by path inside the theme, ready to commit. Changes nothing on the site.', 'block-theme-ops' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'items'       => $ref,
						'description' => __( 'Items to export. Default: every item that is not identical to its theme file.', 'block-theme-ops' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'theme' => array( 'type' => 'string' ),
					'files' => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'string' ),
					),
				),
			),
			array( $this, 'export_changes' ),
			$permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);
	}

	/**
	 * Ability: the drift report.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function get_drift_report( $input = null ): array {
		$input = (array) $input;
		$args  = array( 'status' => (string) ( $input['status'] ?? '' ) );
		if ( empty( $input['include_kept'] ) ) {
			$args['kept'] = false;
		}

		return array(
			'theme'  => $this->drift->themes()->stylesheet(),
			'counts' => $this->drift->counts(),
			'items'  => array_map( array( self::class, 'public_item' ), $this->drift->items( $args ) ),
		);
	}

	/**
	 * Ability: the three versions of one item and their diffs.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function get_item_diff( $input = null ) {
		$item = $this->drift->get( (string) ( ( (array) $input )['item'] ?? '' ) );
		if ( null === $item ) {
			return new \WP_Error( 'block_theme_ops_not_found', __( 'No Site Editor change of the active theme matches that item.', 'block-theme-ops' ) );
		}

		$views = $this->drift->views( $item );
		$diff  = static function ( ?string $from, ?string $to, string $from_label, string $to_label ): string {
			return null === $from || null === $to ? '' : Diff::unified( $from, $to, $from_label, $to_label );
		};

		return array(
			'item'     => self::public_item( $item ),
			'snapshot' => $views['snapshot'],
			'file'     => $views['file'],
			'database' => $views['database'],
			'diffs'    => array(
				'reset'         => $diff( $views['file'], $views['database'], 'theme file', 'database copy' ),
				'theme_changes' => $diff( $views['snapshot'], $views['file'], 'theme file at snapshot', 'theme file now' ),
				'customization' => $diff( $views['snapshot'], $views['database'], 'theme file at snapshot', 'database copy' ),
			),
		);
	}

	/**
	 * Ability: resets one item, or every identical one.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function reset_to_theme( $input = null ) {
		$input = (array) $input;
		$reset = array();

		if ( ! empty( $input['identical'] ) ) {
			foreach ( $this->operations->reset_identical()['reset'] as $done ) {
				$reset[] = array(
					'key'       => (string) $done['item']['key'],
					'backup_id' => (int) $done['backup'],
				);
			}
		} else {
			$item = $this->drift->get( (string) ( $input['item'] ?? '' ) );
			if ( null === $item ) {
				return new \WP_Error( 'block_theme_ops_not_found', __( 'No Site Editor change of the active theme matches that item.', 'block-theme-ops' ) );
			}

			$done = $this->operations->reset( $item );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			$reset[] = array(
				'key'       => (string) $item['key'],
				'backup_id' => (int) $done['backup'],
			);
		}

		return array(
			'reset'      => $reset,
			'undo_until' => gmdate( 'c', time() + Backups::days() * DAY_IN_SECONDS ),
		);
	}

	/**
	 * Ability: theme files for the given items.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function export_changes( $input = null ) {
		$refs = (array) ( ( (array) $input )['items'] ?? array() );
		if ( $refs ) {
			$items = array();
			foreach ( $refs as $ref ) {
				$item = $this->drift->get( (string) $ref );
				if ( null === $item ) {
					/* translators: %s: item reference. */
					return new \WP_Error( 'block_theme_ops_not_found', sprintf( __( 'No Site Editor change of the active theme matches %s.', 'block-theme-ops' ), (string) $ref ) );
				}
				$items[] = $item;
			}
		} else {
			$items = self::exportable( $this->drift->items() );
		}

		$files = $this->exporter->files( $items, 'ability' );
		$this->exporter->exported( $files, $items, 'ability' );

		return array(
			'theme' => $this->drift->themes()->stylesheet(),
			'files' => $files ? $files : new \stdClass(),
		);
	}

	/**
	 * Items worth exporting by default: everything not identical to its file.
	 *
	 * @param array[] $items Items.
	 * @return array[]
	 */
	public static function exportable( array $items ): array {
		return array_values(
			array_filter(
				$items,
				static function ( array $item ): bool {
					return Drift::IDENTICAL !== $item['status'];
				}
			)
		);
	}

	/**
	 * An item as abilities return it, with dates in ISO 8601.
	 *
	 * @param array $item Item.
	 * @return array
	 */
	public static function public_item( array $item ): array {
		return array(
			'id'            => (int) $item['id'],
			'key'           => (string) $item['key'],
			'type'          => (string) $item['type'],
			'slug'          => (string) $item['slug'],
			'title'         => (string) $item['title'],
			'status'        => (string) $item['status'],
			'probable'      => (bool) $item['probable'],
			'kept'          => (bool) $item['kept'],
			'file'          => (string) $item['file'],
			'file_theme'    => (string) $item['file_theme'],
			'modified'      => $item['modified'] ? gmdate( 'c', (int) $item['modified'] ) : '',
			'file_modified' => $item['file_modified'] ? gmdate( 'c', (int) $item['file_modified'] ) : '',
			'snapshot'      => $item['snapshot'] ? gmdate( 'c', (int) $item['snapshot'] ) : '',
			'author'        => (string) $item['author_name'],
		);
	}

	/**
	 * Output schema of one item.
	 *
	 * @return array
	 */
	private static function item_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'            => array( 'type' => 'integer' ),
				'key'           => array( 'type' => 'string' ),
				'type'          => array( 'type' => 'string' ),
				'slug'          => array( 'type' => 'string' ),
				'title'         => array( 'type' => 'string' ),
				'status'        => array(
					'type' => 'string',
					'enum' => Drift::STATUSES,
				),
				'probable'      => array(
					'type'        => 'boolean',
					'description' => 'True when theme-changed is a guess from file dates.',
				),
				'kept'          => array( 'type' => 'boolean' ),
				'file'          => array( 'type' => 'string' ),
				'file_theme'    => array( 'type' => 'string' ),
				'modified'      => array( 'type' => 'string' ),
				'file_modified' => array( 'type' => 'string' ),
				'snapshot'      => array( 'type' => 'string' ),
				'author'        => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Registers one ability in the Block Theme Ops category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does, for people and agents.
	 * @param array    $input       Input schema.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		wp_register_ability(
			self::CATEGORY . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations'  => $annotations,
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}
}
