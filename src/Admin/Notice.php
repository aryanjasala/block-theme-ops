<?php
/**
 * Admin notice after a theme update leaves customizations behind.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps\Admin;

use AryanX\BlockThemeOps\Alerts;
use AryanX\BlockThemeOps\Drift;
use AryanX\BlockThemeOps\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Tells theme managers which Site Editor changes now hide a newer theme file,
 * on the screens where themes are looked after, until they review or dismiss it.
 */
final class Notice implements Service {

	/**
	 * Inventory.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * Pending alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Sets up the notice.
	 *
	 * @param Drift  $drift  Inventory.
	 * @param Alerts $alerts Pending alerts.
	 */
	public function __construct( Drift $drift, Alerts $alerts ) {
		$this->drift  = $drift;
		$this->alerts = $alerts;
	}

	/**
	 * Hooks the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Items in the alert that are still theme-changed and not kept.
	 *
	 * @return array[]
	 */
	public function items(): array {
		$items = array();
		foreach ( $this->alerts->ids() as $id ) {
			$item = $this->drift->get( $id );
			if ( null !== $item && Drift::THEME_CHANGED === $item['status'] && ! $item['kept'] ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * The notice text.
	 *
	 * @param array[] $items Items.
	 * @return string
	 */
	public function message( array $items ): string {
		$names = array();
		foreach ( $items as $item ) {
			$names[] = $item['title'] . ' (' . strtolower( $item['type_label'] ) . ')';
		}

		$default = sprintf(
			/* translators: 1: number of items, 2: list of item names. */
			_n(
				'The theme files changed, but %1$d Site Editor change still overrides a newer theme file: %2$s.',
				'The theme files changed, but %1$d Site Editor changes still override newer theme files: %2$s.',
				count( $items ),
				'block-theme-ops'
			),
			count( $items ),
			implode( ', ', $names )
		);

		/**
		 * Filters the admin notice shown when theme files changed under Site Editor changes.
		 *
		 * @param string  $message Plain text. Empty hides the notice.
		 * @param array[] $items   The items.
		 */
		return (string) apply_filters( 'block_theme_ops_notice_message', $default, $items );
	}

	/**
	 * Screens that show the notice.
	 *
	 * @return string[] Screen IDs.
	 */
	public static function screens(): array {
		$default = array( 'dashboard', 'themes', 'site-editor', 'update-core' );

		/**
		 * Filters the admin screens that show the notice about Site Editor changes hiding newer theme files.
		 * Theme Drift itself never shows it, and Site Health always carries the same finding.
		 *
		 * @param string[] $screens Screen IDs. Default dashboard, themes, site-editor and update-core.
		 */
		return self::screen_ids( apply_filters( 'block_theme_ops_notice_screens', $default ), $default );
	}

	/**
	 * The screen IDs in a filtered value, or the default when it holds none.
	 *
	 * @param mixed    $value    Filtered value.
	 * @param string[] $fallback Default.
	 * @return string[]
	 */
	private static function screen_ids( $value, array $fallback ): array {
		if ( ! is_array( $value ) ) {
			return $fallback;
		}
		$ids = array();
		foreach ( $value as $id ) {
			if ( is_string( $id ) && '' !== $id ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Prints the notice.
	 */
	public function render(): void {
		if ( ! current_user_can( DriftPage::capability() ) || ! $this->alerts->ids() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'appearance_page_' . DriftPage::SLUG === $screen->id || ! in_array( $screen->id, self::screens(), true ) ) {
			return;
		}

		$items = $this->items();
		if ( ! $items ) {
			$this->alerts->clear();
			return;
		}

		$message = $this->message( $items );
		if ( '' === trim( $message ) ) {
			return;
		}
		?>
		<div class="notice notice-warning block-theme-ops-notice">
			<p><strong><?php esc_html_e( 'Block Theme Ops', 'block-theme-ops' ); ?>:</strong> <?php echo esc_html( $message ); ?></p>
			<div class="block-theme-ops-notice__actions" style="display:flex;align-items:center;gap:12px;margin:0 0 10px">
				<a class="button button-primary" href="<?php echo esc_url( DriftPage::url( array( 'status' => Drift::THEME_CHANGED ) ) ); ?>"><?php esc_html_e( 'Review in Theme Drift', 'block-theme-ops' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
					<?php wp_nonce_field( DriftPage::ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( DriftPage::ACTION ); ?>">
					<input type="hidden" name="task" value="dismiss">
					<button type="submit" class="button-link"><?php esc_html_e( 'Dismiss', 'block-theme-ops' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}
}
