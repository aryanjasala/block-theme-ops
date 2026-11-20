<?php
/**
 * Tests for merging a theme update into a customized template part.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Tests\Unit;

use AryanX\Axle\BlockThemeOps\Alerts;
use AryanX\Axle\BlockThemeOps\Backups;
use AryanX\Axle\BlockThemeOps\Drift;
use AryanX\Axle\BlockThemeOps\Markup;
use AryanX\Axle\BlockThemeOps\Merger;
use AryanX\Axle\BlockThemeOps\Operations;
use AryanX\Axle\BlockThemeOps\Snapshots;
use AryanX\Axle\BlockThemeOps\Themes;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

final class MergerTest extends PluginTestCase {

	/**
	 * Posts passed to wp_insert_post(), by ID.
	 *
	 * @var array<int, array>
	 */
	private $inserted = array();

	/**
	 * What wp_update_post() was called with.
	 *
	 * @var array[]
	 */
	private $updated = array();

	protected function setUp(): void {
		parent::setUp();

		$this->inserted = array();
		$this->updated  = array();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_insert_post' )->alias(
			function ( $post ) {
				$id                    = 100 + count( $this->inserted );
				$this->inserted[ $id ] = $post;
				foreach ( $post['meta_input'] ?? array() as $key => $value ) {
					$this->meta[ $id ][ $key ] = $value;
				}
				$this->posts[ $id ] = new FakePost(
					array(
						'ID'        => $id,
						'post_type' => $post['post_type'],
					)
				);
				return $id;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( $post ) {
				$this->updated[]                           = $post;
				$this->posts[ $post['ID'] ]->post_content = $post['post_content'];
				return $post['ID'];
			}
		);
		Functions\when( 'wp_delete_post' )->alias(
			function ( $id ) {
				unset( $this->posts[ (int) $id ] );
				return true;
			}
		);
	}

	private static function p( string $text ): string {
		return "<!-- wp:paragraph --><p>{$text}</p><!-- /wp:paragraph -->";
	}

	/**
	 * The header part as saved on the site, with the theme file changed since.
	 *
	 * @param string $base   Theme file when the part was saved.
	 * @param string $ours   Database copy.
	 * @param string $theirs Theme file now.
	 * @return array{0: Merger, 1: array, 2: Operations}
	 */
	private function setup_merge( string $base, string $ours, string $theirs ): array {
		$themes = new Themes();
		$this->write( 'parts/header.html', $base );
		$post = $this->add_post( 7, Themes::TEMPLATE_PART, 'header', $ours );
		( new Snapshots( $themes ) )->record( $post, 'save' );
		$this->write( 'parts/header.html', $theirs );

		$drift     = new Drift( $themes );
		$backups   = new Backups();
		$snapshots = new Snapshots( $themes );
		$alerts    = new Alerts();
		$merger    = new Merger( $drift, $backups, $snapshots, $alerts );

		return array( $merger, $drift->item_for_post( $post ), new Operations( $drift, $backups, $snapshots, $alerts, null, $merger ) );
	}

	public function test_a_clean_merge_backs_up_writes_the_result_and_moves_the_snapshot_to_the_new_theme_file(): void {
		$base   = self::p( 'One' ) . self::p( 'Two' );
		$ours   = self::p( 'One' ) . self::p( 'Two on site' );
		$theirs = self::p( 'One' ) . self::p( 'Two' ) . '<!-- wp:site-title /-->';
		list( $merger, $item ) = $this->setup_merge( $base, $ours, $theirs );

		$plan = $merger->plan( $item );
		$this->assertTrue( $plan['resolved'] );

		Actions\expectDone( 'block_theme_ops_merged' )->once();
		$done = $merger->apply( $item, array(), $plan['key'] );

		$this->assertSame( 100, $done['backup'] );
		$data = $this->inserted[100]['meta_input'][ Backups::DATA_META ];
		$this->assertSame( 'merge', $data['kind'] );
		$this->assertSame( $ours, $data['content'], 'The backup holds the copy from before.' );
		$this->assertSame( self::p( 'One' ) . self::p( 'Two on site' ) . '<!-- wp:site-title /-->', str_replace( "\n", '', $this->updated[0]['post_content'] ) );
		$snapshot = Drift::snapshot( 7 );
		$this->assertSame( $theirs, $snapshot['content'], 'The theme file now is the base for the next update.' );
	}

	public function test_open_conflicts_stop_the_merge_and_nothing_is_written(): void {
		list( $merger, $item ) = $this->setup_merge( self::p( 'One' ), self::p( 'One, site' ), self::p( 'One, theme' ) );

		$error = $merger->apply( $item, array() );

		$this->assertSame( 'block_theme_ops_merge_conflicts', $error->get_error_code() );
		$this->assertSame( array(), $this->inserted );
		$this->assertSame( array(), $this->updated );

		Actions\expectDone( 'block_theme_ops_merged' )->once();
		$done = $merger->apply( $item, array( 'c0' => 'theirs' ) );
		$this->assertIsArray( $done );
		$this->assertSame( self::p( 'One, theme' ), str_replace( "\n", '', $this->updated[0]['post_content'] ) );
	}

	public function test_a_merge_applies_only_what_was_previewed(): void {
		list( $merger, $item ) = $this->setup_merge( self::p( 'One' ), self::p( 'One, site' ) . self::p( 'Z' ), self::p( 'One' ) . self::p( 'Y' ) );
		$key                   = $merger->plan( $item )['key'];

		$this->posts[7]->post_content = self::p( 'Edited by someone else meanwhile' );

		$this->assertSame( 'block_theme_ops_merge_stale', $merger->apply( $item, array(), $key )->get_error_code() );
		$this->assertSame( array(), $this->updated );
	}

	public function test_items_that_cannot_be_merged_say_why(): void {
		list( $merger, $item ) = $this->setup_merge( self::p( 'One' ), self::p( 'One, site' ), self::p( 'One' ) );
		$this->assertStringContainsString( 'nothing to merge', $merger->blocker( $item ) );

		$this->assertStringContainsString( 'Only templates and template parts', $merger->blocker( array( 'type' => Themes::GLOBAL_STYLES ) + $item ) );
		$this->assertStringContainsString( 'no theme file', $merger->blocker( array( 'file' => '' ) + $item ) );

		unset( $this->meta[7][ Drift::SNAPSHOT_META ] );
		$this->assertStringContainsString( 'no copy of the theme file', $merger->blocker( $item ) );
		$this->assertSame( 'block_theme_ops_no_merge', $merger->plan( $item )->get_error_code() );
	}

	public function test_undo_puts_the_copy_back_with_its_old_snapshot(): void {
		$base   = self::p( 'One' );
		$ours   = self::p( 'One' ) . self::p( 'Mine' );
		$theirs = self::p( 'One' ) . '<!-- wp:site-title /-->';
		list( $merger, $item, $operations ) = $this->setup_merge( $base, $ours, $theirs );
		$old_hash                           = Drift::snapshot( 7 )['hash'];
		$merger->apply( $item, array() );

		Actions\expectDone( 'block_theme_ops_merge_undone' )->once();
		$result = $operations->undo_change( 100 );

		$this->assertSame( 'merge', $result['kind'] );
		$this->assertSame( 7, $result['post_id'] );
		$this->assertSame( $ours, $this->posts[7]->post_content );
		$this->assertSame( $old_hash, Drift::snapshot( 7 )['hash'] );
		$this->assertArrayNotHasKey( 100, $this->posts, 'The backup is removed once used.' );
	}

	public function test_undo_refuses_when_the_merged_copy_was_edited_again(): void {
		list( $merger, $item, $operations ) = $this->setup_merge( self::p( 'One' ), self::p( 'One' ) . self::p( 'Mine' ), self::p( 'One' ) . self::p( 'New' ) );
		$merger->apply( $item, array() );

		$this->posts[7]->post_content = self::p( 'Someone else' );
		$backup                       = ( new Backups() )->get( 100 );

		$this->assertSame( Operations::CUSTOMIZED, $operations->undo_blocker( $backup ) );
		$this->assertSame( 'block_theme_ops_exists', $operations->undo_change( 100 )->get_error_code() );
		$this->assertSame( self::p( 'Someone else' ), $this->posts[7]->post_content );
		$this->assertSame( 'block_theme_ops_is_merge', $operations->undo( 100 )->get_error_code() );
	}

	public function test_the_merged_markup_is_the_same_as_a_hand_merge(): void {
		$base   = '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group"><!-- wp:site-title /--></header><!-- /wp:group -->';
		$ours   = '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group"><!-- wp:site-title /--><!-- wp:paragraph --><p>Tagline</p><!-- /wp:paragraph --></header><!-- /wp:group -->';
		$theirs = '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group"><!-- wp:site-logo /--><!-- wp:site-title /--></header><!-- /wp:group -->';
		list( $merger, $item ) = $this->setup_merge( $base, $ours, $theirs );

		$plan = $merger->plan( $item );

		$this->assertTrue( $plan['resolved'] );
		$this->assertTrue(
			Markup::same(
				'<!-- wp:group {"tagName":"header"} --><header class="wp-block-group"><!-- wp:site-logo /--><!-- wp:site-title /--><!-- wp:paragraph --><p>Tagline</p><!-- /wp:paragraph --></header><!-- /wp:group -->',
				$plan['markup']
			),
			$plan['markup']
		);
	}
}
