<?php
/**
 * Tests for the global styles layer on top of theme.json.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Tests\Unit;

use AryanX\Axle\BlockThemeOps\StylesJson;

final class StylesJsonTest extends TestCase {

	private const THEME = array(
		'version'  => 3,
		'settings' => array(
			'color' => array(
				'palette' => array(
					array(
						'slug'  => 'base',
						'color' => '#fff',
					),
					array(
						'slug'  => 'accent',
						'color' => '#b5451b',
					),
				),
			),
		),
		'styles'   => array(
			'color'      => array(
				'background' => 'white',
				'text'       => 'black',
			),
			'typography' => array( 'lineHeight' => '1.6' ),
		),
	);

	public function test_changes_keep_styles_and_settings_without_empty_branches(): void {
		$user = array(
			'version'                     => 3,
			'isGlobalStylesUserThemeJSON' => true,
			'styles'                      => array(
				'color'  => array( 'background' => 'beige' ),
				'blocks' => array(),
			),
			'settings'                    => array(),
		);

		$this->assertSame( array( 'styles' => array( 'color' => array( 'background' => 'beige' ) ) ), StylesJson::changes( $user ) );
		$this->assertSame( array(), StylesJson::changes( array( 'version' => 3 ) ) );
	}

	public function test_merge_replaces_lists_whole_and_merges_objects(): void {
		$merged = StylesJson::merge(
			self::THEME,
			array(
				'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'only' ) ) ) ),
				'styles'   => array( 'color' => array( 'background' => 'beige' ) ),
			)
		);

		$this->assertSame( array( array( 'slug' => 'only' ) ), $merged['settings']['color']['palette'] );
		$this->assertSame( array( 'background' => 'beige', 'text' => 'black' ), $merged['styles']['color'] );
	}

	public function test_a_layer_that_repeats_theme_values_is_a_noop(): void {
		$this->assertTrue( StylesJson::is_noop( self::THEME, array( 'styles' => array( 'color' => array( 'text' => 'black' ) ) ) ) );
		$this->assertFalse( StylesJson::is_noop( self::THEME, array( 'styles' => array( 'color' => array( 'text' => 'navy' ) ) ) ) );
	}

	public function test_overlaps_only_when_the_theme_changed_a_path_the_user_set(): void {
		$user = array( 'styles' => array( 'color' => array( 'background' => 'beige' ) ) );

		$elsewhere                                       = self::THEME;
		$elsewhere['styles']['typography']['lineHeight'] = '1.7';
		$this->assertFalse( StylesJson::overlaps( self::THEME, $elsewhere, $user ) );

		$same                                  = self::THEME;
		$same['styles']['color']['background'] = 'ivory';
		$this->assertTrue( StylesJson::overlaps( self::THEME, $same, $user ) );

		// A whole branch the user set, changed deeper down by the theme.
		$this->assertTrue( StylesJson::overlaps( self::THEME, $same, array( 'styles' => array( 'color' => array( 'background' => 'beige', 'text' => 'navy' ) ) ) ) );
	}

	public function test_pretty_indents_with_tabs_and_keeps_slashes(): void {
		$this->assertSame( "{\n\t\"a\": {\n\t\t\"b\": \"x/y\"\n\t}\n}", StylesJson::pretty( array( 'a' => array( 'b' => 'x/y' ) ) ) );
		$this->assertSame( '{}', StylesJson::pretty( array() ) );
	}

	public function test_hash_ignores_formatting_and_empty_layer_keeps_the_version(): void {
		$this->assertSame( StylesJson::hash( '{"a": 1}' ), StylesJson::hash( "{\n\t\"a\":1\n}" ) );
		$this->assertSame( '{"version":2,"isGlobalStylesUserThemeJSON":true}', StylesJson::empty_layer( array( 'version' => 2 ) ) );
		$this->assertSame( array(), StylesJson::decode( 'not json' ) );
	}
}
