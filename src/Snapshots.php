<?php
/**
 * Snapshots of theme files, taken when a database copy is saved.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a copy of the theme file next to every database item, so a later
 * theme deploy can be told apart from the customization itself.
 *
 * A snapshot is replaced on each save, except while the theme file differs
 * from it: then the theme changed and nobody has dealt with it yet, and a
 * new edit in the Site Editor does not make the site use the newer file.
 * Keep or Reset clear that state.
 */
final class Snapshots implements Service {

	/**
	 * Theme files.
	 *
	 * @var Themes
	 */
	private Themes $themes;

	/**
	 * Sets up the snapshots.
	 *
	 * @param Themes $themes Theme files.
	 */
	public function __construct( Themes $themes ) {
		$this->themes = $themes;
	}

	/**
	 * Watches saves of every tracked post type.
	 */
	public function register(): void {
		foreach ( Drift::post_types() as $type ) {
			add_action( "save_post_{$type}", array( $this, 'on_save' ), 10, 2 );
		}
	}

	/**
	 * Records who saved and what the theme file looked like.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( $post_id, \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$terms = get_the_terms( $post, 'wp_theme' );
		if ( ! is_array( $terms ) || ! isset( $terms[0]->name ) || $terms[0]->name !== $this->themes->stylesheet() ) {
			return;
		}

		$user = get_current_user_id();
		if ( $user ) {
			update_post_meta( (int) $post_id, Drift::EDITOR_META, $user );
		}

		$this->record( $post, 'save' );
	}

	/**
	 * Stores a snapshot of the theme file behind a post.
	 *
	 * `save` replaces the snapshot unless the theme file changed since it was
	 * taken. `baseline` is for customizations made before Block Theme Ops was
	 * active: it only fills in a missing snapshot.
	 *
	 * @param \WP_Post $post   Template, template part or global styles post.
	 * @param string   $source save or baseline.
	 * @return array|null The snapshot in place afterwards.
	 */
	public function record( \WP_Post $post, string $source = 'save' ): ?array {
		$type = (string) $post->post_type;
		$file = $this->themes->locate( $type, Themes::GLOBAL_STYLES === $type ? '' : (string) $post->post_name );
		if ( null === $file ) {
			return null;
		}

		$contents = $this->themes->read( $file['path'] );
		$hash     = Themes::hash( $type, $contents );
		$existing = Drift::snapshot( (int) $post->ID );

		if ( $existing && ( 'baseline' === $source || $existing['hash'] !== $hash || 'save' === $existing['source'] ) ) {
			// Already there, or the theme changed and that is still unresolved.
			return $existing;
		}

		$snapshot = array(
			'hash'    => $hash,
			'content' => $contents,
			'file'    => $file['file'],
			'theme'   => $file['theme'],
			'time'    => time(),
			'source'  => 'baseline' === $source ? 'baseline' : 'save',
		);
		$this->store( (int) $post->ID, $snapshot );

		return $snapshot;
	}

	/**
	 * Writes a snapshot, for example the one a backup brings back.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $snapshot Snapshot.
	 */
	public function store( int $post_id, array $snapshot ): void {
		// Post meta is unslashed on the way in; theme files contain backslashes.
		update_post_meta( $post_id, Drift::SNAPSHOT_META, wp_slash( $snapshot ) );

		/**
		 * Fires after Block Theme Ops stores a snapshot of a theme file.
		 *
		 * @param int   $post_id  Template, template part or global styles post.
		 * @param array $snapshot hash, content, file, theme, time and source (save or baseline).
		 */
		do_action( 'block_theme_ops_snapshot_saved', $post_id, $snapshot );
	}
}
