<?php
/**
 * Drift checks after theme updates and on a schedule.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Looks for items that went theme-changed: right after WordPress updates
 * the active theme, and once a day for deploys that bypass WordPress (Git,
 * SFTP, rsync). New ones raise the admin notice and, when turned on, an email.
 */
class Checker implements Service {

	public const HOOK = 'block_theme_ops_check';

	/**
	 * Inventory.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * Snapshots.
	 *
	 * @var Snapshots
	 */
	private Snapshots $snapshots;

	/**
	 * Backups, cleaned up by the same scheduled run.
	 *
	 * @var Backups
	 */
	private Backups $backups;

	/**
	 * Pending alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the checker.
	 *
	 * @param Drift     $drift     Inventory.
	 * @param Snapshots $snapshots Snapshots.
	 * @param Backups   $backups   Backups.
	 * @param Alerts    $alerts    Pending alerts.
	 * @param Settings  $settings  Settings.
	 */
	public function __construct( Drift $drift, Snapshots $snapshots, Backups $backups, Alerts $alerts, Settings $settings ) {
		$this->drift     = $drift;
		$this->snapshots = $snapshots;
		$this->backups   = $backups;
		$this->alerts    = $alerts;
		$this->settings  = $settings;
	}

	/**
	 * Hooks the scheduled check and the theme updater.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
	}

	/**
	 * How often the scheduled check runs.
	 *
	 * @return string A schedule name from wp_get_schedules().
	 */
	public static function recurrence(): string {
		/**
		 * Filters how often Block Theme Ops compares theme files, to catch
		 * deploys that bypass WordPress.
		 *
		 * @param string $recurrence A schedule from wp_get_schedules(). Default daily.
		 */
		$recurrence = (string) apply_filters( 'block_theme_ops_check_schedule', 'daily' );

		return array_key_exists( $recurrence, wp_get_schedules() ) ? $recurrence : 'daily';
	}

	/**
	 * Makes sure the check is scheduled, and at the right interval.
	 */
	public function schedule(): void {
		$recurrence = self::recurrence();
		if ( wp_next_scheduled( self::HOOK ) && wp_get_schedule( self::HOOK ) === $recurrence ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, self::HOOK );
	}

	/**
	 * Removes the scheduled check, on deactivation.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The scheduled run: check, then delete expired backups.
	 */
	public function run_scheduled(): void {
		$this->run( 'cron' );
		$this->backups->cleanup();
	}

	/**
	 * Checks right after WordPress updates or replaces the active theme or its parent.
	 *
	 * @param mixed $upgrader   Upgrader.
	 * @param mixed $hook_extra type, action, theme or themes.
	 */
	public function after_upgrade( $upgrader, $hook_extra ): void {
		if ( ! is_array( $hook_extra ) || 'theme' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}

		$themes = array();
		if ( isset( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
			$themes = $hook_extra['themes'];
		} elseif ( isset( $hook_extra['theme'] ) ) {
			$themes = array( $hook_extra['theme'] );
		} elseif ( is_object( $upgrader ) && isset( $upgrader->result ) && is_array( $upgrader->result ) && isset( $upgrader->result['destination_name'] ) ) {
			// Uploading a theme zip over an installed theme.
			$themes = array( $upgrader->result['destination_name'] );
		}

		$active = array_unique( array( $this->drift->themes()->stylesheet(), $this->drift->themes()->template() ) );
		if ( array_intersect( array_map( 'strval', $themes ), $active ) ) {
			$this->run( 'upgrade' );
		}
	}

	/**
	 * Scans every item, records missing snapshots, and reports items that
	 * went theme-changed since the last check.
	 *
	 * @param string $context upgrade, cron or cli.
	 * @return array[] Items newly found theme-changed.
	 */
	public function run( string $context ): array {
		$this->drift->flush();
		$items = $this->drift->items();
		$new   = array();

		foreach ( $items as $item ) {
			if ( ! $item['snapshot'] && '' !== $item['file'] ) {
				$post = get_post( (int) $item['id'] );
				if ( $post instanceof \WP_Post ) {
					// Not the file at the time of the edit, but every later change is now certain.
					$this->snapshots->record( $post, 'baseline' );
				}
			}

			if ( Drift::THEME_CHANGED !== $item['status'] || $item['kept'] ) {
				continue;
			}
			if ( get_post_meta( (int) $item['id'], Drift::NOTIFIED_META, true ) === $item['file_hash'] ) {
				continue;
			}

			update_post_meta( (int) $item['id'], Drift::NOTIFIED_META, $item['file_hash'] );
			$new[] = $item;
		}

		if ( $new ) {
			$this->alerts->add( wp_list_pluck( $new, 'id' ), $context );

			/**
			 * Fires when a check finds items whose theme file changed after the
			 * database copy was made. Each item is reported once per file change.
			 *
			 * @param array[] $new     The items.
			 * @param string  $context upgrade, cron or cli.
			 */
			do_action( 'block_theme_ops_drift_detected', $new, $context );

			$this->notify( $new );
		}

		/**
		 * Fires after every drift check.
		 *
		 * @param array[] $items   Every item found.
		 * @param string  $context upgrade, cron or cli.
		 */
		do_action( 'block_theme_ops_checked', $items, $context );

		return $new;
	}

	/**
	 * Emails the site admin about newly drifted items, when that is turned on.
	 *
	 * @param array[] $items Items.
	 * @return bool Whether an email went out.
	 */
	public function notify( array $items ): bool {
		if ( ! $items || ! $this->settings->get( 'notify' ) ) {
			return false;
		}

		/**
		 * Filters who gets the drift email.
		 *
		 * @param string[] $recipients Default the site admin email.
		 * @param array[]  $items      The items in the email.
		 */
		$recipients = apply_filters( 'block_theme_ops_notify_recipients', array( (string) get_option( 'admin_email' ) ), $items );
		$recipients = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $recipients ) ), 'is_email' ) );
		if ( ! $recipients ) {
			return false;
		}

		$lines = array(
			sprintf(
				/* translators: %s: site name. */
				__( 'The theme files changed on %s, but the site still uses older copies saved in the Site Editor for these:', 'block-theme-ops' ),
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
			),
			'',
		);
		foreach ( $items as $item ) {
			$lines[] = sprintf( '- %s (%s): %s', $item['title'], $item['type_label'], $item['file'] );
		}
		$lines[] = '';
		$lines[] = __( 'Review them, reset them to the theme files or keep them:', 'block-theme-ops' );
		$lines[] = admin_url( 'themes.php?page=block-theme-ops' );

		$default = array(
			'to'      => $recipients,
			'subject' => sprintf(
				/* translators: 1: site name, 2: number of items. */
				_n( '[%1$s] %2$d Site Editor change now hides a newer theme file', '[%1$s] %2$d Site Editor changes now hide newer theme files', count( $items ), 'block-theme-ops' ),
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				count( $items )
			),
			'message' => implode( "\n", $lines ),
			'headers' => array(),
		);

		/**
		 * Filters the drift email.
		 *
		 * @param array   $email to, subject, message and headers, as wp_mail() takes them.
		 * @param array[] $items The items in the email.
		 */
		$email = array_merge( $default, (array) apply_filters( 'block_theme_ops_notify_email', $default, $items ) );
		if ( '' === trim( (string) $email['subject'] ) || '' === trim( (string) $email['message'] ) ) {
			return false;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One email per theme change, and only when the admin turned it on.
		return (bool) wp_mail( $email['to'], (string) $email['subject'], (string) $email['message'], $email['headers'] );
	}
}
