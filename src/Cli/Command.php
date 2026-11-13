<?php
/**
 * `wp block-theme-ops` commands.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Cli;

use AryanX\Axle\BlockThemeOps\Abilities;
use AryanX\Axle\BlockThemeOps\Backups;
use AryanX\Axle\BlockThemeOps\Checker;
use AryanX\Axle\BlockThemeOps\Diff;
use AryanX\Axle\BlockThemeOps\Drift;
use AryanX\Axle\BlockThemeOps\Exporter;
use AryanX\Axle\BlockThemeOps\Importer;
use AryanX\Axle\BlockThemeOps\Locks;
use AryanX\Axle\BlockThemeOps\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, compares, resets and exports Site Editor changes, from deploy scripts and CI.
 */
final class Command {

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
	 * Backups.
	 *
	 * @var Backups
	 */
	private Backups $backups;

	/**
	 * Drift checks.
	 *
	 * @var Checker
	 */
	private Checker $checker;

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
	 * Sets up the command.
	 *
	 * @param Drift      $drift      Inventory.
	 * @param Operations $operations Reset, undo and keep.
	 * @param Exporter   $exporter   Export.
	 * @param Backups    $backups    Backups.
	 * @param Checker    $checker    Drift checks.
	 * @param Importer   $importer   Import.
	 * @param Locks      $locks      Locks.
	 */
	public function __construct( Drift $drift, Operations $operations, Exporter $exporter, Backups $backups, Checker $checker, Importer $importer, Locks $locks ) {
		$this->drift      = $drift;
		$this->operations = $operations;
		$this->exporter   = $exporter;
		$this->backups    = $backups;
		$this->checker    = $checker;
		$this->importer   = $importer;
		$this->locks      = $locks;
	}

