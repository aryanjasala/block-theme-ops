<?php
/**
 * Tests for locking templates against Site Editor saves.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Tests\Unit;

use AryanX\Axle\BlockThemeOps\Locks;
use AryanX\Axle\BlockThemeOps\Settings;
use AryanX\Axle\BlockThemeOps\Themes;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * A REST request with a route, a method and parameters.
 */
class LockRequest extends \WP_REST_Request {
	public $route  = '';
	public $method = 'POST';
	public $params = array();

	public function __construct( string $route = '', string $method = 'POST', array $params = array() ) {
		$this->route  = $route;
		$this->method = $method;
		$this->params = $params;
	}

	public function get_route() {
		return $this->route;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}

final class LocksTest extends PluginTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'sanitize_title' )->alias( 'strtolower' );
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	private function locks( array $saved = array() ): Locks {
		if ( $saved ) {
			$this->options[ Locks::OPTION ] = $saved;
		}

		return new Locks( new Themes(), new Settings() );
	}

	public function test_nothing_is_locked_by_default_and_production_is_the_default_environment(): void {
		$locks = $this->locks();

		$this->assertSame(
			array(
				'all'          => false,
				'items'        => array(),
				'environments' => array( 'production' ),
			),
			$locks->get()
		);
		$this->assertFalse( $locks->any() );
		$this->assertTrue( $locks->enforced() );
		$this->assertFalse( $locks->blocks( Themes::TEMPLATE_PART, 'header' ) );
	}

	public function test_items_are_locked_by_key_and_bad_keys_are_dropped(): void {
		$locks = $this->locks();
		$locks->set_items( array( 'parts/header', 'styles', 'parts/../x', 'junk', 'templates/page' ), true );

		$this->assertSame( array( 'parts/header', 'styles', 'templates/page' ), $locks->get()['items'] );
		$this->assertTrue( $locks->is_locked( Themes::TEMPLATE_PART, 'header' ) );
		$this->assertTrue( $locks->is_locked( Themes::GLOBAL_STYLES ) );
		$this->assertFalse( $locks->is_locked( Themes::TEMPLATE_PART, 'footer' ) );

		$locks->set_items( array( 'styles' ), false );
		$this->assertFalse( $locks->is_locked( Themes::GLOBAL_STYLES ) );
	}

	public function test_everything_from_the_theme_covers_items_with_a_theme_file_only(): void {
		$locks = $this->locks( array( 'all' => true ) );

		$this->assertTrue( $locks->is_locked( Themes::TEMPLATE_PART, 'header' ), 'Has parts/header.html.' );
		$this->assertTrue( $locks->is_locked( Themes::GLOBAL_STYLES ), 'Has theme.json.' );
		$this->assertFalse( $locks->is_locked( Themes::TEMPLATE, 'wp-custom-template-landing' ), 'Made in the Site Editor only.' );
	}

	public function test_locks_only_apply_on_the_chosen_environments(): void {
		$locks = $this->locks( array( 'items' => array( 'parts/header' ) ) );
		$this->assertTrue( $locks->blocks( Themes::TEMPLATE_PART, 'header' ) );

		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		$this->assertFalse( $locks->enforced() );
		$this->assertFalse( $locks->blocks( Themes::TEMPLATE_PART, 'header' ) );

		$locks->update( array( 'environments' => array( 'local', 'nonsense' ) ) );
		$this->assertSame( array( 'local' ), $locks->get()['environments'] );
		$this->assertTrue( $locks->enforced() );
	}

	public function test_the_environments_filter_wins_and_falls_back_on_junk(): void {
		$locks = $this->locks();

		Filters\expectApplied( 'block_theme_ops_lock_environments' )->once()->with( array( 'production' ) )->andReturn( array( 'production', 'staging', 'moon' ) );
		$this->assertSame( array( 'production', 'staging' ), $locks->environments() );
	}

	public function test_an_override_capability_lets_a_role_save_anyway_and_defaults_to_nobody(): void {
		$locks = $this->locks( array( 'items' => array( 'parts/header' ) ) );
		$this->assertSame( '', Locks::override_capability() );

		Filters\expectApplied( 'block_theme_ops_lock_override_capability' )->andReturn( 'manage_options' );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'manage_options' === $cap );
		$this->assertFalse( $locks->blocks( Themes::TEMPLATE_PART, 'header' ) );
	}

	public function test_changing_locks_fires_the_action_once_and_only_on_a_real_change(): void {
		$locks = $this->locks();

		Actions\expectDone( 'block_theme_ops_locks_changed' )->once()->with( \Mockery::on( fn( $new ) => array( 'parts/header' ) === $new['items'] ), \Mockery::type( 'array' ) );
		$locks->set_items( array( 'parts/header' ), true );
		$locks->set_items( array( 'parts/header' ), true );
	}

	public function test_a_save_of_a_locked_template_through_rest_is_refused_with_the_message_and_link(): void {
		$locks = $this->locks( array( 'items' => array( 'parts/header' ) ) );
		$this->options[ Settings::OPTION ] = array( 'repo' => 'acme/site' );

		Actions\expectDone( 'block_theme_ops_save_blocked' )->times( 3 )->with( 'parts/header', \Mockery::type( LockRequest::class ) );

		foreach ( array(
			new LockRequest( '/wp/v2/template-parts/corner-bakery//header', 'POST' ),
			new LockRequest( '/wp/v2/template-parts/corner-bakery%2F%2Fheader', 'DELETE' ),
			new LockRequest( '/wp/v2/template-parts', 'POST', array( 'slug' => 'header' ) ),
		) as $request ) {
			$error = $locks->check_request( null, array(), $request );
			$this->assertSame( 'block_theme_ops_locked', $error->get_error_code() );
			$this->assertSame( 403, $error->get_error_data()['status'] );
			$this->assertSame( 'https://github.com/acme/site', $error->get_error_data()['link'] );
			$this->assertStringContainsString( 'theme files', $error->get_error_message() );
		}
	}

	public function test_autosaves_and_revisions_of_a_locked_item_are_refused_too(): void {
		$locks = $this->locks( array( 'items' => array( 'templates/page' ) ) );

		$request = new LockRequest( '/wp/v2/templates/corner-bakery//page/autosaves', 'POST' );
		$this->assertSame( 'block_theme_ops_locked', $locks->check_request( null, array(), $request )->get_error_code() );
	}

	public function test_reads_other_items_other_themes_and_other_routes_are_not_touched(): void {
		$locks = $this->locks( array( 'items' => array( 'parts/header' ) ) );

		Actions\expectDone( 'block_theme_ops_save_blocked' )->never();
		foreach ( array(
			new LockRequest( '/wp/v2/template-parts/corner-bakery//header', 'GET' ),
			new LockRequest( '/wp/v2/template-parts/corner-bakery//footer', 'POST' ),
			new LockRequest( '/wp/v2/template-parts/other-theme//header', 'POST' ),
			new LockRequest( '/wp/v2/templates/lookup', 'POST' ),
			new LockRequest( '/wp/v2/posts/5', 'POST' ),
		) as $request ) {
			$this->assertNull( $locks->check_request( null, array(), $request ) );
		}
	}

	public function test_global_styles_of_the_active_theme_are_locked_by_the_styles_key(): void {
		$locks = $this->locks( array( 'items' => array( 'styles' ) ) );
		$this->add_post( 9, Themes::GLOBAL_STYLES, 'wp-global-styles-corner-bakery', '{}' );
		$this->add_post( 10, Themes::GLOBAL_STYLES, 'wp-global-styles-other', '{}' );
		$this->terms[10]['wp_theme'] = array( 'other' );

		$this->assertSame( 'block_theme_ops_locked', $locks->check_request( null, array(), new LockRequest( '/wp/v2/global-styles/9', 'PUT' ) )->get_error_code() );
		$this->assertNull( $locks->check_request( null, array(), new LockRequest( '/wp/v2/global-styles/10', 'PUT' ) ) );
	}

	public function test_the_message_and_link_are_filtered_with_fallbacks(): void {
		$locks = $this->locks();

		Filters\expectApplied( 'block_theme_ops_lock_message' )->once()->with( \Mockery::type( 'string' ), 'parts/header' )->andReturn( 'Ask the dev team.' );
		$this->assertSame( 'Ask the dev team.', $locks->message( 'parts/header' ) );

		Filters\expectApplied( 'block_theme_ops_lock_message' )->once()->andReturn( '  ' );
		$this->assertStringContainsString( 'theme files', $locks->message( 'parts/header' ) );

		$this->assertSame( '', $locks->link( 'styles' ) );
		Filters\expectApplied( 'block_theme_ops_lock_link' )->once()->with( '', 'styles' )->andReturn( 'https://git.example/team/theme' );
		$this->assertSame( 'https://git.example/team/theme', $locks->link( 'styles' ) );
	}

	public function test_the_theme_items_list_what_has_a_file_and_the_capability_follows_a_filter(): void {
		$keys = array_column( $this->locks()->theme_items(), 'key' );
		$this->assertContains( 'parts/header', $keys );
		$this->assertContains( 'templates/page', $keys );
		$this->assertContains( 'styles', $keys );

		$this->assertSame( 'edit_theme_options', Locks::capability() );
		Filters\expectApplied( 'block_theme_ops_lock_capability' )->once()->andReturn( 'manage_options' );
		$this->assertSame( 'manage_options', Locks::capability() );
	}
}
