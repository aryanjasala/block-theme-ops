<?php
/**
 * Tests for importing Site Editor changes from a zip.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Tests\Unit;

use AryanX\Axle\BlockThemeOps\Alerts;
use AryanX\Axle\BlockThemeOps\Backups;
use AryanX\Axle\BlockThemeOps\Drift;
use AryanX\Axle\BlockThemeOps\Importer;
use AryanX\Axle\BlockThemeOps\Themes;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ImporterTest extends PluginTestCase {

	/**
	 * Posts written, by ID: type, content and whether it was inserted.
	 *
	 * @var array<int, array>
	 */
	private $written = array();

	/**
	 * Post IDs deleted.
	 *
	 * @var int[]
	 */
	private $deleted = array();

	/**
	 * Temporary files to remove.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Transients, by name.
	 *
	 * @var array<string, mixed>
	 */
	private $transients = array();

	protected function setUp(): void {
		parent::setUp();

		$this->written    = array();
		$this->deleted    = array();
		$this->files      = array();
		$this->transients = array();

		Functions\when( 'home_url' )->justReturn( 'https://prod.test' );
		Functions\when( 'untrailingslashit' )->alias( fn( $s ) => rtrim( (string) $s, '/' ) );
		Functions\when( 'trailingslashit' )->alias( fn( $s ) => rtrim( (string) $s, '/' ) . '/' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( fn( $data, $flags = 0 ) => json_encode( $data, $flags ) );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'wp_get_upload_dir' )->justReturn( array( 'baseurl' => 'https://prod.test/wp-content/uploads' ) );
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'wp_generate_password' )->justReturn( 'AbCdEfGhIjKlMnOpQrSt' );
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $name ) => $this->transients[ $name ] ?? false );
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_get_theme' )->justReturn( new class() {
			public function exists() {
				return false;
			}
		} );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'wp_set_object_terms' )->justReturn( array() );
		Functions\when( 'wp_insert_post' )->alias(
			function ( $post ) {
				$id = ! empty( $post['meta_input'] ) || 'bto_backup' === ( $post['post_type'] ?? '' ) ? 900 + count( $this->written ) : 100 + count( $this->written );
				$this->written[ $id ] = $post;
				foreach ( $post['meta_input'] ?? array() as $key => $value ) {
					$this->meta[ $id ][ $key ] = $value;
				}
				$this->posts[ $id ] = new FakePost(
					array(
						'ID'           => $id,
						'post_type'    => $post['post_type'],
						'post_content' => $post['post_content'] ?? '',
					)
				);
				return $id;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( $post ) {
				$this->posts[ (int) $post['ID'] ]->post_content = $post['post_content'];
				$this->written[ (int) $post['ID'] ]             = $post;
				return (int) $post['ID'];
			}
		);
		Functions\when( 'wp_delete_post' )->alias(
			function ( $id ) {
				$this->deleted[] = (int) $id;
				unset( $this->posts[ (int) $id ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tearDown();
	}

	private function importer(): Importer {
		$themes = new Themes();
		$drift  = new Drift( $themes );

		return new Importer( $drift, new Backups(), new Alerts() );
	}

	/**
	 * Makes a zip from path => contents.
	 *
	 * @param array<string, string> $files   Files.
	 * @param array                 $symlink Names that are symbolic links.
	 * @return string Path.
	 */
	private function zip( array $files, array $symlink = array() ): string {
		$path = sys_get_temp_dir() . '/bto-import-' . uniqid() . '.zip';
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		foreach ( $files as $name => $contents ) {
			$zip->addFromString( $name, $contents );
			if ( in_array( $name, $symlink, true ) ) {
				$zip->setExternalAttributesName( $name, \ZipArchive::OPSYS_UNIX, 0120777 << 16 );
			}
		}
		$zip->close();
		$this->files[] = $path;

		return $path;
	}

	private function block( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
	}

	public function test_reads_an_export_with_its_folder_and_manifest(): void {
		$path    = $this->zip(
			array(
				'corner-bakery/parts/header.html'   => $this->block( 'Hi' ),
				'corner-bakery/templates/page.html' => $this->block( 'Page' ),
				'corner-bakery/theme.json'          => '{"version":3}',
				'corner-bakery/block-theme-ops.json' => '{"format":1,"theme":"corner-bakery","site_url":"https://stage.test","navigation":{"12":"main"},"areas":{"header":"header"}}',
			)
		);
		$payload = $this->importer()->read_zip( $path );

		$this->assertSame( 'corner-bakery', $payload['theme'] );
		$this->assertSame( array( 'parts/header.html', 'templates/page.html', 'theme.json' ), array_keys( $payload['files'] ) );
		$this->assertSame( 'main', $payload['manifest']['navigation'][12] );
		$this->assertSame( 'header', $payload['manifest']['areas']['header'] );
		$this->assertSame( array(), $payload['rejected'] );
	}

	public function test_reads_a_plain_theme_shaped_zip_and_the_old_folder_names(): void {
		$payload = $this->importer()->read_zip(
			$this->zip(
				array(
					'block-template-parts/footer.html' => $this->block( 'Bye' ),
					'block-templates/index.html'       => $this->block( 'Home' ),
				)
			)
		);

		$this->assertSame( '', $payload['theme'] );
		$this->assertSame( array( 'parts/footer.html', 'templates/index.html' ), array_keys( $payload['files'] ) );
	}

	public function test_refuses_unsafe_paths_types_symlinks_and_markup_without_running_anything(): void {
		$payload = $this->importer()->read_zip(
			$this->zip(
				array(
					'templates/good.html'            => $this->block( 'Fine' ),
					'../templates/escape.html'       => $this->block( 'Out' ),
					'/etc/templates/abs.html'        => $this->block( 'Abs' ),
					'functions.php'                  => '<?php echo "no";',
					'templates/evil.html'            => '<?php system( $_GET["c"] ); ?>' . $this->block( 'X' ),
					'templates/loose.html'           => '<p>not in a block</p>',
					'templates/open.html'            => '<!-- wp:group --><div>',
					'templates/linked.html'          => '../../wp-config.php',
					'templates/with space/deep.html' => $this->block( 'Deep' ),
					'templates/bad name!.html'       => $this->block( 'Bad' ),
				),
				array( 'templates/linked.html' )
			)
		);

		$this->assertSame( array( 'templates/good.html' ), array_keys( $payload['files'] ) );
		$this->assertCount( 9, $payload['rejected'] );
		$this->assertArrayHasKey( 'functions.php', $payload['rejected'] );
		$this->assertStringContainsString( 'PHP tag', $payload['rejected']['templates/evil.html'] );
		$this->assertStringContainsString( 'outside of blocks', $payload['rejected']['templates/loose.html'] );
		$this->assertStringContainsString( 'symbolic link', $payload['rejected']['templates/linked.html'] );
	}

	public function test_a_zip_with_nothing_importable_or_not_a_zip_is_an_error(): void {
		$this->assertSame( 'block_theme_ops_empty_zip', $this->importer()->read_zip( $this->zip( array( 'readme.txt' => 'hi' ) ) )->get_error_code() );

		$bad = sys_get_temp_dir() . '/bto-bad-' . uniqid() . '.zip';
		file_put_contents( $bad, 'not a zip' );
		$this->files[] = $bad;
		$this->assertSame( 'block_theme_ops_bad_zip', $this->importer()->read_zip( $bad )->get_error_code() );
	}

	public function test_size_limits_are_filtered_clamped_and_enforced(): void {
		$this->assertSame( array( 200, MB_IN_BYTES, 4 * MB_IN_BYTES, 100 ), array_values( Importer::limits() ) );

		Filters\expectApplied( 'block_theme_ops_import_max_files' )->once()->andReturn( 0 );
		Filters\expectApplied( 'block_theme_ops_import_max_file_size' )->once()->andReturn( 10 );
		Filters\expectApplied( 'block_theme_ops_import_max_size' )->once()->andReturn( 10 ** 12 );
		Filters\expectApplied( 'block_theme_ops_import_max_ratio' )->once()->andReturn( 1 );
		$this->assertSame( array( 1, KB_IN_BYTES, 64 * MB_IN_BYTES, 2 ), array_values( Importer::limits() ) );
	}

	public function test_a_file_over_the_size_limit_is_skipped_and_a_zip_over_the_file_count_refused(): void {
		Filters\expectApplied( 'block_theme_ops_import_max_file_size' )->andReturn( KB_IN_BYTES );
		$payload = $this->importer()->read_zip(
			$this->zip(
				array(
					'templates/small.html' => $this->block( 'ok' ),
					'templates/big.html'   => $this->block( str_repeat( 'x', 5000 ) ),
				)
			)
		);
		$this->assertSame( array( 'templates/small.html' ), array_keys( $payload['files'] ) );
		$this->assertStringContainsString( 'too large', $payload['rejected']['templates/big.html'] );

		Filters\expectApplied( 'block_theme_ops_import_max_files' )->andReturn( 1 );
		$this->assertSame( 'block_theme_ops_too_many', $this->importer()->read_zip( $this->zip( array( 'templates/a.html' => $this->block( 'a' ), 'templates/b.html' => $this->block( 'b' ) ) ) )->get_error_code() ); // phpcs:ignore
	}

	public function test_plan_marks_new_changed_identical_and_what_overwrites_a_local_change(): void {
		$this->add_post( 7, Themes::TEMPLATE_PART, 'header', $this->block( 'Local edit' ) );
		$this->add_post( 8, Themes::TEMPLATE, 'page', $this->file( 'templates/page.html' ) );

		\WP_Query::$queue = array(
			array( 'posts' => array( $this->posts[7] ) ),
			array( 'posts' => array() ),
			array( 'posts' => array() ),
			array( 'posts' => array( $this->posts[8] ) ),
		);
		$payload = array(
			'theme'    => 'corner-bakery',
			'manifest' => array(),
			'rejected' => array(),
			'files'    => array(
				'parts/header.html'    => $this->block( 'Staging edit' ),
				'parts/footer.html'    => $this->block( 'New footer' ),
				'templates/index.html' => $this->file( 'templates/index.html' ),
				'templates/page.html'  => $this->block( 'Different page' ),
			),
		);

		$plan   = $this->importer()->plan( $payload );
		$by_key = array();
		foreach ( $plan['entries'] as $entry ) {
			$by_key[ $entry['key'] ] = $entry;
		}

		$this->assertSame( Importer::THEME_MATCH, $plan['theme_status'] );
		$this->assertSame( Importer::CHANGED, $by_key['parts/header']['status'] );
		$this->assertTrue( $by_key['parts/header']['customized'], 'This site has its own change to the header.' );
		$this->assertSame( Importer::NEW, $by_key['parts/footer']['status'] );
		$this->assertSame( Importer::IDENTICAL, $by_key['templates/index']['status'], 'Same as the theme file, nothing to write.' );
		$this->assertSame( Importer::CHANGED, $by_key['templates/page']['status'] );
		$this->assertFalse( $by_key['templates/page']['customized'], 'Its database copy equals the theme file.' );
	}

	public function test_global_styles_are_compared_as_changes_over_the_theme_json(): void {
		$theme   = json_decode( $this->file( 'theme.json' ), true );
		$staging = $theme;
		$staging['styles']['color']['background'] = '#f4ecdf';
		$payload = array(
			'theme'    => 'corner-bakery',
			'manifest' => array(),
			'rejected' => array(),
			'files'    => array( 'theme.json' => json_encode( $staging ) ),
		);

		\WP_Query::$queue = array( array( 'posts' => array() ) );
		$entry            = $this->importer()->plan( $payload )['entries'][0];
		$this->assertSame( Importer::NEW, $entry['status'] );
		$this->assertSame( array( 'styles' => array( 'color' => array( 'background' => '#f4ecdf' ) ) ), $entry['changes'] );

		$post             = $this->add_post( 9, Themes::GLOBAL_STYLES, 'wp-global-styles-corner-bakery', json_encode( array( 'version' => 3, 'isGlobalStylesUserThemeJSON' => true, 'styles' => array( 'color' => array( 'background' => '#f4ecdf' ) ) ) ) ); // phpcs:ignore
		\WP_Query::$queue = array( array( 'posts' => array( $post ) ) );
		$this->assertSame( Importer::IDENTICAL, $this->importer()->plan( $payload )['entries'][0]['status'] );

		$post->post_content = json_encode( array( 'version' => 3, 'styles' => array( 'color' => array( 'text' => '#000000' ) ) ) );
		\WP_Query::$queue   = array( array( 'posts' => array( $post ) ) );
		$entry              = $this->importer()->plan( $payload )['entries'][0];
		$this->assertSame( Importer::CHANGED, $entry['status'] );
		$this->assertTrue( $entry['customized'] );
		$this->assertSame( '#000000', $entry['changes']['styles']['color']['text'], 'Values only this site has stay.' );
	}

	public function test_a_zip_for_another_theme_is_refused_and_a_child_or_parent_needs_confirming(): void {
		$importer = $this->importer();

		$this->assertSame( Importer::THEME_MATCH, $importer->theme_status( 'corner-bakery' ) );
		$this->assertSame( Importer::THEME_UNKNOWN, $importer->theme_status( '' ) );
		$this->assertSame( Importer::THEME_OTHER, $importer->theme_status( 'twentytwentyfive' ) );

		$payload = array(
			'theme'    => 'twentytwentyfive',
			'manifest' => array(),
			'rejected' => array(),
			'files'    => array( 'parts/footer.html' => $this->block( 'x' ) ),
		);
		$this->assertSame( 'block_theme_ops_other_theme', $importer->apply( $payload )->get_error_code() );
		$this->assertSame( array(), $this->written );

		Filters\expectApplied( 'block_theme_ops_import_related_theme' )->with( false, 'corner-bakery-child', 'corner-bakery' )->andReturn( true );
		$this->assertSame( Importer::THEME_RELATED, $importer->theme_status( 'corner-bakery-child' ) );
		$payload['theme'] = 'corner-bakery-child';
		$this->assertSame( 'block_theme_ops_confirm_theme', $importer->apply( $payload )->get_error_code() );
		$this->assertSame( array(), $this->written );
	}

	public function test_markup_is_mapped_to_this_site_navigation_media_and_address(): void {
		Functions\when( 'attachment_url_to_postid' )->alias( fn( $url ) => 'https://prod.test/wp-content/uploads/2026/logo.png' === $url ? 77 : 0 );
		Functions\when( 'get_posts' )->alias(
			fn( $args ) => 'wp_navigation' === $args['post_type'] && 'main-menu' === $args['name'] ? array( 31 ) : array()
		);

		$markup = '<!-- wp:navigation {"ref":12} /-->'
			. '<!-- wp:navigation {"ref":13} /-->'
			. '<!-- wp:image {"id":5,"sizeSlug":"large"} --><figure class="wp-block-image"><img src="https://stage.test/wp-content/uploads/2026/logo.png" class="wp-image-5"/></figure><!-- /wp:image -->'
			. '<!-- wp:image {"id":6} --><figure><img src="https://stage.test/wp-content/uploads/2026/gone.png" class="wp-image-6"/></figure><!-- /wp:image -->'
			. '<!-- wp:buttons --><div><!-- wp:button {"url":"https:\/\/stage.test\/shop"} --><a href="https://stage.test/shop">Shop</a><!-- /wp:button --></div><!-- /wp:buttons -->';

		\WP_Query::$queue = array( array( 'posts' => array() ) );
		$entry            = $this->importer()->plan(
			array(
				'theme'    => 'corner-bakery',
				'rejected' => array(),
				'files'    => array( 'parts/header.html' => $markup ),
				'manifest' => array(
					'site_url'   => 'https://stage.test',
					'navigation' => array(
						12 => 'main-menu',
						13 => 'sale-menu',
					),
					'media'      => array(
						5 => 'https://stage.test/wp-content/uploads/2026/logo.png',
						6 => 'https://stage.test/wp-content/uploads/2026/gone.png',
					),
				),
			)
		);
		$content          = $entry['entries'][0]['content'];

		$this->assertStringContainsString( '<!-- wp:navigation {"ref":31} /-->', $content );
		$this->assertStringContainsString( '<!-- wp:navigation /-->', $content, 'A menu that does not exist here is not pointed at a wrong one.' );
		$this->assertStringContainsString( '"id":77', $content );
		$this->assertStringContainsString( 'wp-image-77', $content );
		$this->assertStringNotContainsString( '"id":6', $content );
		$this->assertStringContainsString( 'wp-image-missing', $content );
		$this->assertStringNotContainsString( 'stage.test', $content );
		$this->assertStringContainsString( 'https://prod.test/shop', $content );
		$this->assertStringContainsString( 'https:\/\/prod.test\/shop', $content );

		$this->assertSame( array( 'sale-menu' ), $entry['notes']['navigation'] );
		$this->assertSame( array( 'https://stage.test/wp-content/uploads/2026/gone.png' ), $entry['notes']['media'] );
		$this->assertSame( array( 'https://stage.test', 'https://prod.test' ), $entry['notes']['site_url'] );
	}

	/**
	 * A payload that creates the footer and changes the header.
	 *
	 * @return array
	 */
	private function payload(): array {
		$this->add_post( 7, Themes::TEMPLATE_PART, 'header', $this->block( 'Local edit' ) );
		\WP_Query::$queue = array(
			array( 'posts' => array( $this->posts[7] ) ),
			array( 'posts' => array() ),
		);

		return array(
			'theme'    => 'corner-bakery',
			'manifest' => array( 'areas' => array( 'footer' => 'footer' ) ),
			'rejected' => array(),
			'files'    => array(
				'parts/header.html' => $this->block( 'Staging edit' ),
				'parts/footer.html' => $this->block( 'New footer' ),
			),
		);
	}

	public function test_apply_writes_chosen_items_keeps_one_backup_and_fires_the_actions(): void {
		$importer = $this->importer();
		$payload  = $this->payload();
		\WP_Query::$queue = array_merge( \WP_Query::$queue, \WP_Query::$queue ); // plan() runs twice: once to list, once inside apply().

		Actions\expectDone( 'block_theme_ops_before_import' )->once();
		Actions\expectDone( 'block_theme_ops_imported' )->once()->with( \Mockery::on( fn( $r ) => array( 'parts/footer' ) === $r['created'] && array( 'parts/header' ) === $r['updated'] ), \Mockery::type( 'array' ) );

		$result = $importer->apply( $payload, array( 'parts/header', 'parts/footer' ) );

		$this->assertSame( array( 'parts/footer' ), $result['created'] );
		$this->assertSame( array( 'parts/header' ), $result['updated'] );
		$this->assertSame( $this->block( 'Staging edit' ), $this->posts[7]->post_content );

		$backup = $this->written[ $result['backup'] ]['meta_input'][ Backups::DATA_META ];
		$this->assertSame( 'import', $backup['kind'] );
		$this->assertCount( 2, $backup['records'] );
		$this->assertSame( $this->block( 'Local edit' ), $backup['records'][1]['before'], 'What the import overwrote is kept.' );
		$this->assertSame( 'created', $backup['records'][0]['action'] );
	}

	public function test_apply_only_writes_the_chosen_items(): void {
		$importer = $this->importer();
		$payload  = $this->payload();
		\WP_Query::$queue = array_merge( \WP_Query::$queue, \WP_Query::$queue );

		$result = $importer->apply( $payload, array( 'parts/footer' ) );

		$this->assertSame( array( 'parts/footer' ), $result['created'] );
		$this->assertSame( array(), $result['updated'] );
		$this->assertSame( $this->block( 'Local edit' ), $this->posts[7]->post_content );
	}

	public function test_apply_checks_the_capability_of_each_post(): void {
		$importer = $this->importer();
		$payload  = $this->payload();
		\WP_Query::$queue = array_merge( \WP_Query::$queue, \WP_Query::$queue );
		Functions\when( 'current_user_can' )->alias( fn( $cap, $id = 0 ) => 'edit_post' !== $cap || 7 !== $id );
		Functions\when( 'get_post_type_object' )->justReturn( (object) array( 'cap' => (object) array( 'create_posts' => 'edit_theme_options' ) ) );

		$result = $importer->apply( $payload, array(), array( 'check_caps' => true ) );

		$this->assertSame( 'block_theme_ops_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $this->written, 'Nothing is written when one item is not allowed.' );
	}

	public function test_a_failed_write_puts_back_what_was_already_written(): void {
		$importer = $this->importer();
		$payload  = $this->payload();
		\WP_Query::$queue = array_merge( \WP_Query::$queue, \WP_Query::$queue );
		Functions\when( 'wp_insert_post' )->justReturn( new \WP_Error( 'db', 'Disk full.' ) );

		$result = $importer->apply( $payload );

		$this->assertSame( 'block_theme_ops_import_failed', $result->get_error_code() );
		$this->assertSame( $this->block( 'Local edit' ), $this->posts[7]->post_content );
	}

	public function test_nothing_to_import_is_an_error(): void {
		$this->add_post( 8, Themes::TEMPLATE, 'page', $this->file( 'templates/page.html' ) );
		\WP_Query::$queue = array( array( 'posts' => array( $this->posts[8] ) ) );

		$result = $this->importer()->apply( array( 'theme' => '', 'manifest' => array(), 'rejected' => array(), 'files' => array( 'templates/page.html' => $this->file( 'templates/page.html' ) ) ) ); // phpcs:ignore
		$this->assertSame( 'block_theme_ops_nothing', $result->get_error_code() );
	}

	public function test_undo_puts_back_old_content_and_deletes_created_items_but_leaves_ones_changed_again(): void {
		$importer = $this->importer();
		$payload  = $this->payload();
		\WP_Query::$queue = array_merge( \WP_Query::$queue, \WP_Query::$queue );
		$result   = $importer->apply( $payload );
		$backup   = array( 'id' => $result['backup'], 'undo_until' => time() + 60 ) + $this->written[ $result['backup'] ]['meta_input'][ Backups::DATA_META ]; // phpcs:ignore

		Actions\expectDone( 'block_theme_ops_import_undone' )->twice();
		$undo = $importer->undo( $backup );

		$this->assertSame( array( 'parts/footer', 'parts/header' ), $undo['undone'] );
		$this->assertSame( $this->block( 'Local edit' ), $this->posts[7]->post_content );
		$this->assertSame( array( 100 ), array_slice( $this->deleted, 0, 1 ), 'The created footer is deleted.' );

		// Changed again: the header differs from what the import wrote.
		\WP_Query::$queue = array_merge( \WP_Query::$queue, \WP_Query::$queue );
		$this->posts[7]->post_content = $this->block( 'Local edit' );
		$result = $importer->apply( $this->payload() );
		$backup = array( 'id' => $result['backup'], 'undo_until' => time() + 60 ) + $this->written[ $result['backup'] ]['meta_input'][ Backups::DATA_META ]; // phpcs:ignore
		$this->posts[7]->post_content = $this->block( 'Someone edited it after' );

		$undo = $importer->undo( $backup );
		$this->assertSame( array( 'parts/header' ), array_keys( $undo['skipped'] ) );
		$this->assertSame( $this->block( 'Someone edited it after' ), $this->posts[7]->post_content );
	}

	public function test_undo_refuses_an_expired_import(): void {
		$this->assertSame( 'block_theme_ops_expired', $this->importer()->undo( array( 'id' => 1, 'undo_until' => time() - 1, 'records' => array() ) )->get_error_code() ); // phpcs:ignore
	}

	public function test_a_stashed_upload_is_kept_per_user_and_token(): void {
		$importer = $this->importer();
		$payload  = array( 'theme' => 'x', 'files' => array( 'parts/a.html' => 'x' ) ); // phpcs:ignore
		$token    = $importer->stash( $payload );

		$this->assertSame( 'abcdefghijklmnopqrst', $token );
		$this->assertSame( $payload, $importer->stashed( $token ) );
		$this->assertNull( $importer->stashed( 'short' ), 'Anything that is not a token is ignored.' );
		$this->assertNull( $importer->stashed( '../../evilevilevil' ) );
		$this->assertArrayHasKey( 'block_theme_ops_import_1_abcdefghijklmnopqrst', $this->transients );

		$importer->forget( $token );
		$this->assertNull( $importer->stashed( $token ) );
	}

	public function test_capability_follows_the_filter_with_a_safe_fallback(): void {
		$this->assertSame( 'edit_theme_options', Importer::capability() );

		Filters\expectApplied( 'block_theme_ops_import_capability' )->once()->andReturn( 'manage_options' );
		$this->assertSame( 'manage_options', Importer::capability() );

		Filters\expectApplied( 'block_theme_ops_import_capability' )->once()->andReturn( '' );
		$this->assertSame( 'edit_theme_options', Importer::capability() );
	}
}
