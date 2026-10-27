<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

use AryanX\Axle\BlockThemeOps\Admin\DriftPage;
use AryanX\Axle\BlockThemeOps\Admin\Notice;
use AryanX\Axle\BlockThemeOps\Cli\Command;
use AryanX\Axle\BlockThemeOps\Health\SiteHealth;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shared objects once and registers every service.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

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
	 * Backups.
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
	 * Reset, undo and keep.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Export.
	 *
	 * @var Exporter
	 */
	private Exporter $exporter;

	/**
	 * Drift checks.
	 *
	 * @var Checker
	 */
	private Checker $checker;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$themes           = new Themes();
		$this->settings   = new Settings();
		$this->drift      = new Drift( $themes );
		$this->snapshots  = new Snapshots( $themes );
		$this->backups    = new Backups();
		$this->alerts     = new Alerts();
		$this->operations = new Operations( $this->drift, $this->backups, $this->snapshots, $this->alerts );
		$this->exporter   = new Exporter( $this->drift, $this->settings );
		$this->checker    = new Checker( $this->drift, $this->snapshots, $this->backups, $this->alerts, $this->settings );
	}

	/**
	 * The inventory, for other plugins and deploy tooling.
	 *
	 * @return Drift
	 */
	public function drift(): Drift {
		return $this->drift;
	}

	/**
	 * Reset, undo and keep, for other plugins.
	 *
	 * @return Operations
	 */
	public function operations(): Operations {
		return $this->operations;
	}

	/**
	 * Export to files, zip or pull request, for other plugins.
	 *
	 * @return Exporter
	 */
	public function exporter(): Exporter {
		return $this->exporter;
	}

	/**
	 * Drift checks, for other plugins.
	 *
	 * @return Checker
	 */
	public function checker(): Checker {
		return $this->checker;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$services = array(
			$this->snapshots,
			$this->backups,
			$this->checker,
			new DriftPage( $this->drift, $this->operations, $this->exporter, $this->backups, $this->settings, $this->alerts ),
			new Notice( $this->drift, $this->alerts ),
			new SiteHealth( $this->drift ),
			new Privacy(),
			new Abilities( $this->drift, $this->operations, $this->exporter ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'block-theme-ops', new Command( $this->drift, $this->operations, $this->exporter, $this->backups, $this->checker ) );
		}
	}
}
