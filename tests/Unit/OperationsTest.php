<?php
/**
 * Tests for reset, undo, keep and backups.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps\Tests\Unit;

use AryanX\BlockThemeOps\Alerts;
use AryanX\BlockThemeOps\Backups;
use AryanX\BlockThemeOps\Drift;
use AryanX\BlockThemeOps\Operations;
use AryanX\BlockThemeOps\Snapshots;
use AryanX\BlockThemeOps\Themes;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class OperationsTest extends PluginTestCase {

	/**
	 * Posts passed to wp_insert_post(), by the ID they got.
	 *
	 * @var array<int, array>
	 */
	private $inserted = array();

	/**
	 * Post IDs deleted.
	 *
	 * @var int[]
	 */
	private $deleted = array();

	protected function setUp(): void {
		parent::setUp();

		$this->inserted = array();
		$this->deleted  = array();
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
		Functions\when( 'wp_delete_post' )->alias(
			function ( $id ) {
				$this->deleted[] = (int) $id;
				unset( $this->posts[ (int) $id ] );
				return true;
			}
		);
		Functions\when( 'wp_set_object_terms' )->justReturn( array() );
		Functions\when( 'clean_post_cache' )->justReturn( null );
	}

	/**
	 * The header part, customized and then changed by a deploy.
	 *
	 * @return array{0: Operations, 1: array}
	 */
	private function setup_header(): array {
		$themes = new Themes();
		$post   = $this->add_post( 7, Themes::TEMPLATE_PART, 'header', 'edited' );
		$this->terms[7]['wp_template_part_area'] = array( 'header' );
		( new Snapshots( $themes ) )->record( $post, 'save' );
		$drift = new Drift( $themes );

		return array( new Operations( $drift, new Backups(), new Snapshots( $themes ), new Alerts() ), $drift->item_for_post( $post ) );
	}

	public function test_reset_backs_up_then_deletes_and_fires_the_action(): void {
		list( $operations, $item ) = $this->setup_header();
		( new Alerts() )->add( array( 7 ), 'cron' );

		Actions\expectDone( 'block_theme_ops_reset' )->once()->with( $item, 100 );
		$result = $operations->reset( $item );

		$this->assertSame( 100, $result['backup'] );
		$this->assertSame( array( 7 ), $this->deleted );
		$backup = $this->inserted[100];
		$this->assertSame( Backups::POST_TYPE, $backup['post_type'] );
		$this->assertSame( 'edited', $backup['meta_input'][ Backups::DATA_META ]['content'] );
		$this->assertSame( 'header', $backup['meta_input'][ Backups::DATA_META ]['area'] );
		$this->assertSame( 'save', $backup['meta_input'][ Backups::DATA_META ]['snapshot']['source'] );
		$this->assertSame( array(), ( new Alerts() )->ids() );
	}

	public function test_items_without_a_theme_file_cannot_be_reset(): void {
		list( $operations ) = $this->setup_header();
		Actions\expectDone( 'block_theme_ops_reset' )->never();

		$this->assertSame( 'block_theme_ops_no_file', $operations->reset( array( 'file' => '' ) )->get_error_code() );
	}

	public function test_undo_restores_the_post_its_terms_and_snapshot(): void {
		list( $operations, $item ) = $this->setup_header();
		$snapshot                  = Drift::snapshot( 7 );
		$operations->reset( $item );

		\WP_Query::$queue[] = array( 'posts' => array() );

		Actions\expectDone( 'block_theme_ops_reset_undone' )->once()->with( \Mockery::on( fn( $b ) => 'header' === $b['slug'] ), 101 );
		$this->assertSame( 101, $operations->undo( 100 ) );

		$restored = $this->inserted[101];
		$this->assertSame( array( 7, 'wp_template_part', 'header', 'edited' ), array( $restored['import_id'], $restored['post_type'], $restored['post_name'], $restored['post_content'] ) );
		$this->assertSame( $snapshot['hash'], Drift::snapshot( 101 )['hash'] );
		$this->assertContains( 100, $this->deleted, 'The backup is removed once used.' );
	}

	public function test_undo_refuses_when_the_item_was_customized_again_or_the_backup_expired(): void {
		list( $operations, $item ) = $this->setup_header();
		$operations->reset( $item );

		\WP_Query::$queue[] = array( 'posts' => array( 55 ) );
		$this->assertSame( 'block_theme_ops_exists', $operations->undo( 100 )->get_error_code() );

		$this->meta[100][ Backups::UNDO_UNTIL ] = time() - 1;
		$this->assertSame( 'block_theme_ops_expired', $operations->undo( 100 )->get_error_code() );
		$this->assertSame( 'block_theme_ops_no_backup', $operations->undo( 999 )->get_error_code() );
	}

	public function test_keep_stores_the_file_hash_and_fires_the_action(): void {
		list( $operations, $item ) = $this->setup_header();

		Actions\expectDone( 'block_theme_ops_acknowledged' )->once()->with( $item );
		$operations->keep( $item );
		$this->assertSame( $item['file_hash'], $this->meta[7][ Drift::KEPT_META ] );

		$operations->unkeep( $item );
		$this->assertArrayNotHasKey( Drift::KEPT_META, $this->meta[7] );
	}

	public function test_backup_days_are_filtered_and_clamped(): void {
		$this->assertSame( 30, Backups::days() );

		Filters\expectApplied( 'block_theme_ops_backup_days' )->once()->with( 30 )->andReturn( 7 );
		$this->assertSame( 7, Backups::days() );

		Filters\expectApplied( 'block_theme_ops_backup_days' )->once()->andReturn( -5 );
		$this->assertSame( 1, Backups::days() );
	}

	public function test_cleanup_deletes_expired_backups_in_batches_and_fires_the_action(): void {
		\WP_Query::$queue[] = array( 'posts' => array( 31, 32 ) );
		Filters\expectApplied( 'block_theme_ops_cleanup_batch' )->once()->with( 50 )->andReturn( 9999 );
		Actions\expectDone( 'block_theme_ops_backups_expired' )->once()->with( array( 31, 32 ) );

		$this->assertSame( array( 31, 32 ), ( new Backups() )->cleanup() );
		$this->assertSame( 500, end( \WP_Query::$made )['posts_per_page'], 'The batch is capped.' );
	}
}
