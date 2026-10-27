<?php
/**
 * Site Health check.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Health;

use AryanX\Axle\BlockThemeOps\Admin\DriftPage;
use AryanX\Axle\BlockThemeOps\Drift;
use AryanX\Axle\BlockThemeOps\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Theme Drift test to Tools > Site Health: Site Editor changes that
 * hide a newer theme file are a recommended fix.
 */
final class SiteHealth implements Service {

	public const TEST = 'block_theme_ops';

	/**
	 * Inventory.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * Sets up the check.
	 *
	 * @param Drift $drift Inventory.
	 */
	public function __construct( Drift $drift ) {
		$this->drift = $drift;
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

		return $tests;
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