	/**
	 * Lists every Site Editor change of the active theme and its drift status.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only this status: customized, theme-changed, db-only or identical.
	 *
	 * [--kept]
	 * : List the items someone chose to keep instead.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-theme-ops status
	 *     wp block-theme-ops status --status=theme-changed --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$items = $this->drift->items(
			array(
				'status' => (string) ( $assoc_args['status'] ?? '' ),
				'kept'   => ! empty( $assoc_args['kept'] ),
			)
		);
		$rows  = array_map(
			static function ( array $item ): array {
				$row             = Abilities::public_item( $item );
				$row['probable'] = $item['probable'] ? 'yes' : 'no';
				$row['kept']     = $item['kept'] ? 'yes' : 'no';

				return $row;
			},
			$items
		);

		$this->print_rows( $rows, (string) ( $assoc_args['format'] ?? 'table' ), array( 'id', 'key', 'title', 'status', 'probable', 'kept', 'file', 'modified', 'author' ) );
	}

	/**
	 * Shows what a Site Editor change differs in, as a unified diff.
	 *
	 * ## OPTIONS
	 *
	 * <item>
	 * : Post ID, templates/<slug>, parts/<slug>, styles, or a bare slug.
	 *
	 * [--against=<version>]
	 * : file compares the database copy with the theme file now (what Reset throws away). theme shows how the theme file changed since the snapshot. snapshot compares the database copy with the theme file at the snapshot (the customization itself).
	 * ---
	 * default: file
	 * options:
	 *   - file
	 *   - theme
	 *   - snapshot
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-theme-ops diff parts/header
	 *     wp block-theme-ops diff templates/index --against=theme
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function diff( array $args, array $assoc_args ): void {
		$item    = $this->item( (string) $args[0] );
		$views   = $this->drift->views( $item );
		$against = (string) ( $assoc_args['against'] ?? 'file' );

		if ( 'file' !== $against && null === $views['snapshot'] ) {
			\WP_CLI::error( 'There is no snapshot of the theme file for this item. It was saved before Block Theme Ops was active.' );
		}
		if ( 'snapshot' !== $against && null === $views['file'] ) {
			\WP_CLI::error( 'This item has no theme file.' );
		}

		if ( 'theme' === $against ) {
			$diff = Diff::unified( (string) $views['snapshot'], (string) $views['file'], 'theme file at snapshot', 'theme file now' );
		} elseif ( 'snapshot' === $against ) {
			$diff = Diff::unified( (string) $views['snapshot'], $views['database'], 'theme file at snapshot', 'database copy' );
		} else {
			$diff = Diff::unified( (string) $views['file'], $views['database'], 'theme file', 'database copy' );
		}

		if ( '' === $diff ) {
			\WP_CLI::success( 'No differences.' );
			return;
		}

		\WP_CLI::line( rtrim( $diff ) );
	}

	/**
	 * Resets Site Editor changes to the theme files, keeping a backup of each.
	 *
	 * ## OPTIONS
	 *
	 * [<item>...]
	 * : Post IDs, templates/<slug>, parts/<slug>, styles, or bare slugs.
	 *
	 * [--identical]
	 * : Reset every item whose database copy matches its theme file.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-theme-ops reset parts/header --yes
	 *     wp block-theme-ops reset --identical --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function reset( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['identical'] ) ) {
			$count = count( $this->drift->items( array( 'status' => Drift::IDENTICAL ) ) );
			if ( ! $count ) {
				\WP_CLI::success( 'No identical items.' );
				return;
			}
			\WP_CLI::confirm( sprintf( 'Reset %d identical item(s)?', $count ), $assoc_args );
			$result = $this->operations->reset_identical();
			foreach ( $result['reset'] as $done ) {
				\WP_CLI::log( sprintf( 'Reset %s (backup %d).', $done['item']['key'], $done['backup'] ) );
			}
			if ( $result['failed'] ) {
				\WP_CLI::error( sprintf( '%d item(s) could not be reset.', count( $result['failed'] ) ) );
			}
			\WP_CLI::success( sprintf( '%d item(s) reset.', count( $result['reset'] ) ) );
			return;
		}

		if ( ! $args ) {
			\WP_CLI::error( 'Name at least one item, or use --identical.' );
		}

		$items = array_map( array( $this, 'item' ), $args );
		\WP_CLI::confirm( sprintf( 'Reset %d item(s) to the theme file?', count( $items ) ), $assoc_args );

		$failed = 0;
		foreach ( $items as $item ) {
			$result = $this->operations->reset( $item );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( $item['key'] . ': ' . $result->get_error_message() );
				++$failed;
				continue;
			}
			\WP_CLI::log( sprintf( 'Reset %s. Undo with: wp block-theme-ops undo %d', $item['key'], $result['backup'] ) );
		}

		if ( $failed ) {
			\WP_CLI::error( sprintf( '%d item(s) could not be reset.', $failed ) );
		}
		\WP_CLI::success( sprintf( '%d item(s) reset.', count( $items ) ) );
	}

	/**
	 * Undoes a reset or an import from its backup.
	 *
	 * ## OPTIONS
	 *
	 * <backup>
	 * : Backup ID, from `wp block-theme-ops backups`.
	 *
	 * @param array $args Positional arguments.
	 */
	public function undo( array $args ): void {
		$result = $this->operations->undo_change( (int) $args[0] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		foreach ( $result['skipped'] as $key => $reason ) {
			\WP_CLI::warning( $key . ': ' . $reason );
		}
		if ( 'import' === $result['kind'] ) {
			\WP_CLI::success( sprintf( 'Import undone: %s.', implode( ', ', $result['undone'] ) ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Restored as post %d.', $result['post_id'] ) );
	}

	/**
	 * Imports Site Editor changes from a zip made by `export --zip` (or a zip laid out like the theme).
	 *
	 * Shows what would change first. Everything it overwrites is backed up, and one `undo` brings it back.
	 * Navigation menus are mapped by slug, the site address is rewritten, and media this site lacks is listed.
	 *
	 * ## OPTIONS
	 *
	 * <zip>
	 * : Path of the zip.
	 *
	 * [--dry-run]
	 * : Show what would change and write nothing.
	 *
	 * [--only=<items>]
	 * : Comma-separated item keys to import, such as parts/header,styles. Default: every new or changed item.
	 *
	 * [--confirm-theme]
	 * : Import a zip made for a child or parent of the active theme.
	 *
	 * [--yes]
	 * : Do not ask before writing.
	 *
	 * [--format=<format>]
	 * : Format of the preview: table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-theme-ops import staging-changes.zip --dry-run
	 *     wp block-theme-ops import staging-changes.zip --only=parts/header --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function import( array $args, array $assoc_args ): void {
		$payload = $this->importer->read_zip( (string) $args[0] );
		if ( is_wp_error( $payload ) ) {
			\WP_CLI::error( $payload->get_error_message() );
		}

		$only = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['only'] ?? '' ) ) ) ) );
		$plan = $this->importer->plan( $payload );

		foreach ( $plan['rejected'] as $name => $reason ) {
			\WP_CLI::warning( sprintf( 'Skipped %s: %s', $name, $reason ) );
		}
		foreach ( $plan['notes']['navigation'] as $menu ) {
			\WP_CLI::warning( sprintf( 'Navigation menu %s does not exist here. Its blocks use the default menu.', $menu ) );
		}
		foreach ( $plan['notes']['media'] as $media ) {
			\WP_CLI::warning( sprintf( 'Media not on this site: %s', $media ) );
		}
		if ( $plan['notes']['site_url'] ) {
			\WP_CLI::log( sprintf( 'Site address rewritten: %s to %s', $plan['notes']['site_url'][0], $plan['notes']['site_url'][1] ) );
		}

		$rows = array();
		foreach ( $plan['entries'] as $entry ) {
			if ( $only && ! in_array( $entry['key'], $only, true ) ) {
				continue;
			}
			$rows[] = array(
				'key'        => $entry['key'],
				'status'     => $entry['status'],
				'customized' => $entry['customized'] ? 'yes' : 'no',
			);
		}
		$this->print_rows( $rows, (string) ( $assoc_args['format'] ?? 'table' ), array( 'key', 'status', 'customized' ) );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			\WP_CLI::success( 'Dry run. Nothing was changed.' );
			return;
		}

		$todo = count(
			array_filter(
				$rows,
				static function ( array $row ): bool {
					return in_array( $row['status'], array( Importer::NEW, Importer::CHANGED ), true );
				} 
			) 
		);
		if ( ! $todo ) {
			\WP_CLI::success( 'Nothing to import: everything is the same here.' );
			return;
		}

		\WP_CLI::confirm( sprintf( 'Import %d item(s)? Each one it overwrites is backed up first.', $todo ), $assoc_args );
		$result = $this->importer->apply( $payload, $only, array( 'confirm_theme' => ! empty( $assoc_args['confirm-theme'] ) ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Imported %d item(s). Undo with: wp block-theme-ops undo %d', count( $result['created'] ) + count( $result['updated'] ), $result['backup'] ) );
	}

	/**
	 * Lists the locks.
	 *
	 * Locked items cannot be saved from the Site Editor or the REST API on the environments where locks are enforced.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function locks( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( $this->locks->theme_items() as $item ) {
			$rows[] = array(
				'key'    => $item['key'],
				'locked' => $this->locks->is_locked( $item['type'], $item['slug'] ) ? 'yes' : 'no',
				'file'   => $item['file'],
			);
		}

		\WP_CLI::log( sprintf( 'Enforced on this site (%s): %s', function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production', $this->locks->enforced() ? 'yes' : 'no' ) );
		$this->print_rows( $rows, (string) ( $assoc_args['format'] ?? 'table' ), array( 'key', 'locked', 'file' ) );
	}

	/**
	 * Locks items against Site Editor saves.
	 *
	 * ## OPTIONS
	 *
	 * [<item>...]
	 * : Item keys: templates/<slug>, parts/<slug> or styles.
	 *
	 * [--all]
	 * : Lock everything that comes from the theme files.
	 *
	 * [--environments=<types>]
	 * : Comma-separated environment types where locks are enforced: production, staging, development, local.
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-theme-ops lock parts/header parts/footer
	 *     wp block-theme-ops lock --all --environments=production,staging
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function lock( array $args, array $assoc_args ): void {
		$this->change_locks( $args, $assoc_args, true );
	}

	/**
	 * Unlocks items.
	 *
	 * ## OPTIONS
	 *
	 * [<item>...]
	 * : Item keys: templates/<slug>, parts/<slug> or styles.
	 *
	 * [--all]
	 * : Remove the lock on everything that comes from the theme files. Locks on single items stay.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function unlock( array $args, array $assoc_args ): void {
		$this->change_locks( $args, $assoc_args, false );
	}

	/**
	 * Locks or unlocks, then says what is locked.
	 *
	 * @param array $args       Item keys.
	 * @param array $assoc_args Named arguments.
	 * @param bool  $locked     True to lock.
	 */
	private function change_locks( array $args, array $assoc_args, bool $locked ): void {
		if ( ! $args && empty( $assoc_args['all'] ) && empty( $assoc_args['environments'] ) ) {
			\WP_CLI::error( 'Name at least one item, or use --all.' );
		}

		$bad = array_filter(
			$args,
			static function ( string $key ): bool {
				return ! preg_match( '#^(styles|(templates|parts)/[A-Za-z0-9_%.-]+)$#', $key );
			}
		);
		if ( $bad ) {
			\WP_CLI::error( 'Not an item key: ' . implode( ', ', $bad ) . '. Use templates/<slug>, parts/<slug> or styles.' );
		}

		if ( $args ) {
			$this->locks->set_items( $args, $locked );
		}
		$changes = array();
		if ( ! empty( $assoc_args['all'] ) ) {
			$changes['all'] = $locked;
		}
		if ( ! empty( $assoc_args['environments'] ) ) {
			$changes['environments'] = array_map( 'trim', explode( ',', (string) $assoc_args['environments'] ) );
		}
		if ( $changes ) {
			$this->locks->update( $changes );
		}

		$now = $this->locks->get();
		\WP_CLI::success( sprintf( 'Locked: %s%s. Enforced on: %s.', $now['all'] ? 'everything from the theme' : ( $now['items'] ? implode( ', ', $now['items'] ) : 'nothing' ), $now['all'] && $now['items'] ? ', ' . implode( ', ', $now['items'] ) : '', implode( ', ', $this->locks->environments() ) ) );
	}

	/**
	 * Lists resets that can still be undone.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function backups( array $args, array $assoc_args ): void {
		$rows = array_map(
			static function ( array $backup ): array {
				return array(
					'id'         => $backup['id'],
					'key'        => (string) ( $backup['item']['key'] ?? $backup['slug'] ),
					'kind'       => (string) ( $backup['kind'] ?? 'reset' ),
					'theme'      => $backup['theme'],
					'reset_at'   => gmdate( 'Y-m-d H:i:s', (int) $backup['reset_at'] ),
					'reset_by'   => Drift::user_name( (int) $backup['reset_by'] ),
					'undo_until' => gmdate( 'Y-m-d H:i:s', (int) $backup['undo_until'] ),
				);
			},
			$this->backups->all( 100 )
		);

		$this->print_rows( $rows, (string) ( $assoc_args['format'] ?? 'table' ), array( 'id', 'key', 'kind', 'theme', 'reset_at', 'reset_by', 'undo_until' ) );
	}

	/**
	 * Keeps Site Editor changes on purpose, so they drop out of the list and alerts until their theme file changes again.
	 *
	 * ## OPTIONS
	 *
	 * <item>...
	 * : Post IDs, templates/<slug>, parts/<slug>, styles, or bare slugs.
	 *
	 * [--undo]
	 * : List the items again instead.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function keep( array $args, array $assoc_args ): void {
		foreach ( array_map( array( $this, 'item' ), $args ) as $item ) {
			if ( ! empty( $assoc_args['undo'] ) ) {
				$this->operations->unkeep( $item );
				\WP_CLI::log( 'Listed again: ' . $item['key'] );
			} else {
				$this->operations->keep( $item );
				\WP_CLI::log( 'Kept: ' . $item['key'] );
			}
		}

		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Exports Site Editor changes as theme files: a list, a zip, or a pull request.
	 *
	 * ## OPTIONS
	 *
	 * [<item>...]
	 * : Post IDs, templates/<slug>, parts/<slug>, styles, or bare slugs. Default: every item that is not identical to its theme file.
	 *
	 * [--zip=<file>]
	 * : Write a zip laid out like the theme to this path.
	 *
	 * [--pr]
	 * : Open a pull request on the repository set under Appearance > Theme Drift > Settings.
	 *
	 * [--format=<format>]
	 * : Without --zip or --pr: table lists the files, json prints path => contents.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-theme-ops export --zip=changes.zip
	 *     wp block-theme-ops export parts/header --pr
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function export( array $args, array $assoc_args ): void {
		$items = $args ? array_map( array( $this, 'item' ), $args ) : Abilities::exportable( $this->drift->items() );
		if ( ! $items ) {
			\WP_CLI::success( 'Nothing to export.' );
			return;
		}

		if ( ! empty( $assoc_args['pr'] ) ) {
			$result = $this->exporter->pull_request( $items, 'cli' );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::success( sprintf( 'Pull request #%d opened: %s', $result['number'], $result['url'] ) );
			return;
		}

		$files = $this->exporter->files( $items, 'cli' );

		if ( ! empty( $assoc_args['zip'] ) ) {
			$zip = $this->exporter->zip( $files, $items );
			if ( is_wp_error( $zip ) ) {
				\WP_CLI::error( $zip->get_error_message() );
			}
			$target = (string) $assoc_args['zip'];
			if ( ! copy( $zip, $target ) ) {
				wp_delete_file( $zip );
				\WP_CLI::error( 'Could not write ' . $target );
			}
			wp_delete_file( $zip );
			$this->exporter->exported( $files, $items, 'cli' );
			\WP_CLI::success( sprintf( '%d file(s) written to %s', count( $files ), $target ) );
			return;
		}

		$this->exporter->exported( $files, $items, 'cli' );
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$rows = array();
		foreach ( $files as $path => $contents ) {
			$rows[] = array(
				'file'  => $path,
				'bytes' => strlen( $contents ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'file', 'bytes' ) );
	}

	/**
	 * Runs the drift check now. Exits with 1 when any item is theme-changed, for deploy scripts and CI.
	 *
	 * Also records snapshots for customizations made before Block Theme Ops
	 * was active, and sends the alert email when it is turned on.
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-theme-ops check || echo "Site Editor changes hide the new theme files"
	 */
	public function check(): void {
		$this->checker->run( 'cli' );
		$this->drift->flush();
		$changed = $this->drift->items(
			array(
				'status' => Drift::THEME_CHANGED,
				'kept'   => false,
			)
		);

		if ( ! $changed ) {
			\WP_CLI::success( 'No Site Editor change hides a newer theme file.' );
			return;
		}

		foreach ( $changed as $item ) {
			\WP_CLI::log( sprintf( '%s: %s changed after the database copy was saved%s.', $item['key'], $item['file'], $item['probable'] ? ' (probably)' : '' ) );
		}
		\WP_CLI::halt( 1 );
	}

	/**
	 * Prints rows in the format asked for. `ids` prints the IDs on one line.
	 *
	 * @param array[]  $rows   Rows with an `id` column.
	 * @param string   $format table, csv, json, yaml, ids or count.
	 * @param string[] $fields Columns.
	 */
	private function print_rows( array $rows, string $format, array $fields ): void {
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Finds an item or stops with an error.
	 *
	 * @param string $ref Reference.
	 * @return array
	 */
	private function item( string $ref ): array {
		$item = $this->drift->get( $ref );
		if ( null === $item ) {
			\WP_CLI::error( sprintf( 'No Site Editor change of the active theme matches "%s". See wp block-theme-ops status.', $ref ) );
		}

		return (array) $item;
	}
}
