<?php
/**
 * Tests for exporting items as theme files and opening pull requests.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps\Tests\Unit;

use AryanX\BlockThemeOps\Drift;
use AryanX\BlockThemeOps\Exporter;
use AryanX\BlockThemeOps\Git\GitHub;
use AryanX\BlockThemeOps\Git\Provider;
use AryanX\BlockThemeOps\Settings;
use AryanX\BlockThemeOps\Themes;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ExportTest extends PluginTestCase {

	/**
	 * Requests sent through wp_remote_request().
	 *
	 * @var array[]
	 */
	private $requests = array();

	protected function setUp(): void {
		parent::setUp();

		$this->requests = array();
		Functions\when( 'get_bloginfo' )->justReturn( 'Corner Bakery' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://bakery.test/' );
		Functions\when( 'sanitize_title' )->alias( 'strtolower' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( $r ) => $r['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) {
				$this->requests[] = array( 'url' => $url ) + $args;
				$answers          = array(
					'/git/ref/heads/main'      => array( 'object' => array( 'sha' => 'base' ) ),
					'/git/commits/base'        => array( 'tree' => array( 'sha' => 'tree0' ) ),
					'/git/trees'               => array( 'sha' => 'tree1' ),
					'/git/commits'             => array( 'sha' => 'commit1' ),
					'/git/refs'                => array( 'ref' => 'x' ),
					'/pulls'                   => array(
						'number'   => 9,
						'html_url' => 'https://github.com/acme/site/pull/9',
					),
				);
				foreach ( $answers as $end => $answer ) {
					if ( substr( $url, -strlen( $end ) ) === $end ) {
						return array(
							'code' => 201,
							'body' => json_encode( $answer ),
						);
					}
				}
				return array(
					'code' => 404,
					'body' => '{"message":"Not Found"}',
				);
			}
		);
	}

	/**
	 * An item for the header part, customized in the Site Editor.
	 *
	 * @return array
	 */
	private function header_item(): array {
		$post = $this->add_post( 7, Themes::TEMPLATE_PART, 'header', '<!-- wp:template-part {"slug":"logo","theme":"corner-bakery"} /--><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->', array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ) );

		return ( new Drift( new Themes() ) )->item_for_post( $post );
	}

	private function exporter( array $settings = array() ): Exporter {
		$this->options[ Settings::OPTION ] = $settings;

		return new Exporter( new Drift( new Themes() ), new Settings() );
	}

	public function test_files_are_laid_out_like_the_theme_without_the_theme_attribute(): void {
		$item   = $this->header_item();
		$styles = ( new Drift( new Themes() ) )->item_for_post( $this->add_post( 8, Themes::GLOBAL_STYLES, 'wp-global-styles-corner-bakery', '{"styles":{"color":{"background":"#f4ecdf"}}}' ) );

		$files = $this->exporter()->files( array( $item, $styles ), 'zip' );

		$this->assertSame( array( 'parts/header.html', 'theme.json' ), array_keys( $files ) );
		$this->assertSame( "<!-- wp:template-part {\"slug\":\"logo\"} /--><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->\n", $files['parts/header.html'] );
		$theme_json = json_decode( $files['theme.json'], true );
		$this->assertSame( '#f4ecdf', $theme_json['styles']['color']['background'] );
		$this->assertCount( 3, $theme_json['settings']['color']['palette'] );
	}

	public function test_the_export_files_filter_can_add_files_but_not_leave_the_theme(): void {
		$item = $this->header_item();
		Filters\expectApplied( 'block_theme_ops_export_files' )->once()->with( \Mockery::type( 'array' ), array( $item ), 'pr' )->andReturnUsing(
			static function ( $files ) {
				return $files + array(
					'styles/user-changes.json' => '{}',
					'../wp-config.php'         => 'nope',
					'/abs/../x.html'           => 'nope',
					'parts/footer.html'        => 42,
				);
			}
		);

		$this->assertSame( array( 'parts/header.html', 'styles/user-changes.json' ), array_keys( $this->exporter()->files( array( $item ), 'pr' ) ) );
	}

	public function test_a_pull_request_is_one_commit_on_a_new_branch(): void {
		$item     = $this->header_item();
		$exporter = $this->exporter(
			array(
				'repo'  => 'acme/site',
				'path'  => 'wp-content/themes/corner-bakery',
				'token' => 'secret',
			)
		);

		Actions\expectDone( 'block_theme_ops_exported' )->once()->with( \Mockery::type( 'array' ), array( $item ), 'pr' );
		Actions\expectDone( 'block_theme_ops_pull_request_opened' )->once()->with( 'https://github.com/acme/site/pull/9', array( $item ), \Mockery::type( 'array' ) );

		$result = $exporter->pull_request( array( $item ) );

		$this->assertSame( 9, $result['number'] );
		$this->assertMatchesRegularExpression( '#^block-theme-ops/corner-bakery-\d{8}-\d{6}$#', $result['branch'] );
		$this->assertSame(
			array( 'GET', 'GET', 'POST', 'POST', 'POST', 'POST' ),
			array_column( $this->requests, 'method' )
		);
		$this->assertSame( 'https://api.github.com/repos/acme/site/git/ref/heads/main', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer secret', $this->requests[0]['headers']['Authorization'] );
		$tree = json_decode( $this->requests[2]['body'], true );
		$this->assertSame( 'tree0', $tree['base_tree'] );
		$this->assertSame( 'wp-content/themes/corner-bakery/parts/header.html', $tree['tree'][0]['path'] );
		$pull = json_decode( $this->requests[5]['body'], true );
		$this->assertSame( 'Site Editor changes from Corner Bakery', $pull['title'] );
		$this->assertStringContainsString( '| Header (Template part) | `parts/header.html` | Customized | Sam |', $pull['body'] );
	}

	public function test_pull_request_filters_shape_the_request(): void {
		$item     = $this->header_item();
		$exporter = $this->exporter(
			array(
				'repo'  => 'acme/site',
				'token' => 'secret',
			)
		);

		Filters\expectApplied( 'block_theme_ops_github_api_url' )->atLeast()->once()->andReturn( 'https://github.example.com/api/v3/' );
		Filters\expectApplied( 'block_theme_ops_github_request_args' )->times( 6 )->andReturnUsing(
			static function ( $args ) {
				$args['timeout'] = 30;
				return $args;
			}
		);
		Filters\expectApplied( 'block_theme_ops_pr_title' )->once()->with( 'Site Editor changes from Corner Bakery', array( $item ) )->andReturn( 'Header from production' );
		Filters\expectApplied( 'block_theme_ops_pr_body' )->once()->andReturn( 'Body' );
		Filters\expectApplied( 'block_theme_ops_branch_name' )->once()->andReturn( 'bad..branch' );

		$result = $exporter->pull_request( array( $item ) );

		$this->assertStringStartsWith( 'https://github.example.com/api/v3/repos/acme/site/', $this->requests[0]['url'] );
		$this->assertSame( 30, $this->requests[0]['timeout'] );
		$this->assertStringStartsWith( 'block-theme-ops/', $result['branch'], 'An invalid branch name falls back to the default.' );
		$pull = json_decode( $this->requests[5]['body'], true );
		$this->assertSame( array( 'Header from production', 'Body' ), array( $pull['title'], $pull['body'] ) );
		$this->assertSame( 'Header from production', json_decode( $this->requests[3]['body'], true )['message'] );
	}

	public function test_github_errors_and_missing_settings_come_back_as_wp_errors(): void {
		$item = $this->header_item();
		$this->assertSame( 'block_theme_ops_not_configured', $this->exporter()->pull_request( array( $item ) )->get_error_code() );

		$error = $this->exporter(
			array(
				'repo'   => 'acme/site',
				'branch' => 'develop',
				'token'  => 'secret',
			)
		)->pull_request( array( $item ) );
		$this->assertSame( 'block_theme_ops_github', $error->get_error_code() );
		$this->assertStringContainsString( 'Not Found (HTTP 404 on repos/acme/site/git/ref/heads/develop)', $error->get_error_message() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_the_provider_filter_swaps_the_git_host_or_falls_back(): void {
		$exporter = $this->exporter();
		$other    = new class() implements Provider {
			public function name(): string {
				return 'GitLab';
			}
			public function is_configured(): bool {
				return true;
			}
			public function target(): string {
				return 'group/project';
			}
			public function open_pull_request( array $files, array $items ) {
				return array(
					'url'    => 'https://gitlab.test/mr/1',
					'number' => 1,
					'branch' => 'b',
				);
			}
		};

		Filters\expectApplied( 'block_theme_ops_git_provider' )->once()->with( \Mockery::type( GitHub::class ), \Mockery::type( Settings::class ) )->andReturn( $other );
		$this->assertSame( 'https://gitlab.test/mr/1', $exporter->pull_request( array( $this->header_item() ) )['url'] );

		Filters\expectApplied( 'block_theme_ops_git_provider' )->once()->andReturn( 'not a provider' );
		$this->assertInstanceOf( GitHub::class, $exporter->provider() );
	}
}
