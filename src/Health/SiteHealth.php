<?php
/**
 * Site Health check.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Health;

use AryanX\Axle\BlockThemeOps\Admin\DriftPage;
use AryanX\Axle\BlockThemeOps\Drift;
use AryanX\Axle\BlockThemeOps\Locks;
use AryanX\Axle\BlockThemeOps\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Theme Drift test to Tools > Site Health: Site Editor changes that
 * hide a newer theme file are a recommended fix.
 */
final class SiteHealth implements Service {

	public const TEST = 'block_theme_ops';

	public const LOCKS_TEST = 'block_theme_ops_locks';

	/**
	 * Inventory.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * Locks.
	 *
	 * @var Locks|null
	 */
	private ?Locks $locks;

	/**
	 * Sets up the check.
	 *
	 * @param Drift      $drift Inventory.
	 * @param Locks|null $locks Locks.
	 */
	public function __construct( Drift $drift, ?Locks $locks = null ) {
		$this->drift = $drift;
		$this->locks = $locks;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test to the direct (server-side) tests.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function add_test( array $tests ): array {
		$tests['direct'][ self::TEST ] = array(
			'label' => __( 'Site Editor changes and theme files', 'block-theme-ops' ),
			'test'  => array( $this, 'run' ),
		);

		if ( null !== $this->locks ) {
			$tests['direct'][ self::LOCKS_TEST ] = array(
				'label' => __( 'Locked templates', 'block-theme-ops' ),
				'test'  => array( $this, 'run_locks' ),
			);
		}

		return $tests;
	}

	/**
	 * Runs the locks test: locked items that still have a Site Editor copy overriding the theme file.
	 *
	 * @return array Site Health result.
	 */
	public function run_locks(): array {
		$base = array(
			'badge' => self::badge(),
			'test'  => self::LOCKS_TEST,
		);
		$url  = DriftPage::url( array( 'tab' => 'locks' ) );
		$link = sprintf( '<p><a href="%s">%s</a></p>', esc_url( $url ), esc_html__( 'Open the locks', 'block-theme-ops' ) );

		if ( null === $this->locks || ! $this->locks->any() ) {
			return $base + array(
				'label'       => __( 'No templates are locked', 'block-theme-ops' ),
				'status'      => 'good',
				'description' => '<p>' . esc_html__( 'Site Editor changes are not blocked. You can lock templates that come from your theme repository on the Locks tab.', 'block-theme-ops' ) . '</p>',
				'actions'     => $link,
			);
		}

		if ( ! $this->locks->enforced() ) {
			return $base + array(
				'label'       => __( 'Locks are saved but not enforced on this site', 'block-theme-ops' ),
				'status'      => 'good',
				'description' => '<p>' . esc_html__( 'The locks only apply on the environments you chose, and this site is not one of them.', 'block-theme-ops' ) . '</p>',
				'actions'     => $link,
			);
		}

		$overriding = array_values(
			array_filter(
				$this->drift->items(),
				function ( array $item ): bool {
					return $this->locks->is_locked( $item['type'], $item['slug'] ) && Drift::IDENTICAL !== $item['status'] && '' !== $item['file'];
				}
			)
		);
		if ( ! $overriding ) {
			return $base + array(
				'label'       => __( 'Locked templates are served from the theme files', 'block-theme-ops' ),
				'status'      => 'good',
				'description' => '<p>' . esc_html__( 'No locked item has a Site Editor copy that hides its theme file.', 'block-theme-ops' ) . '</p>',
				'actions'     => $link,
			);
		}

		$list = '<ul>';
		foreach ( $overriding as $item ) {
			$list .= '<li>' . esc_html( $item['title'] . ' (' . $item['key'] . ')' ) . '</li>';
		}
		$list .= '</ul>';

		return $base + array(
			'label'       => sprintf(
				/* translators: %d: number of items. */
				_n( '%d locked item still has a Site Editor copy', '%d locked items still have a Site Editor copy', count( $overriding ), 'block-theme-ops' ),
				count( $overriding )
			),
			'status'      => 'recommended',
			'description' => '<p>' . esc_html__( 'A lock stops new saves. It does not remove a copy saved earlier, so the site still ignores the theme file for these items. Reset them to the theme file.', 'block-theme-ops' ) . '</p>' . $list,
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( DriftPage::url() ), esc_html__( 'Open Theme Drift', 'block-theme-ops' ) ),
		);
	}

	/**
	 * Runs the test.
	 *
	 * @return array Site Health result.
	 */
	public function run(): array {
		$items   = $this->drift->items( array( 'kept' => false ) );
		$changed = array_values(
			array_filter(
				$items,
				static function ( array $item ): bool {
					return Drift::THEME_CHANGED === $item['status'];
				}
			)
		);
		$actions = sprintf( '<p><a href="%s">%s</a></p>', esc_url( DriftPage::url( array( 'status' => Drift::THEME_CHANGED ) ) ), esc_html__( 'Open Theme Drift', 'block-theme-ops' ) );

		if ( $changed ) {
			$list = '<ul>';
			foreach ( $changed as $item ) {
				$list .= '<li>' . esc_html( $item['title'] . ' (' . $item['type_label'] . '), ' . $item['file'] ) . '</li>';
			}
			$list .= '</ul>';

			return array(
				'label'       => sprintf(
					/* translators: %d: number of items. */
					_n( '%d Site Editor change hides a newer theme file', '%d Site Editor changes hide newer theme files', count( $changed ), 'block-theme-ops' ),
					count( $changed )
				),
				'status'      => 'recommended',
				'badge'       => self::badge(),
				'description' => '<p>' . esc_html__( 'The theme files below changed after they were edited in the Site Editor. The site still shows the older database copies, so the theme\'s fixes and changes do not reach visitors. Reset each one to the theme file, or keep it on purpose.', 'block-theme-ops' ) . '</p>' . $list,
				'actions'     => $actions,
				'test'        => self::TEST,
			);
		}

		return array(
			'label'       => __( 'Site Editor changes do not hide newer theme files', 'block-theme-ops' ),
			'status'      => 'good',
			'badge'       => self::badge(),
			'description' => '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of items. */
					_n( '%d template, template part or style is saved in the database, and its theme file has not changed since.', '%d templates, template parts or styles are saved in the database, and their theme files have not changed since.', count( $items ), 'block-theme-ops' ),
					count( $items )
				)
			) . '</p>',
			'actions'     => $actions,
			'test'        => self::TEST,
		);
	}

	/**
	 * Badge shown next to the result.
	 *
	 * @return array
	 */
	private static function badge(): array {
		return array(
			'label' => __( 'Appearance', 'block-theme-ops' ),
			'color' => 'blue',
		);
	}
}
