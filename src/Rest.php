<?php
/**
 * REST route behind the Site Editor drift notice.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

use AryanX\Axle\BlockThemeOps\Admin\DriftPage;
use const AryanX\Axle\BlockThemeOps\PLUGIN_FILE;
use const AryanX\Axle\BlockThemeOps\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the Site Editor whether the template or part someone just opened has
 * a theme file that changed since it was saved, and what changed.
 */
class Rest implements Service {

	public const NAMESPACE = 'block-theme-ops/v1';

	/**
	 * Inventory.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * Merges.
	 *
	 * @var Merger
	 */
	private Merger $merger;

	/**
	 * Sets up the route.
	 *
	 * @param Drift  $drift  Inventory.
	 * @param Merger $merger Merges.
	 */
	public function __construct( Drift $drift, Merger $merger ) {
		$this->drift  = $drift;
		$this->merger = $merger;
	}

	/**
	 * Hooks the route and the editor script.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor' ) );
	}

	/**
	 * Registers the route.
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/drift',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_drift' ),
				'permission_callback' => static function (): bool {
					return current_user_can( DriftPage::capability() );
				},
				'args'                => array(
					'type' => array(
						'type'     => 'string',
						'enum'     => array( Themes::TEMPLATE, Themes::TEMPLATE_PART ),
						'required' => true,
					),
					'slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);
	}

	/**
	 * Drift of one template or part.
	 *
	 * Says nothing about items the person may not edit: they get the same
	 * answer as for an item with no drift.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_drift( \WP_REST_Request $request ): \WP_REST_Response {
		$key  = Drift::key( (string) $request->get_param( 'type' ), (string) $request->get_param( 'slug' ) );
		$item = $this->drift->get( $key );
		if ( null === $item || Drift::THEME_CHANGED !== $item['status'] || $item['kept'] || ! current_user_can( 'edit_post', (int) $item['id'] ) ) {
			return new \WP_REST_Response( array( 'drifted' => false ) );
		}

		$views  = $this->drift->views( $item );
		$reason = $this->merger->blocker( $item );
		$counts = array(
			'auto'      => 0,
			'conflicts' => 0,
		);
		if ( '' === $reason ) {
			$plan = $this->merger->plan( $item );
			if ( ! is_wp_error( $plan ) ) {
				$counts['auto']      = count( $plan['applied'] );
				$counts['conflicts'] = count( $plan['conflicts'] );
			}
		}

		$diff = null !== $views['snapshot'] && null !== $views['file'] ? Diff::unified( $views['snapshot'], $views['file'], 'theme file when saved', 'theme file now' ) : '';

		return new \WP_REST_Response(
			array(
				'drifted'   => true,
				'key'       => $key,
				'title'     => $item['title'],
				'changed'   => (int) $item['file_modified'],
				'diff'      => $diff,
				'canMerge'  => '' === $reason,
				'reason'    => $reason,
				'auto'      => $counts['auto'],
				'conflicts' => $counts['conflicts'],
				'mergeUrl'  => DriftPage::url(
					array(
						'item'  => $item['id'],
						'merge' => 1,
					)
				),
				'itemUrl'   => DriftPage::url( array( 'item' => $item['id'] ) ),
			)
		);
	}

	/**
	 * Loads the notice script on the Site Editor.
	 */
	public function enqueue_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'site-editor' !== $screen->id || ! current_user_can( DriftPage::capability() ) ) {
			return;
		}

		wp_enqueue_script( 'block-theme-ops-editor-drift', plugins_url( 'assets/editor-drift.js', PLUGIN_FILE ), array( 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n', 'wp-notices' ), VERSION, true );
		wp_set_script_translations( 'block-theme-ops-editor-drift', 'block-theme-ops' );
		wp_enqueue_style( 'block-theme-ops-editor-drift', plugins_url( 'assets/editor-drift.css', PLUGIN_FILE ), array( 'wp-components' ), VERSION );
		wp_add_inline_script(
			'block-theme-ops-editor-drift',
			'window.blockThemeOpsDrift = ' . wp_json_encode(
				array(
					'path' => '/' . self::NAMESPACE . '/drift',
				)
			) . ';',
			'before'
		);
	}
}
