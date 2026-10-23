<?php
/**
 * Tests for the inventory and the drift status of each item.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps\Tests\Unit;

use AryanX\BlockThemeOps\Drift;
use AryanX\BlockThemeOps\Markup;
use AryanX\BlockThemeOps\Snapshots;
use AryanX\BlockThemeOps\Themes;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class DriftTest extends PluginTestCase {

	/**
	 * The header part with a change made in the Site Editor.
	 *
	 * @return string
	 */
	private function edited_header(): string {
		return str_replace( 'Fresh bread every morning.', 'Fresh bread and coffee every morning.', $this->file( 'parts/header.html' ) );
	}

	/**
	 * Takes the snapshot the save hook would take.
	 *
	 * @param FakePost $post Post.
	 */
	private function snapshot( FakePost $post ): void {
		( new Snapshots( new Themes() ) )->record( $post, 'save' );
	}

	public function test_decide_covers_every_status(): void {
		$base = array(
			'has_file'        => true,
			'identical'       => false,
			'snapshot_hash'   => 'a',
			'snapshot_source' => 'save',
			'file_hash'       => 'a',
			'modified'        => 100,
			'file_modified'   => 50,
			'overlaps'        => true,
		);

		$this->assertSame( Drift::DB_ONLY, Drift::decide( array( 'has_file' => false ) + $base )['status'] );
		$this->assertSame( Drift::IDENTICAL, Drift::decide( array( 'identical' => true ) + $base )['status'] );
		$this->assertSame( Drift::CUSTOMIZED, Drift::decide( $base )['status'] );
		$this->assertSame( array( 'status' => Drift::THEME_CHANGED, 'probable' => false ), Drift::decide( array( 'file_hash' => 'b' ) + $base ) );
		$this->assertSame( Drift::CUSTOMIZED, Drift::decide( array( 'file_hash' => 'b', 'overlaps' => false ) + $base )['status'], 'Global styles: the theme changed paths the user did not touch.' );
		// A file saved after the edit says nothing when the snapshot proves it did not change.
		$this->assertSame( Drift::CUSTOMIZED, Drift::decide( array( 'file_modified' => 200 ) + $base )['status'] );
	}

	public function test_without_a_snapshot_a_newer_file_is_probably_theme_changed(): void {
		$facts = array(
			'has_file'      => true,
			'snapshot_hash' => '',
			'file_hash'     => 'a',
			'modified'      => 100,
		);

		$this->assertSame( array( 'status' => Drift::THEME_CHANGED, 'probable' => true ), Drift::decide( $facts + array( 'file_modified' => 200 ) ) );
		$this->assertSame( Drift::CUSTOMIZED, Drift::decide( $facts + array( 'file_modified' => 50 ) )['status'] );
		// A baseline with the same hash proves nothing about the time before it, so the dates still decide.
		$this->assertTrue( Drift::decide( $facts + array( 'file_modified' => 200, 'snapshot_hash' => 'a', 'snapshot_source' => 'baseline' ) )['probable'] );
	}

	public function test_an_edit_is_customized_until_the_theme_file_changes(): void {
		$post = $this->add_post( 7, Themes::TEMPLATE_PART, 'header', $this->edited_header() );
		$this->snapshot( $post );
		$drift = new Drift( new Themes() );

		$item = $drift->item_for_post( $post );
		$this->assertSame( Drift::CUSTOMIZED, $item['status'] );
		$this->assertSame( 'parts/header', $item['key'] );
		$this->assertSame( 'parts/header.html', $item['file'] );
		$this->assertSame( 'Sam', $item['author_name'] );

		$this->write( 'parts/header.html', str_replace( '<!-- wp:site-title /-->', "<!-- wp:site-title /-->\n<!-- wp:navigation /-->", $this->file( 'parts/header.html' ) ) );
		$this->assertSame( Drift::THEME_CHANGED, $drift->item_for_post( $post )['status'] );

		// Saving again in the Site Editor does not clear it: the site still ignores the new file.
		$this->snapshot( $post );
		$this->assertSame( Drift::THEME_CHANGED, $drift->item_for_post( $post )['status'] );
	}

	public function test_identical_db_only_and_plugin_templates(): void {
		$drift = new Drift( new Themes() );

		$page = $this->add_post( 8, Themes::TEMPLATE, 'page', str_replace( "\n\t", '', $this->file( 'templates/page.html' ) ) );
		$this->assertSame( Drift::IDENTICAL, $drift->item_for_post( $page )['status'] );

		$landing = $this->add_post( 9, Themes::TEMPLATE, 'wp-custom-template-landing', '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' );
		$this->assertSame( Drift::DB_ONLY, $drift->item_for_post( $landing )['status'] );

		$this->meta[10]['origin'] = 'plugin';
		$this->assertNull( $drift->item_for_post( $this->add_post( 10, Themes::TEMPLATE, 'single-product', 'x' ) ) );
	}

	public function test_other_themes_drafts_and_empty_global_styles_are_left_out(): void {
		$drift = new Drift( new Themes() );

		$other                         = $this->add_post( 11, Themes::TEMPLATE_PART, 'header', 'x' );
		$this->terms[11]['wp_theme'] = array( 'twentytwentyfive' );
		$this->assertNull( $drift->item_for_post( $other ) );

		$this->assertNull( $drift->item_for_post( $this->add_post( 12, Themes::TEMPLATE_PART, 'footer', 'x', array( 'post_status' => 'draft' ) ) ) );
		$this->assertNull( $drift->item_for_post( $this->add_post( 13, Themes::GLOBAL_STYLES, 'wp-global-styles-corner-bakery', '{"version":3,"isGlobalStylesUserThemeJSON":true}' ) ) );

		$styles = $drift->item_for_post( $this->add_post( 14, Themes::GLOBAL_STYLES, 'wp-global-styles-corner-bakery', '{"version":3,"styles":{"color":{"background":"#f4ecdf"}}}', array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ) ) );
		$this->assertSame( array( 'styles', Drift::CUSTOMIZED, 'theme.json' ), array( $styles['key'], $styles['status'], $styles['file'] ) );
	}

	public function test_the_post_types_filter_can_drop_types_but_not_add_unknown_ones(): void {
		Filters\expectApplied( 'block_theme_ops_post_types' )->once()->with( Drift::TYPES )->andReturn( array( 'wp_template', 'post' ) );
		$this->assertSame( array( 'wp_template' ), Drift::post_types() );

		Filters\expectApplied( 'block_theme_ops_post_types' )->once()->andReturn( 'nonsense' );
		$this->assertSame( array(), Drift::post_types() );
	}

	public function test_the_status_filter_can_change_a_status_within_the_rules(): void {
		$post = $this->add_post( 7, Themes::TEMPLATE_PART, 'header', $this->edited_header() );
		$this->snapshot( $post );
		$drift = new Drift( new Themes() );

		Filters\expectApplied( 'block_theme_ops_status' )->once()->with( Drift::CUSTOMIZED, \Mockery::type( 'array' ) )->andReturn( Drift::IDENTICAL );
		$this->assertSame( Drift::IDENTICAL, $drift->item_for_post( $post )['status'] );

		// db-only is only for items without a file, and unknown values are ignored.
		Filters\expectApplied( 'block_theme_ops_status' )->once()->andReturn( Drift::DB_ONLY );
		$this->assertSame( Drift::CUSTOMIZED, $drift->item_for_post( $post )['status'] );
		Filters\expectApplied( 'block_theme_ops_status' )->once()->andReturn( 'broken' );
		$this->assertSame( Drift::CUSTOMIZED, $drift->item_for_post( $post )['status'] );
	}

	public function test_kept_lasts_until_the_file_changes(): void {
		$post = $this->add_post( 7, Themes::TEMPLATE_PART, 'header', $this->edited_header() );
		$drift = new Drift( new Themes() );

		$item                                       = $drift->item_for_post( $post );
		$this->meta[7][ Drift::KEPT_META ] = Drift::keep_marker( $item );
		$this->assertTrue( $drift->item_for_post( $post )['kept'] );

		$this->write( 'parts/header.html', 'changed' );
		$this->assertFalse( $drift->item_for_post( $post )['kept'] );
		$this->assertSame( 'none', Drift::keep_marker( array( 'file_hash' => '' ) ) );
	}

	public function test_snapshots_fire_an_action_and_baselines_never_overwrite(): void {
		$post      = $this->add_post( 7, Themes::TEMPLATE_PART, 'header', $this->edited_header() );
		$snapshots = new Snapshots( new Themes() );

		Actions\expectDone( 'block_theme_ops_snapshot_saved' )->once()->with( 7, \Mockery::on( fn( $s ) => 'baseline' === $s['source'] && Markup::hash( $this->file( 'parts/header.html' ) ) === $s['hash'] ) );
		$snapshots->record( $post, 'baseline' );

		$this->write( 'parts/header.html', 'deployed' );
		$this->assertSame( 'baseline', $snapshots->record( $post, 'baseline' )['source'] );
		$this->assertNotSame( Markup::hash( 'deployed' ), Drift::snapshot( 7 )['hash'] );
	}

	public function test_the_save_hook_records_the_editor_for_the_active_theme_only(): void {
		$post      = $this->add_post( 7, Themes::TEMPLATE_PART, 'header', 'x' );
		$snapshots = new Snapshots( new Themes() );
		\Brain\Monkey\Functions\when( 'wp_is_post_revision' )->justReturn( false );
		\Brain\Monkey\Functions\when( 'wp_is_post_autosave' )->justReturn( false );

		$snapshots->on_save( 7, $post );
		$this->assertSame( 1, $this->meta[7][ Drift::EDITOR_META ] );
		$this->assertSame( 'save', Drift::snapshot( 7 )['source'] );

		$other                         = $this->add_post( 8, Themes::TEMPLATE_PART, 'footer', 'x' );
		$this->terms[8]['wp_theme'] = array( 'another-theme' );
		$snapshots->on_save( 8, $other );
		$this->assertArrayNotHasKey( 8, $this->meta );
	}

	public function test_parent_theme_files_are_found_after_the_child(): void {
		$child = sys_get_temp_dir() . '/bto-child-' . uniqid();
		mkdir( $child . '/parts', 0777, true );
		file_put_contents( $child . '/parts/footer.html', 'child footer' );
		\Brain\Monkey\Functions\when( 'get_stylesheet' )->justReturn( 'bakery-child' );
		\Brain\Monkey\Functions\when( 'get_stylesheet_directory' )->justReturn( $child );

		$themes = new Themes();
		$this->assertSame( array( 'path' => $child . '/parts/footer.html', 'file' => 'parts/footer.html', 'theme' => 'bakery-child' ), $themes->locate( Themes::TEMPLATE_PART, 'footer' ) );
		$this->assertSame( 'corner-bakery', $themes->locate( Themes::TEMPLATE_PART, 'header' )['theme'] );
		$this->assertNull( $themes->locate( Themes::TEMPLATE_PART, '../header' ) );
		$this->assertNull( $themes->locate( Themes::TEMPLATE_PART, 'missing' ) );

		unlink( $child . '/parts/footer.html' );
		rmdir( $child . '/parts' );
		rmdir( $child );
	}

	public function test_an_item_saved_without_a_user_shows_system_not_unknown(): void {
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				return 3 === $id ? (object) array( 'display_name' => 'Maya' ) : false;
			}
		);

		$this->assertSame( 'Maya', Drift::user_name( 3 ) );
		$this->assertSame( 'System', Drift::user_name( 0 ), 'WP-CLI, WP-Cron or another plugin\'s undo' );
		$this->assertSame( 'Deleted user #8', Drift::user_name( 8 ) );

		Filters\expectApplied( 'block_theme_ops_user_name' )->once()->with( 'System', 0 )->andReturn( 'WP-CLI' );
		$this->assertSame( 'WP-CLI', Drift::user_name( 0 ) );
		Filters\expectApplied( 'block_theme_ops_user_name' )->once()->andReturn( '' );
		$this->assertSame( 'System', Drift::user_name( 0 ), 'an empty name falls back' );
	}
}
