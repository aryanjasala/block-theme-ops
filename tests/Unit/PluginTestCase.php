<?php
/**
 * Base class for Block Theme Ops unit tests: a real copy of the fixture
 * theme on disk, and posts, terms and post meta kept in memory.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Tests\Unit;

use Brain\Monkey\Functions;

// Set by the main plugin file, which unit tests do not load.
defined( 'AryanX\Axle\BlockThemeOps\VERSION' ) || define( 'AryanX\Axle\BlockThemeOps\VERSION', '1.0.0' );
defined( 'AryanX\Axle\BlockThemeOps\PLUGIN_FILE' ) || define( 'AryanX\Axle\BlockThemeOps\PLUGIN_FILE', dirname( __DIR__, 2 ) . '/block-theme-ops.php' );

/**
 * A WP_Post with every field the plugin reads declared, so PHP 8.2+ does not
 * warn about dynamic properties.
 */
class FakePost extends \WP_Post {
	/** @var string */
	public $post_type = 'wp_template_part';
	/** @var string */
	public $post_name = '';
	/** @var string */
	public $post_title = '';
	/** @var string */
	public $post_excerpt = '';
	/** @var string */
	public $post_status = 'publish';
	/** @var string */
	public $post_modified_gmt = '2026-01-01 00:00:00';
	/** @var int|string */
	public $post_author = 1;
}

abstract class PluginTestCase extends TestCase {

	/**
	 * Copy of the fixture theme.
	 *
	 * @var string
	 */
	protected $theme_dir = '';

	/**
	 * Post meta by post ID and key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $meta = array();

	/**
	 * Posts by ID.
	 *
	 * @var array<int, FakePost>
	 */
	protected $posts = array();

	/**
	 * Term names by post ID and taxonomy.
	 *
	 * @var array<int, array<string, string[]>>
	 */
	protected $terms = array();

	protected function setUp(): void {
		parent::setUp();

		$this->meta  = array();
		$this->posts = array();
		$this->terms = array();

		$this->theme_dir = sys_get_temp_dir() . '/bto-unit-' . uniqid();
		$this->copy( dirname( __DIR__ ) . '/e2e/fixtures/corner-bakery', $this->theme_dir );

		Functions\when( 'get_stylesheet' )->justReturn( 'corner-bakery' );
		Functions\when( 'get_template' )->justReturn( 'corner-bakery' );
		Functions\when( 'get_stylesheet_directory' )->alias( fn() => $this->theme_dir );
		Functions\when( 'get_template_directory' )->alias( fn() => $this->theme_dir );
		Functions\when( 'get_block_theme_folders' )->justReturn(
			array(
				'wp_template'      => 'templates',
				'wp_template_part' => 'parts',
			)
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				return $id ? (object) array( 'display_name' => 'Sam' ) : false;
			}
		);
		Functions\when( 'wp_get_post_revisions' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				return $this->posts[ (int) $id ] ?? null;
			}
		);
		Functions\when( 'get_the_terms' )->alias(
			function ( $post, $taxonomy ) {
				$id    = is_object( $post ) ? (int) $post->ID : (int) $post;
				$names = $this->terms[ $id ][ $taxonomy ] ?? array();

				return $names ? array_map(
					static function ( $name ) {
						return (object) array( 'name' => $name );
					},
					$names
				) : false;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key = '', $single = false ) {
				return $this->meta[ (int) $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ (int) $id ][ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$this->remove( $this->theme_dir );
		parent::tearDown();
	}

	/**
	 * Adds a database item of the fixture theme.
	 *
	 * @param int    $id      Post ID.
	 * @param string $type    Post type.
	 * @param string $slug    Slug.
	 * @param string $content Content.
	 * @param array  $fields  More post fields.
	 * @return FakePost
	 */
	protected function add_post( int $id, string $type, string $slug, string $content, array $fields = array() ): FakePost {
		$post = new FakePost(
			array_merge(
				array(
					'ID'           => $id,
					'post_type'    => $type,
					'post_name'    => $slug,
					'post_title'   => ucfirst( $slug ),
					'post_content' => $content,
				),
				$fields
			)
		);

		$this->posts[ $id ]              = $post;
		$this->terms[ $id ]['wp_theme'] = array( 'corner-bakery' );

		return $post;
	}

	/**
	 * Contents of a fixture theme file.
	 *
	 * @param string $file Path inside the theme.
	 * @return string
	 */
	protected function file( string $file ): string {
		return (string) file_get_contents( $this->theme_dir . '/' . $file );
	}

	/**
	 * Changes a fixture theme file, like a deploy.
	 *
	 * @param string $file     Path inside the theme.
	 * @param string $contents New contents.
	 */
	protected function write( string $file, string $contents ): void {
		file_put_contents( $this->theme_dir . '/' . $file, $contents );
	}

	/**
	 * Copies a folder.
	 *
	 * @param string $from Source.
	 * @param string $to   Target.
	 */
	private function copy( string $from, string $to ): void {
		mkdir( $to, 0777, true );
		foreach ( scandir( $from ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			is_dir( "$from/$name" ) ? $this->copy( "$from/$name", "$to/$name" ) : copy( "$from/$name", "$to/$name" );
		}
	}

	/**
	 * Removes a folder.
	 *
	 * @param string $dir Folder.
	 */
	private function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			is_dir( "$dir/$name" ) ? $this->remove( "$dir/$name" ) : unlink( "$dir/$name" );
		}
		rmdir( $dir );
	}
}
