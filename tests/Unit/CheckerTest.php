<?php
/**
 * Tests for the drift check, its schedule, the alert email and the notice.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps\Tests\Unit;

use AryanX\BlockThemeOps\Admin\Notice;
use AryanX\BlockThemeOps\Alerts;
use AryanX\BlockThemeOps\Backups;
use AryanX\BlockThemeOps\Checker;
use AryanX\BlockThemeOps\Drift;
use AryanX\BlockThemeOps\Settings;
use AryanX\BlockThemeOps\Snapshots;
use AryanX\BlockThemeOps\Themes;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class CheckerTest extends PluginTestCase {

	/**
	 * Emails sent.
	 *
	 * @var array[]
	 */
	private $mail = array();

	protected function setUp(): void {
		parent::setUp();

		$this->mail                     = array();
		$this->options['admin_email'] = 'owner@bakery.test';
		Functions\when( 'is_email' )->alias( fn( $email ) => filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'get_bloginfo' )->justReturn( 'Corner Bakery' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'https://bakery.test/wp-admin/' . $path );
		Functions\when( 'wp_list_pluck' )->alias( fn( $list, $field ) => array_column( $list, $field ) );
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->mail[] = compact( 'to', 'subject', 'message' );
				return true;
			}
		);
		Functions\when( 'wp_get_schedules' )->justReturn(
			array(
				'hourly' => array(),
				'daily'  => array(),
			)
		);
	}

	/**
	 * A header part edited in the Site Editor, then a theme deploy that changed the file.
	 *
	 * @return Checker
	 */
	private function drifted(): Checker {
		$themes    = new Themes();
		$snapshots = new Snapshots( $themes );
		$post      = $this->add_post( 7, Themes::TEMPLATE_PART, 'header', 'edited' );
		$snapshots->record( $post, 'save' );
		$this->write( 'parts/header.html', $this->file( 'parts/header.html' ) . "\n<!-- wp:navigation /-->" );

		$drift = $this->getMockBuilder( Drift::class )->setConstructorArgs( array( $themes ) )->onlyMethods( array( 'items' ) )->getMock();
		$drift->method( 'items' )->willReturnCallback( fn() => array( ( new Drift( $themes ) )->item_for_post( $post ) ) );

		return new Checker( $drift, $snapshots, new Backups(), new Alerts(), new Settings() );
	}

	public function test_a_drifted_item_is_reported_once_per_file_change(): void {
		$checker = $this->drifted();

		Actions\expectDone( 'block_theme_ops_drift_detected' )->once()->with( \Mockery::on( fn( $items ) => 'parts/header' === $items[0]['key'] ), 'cron' );
		Actions\expectDone( 'block_theme_ops_checked' )->twice();

		$this->assertCount( 1, $checker->run( 'cron' ) );
		$this->assertSame( array( 7 ), ( new Alerts() )->ids() );
		$this->assertSame( 'cron', ( new Alerts() )->context() );

		$this->assertSame( array(), $checker->run( 'cron' ), 'Already reported for this version of the file.' );
	}

	public function test_the_email_goes_out_only_when_turned_on_and_through_its_filters(): void {
		$checker = $this->drifted();
		$checker->run( 'cron' );
		$this->assertSame( array(), $this->mail, 'Email is off by default.' );

		$this->options[ Settings::OPTION ] = array( 'notify' => true );
		$this->meta[7]                      = array_diff_key( $this->meta[7], array( Drift::NOTIFIED_META => 1 ) );

		Filters\expectApplied( 'block_theme_ops_notify_recipients' )->once()->with( array( 'owner@bakery.test' ), \Mockery::type( 'array' ) )->andReturn( array( 'dev@agency.test', 'not-an-email' ) );
		Filters\expectApplied( 'block_theme_ops_notify_email' )->once()->andReturnUsing(
			static function ( $email ) {
				$email['subject'] = 'Drift! ' . $email['subject'];
				return $email;
			}
		);
		$checker->run( 'upgrade' );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( array( 'dev@agency.test' ), $this->mail[0]['to'] );
		$this->assertSame( 'Drift! [Corner Bakery] 1 Site Editor change now hides a newer theme file', $this->mail[0]['subject'] );
		$this->assertStringContainsString( '- Header (Template part): parts/header.html', $this->mail[0]['message'] );
	}

	public function test_no_recipients_or_an_empty_email_sends_nothing(): void {
		$this->options[ Settings::OPTION ] = array( 'notify' => true );
		$checker                            = new Checker( new Drift( new Themes() ), new Snapshots( new Themes() ), new Backups(), new Alerts(), new Settings() );
		$item                               = array(
			'title'      => 'Header',
			'type_label' => 'Template part',
			'file'       => 'parts/header.html',
		);

		Filters\expectApplied( 'block_theme_ops_notify_recipients' )->twice()->andReturn( array(), array( 'dev@agency.test' ) );
		$this->assertFalse( $checker->notify( array( $item ) ) );

		Filters\expectApplied( 'block_theme_ops_notify_email' )->once()->andReturn( array( 'message' => '' ) );
		$this->assertFalse( $checker->notify( array( $item ) ) );
		$this->assertSame( array(), $this->mail );
	}

	public function test_the_schedule_filter_falls_back_to_daily(): void {
		Filters\expectApplied( 'block_theme_ops_check_schedule' )->once()->with( 'daily' )->andReturn( 'hourly' );
		$this->assertSame( 'hourly', Checker::recurrence() );

		Filters\expectApplied( 'block_theme_ops_check_schedule' )->once()->andReturn( 'every_second' );
		$this->assertSame( 'daily', Checker::recurrence() );
	}

	public function test_schedule_reschedules_when_the_interval_changes(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );
		Functions\when( 'wp_get_schedule' )->justReturn( 'daily' );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Checker::HOOK );
		Functions\expect( 'wp_schedule_event' )->once()->with( \Mockery::type( 'int' ), 'hourly', Checker::HOOK );
		Filters\expectApplied( 'block_theme_ops_check_schedule' )->andReturn( 'hourly' );

		( new Checker( new Drift( new Themes() ), new Snapshots( new Themes() ), new Backups(), new Alerts(), new Settings() ) )->schedule();
	}

	public function test_only_updates_of_the_active_theme_or_its_parent_trigger_a_check(): void {
		$checker = $this->getMockBuilder( Checker::class )
			->setConstructorArgs( array( new Drift( new Themes() ), new Snapshots( new Themes() ), new Backups(), new Alerts(), new Settings() ) )
			->onlyMethods( array( 'run' ) )
			->getMock();
		$checker->expects( $this->exactly( 2 ) )->method( 'run' )->with( 'upgrade' );

		$checker->after_upgrade( null, array( 'type' => 'plugin', 'plugins' => array( 'corner-bakery' ) ) );
		$checker->after_upgrade( null, array( 'type' => 'theme', 'themes' => array( 'twentytwentyfive' ) ) );
		$checker->after_upgrade( null, array( 'type' => 'theme', 'themes' => array( 'twentytwentyfive', 'corner-bakery' ) ) );
		$checker->after_upgrade( (object) array( 'result' => array( 'destination_name' => 'corner-bakery' ) ), array( 'type' => 'theme', 'action' => 'install' ) );
	}

	public function test_the_notice_message_filter(): void {
		$notice = new Notice( new Drift( new Themes() ), new Alerts() );
		$items  = array(
			array(
				'title'      => 'Header',
				'type_label' => 'Template part',
			),
		);

		$this->assertSame( 'The theme files changed, but 1 Site Editor change still overrides a newer theme file: Header (template part).', $notice->message( $items ) );

		Filters\expectApplied( 'block_theme_ops_notice_message' )->once()->with( \Mockery::type( 'string' ), $items )->andReturn( 'Custom' );
		$this->assertSame( 'Custom', $notice->message( $items ) );
	}

	public function test_alerts_drop_items_and_clear_themselves(): void {
		$alerts = new Alerts();
		$alerts->add( array( 3, 4, 4 ), 'cron' );
		$this->assertSame( array( 3, 4 ), $alerts->ids() );

		$alerts->remove( 3 );
		$this->assertSame( array( 4 ), $alerts->ids() );
		$alerts->remove( 4 );
		$this->assertArrayNotHasKey( Alerts::OPTION, $this->options );
	}

	public function test_the_notice_shows_on_theme_screens_only(): void {
		$this->drifted()->run( 'cron' );
		$themes = new Themes();
		$drift  = $this->getMockBuilder( Drift::class )->setConstructorArgs( array( $themes ) )->onlyMethods( array( 'get' ) )->getMock();
		$drift->method( 'get' )->willReturnCallback( fn() => ( new Drift( $themes ) )->item_for_post( $this->posts[7] ) );
		$notice = new Notice( $drift, new Alerts() );
		$screen = 'edit-post';
		Functions\when( 'get_current_screen' )->alias(
			static function () use ( &$screen ) {
				return (object) array( 'id' => $screen );
			}
		);
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		$render = static function () use ( $notice ): string {
			ob_start();
			$notice->render();
			return (string) ob_get_clean();
		};

		$this->assertSame( array( 'dashboard', 'themes', 'site-editor', 'update-core' ), Notice::screens() );
		$this->assertSame( '', $render(), 'not on the Posts screen, or any other plugin\'s' );

		$screen = 'dashboard';
		$this->assertStringContainsString( 'block-theme-ops-notice', $render() );

		$screen = 'appearance_page_block-theme-ops';
		$this->assertSame( '', $render(), 'Theme Drift lists the items itself' );

		$screen = 'edit-post';
		Filters\expectApplied( 'block_theme_ops_notice_screens' )->andReturn( array( 'edit-post', 42 ) );
		$this->assertSame( array( 'edit-post' ), Notice::screens() );
		$this->assertStringContainsString( 'block-theme-ops-notice', $render() );
	}
}
