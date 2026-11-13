<?php
/**
 * Tests for the abilities and the capability filter.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Tests\Unit;

use AryanX\Axle\BlockThemeOps\Abilities;
use AryanX\Axle\BlockThemeOps\Admin\DriftPage;
use AryanX\Axle\BlockThemeOps\Alerts;
use AryanX\Axle\BlockThemeOps\Backups;
use AryanX\Axle\BlockThemeOps\Drift;
use AryanX\Axle\BlockThemeOps\Exporter;
use AryanX\Axle\BlockThemeOps\Importer;
use AryanX\Axle\BlockThemeOps\Locks;
use AryanX\Axle\BlockThemeOps\Operations;
use AryanX\Axle\BlockThemeOps\Settings;
use AryanX\Axle\BlockThemeOps\Snapshots;
use AryanX\Axle\BlockThemeOps\Themes;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class AbilitiesTest extends PluginTestCase {

	/**
	 * Abilities registered, by name.
	 *
	 * @var array<string, array>
	 */
	private $registered = array();

	private function abilities(): Abilities {
		$themes = new Themes();
		$drift  = new Drift( $themes );

		$settings = new Settings();
		$importer = new Importer( $drift, new Backups(), new Alerts() );

		return new Abilities( $drift, new Operations( $drift, new Backups(), new Snapshots( $themes ), new Alerts(), $importer ), new Exporter( $drift, $settings ), $importer, new Locks( $themes, $settings ) );
	}

	public function test_every_ability_has_the_right_annotations(): void {
		Functions\when( 'wp_register_ability' )->alias(
			function ( $name, $args ) {
				$this->registered[ $name ] = $args;
			}
		);
		$this->abilities()->register_abilities();

		$this->assertSame(
			array(
				'block-theme-ops/get-drift-report',
				'block-theme-ops/get-item-diff',
				'block-theme-ops/reset-to-theme',
				'block-theme-ops/export-changes',
				'block-theme-ops/preview-import',
				'block-theme-ops/apply-import',
				'block-theme-ops/undo-change',
				'block-theme-ops/get-locks',
				'block-theme-ops/set-locks',
			),
			array_keys( $this->registered )
		);
		$writes = array( 'reset-to-theme', 'apply-import', 'undo-change', 'set-locks' );
		foreach ( $this->registered as $name => $args ) {
			$readonly = ! in_array( substr( $name, strlen( 'block-theme-ops/' ) ), $writes, true );
			$this->assertSame( $readonly, $args['meta']['annotations']['readonly'], $name );
			$this->assertTrue( $args['meta']['show_in_rest'] );
			$this->assertTrue( $args['meta']['public'] );
			$this->assertSame( array( 'public' => true ), $args['meta']['mcp'] );
			$this->assertSame( 'block-theme-ops', $args['category'] );
		}
		foreach ( array( 'reset-to-theme', 'apply-import', 'undo-change' ) as $destructive ) {
			$this->assertTrue( $this->registered[ 'block-theme-ops/' . $destructive ]['meta']['annotations']['destructive'], $destructive );
		}
		$this->assertFalse( $this->registered['block-theme-ops/set-locks']['meta']['annotations']['destructive'] );
		$this->assertTrue( $this->registered['block-theme-ops/set-locks']['meta']['annotations']['idempotent'] );
	}

	public function test_permission_follows_the_capability_filter(): void {
		Functions\when( 'wp_register_ability' )->alias(
			function ( $name, $args ) {
				$this->registered[ $name ] = $args;
			}
		);
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'manage_options' !== $cap );
		Filters\expectApplied( 'block_theme_ops_capability' )->once()->with( 'edit_theme_options' )->andReturn( 'manage_options' );

		$this->abilities()->register_abilities();
		$this->assertFalse( ( $this->registered['block-theme-ops/get-drift-report']['permission_callback'] )() );
	}

	public function test_the_capability_falls_back_when_the_filter_returns_nothing(): void {
		$this->assertSame( 'edit_theme_options', DriftPage::capability() );

		Filters\expectApplied( 'block_theme_ops_capability' )->once()->andReturn( '' );
		$this->assertSame( 'edit_theme_options', DriftPage::capability() );
	}

	public function test_report_export_and_reset_through_the_callbacks(): void {
		$post = $this->add_post( 7, Themes::TEMPLATE_PART, 'header', '<!-- wp:paragraph --><p>Edited</p><!-- /wp:paragraph -->', array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ) );
		\WP_Query::$queue[] = array( 'posts' => array( $post ) );
		$abilities          = $this->abilities();

		$report = $abilities->get_drift_report( array( 'status' => 'customized' ) );
		$this->assertSame( 'corner-bakery', $report['theme'] );
		$this->assertSame( 1, $report['counts']['customized'] );
		$this->assertSame( array( 'parts/header' ), array_column( $report['items'], 'key' ) );
		$this->assertSame( 'wp_theme', \WP_Query::$made[0]['tax_query'][0]['taxonomy'] );

		Actions\expectDone( 'block_theme_ops_exported' )->once()->with( \Mockery::type( 'array' ), \Mockery::type( 'array' ), 'ability' );
		$files = $abilities->export_changes( array( 'items' => array( 'parts/header' ) ) )['files'];
		$this->assertSame( array( 'parts/header.html' ), array_keys( $files ) );

		$this->assertSame( 'block_theme_ops_not_found', $abilities->export_changes( array( 'items' => array( 'parts/nope' ) ) )->get_error_code() );
		$this->assertSame( 'block_theme_ops_not_found', $abilities->reset_to_theme( array( 'item' => 'nope' ) )->get_error_code() );
	}

	public function test_items_the_user_cannot_edit_are_left_out_even_with_the_screen_capability(): void {
		$post = $this->add_post( 7, Themes::TEMPLATE_PART, 'header', '<!-- wp:paragraph --><p>Edited</p><!-- /wp:paragraph -->', array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ) );
		\WP_Query::$queue = array( array( 'posts' => array( $post ) ), array( 'posts' => array( $post ) ), array( 'posts' => array( $post ) ), array( 'posts' => array( $post ) ) );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'edit_post' !== $cap );
		$abilities = $this->abilities();

		$this->assertSame( array(), $abilities->get_drift_report()['items'] );
		$this->assertSame( 'block_theme_ops_not_found', $abilities->get_item_diff( array( 'item' => 'parts/header' ) )->get_error_code() );
		$this->assertSame( 'block_theme_ops_not_found', $abilities->reset_to_theme( array( 'item' => 'parts/header' ) )->get_error_code() );
		$this->assertSame( 'block_theme_ops_not_found', $abilities->export_changes( array( 'items' => array( 'parts/header' ) ) )->get_error_code() );
		$this->assertSame( array(), (array) $abilities->export_changes()['files'] );
	}

	public function test_the_locks_ability_changes_and_reports_locks(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'sanitize_title' )->alias( 'strtolower' );
		$abilities = $this->abilities();

		$report = $abilities->set_locks( array( 'lock' => array( 'parts/header', 'bogus' ), 'all' => false, 'environments' => array( 'production', 'staging' ) ) ); // phpcs:ignore
		$this->assertSame( array( 'parts/header' ), $report['items'] );
		$this->assertSame( array( 'production', 'staging' ), $report['environments'] );
		$this->assertTrue( $report['enforced'] );

		$this->assertSame( array(), $abilities->set_locks( array( 'unlock' => array( 'parts/header' ) ) )['items'] );
		$this->assertSame( array(), $abilities->get_locks()['items'] );
	}

	public function test_import_abilities_reject_bad_input_before_reading_anything(): void {
		$abilities = $this->abilities();

		$this->assertSame( 'block_theme_ops_bad_zip', $abilities->preview_import( array() )->get_error_code() );
		$this->assertSame( 'block_theme_ops_bad_zip', $abilities->preview_import( array( 'zip' => '%%%not base64%%%' ) )->get_error_code() );
		$this->assertSame( 'block_theme_ops_bad_zip', $abilities->apply_import( array( 'zip' => str_repeat( 'A', 6 * MB_IN_BYTES ) ) )->get_error_code() );
		$this->assertSame( 'block_theme_ops_no_backup', $abilities->undo_change( array( 'backup_id' => 999 ) )->get_error_code() );
	}
}
