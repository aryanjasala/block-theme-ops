<?php
/**
 * Tests for the abilities and the capability filter.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps\Tests\Unit;

use AryanX\BlockThemeOps\Abilities;
use AryanX\BlockThemeOps\Admin\DriftPage;
use AryanX\BlockThemeOps\Alerts;
use AryanX\BlockThemeOps\Backups;
use AryanX\BlockThemeOps\Drift;
use AryanX\BlockThemeOps\Exporter;
use AryanX\BlockThemeOps\Operations;
use AryanX\BlockThemeOps\Settings;
use AryanX\BlockThemeOps\Snapshots;
use AryanX\BlockThemeOps\Themes;
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

		return new Abilities( $drift, new Operations( $drift, new Backups(), new Snapshots( $themes ), new Alerts() ), new Exporter( $drift, new Settings() ) );
	}

	public function test_four_abilities_with_the_right_annotations(): void {
		Functions\when( 'wp_register_ability' )->alias(
			function ( $name, $args ) {
				$this->registered[ $name ] = $args;
			}
		);
		$this->abilities()->register_abilities();

		$this->assertSame( array( 'block-theme-ops/get-drift-report', 'block-theme-ops/get-item-diff', 'block-theme-ops/reset-to-theme', 'block-theme-ops/export-changes' ), array_keys( $this->registered ) );
		foreach ( $this->registered as $name => $args ) {
			$readonly = 'block-theme-ops/reset-to-theme' !== $name;
			$this->assertSame( $readonly, $args['meta']['annotations']['readonly'], $name );
			$this->assertTrue( $args['meta']['show_in_rest'] );
			$this->assertTrue( $args['meta']['public'] );
			$this->assertSame( array( 'public' => true ), $args['meta']['mcp'] );
			$this->assertSame( 'block-theme-ops', $args['category'] );
		}
		$this->assertTrue( $this->registered['block-theme-ops/reset-to-theme']['meta']['annotations']['destructive'] );
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
}
