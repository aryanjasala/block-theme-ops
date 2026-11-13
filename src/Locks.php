<?php
/**
 * Locks for templates that belong to Git.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

use AryanX\Axle\BlockThemeOps\Admin\DriftPage;
use const AryanX\Axle\BlockThemeOps\PLUGIN_FILE;
use const AryanX\Axle\BlockThemeOps\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses Site Editor saves of chosen templates, template parts and the
 * global styles, on the environments you pick (production by default).
 *
 * The refusal is on the REST routes the Site Editor saves through. Reset,
 * import and Undo of this plugin write through PHP, so they are not blocked.
 */
class Locks implements Service {

	public const OPTION = 'block_theme_ops_locks';

	/**
	 * Environment types WordPress knows.
	 */
	public const ENVIRONMENTS = array( 'production', 'staging', 'development', 'local' );

	/**
	 * Themes.
	 *
	 * @var Themes
	 */
	private Themes $themes;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the locks.
	 *
	 * @param Themes   $themes   Theme files.
	 * @param Settings $settings Settings, for the repository link.
	 */
	public function __construct( Themes $themes, Settings $settings ) {
		$this->themes   = $themes;
		$this->settings = $settings;
	}

	/**
	 * Hooks the REST check and the editor notice.
	 */
	public function register(): void {
		add_filter( 'rest_request_before_callbacks', array( $this, 'check_request' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor' ) );
	}

	/**
	 * Capability needed to change locks.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to lock and unlock templates on the Locks tab and in abilities.
		 *
		 * @param string $capability Default the same as Theme Drift itself, edit_theme_options.
		 */
		$capability = (string) apply_filters( 'block_theme_ops_lock_capability', DriftPage::capability() );

		return '' !== $capability ? $capability : DriftPage::capability();
	}

	/**
	 * Capability that lets someone save a locked item anyway.
	 *
	 * @return string Empty for nobody. That is the default: unlock on the Locks tab instead.
	 */
	public static function override_capability(): string {
		/**
		 * Filters the capability that may still save locked templates in the Site Editor.
		 *
		 * @param string $capability Default empty: nobody. Unlock the item on the Locks tab instead.
		 */
		return (string) apply_filters( 'block_theme_ops_lock_override_capability', '' );
	}

	/**
	 * The saved locks.
	 *
	 * @return array{all: bool, items: string[], environments: string[]}
	 */
	public function get(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$envs  = isset( $saved['environments'] ) && is_array( $saved['environments'] ) ? array_values( array_intersect( self::ENVIRONMENTS, $saved['environments'] ) ) : array( 'production' );

		return array(
			'all'          => ! empty( $saved['all'] ),
			'items'        => isset( $saved['items'] ) && is_array( $saved['items'] ) ? array_values( array_unique( array_filter( array_map( array( $this, 'clean_key' ), $saved['items'] ) ) ) ) : array(),
			'environments' => $envs,
		);
	}

	/**
	 * Saves locks.
	 *
	 * @param array $changes `all` (bool), `items` (item keys) and `environments`. Missing parts stay as they are.
	 * @return array The locks after the change.
	 */
	public function update( array $changes ): array {
		$old = $this->get();
		$new = $old;

		if ( array_key_exists( 'all', $changes ) ) {
			$new['all'] = (bool) $changes['all'];
		}
		if ( isset( $changes['items'] ) ) {
			$new['items'] = array_values( array_unique( array_filter( array_map( array( $this, 'clean_key' ), (array) $changes['items'] ) ) ) );
			sort( $new['items'] );
		}
		if ( isset( $changes['environments'] ) ) {
			$new['environments'] = array_values( array_intersect( self::ENVIRONMENTS, (array) $changes['environments'] ) );
		}

		if ( $new !== $old ) {
			update_option( self::OPTION, $new, false );

			/**
			 * Fires after the locks change.
			 *
			 * @param array $new `all`, `items` and `environments` now.
			 * @param array $old The same before.
			 */
			do_action( 'block_theme_ops_locks_changed', $new, $old );
		}

		return $new;
	}

	/**
	 * Locks some items, or unlocks them.
	 *
	 * @param string[] $keys   Item keys.
	 * @param bool     $locked True to lock, false to unlock.
	 * @return array The locks after the change.
	 */
	public function set_items( array $keys, bool $locked ): array {
		$items = $this->get()['items'];
		$keys  = array_filter( array_map( array( $this, 'clean_key' ), $keys ) );
		$items = $locked ? array_merge( $items, $keys ) : array_diff( $items, $keys );

		return $this->update( array( 'items' => $items ) );
	}

	/**
	 * Environments where the locks are enforced.
	 *
	 * @return string[]
	 */
	public function environments(): array {
		$default = $this->get()['environments'];

		/**
		 * Filters the environment types where locks are enforced, by `wp_get_environment_type()`.
		 *
		 * @param string[] $environments Default production only, or what is chosen on the Locks tab.
		 */
		$filtered = array_values( array_intersect( self::ENVIRONMENTS, (array) apply_filters( 'block_theme_ops_lock_environments', $default ) ) );

		return $filtered;
	}

	/**
	 * Whether locks are enforced on this site, by its environment type.
	 *
	 * @return bool
	 */
	public function enforced(): bool {
		$type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		return in_array( $type, $this->environments(), true );
	}

	/**
	 * Whether anything is locked.
	 *
	 * @return bool
	 */
	public function any(): bool {
		$locks = $this->get();

		return $locks['all'] || (bool) $locks['items'];
	}

	/**
	 * Whether an item is locked, whatever the environment.
	 *
	 * @param string $type Post type.
	 * @param string $slug Slug, ignored for global styles.
	 * @return bool
	 */
	public function is_locked( string $type, string $slug = '' ): bool {
		$key   = Drift::key( $type, $slug );
		$locks = $this->get();
		if ( in_array( $key, $locks['items'], true ) ) {
			return true;
		}

		// "Everything that comes from the theme" covers what has a theme file.
		return $locks['all'] && null !== $this->themes->locate( $type, Themes::GLOBAL_STYLES === $type ? '' : $slug );
	}

	/**
	 * Whether the current user may not save an item right now.
	 *
	 * @param string $type Post type.
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function blocks( string $type, string $slug = '' ): bool {
		if ( ! $this->enforced() || ! $this->is_locked( $type, $slug ) ) {
			return false;
		}

		$override = self::override_capability();

		return '' === $override || ! current_user_can( $override );
	}

	/**
	 * The message people see when a save is refused.
	 *
	 * @param string $key Item key.
	 * @return string
	 */
	public function message( string $key ): string {
		$message = __( 'This is managed in the theme files, not in the Site Editor. Change the theme file and deploy it.', 'block-theme-ops' );

		/**
		 * Filters the message shown when a save of a locked item is refused, in REST errors and the Site Editor notice.
		 *
		 * @param string $message Plain text.
		 * @param string $key     Item key: `templates/<slug>`, `parts/<slug>` or `styles`.
		 */
		return Drift::text_or( apply_filters( 'block_theme_ops_lock_message', $message, $key ), $message );
	}

	/**
	 * Where to change a locked item, for the message: the repository when one is set.
	 *
	 * @param string $key Item key.
	 * @return string Address, or empty.
	 */
	public function link( string $key ): string {
		$repo = (string) $this->settings->get( 'repo' );
		$link = '' !== $repo ? 'https://github.com/' . $repo : '';

		/**
		 * Filters the address that the lock message points to, such as the theme repository.
		 *
		 * @param string $link Default the GitHub repository from Settings, empty when there is none.
		 * @param string $key  Item key.
		 */
		$link = apply_filters( 'block_theme_ops_lock_link', $link, $key );

		return esc_url_raw( (string) $link );
	}

	/**
	 * Items of the active theme that can be locked: everything it has a file for.
	 *
	 * @return array<int, array{key: string, type: string, slug: string, title: string, file: string}>
	 */
	public function theme_items(): array {
		$items = array();
		$dirs  = $this->themes->directories();

		foreach ( array( Themes::TEMPLATE, Themes::TEMPLATE_PART ) as $type ) {
			$seen = array();
			foreach ( $dirs as $theme => $dir ) {
				$folder = $this->themes->folders( (string) $theme )[ $type ];
				$files  = glob( trailingslashit( $dir ) . $folder . '/*.html' );
				foreach ( is_array( $files ) ? $files : array() as $file ) {
					$slug = basename( $file, '.html' );
					if ( isset( $seen[ $slug ] ) || ! Themes::is_safe_slug( $slug ) ) {
						continue;
					}
					$seen[ $slug ] = true;
					$items[]       = array(
						'key'   => Drift::key( $type, $slug ),
						'type'  => $type,
						'slug'  => $slug,
						'title' => ucfirst( trim( str_replace( array( '-', '_' ), ' ', $slug ) ) ),
						'file'  => $folder . '/' . $slug . '.html',
					);
				}
			}
		}

		if ( null !== $this->themes->locate( Themes::GLOBAL_STYLES, '' ) ) {
			$items[] = array(
				'key'   => 'styles',
				'type'  => Themes::GLOBAL_STYLES,
				'slug'  => '',
				'title' => __( 'Global styles', 'block-theme-ops' ),
				'file'  => 'theme.json',
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return array( $a['type'], $a['slug'] ) <=> array( $b['type'], $b['slug'] );
			}
		);

		return $items;
	}

	/**
	 * Refuses a save of a locked item on the REST API.
	 *
	 * @param mixed $response Response so far.
	 * @param mixed $handler  Route handler.
	 * @param mixed $request  The request, a WP_REST_Request.
	 * @return mixed
	 */
	public function check_request( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request || in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) || ! $this->any() ) {
			return $response;
		}

