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
	 * Import.
	 *
	 * @var Importer
	 */
	private Importer $importer;

	/**
	 * Locks.
	 *
	 * @var Locks
	 */
	private Locks $locks;

	/**
	 * Sets up the abilities.
	 *
	 * @param Drift      $drift      Inventory.
	 * @param Operations $operations Reset, undo and keep.
	 * @param Exporter   $exporter   Export.
	 * @param Importer   $importer   Import.
	 * @param Locks      $locks      Locks.
	 */
	public function __construct( Drift $drift, Operations $operations, Exporter $exporter, Importer $importer, Locks $locks ) {
		$this->drift      = $drift;
		$this->operations = $operations;
		$this->exporter   = $exporter;
		$this->importer   = $importer;
		$this->locks      = $locks;
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

		$import_permission = static function (): bool {
			return current_user_can( Importer::capability() );
		};
		$lock_permission   = static function (): bool {
			return current_user_can( Locks::capability() );
		};
		$zip_input         = array(
			'type'        => 'string',
			'description' => __( 'The zip file, base64 encoded: the export of this plugin, or a zip laid out like the theme (templates/*.html, parts/*.html, theme.json).', 'block-theme-ops' ),
		);

		$this->ability(
			'preview-import',
			__( 'Preview an import', 'block-theme-ops' ),
			__( 'Reads a zip made by Export on another site (or a theme-shaped zip) and says what would change here: each item is new, changed, identical or for another theme, with navigation menus and media that this site lacks. Changes nothing.', 'block-theme-ops' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'zip' => $zip_input ),
				'required'             => array( 'zip' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'theme'        => array( 'type' => 'string' ),
					'theme_status' => array(
						'type' => 'string',
						'enum' => array( Importer::THEME_MATCH, Importer::THEME_UNKNOWN, Importer::THEME_RELATED, Importer::THEME_OTHER ),
					),
					'items'        => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'key'        => array( 'type' => 'string' ),
								'status'     => array(
									'type' => 'string',
									'enum' => array( Importer::NEW, Importer::CHANGED, Importer::IDENTICAL, Importer::OTHER_THEME ),
								),
								'customized' => array(
									'type'        => 'boolean',
									'description' => 'True when this site has its own change to the item that an import would overwrite.',
								),
							),
						),
					),
					'notes'        => array( 'type' => 'object' ),
					'rejected'     => array( 'type' => 'object' ),
				),
			),
			array( $this, 'preview_import' ),
			$import_permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'apply-import',
			__( 'Import Site Editor changes', 'block-theme-ops' ),
			__( 'Writes the new and changed items of a zip into this site\'s database, with navigation menus mapped by slug and the site address rewritten. Everything it overwrites is backed up and one Undo brings it back for 30 days. Preview first.', 'block-theme-ops' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'zip'           => $zip_input,
					'items'         => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Item keys to import, such as parts/header or styles. Default: every new or changed item.', 'block-theme-ops' ),
					),
					'confirm_theme' => array(
						'type'        => 'boolean',
						'description' => __( 'Required when the zip is for a child or parent of the active theme.', 'block-theme-ops' ),
					),
				),
				'required'             => array( 'zip' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'backup_id'  => array( 'type' => 'integer' ),
					'created'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'updated'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'undo_until' => array(
						'type'        => 'string',
						'description' => 'ISO 8601, UTC.',
					),
				),
			),
			array( $this, 'apply_import' ),
			$import_permission,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'undo-change',
			__( 'Undo a reset or an import', 'block-theme-ops' ),
			__( 'Brings back what a reset or an import changed, from its backup. An item that was changed again since is left alone and listed.', 'block-theme-ops' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'backup_id' => array(
						'type'        => 'integer',
						'description' => __( 'Backup ID, from the reset or import result.', 'block-theme-ops' ),
					),
				),
				'required'             => array( 'backup_id' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'kind'    => array( 'type' => 'string' ),
					'undone'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'skipped' => array( 'type' => 'object' ),
				),
			),
			array( $this, 'undo_change' ),
			$import_permission,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'get-locks',
			__( 'Get the locks', 'block-theme-ops' ),
			__( 'Lists which templates, template parts and global styles are locked against Site Editor saves, and whether the locks are enforced on this environment.', 'block-theme-ops' ),
			array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			self::locks_schema(),
			array( $this, 'get_locks' ),
			$permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'set-locks',
			__( 'Lock or unlock templates', 'block-theme-ops' ),
			__( 'Locks or unlocks items so they cannot be saved from the Site Editor or the REST API. Does not delete anything and does not block reset or import.', 'block-theme-ops' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'lock'         => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Item keys to lock: templates/<slug>, parts/<slug> or styles.', 'block-theme-ops' ),
					),
					'unlock'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'all'          => array(
						'type'        => 'boolean',
						'description' => __( 'Lock everything that comes from the theme files.', 'block-theme-ops' ),
					),
					'environments' => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => Locks::ENVIRONMENTS,
						),
						'description' => __( 'Environment types where the locks are enforced.', 'block-theme-ops' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			self::locks_schema(),
			array( $this, 'set_locks' ),
			$lock_permission,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
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
			'items'  => array_map( array( self::class, 'public_item' ), array_values( array_filter( $this->drift->items( $args ), array( self::class, 'can_touch' ) ) ) ),
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
		if ( null === $item || ! self::can_touch( $item ) ) {
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
			foreach ( $this->operations->reset_identical( array( self::class, 'can_touch' ) )['reset'] as $done ) {
				$reset[] = array(
					'key'       => (string) $done['item']['key'],
					'backup_id' => (int) $done['backup'],
				);
			}
		} else {
			$item = $this->drift->get( (string) ( $input['item'] ?? '' ) );
			if ( null === $item || ! self::can_touch( $item ) ) {
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
				if ( null === $item || ! self::can_touch( $item ) ) {
					/* translators: %s: item reference. */
					return new \WP_Error( 'block_theme_ops_not_found', sprintf( __( 'No Site Editor change of the active theme matches %s.', 'block-theme-ops' ), (string) $ref ) );
				}
				$items[] = $item;
			}
		} else {
			$items = self::exportable( array_values( array_filter( $this->drift->items(), array( self::class, 'can_touch' ) ) ) );
		}

		$files = $this->exporter->files( $items, 'ability' );
		$this->exporter->exported( $files, $items, 'ability' );

		return array(
			'theme' => $this->drift->themes()->stylesheet(),
			'files' => $files ? $files : new \stdClass(),
		);
	}

	/**
	 * Ability: what an import would change.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function preview_import( $input = null ) {
		$payload = $this->payload_of( (array) $input );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$plan = $this->importer->plan( $payload );

		return array(
			'theme'        => $plan['theme'],
			'theme_status' => $plan['theme_status'],
			'items'        => array_map(
				static function ( array $entry ): array {
					return array(
						'key'        => (string) $entry['key'],
						'status'     => (string) $entry['status'],
						'customized' => (bool) $entry['customized'],
					);
				},
				$plan['entries']
			),
			'notes'        => $plan['notes'],
			'rejected'     => $plan['rejected'] ? $plan['rejected'] : new \stdClass(),
		);
	}

	/**
	 * Ability: imports a zip.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function apply_import( $input = null ) {
		$input   = (array) $input;
		$payload = $this->payload_of( $input );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$result = $this->importer->apply(
			$payload,
			array_map( 'strval', (array) ( $input['items'] ?? array() ) ),
			array(
				'confirm_theme' => ! empty( $input['confirm_theme'] ),
				'check_caps'    => true,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'backup_id'  => $result['backup'],
			'created'    => $result['created'],
			'updated'    => $result['updated'],
			'undo_until' => gmdate( 'c', time() + Backups::days() * DAY_IN_SECONDS ),
		);
	}

	/**
	 * Ability: undoes a reset or an import.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function undo_change( $input = null ) {
		$result = $this->operations->undo_change( (int) ( ( (array) $input )['backup_id'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'kind'    => $result['kind'],
			'undone'  => $result['undone'],
			'skipped' => $result['skipped'] ? $result['skipped'] : new \stdClass(),
		);
	}

	/**
	 * Ability: the locks.
	 *
	 * @return array
	 */
	public function get_locks(): array {
		return $this->locks_report();
	}

	/**
	 * Ability: changes the locks.
	 *
	 * @param array|null $input Ability input.
	 * @return array
	 */
	public function set_locks( $input = null ): array {
		$input = (array) $input;
		if ( ! empty( $input['lock'] ) ) {
			$this->locks->set_items( array_map( 'strval', (array) $input['lock'] ), true );
		}
		if ( ! empty( $input['unlock'] ) ) {
			$this->locks->set_items( array_map( 'strval', (array) $input['unlock'] ), false );
		}
		$changes = array();
		if ( array_key_exists( 'all', $input ) ) {
			$changes['all'] = (bool) $input['all'];
		}
		if ( isset( $input['environments'] ) ) {
			$changes['environments'] = (array) $input['environments'];
		}
		if ( $changes ) {
			$this->locks->update( $changes );
		}

		return $this->locks_report();
	}

	/**
	 * The locks as abilities return them.
	 *
	 * @return array
	 */
	private function locks_report(): array {
		$locks = $this->locks->get();

		return array(
			'all'          => $locks['all'],
			'items'        => $locks['items'],
			'environments' => $this->locks->environments(),
			'enforced'     => $this->locks->enforced(),
		);
	}

	/**
	 * Output schema of the lock abilities.
	 *
	 * @return array
	 */
	private static function locks_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'all'          => array( 'type' => 'boolean' ),
				'items'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'environments' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'enforced'     => array(
					'type'        => 'boolean',
					'description' => 'Whether the locks apply on this site, by its environment type.',
				),
			),
		);
	}

	/**
	 * Reads the zip of an ability input.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error What Importer::read_zip() returns.
	 */
	private function payload_of( array $input ) {
		$limits = Importer::limits();
		$data   = (string) ( $input['zip'] ?? '' );
		if ( '' === $data || strlen( $data ) > (int) ceil( $limits['total'] * 4 / 3 ) + 1024 ) {
			return new \WP_Error( 'block_theme_ops_bad_zip', __( 'Send the zip as base64 text, within the size limit.', 'block-theme-ops' ) );
		}

		$bytes = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A binary upload sent as text.
		if ( false === $bytes ) {
			return new \WP_Error( 'block_theme_ops_bad_zip', __( 'The zip is not valid base64.', 'block-theme-ops' ) );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$path = wp_tempnam( 'block-theme-ops-import.zip' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- A temporary file from wp_tempnam() that this request deletes.
		file_put_contents( $path, $bytes );
		$payload = $this->importer->read_zip( $path );
		wp_delete_file( $path );

		return $payload;
	}

	/**
	 * Whether the current user may edit the post behind an item. Having the
	 * ability's capability is not enough: the post's own capability counts too.
	 *
	 * @param array $item Item.
	 * @return bool
	 */
	public static function can_touch( array $item ): bool {
		return current_user_can( 'edit_post', (int) $item['id'] );
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
