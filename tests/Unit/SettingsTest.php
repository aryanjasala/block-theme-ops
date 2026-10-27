<?php
/**
 * Tests for settings and the GitHub token.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Tests\Unit;

use AryanX\Axle\BlockThemeOps\Settings;

final class SettingsTest extends TestCase {

	protected function tearDown(): void {
		putenv( Settings::TOKEN_NAME );
		parent::tearDown();
	}

	public function test_repository_branch_and_path_are_cleaned(): void {
		$this->assertSame( 'acme/site', Settings::clean_repo( 'https://github.com/acme/site.git' ) );
		$this->assertSame( 'acme/site', Settings::clean_repo( ' acme/site/ ' ) );
		$this->assertSame( '', Settings::clean_repo( 'acme' ) );
		$this->assertSame( '', Settings::clean_repo( 'acme/site; rm -rf' ) );

		$this->assertSame( 'release/2.0', Settings::clean_ref( 'release/2.0' ) );
		foreach ( array( '', 'a..b', '/main', 'main/', 'main.lock', 'has space', 'a//b' ) as $bad ) {
			$this->assertSame( '', Settings::clean_ref( $bad ), $bad );
		}

		$this->assertSame( 'wp-content/themes/bakery', Settings::clean_path( '/wp-content//themes/bakery/' ) );
		$this->assertSame( '', Settings::clean_path( 'wp-content/../secrets' ) );
	}

	public function test_update_cleans_values_and_keeps_the_rest(): void {
		$settings = new Settings();
		$settings->update(
			array(
				'repo'   => 'github.com/acme/site',
				'branch' => 'bad..branch',
				'notify' => '1',
				'token'  => ' abc ',
				'nope'   => 'ignored',
			)
		);

		$this->assertSame(
			array(
				'notify' => true,
				'repo'   => 'acme/site',
				'branch' => 'main',
				'path'   => '',
				'token'  => 'abc',
			),
			$settings->all()
		);
		$this->assertArrayNotHasKey( 'nope', $this->options[ Settings::OPTION ] );
	}

	public function test_the_environment_variable_wins_over_the_saved_token(): void {
		$settings = new Settings();
		$this->assertSame( '', $settings->token_source() );

		$settings->update( array( 'token' => 'saved' ) );
		$this->assertSame( array( 'saved', 'option' ), array( $settings->token(), $settings->token_source() ) );

		putenv( Settings::TOKEN_NAME . '=from-env' );
		$this->assertSame( array( 'from-env', 'environment' ), array( $settings->token(), $settings->token_source() ) );
	}
}