		$target = $this->target_of( $request );
		if ( null === $target || ! $this->blocks( $target['type'], $target['slug'] ) ) {
			return $response;
		}

		$key  = Drift::key( $target['type'], $target['slug'] );
		$link = $this->link( $key );

		/**
		 * Fires when a save of a locked item is refused.
		 *
		 * @param string           $key     Item key.
		 * @param \WP_REST_Request $request The request.
		 */
		do_action( 'block_theme_ops_save_blocked', $key, $request );

		return new \WP_Error(
			'block_theme_ops_locked',
			$this->message( $key ),
			array(
				'status' => 403,
				'key'    => $key,
				'link'   => $link,
			)
		);
	}

	/**
	 * Loads the editor notice on the Site Editor.
	 */
	public function enqueue_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'site-editor' !== $screen->id || ! $this->any() || ! $this->enforced() ) {
			return;
		}
		$override = self::override_capability();
		if ( '' !== $override && current_user_can( $override ) ) {
			return;
		}

		$messages = array();
		foreach ( $this->theme_items() as $item ) {
			if ( $this->is_locked( $item['type'], $item['slug'] ) ) {
				$messages[ $item['key'] ] = $this->message( $item['key'] );
			}
		}
		foreach ( $this->get()['items'] as $key ) {
			$messages[ $key ] = $messages[ $key ] ?? $this->message( $key );
		}
		if ( ! $messages ) {
			return;
		}

		wp_enqueue_script( 'block-theme-ops-editor-lock', plugins_url( 'assets/editor-lock.js', PLUGIN_FILE ), array( 'wp-data', 'wp-notices', 'wp-hooks', 'wp-i18n' ), VERSION, true );
		wp_add_inline_script(
			'block-theme-ops-editor-lock',
			'window.blockThemeOpsLocks = ' . wp_json_encode(
				array(
					'messages' => $messages,
					'link'     => $this->link( 'styles' ),
					'linkText' => __( 'Where to change it', 'block-theme-ops' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The item a REST request would save, or null when it is not a Site Editor save.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{type: string, slug: string}|null
	 */
	private function target_of( \WP_REST_Request $request ): ?array {
		$route = rtrim( (string) $request->get_route(), '/' );

		if ( preg_match( '#^/wp/v2/global-styles/(\d+)#', $route, $found ) ) {
			$post = get_post( (int) $found[1] );
			if ( ! $post instanceof \WP_Post || Themes::GLOBAL_STYLES !== $post->post_type ) {
				return null;
			}
			$terms = get_the_terms( $post, 'wp_theme' );

			return is_array( $terms ) && isset( $terms[0]->name ) && $terms[0]->name === $this->themes->stylesheet()
				? array(
					'type' => Themes::GLOBAL_STYLES,
					'slug' => '',
				)
				: null;
		}

		if ( ! preg_match( '#^/wp/v2/(templates|template-parts)(?:/(.+))?$#', $route, $found ) ) {
			return null;
		}

		$type = 'templates' === $found[1] ? Themes::TEMPLATE : Themes::TEMPLATE_PART;
		$id   = isset( $found[2] ) ? rawurldecode( (string) preg_split( '#/(?:autosaves|revisions)(?:/|$)#', $found[2] )[0] ) : '';
		if ( 'lookup' === $id ) {
			return null;
		}

		if ( '' === $id ) {
			// A new item: the slug and theme come in the body.
			$slug  = (string) $request->get_param( 'slug' );
			$theme = (string) $request->get_param( 'theme' );
		} elseif ( false !== strpos( $id, '//' ) ) {
			list( $theme, $slug ) = explode( '//', $id, 2 );
		} else {
			$theme = '';
			$slug  = $id;
		}

		if ( '' !== $theme && $theme !== $this->themes->stylesheet() ) {
			return null;
		}

		return '' !== $slug ? array(
			'type' => $type,
			'slug' => sanitize_title( $slug ),
		) : null;
	}

	/**
	 * A clean item key, or an empty string.
	 *
	 * @param mixed $key Key.
	 * @return string
	 */
	private function clean_key( $key ): string {
		$key = is_string( $key ) ? trim( $key ) : '';
		if ( 'styles' === $key ) {
			return $key;
		}

		return preg_match( '#^(templates|parts)/([A-Za-z0-9_%-][A-Za-z0-9_.%-]*)$#', $key, $found ) && Themes::is_safe_slug( $found[2] ) ? $key : '';
	}
}
