<?php
/**
 * `wp block-theme-ops` commands.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps\Cli;

use AryanX\BlockThemeOps\Abilities;
use AryanX\BlockThemeOps\Backups;
use AryanX\BlockThemeOps\Checker;
use AryanX\BlockThemeOps\Diff;
use AryanX\BlockThemeOps\Drift;
use AryanX\BlockThemeOps\Exporter;
use AryanX\BlockThemeOps\Operations;

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
	 * Sets up the command.
	 *
	 * @param Drift      $drift      Inventory.
	 * @param Operations $operations Reset, undo and keep.
	 * @param Exporter   $exporter   Export.
	 * @param Backups    $backups    Backups.
	 * @param Checker    $checker    Drift checks.
	 */
	public function __construct( Drift $drift, Operations $operations, Exporter $exporter, Backups $backups, Checker $checker ) {
		$this->drift      = $drift;
		$this->operations = $operations;
		$this->exporter   = $exporter;
		$this->backups    = $backups;
		$this->checker    = $checker;
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
	 * Undoes a reset from its backup.
	 *
	 * ## OPTIONS
	 *
	 * <backup>
	 * : Backup ID, from `wp block-theme-ops backups`.
	 *
	 * @param array $args Positional arguments.
	 */
	public function undo( array $args ): void {
		$result = $this->operations->undo( (int) $args[0] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Restored as post %d.', $result ) );
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
					'theme'      => $backup['theme'],
					'reset_at'   => gmdate( 'Y-m-d H:i:s', (int) $backup['reset_at'] ),
					'reset_by'   => Drift::user_name( (int) $backup['reset_by'] ),
					'undo_until' => gmdate( 'Y-m-d H:i:s', (int) $backup['undo_until'] ),
				);
			},
			$this->backups->all( 100 )
		);

		$this->print_rows( $rows, (string) ( $assoc_args['format'] ?? 'table' ), array( 'id', 'key', 'theme', 'reset_at', 'reset_by', 'undo_until' ) );
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
			$zip = $this->exporter->zip( $files );
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
