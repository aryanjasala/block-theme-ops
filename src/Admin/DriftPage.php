<?php
/**
 * Appearance > Theme Drift.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Admin;

use AryanX\Axle\BlockThemeOps\Alerts;
use AryanX\Axle\BlockThemeOps\Backups;
use AryanX\Axle\BlockThemeOps\Drift;
use AryanX\Axle\BlockThemeOps\Exporter;
use AryanX\Axle\BlockThemeOps\Operations;
use AryanX\Axle\BlockThemeOps\Service;
use AryanX\Axle\BlockThemeOps\Settings;
use AryanX\Axle\BlockThemeOps\Themes;
use const AryanX\Axle\BlockThemeOps\PLUGIN_FILE;
use const AryanX\Axle\BlockThemeOps\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Counts, the list of items, the three-way view of one item, backups and settings.
 */
final class DriftPage implements Service {

	public const SLUG = 'block-theme-ops';

	public const ACTION = 'block_theme_ops';

	/**
	 * Inventory.
	 *
	 * @var Drift
	 */
	private Drift $drift;

	/**
	 * Reset, undo and keep.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Zip and pull request export.
	 *
	 * @var Exporter
	 */
	private Exporter $exporter;

	/**
	 * Backups.
	 *
	 * @var Backups
	 */
	private Backups $backups;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Pending alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Sets up the screen.
	 *
	 * @param Drift      $drift      Inventory.
	 * @param Operations $operations Reset, undo and keep.
	 * @param Exporter   $exporter   Export.
	 * @param Backups    $backups    Backups.
	 * @param Settings   $settings   Settings.
	 * @param Alerts     $alerts     Pending alerts.
	 */
	public function __construct( Drift $drift, Operations $operations, Exporter $exporter, Backups $backups, Settings $settings, Alerts $alerts ) {
		$this->drift      = $drift;
		$this->operations = $operations;
		$this->exporter   = $exporter;
		$this->backups    = $backups;
		$this->settings   = $settings;
		$this->alerts     = $alerts;
	}

	/**
	 * Adds the menu entry, form handler and plugin link.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'add_link' ) );
	}

	/**
	 * Capability needed to see and change Theme Drift.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed for Theme Drift, its abilities and WP-CLI-free actions.
		 *
		 * @param string $capability Default edit_theme_options, the capability the Site Editor needs.
		 */
		$capability = (string) apply_filters( 'block_theme_ops_capability', 'edit_theme_options' );

		return '' !== $capability ? $capability : 'edit_theme_options';
	}

	/**
	 * Address of the screen.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'themes.php' ) );
	}

	/**
	 * Registers Appearance > Theme Drift, with the number of unreviewed alerts.
	 */
	public function add_page(): void {
		$count = count( $this->alerts->ids() );
		$menu  = __( 'Theme Drift', 'block-theme-ops' );
		if ( $count ) {
			$menu .= sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $count );
		}

		$hook = add_theme_page( __( 'Theme Drift', 'block-theme-ops' ), $menu, self::capability(), self::SLUG, array( $this, 'render' ) );
		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue' ) );
		}
	}

	/**
	 * Adds a Theme Drift link on the Plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public function add_link( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Theme Drift', 'block-theme-ops' ) ) );

		return $links;
	}

	/**
	 * Styles and the confirmation script.
	 */
	public function enqueue(): void {
		wp_enqueue_style( 'block-theme-ops-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'block-theme-ops-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks' ), VERSION, true );
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to see Theme Drift.', 'block-theme-ops' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$item_id = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap bto-page">';
		if ( $item_id ) {
			$this->render_item( $item_id );
		} else {
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Theme Drift', 'block-theme-ops' ) . '</h1>';
			echo '<hr class="wp-header-end">';
			$this->render_notice();
			$this->render_tabs( $tab );
			if ( 'backups' === $tab ) {
				$this->render_backups();
			} elseif ( 'settings' === $tab ) {
				$this->render_settings();
			} else {
				$this->render_list();
			}
		}
		echo '</div>';
	}

	/**
	 * Prints the tab bar.
	 *
	 * @param string $current Current tab.
	 */
	private function render_tabs( string $current ): void {
		$tabs = array(
			''         => __( 'Drift', 'block-theme-ops' ),
			'backups'  => __( 'Backups', 'block-theme-ops' ),
			'settings' => __( 'Settings', 'block-theme-ops' ),
		);

		echo '<nav class="nav-tab-wrapper bto-tabs" aria-label="' . esc_attr__( 'Theme Drift sections', 'block-theme-ops' ) . '">';
		foreach ( $tabs as $tab => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( self::url( '' !== $tab ? array( 'tab' => $tab ) : array() ) ),
				$tab === $current ? ' nav-tab-active' : '',
				$tab === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Prints the counts and the list of items.
	 */
	private function render_list(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( ! in_array( $filter, array_merge( Drift::STATUSES, array( 'kept' ) ), true ) ) {
			$filter = '';
		}

		$all    = $this->drift->items();
		$counts = $this->drift->counts( $all );
		$items  = array_values(
			array_filter(
				$all,
				static function ( array $item ) use ( $filter ): bool {
					if ( 'kept' === $filter ) {
						return $item['kept'];
					}

					return ! $item['kept'] && ( '' === $filter || $item['status'] === $filter );
				}
			)
		);

		$themes = $this->drift->themes();
		$theme  = wp_get_theme();
		?>
		<p class="bto-intro">
			<?php
			if ( $themes->template() !== $themes->stylesheet() ) {
				/* translators: 1: theme name, 2: parent theme name. */
				echo esc_html( sprintf( __( 'Active theme: %1$s, a child of %2$s.', 'block-theme-ops' ), $theme->get( 'Name' ), $theme->parent() ? $theme->parent()->get( 'Name' ) : $themes->template() ) );
			} else {
				/* translators: %s: theme name. */
				echo esc_html( sprintf( __( 'Active theme: %s.', 'block-theme-ops' ), $theme->get( 'Name' ) ) );
			}
			echo ' ';
			esc_html_e( 'These are the templates, template parts and styles saved in the Site Editor. Each one overrides the theme file until it is reset.', 'block-theme-ops' );
			?>
		</p>

		<div class="bto-cards">
			<?php foreach ( Drift::STATUSES as $status ) : ?>
				<a class="bto-card bto-card--<?php echo esc_attr( $status ); ?><?php echo $filter === $status ? ' is-current' : ''; ?>" href="<?php echo esc_url( self::url( array( 'status' => $status ) ) ); ?>">
					<span class="bto-card__count"><?php echo esc_html( number_format_i18n( $counts[ $status ] ) ); ?></span>
					<span class="bto-card__label"><?php echo esc_html( Drift::status_label( $status ) ); ?></span>
					<span class="bto-card__help"><?php echo esc_html( self::status_help( $status ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( self::url() ); ?>"<?php echo '' === $filter ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'All', 'block-theme-ops' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts['all'] ) ); ?>)</span></a> |</li>
			<?php foreach ( Drift::STATUSES as $status ) : ?>
				<li><a href="<?php echo esc_url( self::url( array( 'status' => $status ) ) ); ?>"<?php echo $filter === $status ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( Drift::status_label( $status ) ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $status ] ) ); ?>)</span></a> |</li>
			<?php endforeach; ?>
			<li><a href="<?php echo esc_url( self::url( array( 'status' => 'kept' ) ) ); ?>"<?php echo 'kept' === $filter ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Kept', 'block-theme-ops' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts['kept'] ) ); ?>)</span></a></li>
		</ul>

		<?php if ( $counts[ Drift::IDENTICAL ] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form bto-identical" data-bto-confirm-reset_identical="<?php esc_attr_e( 'Reset every item that matches its theme file? Each one is backed up first and can be undone.', 'block-theme-ops' ); ?>">
				<?php $this->hidden( 'reset_identical' ); ?>
				<button type="submit" class="button">
					<?php
					/* translators: %d: number of items. */
					echo esc_html( sprintf( _n( 'Reset %d identical item', 'Reset all %d identical items', $counts[ Drift::IDENTICAL ], 'block-theme-ops' ), $counts[ Drift::IDENTICAL ] ) );
					?>
				</button>
			</form>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form bto-list-form" <?php $this->confirm_attributes(); ?>>
			<?php $this->hidden( 'bulk', 0, array( 'status' => $filter ) ); ?>
			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<label for="bto-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'block-theme-ops' ); ?></label>
					<select name="bulk_action" id="bto-bulk-action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'block-theme-ops' ); ?></option>
						<option value="reset"><?php esc_html_e( 'Reset to theme file', 'block-theme-ops' ); ?></option>
						<?php if ( 'kept' === $filter ) : ?>
							<option value="unkeep"><?php esc_html_e( 'List again', 'block-theme-ops' ); ?></option>
						<?php else : ?>
							<option value="keep"><?php esc_html_e( 'Keep', 'block-theme-ops' ); ?></option>
						<?php endif; ?>
						<option value="export"><?php esc_html_e( 'Download zip', 'block-theme-ops' ); ?></option>
						<?php if ( $this->exporter->provider()->is_configured() ) : ?>
							<option value="pr"><?php esc_html_e( 'Open pull request', 'block-theme-ops' ); ?></option>
						<?php else : ?>
							<option value="pr" disabled><?php esc_html_e( 'Open pull request (set up GitHub under Settings first)', 'block-theme-ops' ); ?></option>
						<?php endif; ?>
					</select>
					<button type="submit" class="button action"><?php esc_html_e( 'Apply', 'block-theme-ops' ); ?></button>
				</div>
				<br class="clear">
			</div>

			<table class="wp-list-table widefat fixed striped bto-table">
				<thead>
					<tr>
						<td id="cb" class="manage-column column-cb check-column"><label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select all', 'block-theme-ops' ); ?></label><input id="cb-select-all-1" type="checkbox"></td>
						<th scope="col" class="column-primary"><?php esc_html_e( 'Item', 'block-theme-ops' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'block-theme-ops' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Theme file', 'block-theme-ops' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Saved in the Site Editor', 'block-theme-ops' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Theme file changed', 'block-theme-ops' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $items ) : ?>
						<tr class="no-items"><td class="colspanchange" colspan="6"><?php echo esc_html( $all ? __( 'Nothing here.', 'block-theme-ops' ) : __( 'Nothing in the database overrides the theme. Everything comes from the theme files.', 'block-theme-ops' ) ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $items as $item ) : ?>
						<tr class="bto-row bto-row--<?php echo esc_attr( $item['status'] ); ?>" data-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
							<th scope="row" class="check-column">
								<label class="screen-reader-text" for="bto-cb-<?php echo esc_attr( (string) $item['id'] ); ?>">
									<?php
									/* translators: %s: item name. */
									echo esc_html( sprintf( __( 'Select %s', 'block-theme-ops' ), $item['title'] ) );
									?>
								</label>
								<input type="checkbox" name="ids[]" id="bto-cb-<?php echo esc_attr( (string) $item['id'] ); ?>" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
							</th>
							<td class="column-primary">
								<strong><a class="row-title" href="<?php echo esc_url( self::url( array( 'item' => $item['id'] ) ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a></strong>
								<span class="bto-type"><?php echo esc_html( $item['type_label'] ); ?> &middot; <code dir="ltr"><?php echo esc_html( $item['key'] ); ?></code></span>
								<div class="row-actions">
									<span><a href="<?php echo esc_url( self::url( array( 'item' => $item['id'] ) ) ); ?>"><?php esc_html_e( 'View changes', 'block-theme-ops' ); ?></a> | </span>
									<?php if ( '' !== $item['file'] ) : ?>
										<span class="delete"><button type="submit" name="row" value="reset:<?php echo esc_attr( (string) $item['id'] ); ?>" class="button-link button-link-delete"><?php esc_html_e( 'Reset', 'block-theme-ops' ); ?></button> | </span>
									<?php endif; ?>
									<?php if ( $item['kept'] ) : ?>
										<span><button type="submit" name="row" value="unkeep:<?php echo esc_attr( (string) $item['id'] ); ?>" class="button-link"><?php esc_html_e( 'List again', 'block-theme-ops' ); ?></button></span>
									<?php else : ?>
										<span><button type="submit" name="row" value="keep:<?php echo esc_attr( (string) $item['id'] ); ?>" class="button-link"><?php esc_html_e( 'Keep', 'block-theme-ops' ); ?></button></span>
									<?php endif; ?>
								</div>
							</td>
							<td><?php $this->badge( $item ); ?></td>
							<td><?php $this->file_cell( $item ); ?></td>
							<td>
								<?php echo esc_html( self::date( (int) $item['modified'] ) ); ?><br>
								<span class="bto-muted">
									<?php
									/* translators: %s: user name. */
									echo esc_html( sprintf( __( 'by %s', 'block-theme-ops' ), $item['author_name'] ) );
									?>
								</span>
							</td>
							<td><?php echo esc_html( $item['file_modified'] ? self::date( (int) $item['file_modified'] ) : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<?php
	}

	/**
	 * Prints one item: facts, actions and the three-way view.
	 *
	 * @param int $id Post ID.
	 */
	private function render_item( int $id ): void {
		$item = $this->drift->get( $id );

		echo '<p class="bto-back"><a href="' . esc_url( self::url() ) . '">' . esc_html( self::back_arrow() ) . ' ' . esc_html__( 'Theme Drift', 'block-theme-ops' ) . '</a></p>';
		if ( null === $item ) {
			echo '<h1>' . esc_html__( 'Theme Drift', 'block-theme-ops' ) . '</h1>';
			$this->render_notice();
			echo '<p>' . esc_html__( 'This item is not a Site Editor change of the active theme, or it no longer exists.', 'block-theme-ops' ) . '</p>';
			return;
		}

		// wp_text_diff() collapses tabs and spaces, so indent with no-break spaces.
		$views    = $this->drift->views( $item, str_repeat( "\u{00A0}", 4 ) );
		$snapshot = Drift::snapshot( (int) $item['id'] );
		?>
		<h1 class="bto-item-title">
			<?php echo esc_html( $item['title'] ); ?>
			<span class="bto-type"><?php echo esc_html( $item['type_label'] ); ?></span>
			<?php $this->badge( $item ); ?>
		</h1>
		<hr class="wp-header-end">
		<?php $this->render_notice(); ?>

		<p class="bto-explain"><?php echo esc_html( self::status_help( $item['status'], $item['probable'] ) ); ?></p>
		<?php if ( $item['kept'] ) : ?>
			<p class="bto-explain"><?php esc_html_e( 'You chose to keep this database copy. It stays out of the list and the alerts until the theme file changes again.', 'block-theme-ops' ); ?></p>
		<?php endif; ?>

		<table class="bto-facts">
			<tr>
				<th scope="row"><?php esc_html_e( 'Theme file', 'block-theme-ops' ); ?></th>
				<td><?php $this->file_cell( $item ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Saved in the Site Editor', 'block-theme-ops' ); ?></th>
				<td>
					<?php
					/* translators: 1: date, 2: user name. */
					echo esc_html( sprintf( __( '%1$s by %2$s', 'block-theme-ops' ), self::date( (int) $item['modified'] ), $item['author_name'] ) );
					?>
				</td>
			</tr>
			<?php if ( $item['file_modified'] ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Theme file changed', 'block-theme-ops' ); ?></th>
					<td><?php echo esc_html( self::date( (int) $item['file_modified'] ) ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Snapshot of the theme file', 'block-theme-ops' ); ?></th>
				<td>
					<?php
					if ( ! $snapshot ) {
						esc_html_e( 'None. This was saved before Block Theme Ops was active.', 'block-theme-ops' );
					} elseif ( 'baseline' === $snapshot['source'] ) {
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'Taken %s, when Block Theme Ops first saw this item. Older theme changes are not in it.', 'block-theme-ops' ), self::date( $snapshot['time'] ) ) );
					} else {
						/* translators: %s: date. */
						echo esc_html( sprintf( __( 'Taken %s, when the database copy was saved.', 'block-theme-ops' ), self::date( $snapshot['time'] ) ) );
					}
					?>
				</td>
			</tr>
		</table>

		<div class="bto-actions">
			<?php if ( '' !== $item['file'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form" <?php $this->confirm_attributes(); ?>>
					<?php $this->hidden( 'reset', (int) $item['id'] ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Reset to theme file', 'block-theme-ops' ); ?></button>
				</form>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form">
				<?php $this->hidden( $item['kept'] ? 'unkeep' : 'keep', (int) $item['id'] ); ?>
				<button type="submit" class="button"><?php echo esc_html( $item['kept'] ? __( 'List again', 'block-theme-ops' ) : __( 'Keep the database copy', 'block-theme-ops' ) ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form">
				<?php $this->hidden( 'export', (int) $item['id'] ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Download zip', 'block-theme-ops' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form" <?php $this->confirm_attributes(); ?>>
				<?php $this->hidden( 'pr', (int) $item['id'] ); ?>
				<?php if ( $this->exporter->provider()->is_configured() ) : ?>
					<button type="submit" class="button"><?php esc_html_e( 'Open pull request', 'block-theme-ops' ); ?></button>
				<?php else : ?>
					<button type="submit" class="button" disabled aria-describedby="bto-pr-hint"><?php esc_html_e( 'Open pull request', 'block-theme-ops' ); ?></button>
				<?php endif; ?>
			</form>
		</div>
		<?php if ( ! $this->exporter->provider()->is_configured() ) : ?>
			<p class="description bto-pr-hint" id="bto-pr-hint"><?php $this->settings_hint(); ?></p>
		<?php endif; ?>

		<?php
		if ( null !== $views['file'] ) {
			$this->diff(
				'bto-diff-reset',
				__( 'Database copy compared with the theme file now', 'block-theme-ops' ),
				__( 'What the site shows instead of the theme file. Reset throws the database copy away.', 'block-theme-ops' ),
				$views['file'],
				$views['database'],
				__( 'Theme file now', 'block-theme-ops' ),
				__( 'Database copy', 'block-theme-ops' )
			);
		} else {
			echo '<h2>' . esc_html__( 'Database copy', 'block-theme-ops' ) . '</h2>';
			echo '<pre class="bto-code" dir="ltr">' . esc_html( $views['database'] ) . '</pre>';
		}

		if ( null !== $views['snapshot'] && null !== $views['file'] ) {
			$this->diff(
				'bto-diff-theme',
				__( 'Theme file then and now', 'block-theme-ops' ),
				__( 'What the theme changed since the snapshot. The site ignores these changes while the database copy exists.', 'block-theme-ops' ),
				$views['snapshot'],
				$views['file'],
				__( 'Theme file at the snapshot', 'block-theme-ops' ),
				__( 'Theme file now', 'block-theme-ops' )
			);
			$this->diff(
				'bto-diff-custom',
				__( 'The customization itself', 'block-theme-ops' ),
				__( 'What was changed in the Site Editor, compared with the theme file it started from.', 'block-theme-ops' ),
				$views['snapshot'],
				$views['database'],
				__( 'Theme file at the snapshot', 'block-theme-ops' ),
				__( 'Database copy', 'block-theme-ops' )
			);
		} elseif ( null !== $views['file'] ) {
			echo '<p class="bto-muted">' . esc_html__( 'There is no snapshot of the theme file from the time of the edit, so the theme\'s own changes cannot be shown apart from the customization.', 'block-theme-ops' ) . '</p>';
		}
	}

	/**
	 * Prints a diff section.
	 *
	 * @param string $id          Element ID.
	 * @param string $title       Heading.
	 * @param string $help        Explanation.
	 * @param string $left        Left text.
	 * @param string $right       Right text.
	 * @param string $left_title  Left column title.
	 * @param string $right_title Right column title.
	 */
	private function diff( string $id, string $title, string $help, string $left, string $right, string $left_title, string $right_title ): void {
		$diff = wp_text_diff(
			$left,
			$right,
			array(
				'title_left'      => $left_title,
				'title_right'     => $right_title,
				'show_split_view' => true,
			)
		);
		?>
		<section class="bto-diff" id="<?php echo esc_attr( $id ); ?>">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p class="bto-muted"><?php echo esc_html( $help ); ?></p>
			<?php if ( '' === $diff ) : ?>
				<p class="bto-same"><?php esc_html_e( 'No differences.', 'block-theme-ops' ); ?></p>
			<?php else : ?>
				<?php // Block markup and JSON read left to right, in every language. ?>
				<div class="bto-diff__table" dir="ltr"><?php echo wp_kses_post( $diff ); ?></div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Prints the backups that can still be undone.
	 */
	private function render_backups(): void {
		$backups = $this->backups->all();
		?>
		<p class="bto-intro">
			<?php
			/* translators: %d: number of days. */
			echo esc_html( sprintf( _n( 'Every reset keeps a backup of the database copy for %d day. Undo puts it back as it was.', 'Every reset keeps a backup of the database copy for %d days. Undo puts it back as it was.', Backups::days(), 'block-theme-ops' ), Backups::days() ) );
			?>
		</p>
		<table class="wp-list-table widefat fixed striped bto-backups">
			<thead>
				<tr>
					<th scope="col" class="column-primary"><?php esc_html_e( 'Item', 'block-theme-ops' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Theme', 'block-theme-ops' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reset', 'block-theme-ops' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Can be undone until', 'block-theme-ops' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'block-theme-ops' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $backups ) : ?>
					<tr class="no-items"><td colspan="5"><?php esc_html_e( 'No resets to undo.', 'block-theme-ops' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $backups as $backup ) : ?>
					<tr class="bto-backup" data-id="<?php echo esc_attr( (string) $backup['id'] ); ?>">
						<td class="column-primary">
							<strong><?php echo esc_html( (string) ( $backup['item']['title'] ?? $backup['title'] ) ); ?></strong>
							<span class="bto-type"><?php echo esc_html( Drift::type_label( (string) $backup['post_type'] ) ); ?> &middot; <code dir="ltr"><?php echo esc_html( (string) ( $backup['item']['key'] ?? $backup['slug'] ) ); ?></code></span>
						</td>
						<td><?php echo esc_html( (string) $backup['theme'] ); ?></td>
						<td>
							<?php
							/* translators: 1: date, 2: user name. */
							echo esc_html( sprintf( __( '%1$s by %2$s', 'block-theme-ops' ), self::date( (int) $backup['reset_at'] ), Drift::user_name( (int) $backup['reset_by'] ) ) );
							?>
						</td>
						<td><?php echo esc_html( self::date( (int) $backup['undo_until'] ) ); ?></td>
						<td>
							<?php $blocker = $this->operations->undo_blocker( $backup ); ?>
							<?php if ( '' === $blocker ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form">
									<?php $this->hidden( 'undo', (int) $backup['id'], array( 'tab' => 'backups' ) ); ?>
									<button type="submit" class="button"><?php esc_html_e( 'Undo reset', 'block-theme-ops' ); ?></button>
								</form>
							<?php else : ?>
								<p class="bto-blocked" data-reason="<?php echo esc_attr( $blocker ); ?>">
									<strong><?php echo esc_html( Operations::RESTORED === $blocker ? __( 'Back already', 'block-theme-ops' ) : __( 'Customized again since', 'block-theme-ops' ) ); ?></strong><br>
									<?php echo esc_html( Operations::blocked_message( $blocker, $backup ) ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Prints the settings form.
	 */
	private function render_settings(): void {
		$settings = $this->settings->all();
		$source   = $this->settings->token_source();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form bto-settings">
			<?php $this->hidden( 'save_settings', 0, array( 'tab' => 'settings' ) ); ?>
			<h2><?php esc_html_e( 'Alerts', 'block-theme-ops' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Email', 'block-theme-ops' ); ?></th>
					<td>
						<input type="checkbox" id="bto-notify" name="notify" value="1" <?php checked( $settings['notify'] ); ?>>
						<label for="bto-notify">
						<?php
						/* translators: %s: email address. */
						echo esc_html( sprintf( __( 'Email %s when a theme update or deploy leaves Site Editor changes behind', 'block-theme-ops' ), (string) get_option( 'admin_email' ) ) );
						?>
						</label>
						<p class="description"><?php esc_html_e( 'Theme files are checked right after WordPress updates the theme, and once a day for deploys through Git, SFTP or rsync. The admin notice shows either way.', 'block-theme-ops' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'GitHub pull requests', 'block-theme-ops' ); ?></h2>
			<p><?php esc_html_e( 'Send Site Editor changes to the theme\'s repository as a pull request, so they end up in the theme files. Only the files you export are sent, and only when you ask.', 'block-theme-ops' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bto-repo"><?php esc_html_e( 'Repository', 'block-theme-ops' ); ?></label></th>
					<td>
						<input type="text" id="bto-repo" name="repo" class="regular-text code" value="<?php echo esc_attr( $settings['repo'] ); ?>" placeholder="owner/repo">
						<p class="description"><?php esc_html_e( 'As owner/repo, or the github.com address.', 'block-theme-ops' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bto-branch"><?php esc_html_e( 'Base branch', 'block-theme-ops' ); ?></label></th>
					<td><input type="text" id="bto-branch" name="branch" class="regular-text code" value="<?php echo esc_attr( $settings['branch'] ); ?>" placeholder="main"></td>
				</tr>
				<tr>
					<th scope="row"><label for="bto-path"><?php esc_html_e( 'Theme folder in the repository', 'block-theme-ops' ); ?></label></th>
					<td>
						<input type="text" id="bto-path" name="path" class="regular-text code" value="<?php echo esc_attr( $settings['path'] ); ?>" placeholder="wp-content/themes/<?php echo esc_attr( $this->drift->themes()->stylesheet() ); ?>">
						<p class="description"><?php esc_html_e( 'Leave empty when the repository is the theme itself.', 'block-theme-ops' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bto-token"><?php esc_html_e( 'Access token', 'block-theme-ops' ); ?></label></th>
					<td>
						<?php if ( 'constant' === $source || 'environment' === $source ) : ?>
							<p class="bto-token-status">
								<?php
								echo esc_html(
									'constant' === $source
										? __( 'Set in wp-config.php with BLOCK_THEME_OPS_GITHUB_TOKEN.', 'block-theme-ops' )
										: __( 'Set in the server environment as BLOCK_THEME_OPS_GITHUB_TOKEN.', 'block-theme-ops' )
								);
								?>
							</p>
						<?php else : ?>
							<input type="password" id="bto-token" name="token" class="regular-text code" value="" autocomplete="new-password" placeholder="<?php echo 'option' === $source ? esc_attr__( 'Saved. Type a new one to replace it.', 'block-theme-ops' ) : 'github_pat_...'; ?>">
							<?php if ( 'option' === $source ) : ?>
								<p class="bto-token-status"><?php esc_html_e( 'A token is saved. It is never shown again.', 'block-theme-ops' ); ?></p>
								<label><input type="checkbox" name="remove_token" value="1"> <?php esc_html_e( 'Remove the saved token', 'block-theme-ops' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'A fine-grained token for this one repository, with read and write access to Contents and Pull requests. Better: define BLOCK_THEME_OPS_GITHUB_TOKEN in wp-config.php, so the token is not stored in the database.', 'block-theme-ops' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'block-theme-ops' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Handles every form on the screen.
	 */
	public function handle(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'block-theme-ops' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$ids  = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$back = array();
		foreach ( array( 'status', 'tab' ) as $key ) {
			if ( ! empty( $_POST[ $key ] ) ) {
				$back[ $key ] = sanitize_key( wp_unslash( $_POST[ $key ] ) );
			}
		}

		if ( 'bulk' === $task ) {
			$row = isset( $_POST['row'] ) ? sanitize_text_field( wp_unslash( $_POST['row'] ) ) : '';
			if ( '' !== $row && false !== strpos( $row, ':' ) ) {
				list( $task, $row_id ) = explode( ':', $row, 2 );
				$task                  = sanitize_key( $task );
				$ids                   = array( absint( $row_id ) );
			} else {
				$task = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
			}
		} elseif ( $id ) {
			$ids          = array( $id );
			$back['item'] = $id;
		}

		switch ( $task ) {
			case 'reset':
			case 'keep':
			case 'unkeep':
			case 'export':
			case 'pr':
				$this->handle_items( $task, $ids, $back );
				break;
			case 'reset_identical':
				$this->handle_reset_identical( $back );
				break;
			case 'undo':
				$this->handle_undo( $id, $back );
				break;
			case 'save_settings':
				$this->handle_settings( $back );
				break;
			case 'dismiss':
				$this->alerts->clear();
				wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url() );
				exit;
			default:
				$this->done( 'error', __( 'Pick an action first.', 'block-theme-ops' ), $back );
		}
	}

	/**
	 * Runs an action on one or more items.
	 *
	 * @param string $task reset, keep, unkeep, export or pr.
	 * @param int[]  $ids  Post IDs.
	 * @param array  $back Where to go afterwards.
	 */
	private function handle_items( string $task, array $ids, array $back ): void {
		$items = array();
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$item = $this->drift->get( $id );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}
		if ( ! $items ) {
			$this->done( 'error', __( 'Select at least one item.', 'block-theme-ops' ), $back );
			return;
		}

		if ( 'export' === $task ) {
			$this->download( $items, $back );
			return;
		}

		if ( 'pr' === $task ) {
			$result = $this->exporter->pull_request( $items, 'pr' );
			if ( is_wp_error( $result ) ) {
				$this->done(
					'error',
					$result->get_error_message(),
					$back,
					'block_theme_ops_not_configured' === $result->get_error_code() ? array( 'settings' => 1 ) : array()
				);
				return;
			}
			$this->done(
				'success',
				/* translators: %d: pull request number. */
				sprintf( __( 'Pull request #%d opened.', 'block-theme-ops' ), $result['number'] ),
				$back,
				array( 'url' => $result['url'] )
			);
			return;
		}

		$done   = 0;
		$errors = array();
		$backup = 0;
		foreach ( $items as $item ) {
			if ( 'reset' === $task ) {
				$result = $this->operations->reset( $item );
				if ( is_wp_error( $result ) ) {
					/* translators: 1: item name, 2: error message. */
					$errors[] = sprintf( __( '%1$s: %2$s', 'block-theme-ops' ), $item['title'], $result->get_error_message() );
					continue;
				}
				$backup = (int) $result['backup'];
			} elseif ( 'keep' === $task ) {
				$this->operations->keep( $item );
			} else {
				$this->operations->unkeep( $item );
			}
			++$done;
		}

		if ( 'reset' === $task ) {
			// The item is gone, so go back to the list.
			unset( $back['item'] );
		}

		if ( $errors ) {
			$this->done( 'error', implode( ' ', $errors ), $back );
			return;
		}

		$messages = array(
			/* translators: %d: number of items. */
			'reset'  => _n( '%d item reset to the theme file. A backup is kept.', '%d items reset to the theme files. Backups are kept.', $done, 'block-theme-ops' ),
			/* translators: %d: number of items. */
			'keep'   => _n( '%d item kept. It comes back when its theme file changes.', '%d items kept. They come back when their theme files change.', $done, 'block-theme-ops' ),
			/* translators: %d: number of items. */
			'unkeep' => _n( '%d item listed again.', '%d items listed again.', $done, 'block-theme-ops' ),
		);

		$this->done( 'success', sprintf( $messages[ $task ], $done ), $back, 1 === $done && $backup ? array( 'undo' => $backup ) : array() );
	}

	/**
	 * Resets every identical item.
	 *
	 * @param array $back Where to go afterwards.
	 */
	private function handle_reset_identical( array $back ): void {
		$result = $this->operations->reset_identical();
		$count  = count( $result['reset'] );
		if ( $result['failed'] ) {
			/* translators: %d: number of items. */
			$this->done( 'error', sprintf( _n( '%d identical item could not be reset.', '%d identical items could not be reset.', count( $result['failed'] ), 'block-theme-ops' ), count( $result['failed'] ) ), $back );
			return;
		}

		/* translators: %d: number of items. */
		$this->done( 'success', sprintf( _n( '%d identical item reset. Nothing changes on the site.', '%d identical items reset. Nothing changes on the site.', $count, 'block-theme-ops' ), $count ), $back );
	}

	/**
	 * Undoes a reset.
	 *
	 * @param int   $backup_id Backup ID.
	 * @param array $back      Where to go afterwards.
	 */
	private function handle_undo( int $backup_id, array $back ): void {
		unset( $back['item'] );
		$result = $this->operations->undo( $backup_id );
		if ( is_wp_error( $result ) ) {
			$this->done( 'error', $result->get_error_message(), $back );
			return;
		}

		$this->done( 'success', __( 'Reset undone. The database copy is back.', 'block-theme-ops' ), array( 'item' => $result ) );
	}

	/**
	 * Saves the settings.
	 *
	 * @param array $back Where to go afterwards.
	 */
	private function handle_settings( array $back ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in handle().
		$changes = array(
			'notify' => ! empty( $_POST['notify'] ),
			'repo'   => isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '',
			'branch' => isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( $_POST['branch'] ) ) : '',
			'path'   => isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '',
		);
		$token   = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		if ( '' !== $token ) {
			$changes['token'] = $token;
		} elseif ( ! empty( $_POST['remove_token'] ) ) {
			$changes['token'] = '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->settings->update( $changes );

		$problems = array();
		if ( '' !== $changes['repo'] && '' === $this->settings->get( 'repo' ) ) {
			$problems[] = __( 'The repository was not saved: write it as owner/repo.', 'block-theme-ops' );
		}
		if ( '' !== $changes['branch'] && $changes['branch'] !== $this->settings->get( 'branch' ) ) {
			$problems[] = __( 'The base branch was not saved: that is not a valid branch name.', 'block-theme-ops' );
		}

		if ( $problems ) {
			$this->done( 'error', implode( ' ', $problems ), $back );
			return;
		}

		$this->done( 'success', __( 'Settings saved.', 'block-theme-ops' ), $back );
	}

	/**
	 * Sends a zip of the items to the browser.
	 *
	 * @param array[] $items Items.
	 * @param array   $back  Where to go when it fails.
	 */
	private function download( array $items, array $back ): void {
		$files = $this->exporter->files( $items, 'zip' );
		$zip   = $this->exporter->zip( $files );
		if ( is_wp_error( $zip ) ) {
			$this->done( 'error', $zip->get_error_message(), $back );
			return;
		}

		$this->exporter->exported( $files, $items, 'zip' );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $this->exporter->zip_name() . '"' );
		header( 'Content-Length: ' . (string) filesize( $zip ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.Security.EscapeOutput.OutputNotEscaped -- A zip file this request just built, sent as the download.
		echo file_get_contents( $zip );
		wp_delete_file( $zip );
		exit;
	}

	/**
	 * Stores the result for the next screen and redirects there.
	 *
	 * @param string $type    success or error.
	 * @param string $message Message.
	 * @param array  $back    Query arguments of the screen to go back to.
	 * @param array  $extra   undo (backup ID), url (pull request) or settings (link to the Settings tab).
	 */
	private function done( string $type, string $message, array $back, array $extra = array() ): void {
		set_transient(
			self::notice_key(),
			array(
				'type'    => $type,
				'message' => $message,
			) + $extra,
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( self::url( $back ) );
		exit;
	}

	/**
	 * Prints the result of the last action.
	 */
	private function render_notice(): void {
		$notice = get_transient( self::notice_key() );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( self::notice_key() );

		$type = 'error' === ( $notice['type'] ?? '' ) ? 'error' : 'success';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible bto-notice">
			<p>
				<?php echo esc_html( (string) $notice['message'] ); ?>
				<?php if ( ! empty( $notice['url'] ) ) : ?>
					<a href="<?php echo esc_url( (string) $notice['url'] ); ?>" class="bto-pr-link" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $notice['url'] ); ?></a>
				<?php endif; ?>
				<?php if ( ! empty( $notice['settings'] ) ) : ?>
					<a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>" class="bto-settings-link"><?php esc_html_e( 'Open the GitHub settings', 'block-theme-ops' ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $notice['undo'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form bto-undo">
					<?php $this->hidden( 'undo', (int) $notice['undo'] ); ?>
					<button type="submit" class="button button-small"><?php esc_html_e( 'Undo', 'block-theme-ops' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Transient that carries a result to the next screen, per user.
	 *
	 * @return string
	 */
	private static function notice_key(): string {
		return 'block_theme_ops_notice_' . get_current_user_id();
	}

	/**
	 * Prints the hidden fields every form needs.
	 *
	 * @param string $task  Task.
	 * @param int    $id    Item or backup ID.
	 * @param array  $extra More hidden fields.
	 */
	private function hidden( string $task, int $id = 0, array $extra = array() ): void {
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="task" value="' . esc_attr( $task ) . '">';
		if ( $id ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		}
		foreach ( $extra as $name => $value ) {
			if ( '' !== (string) $value ) {
				echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			}
		}
	}

	/**
	 * Prints the confirmation messages admin.js shows before a reset or a pull request.
	 */
	private function confirm_attributes(): void {
		$target = $this->exporter->provider()->target();
		printf(
			'data-bto-confirm-reset="%s" data-bto-confirm-pr="%s"',
			esc_attr__( 'Reset to the theme file? The database copy is backed up first, and you can undo this from the Backups tab.', 'block-theme-ops' ),
			esc_attr(
				'' !== $target
					/* translators: %s: repository and branch. */
					? sprintf( __( 'Open a pull request on %s with these changes?', 'block-theme-ops' ), $target )
					: ''
			)
		);
	}

	/**
	 * Prints a status badge.
	 *
	 * @param array $item Item.
	 */
	private function badge( array $item ): void {
		printf(
			'<span class="bto-badge bto-badge--%s">%s</span>',
			esc_attr( $item['status'] ),
			esc_html( Drift::status_label( $item['status'] ) )
		);
		if ( $item['probable'] ) {
			echo ' <span class="bto-probable" title="' . esc_attr__( 'Saved before Block Theme Ops was active: a guess from file dates.', 'block-theme-ops' ) . '">' . esc_html__( 'probably', 'block-theme-ops' ) . '</span>';
		}
		if ( $item['kept'] ) {
			echo ' <span class="bto-badge bto-badge--kept">' . esc_html__( 'Kept', 'block-theme-ops' ) . '</span>';
		}
	}

	/**
	 * Prints the theme file path, noting a parent theme.
	 *
	 * @param array $item Item.
	 */
	private function file_cell( array $item ): void {
		if ( '' === $item['file'] ) {
			echo '<span class="bto-muted">' . esc_html__( 'None', 'block-theme-ops' ) . '</span>';
			return;
		}

		echo '<code dir="ltr">' . esc_html( $item['file'] ) . '</code>';
		if ( $item['file_theme'] !== $item['theme'] ) {
			/* translators: %s: parent theme folder name. */
			echo '<br><span class="bto-muted">' . esc_html( sprintf( __( 'in the parent theme, %s', 'block-theme-ops' ), $item['file_theme'] ) ) . '</span>';
		}
	}

	/**
	 * The arrow of the link back to the list, pointing the way the language reads.
	 *
	 * @return string
	 */
	public static function back_arrow(): string {
		return is_rtl() ? "\u{2192}" : "\u{2190}";
	}

	/**
	 * Prints why pull requests are off, with a link to the Settings tab.
	 */
	private function settings_hint(): void {
		printf(
			/* translators: %s: link to the Settings tab. */
			esc_html__( 'Pull requests need a repository and a token. Set them up under %s first.', 'block-theme-ops' ),
			'<a href="' . esc_url( self::url( array( 'tab' => 'settings' ) ) ) . '">' . esc_html__( 'Settings', 'block-theme-ops' ) . '</a>'
		);
	}

	/**
	 * One-line explanation of a status.
	 *
	 * @param string $status   Status.
	 * @param bool   $probable Whether the status is a guess from dates.
	 * @return string
	 */
	public static function status_help( string $status, bool $probable = false ): string {
		switch ( $status ) {
			case Drift::THEME_CHANGED:
				return $probable
					? __( 'The theme file is newer than this database copy, so the theme probably changed after the edit. The copy was saved before Block Theme Ops was active, so this is a guess from file dates.', 'block-theme-ops' )
					: __( 'The theme file changed after this was saved in the Site Editor. The site still uses the older database copy.', 'block-theme-ops' );
			case Drift::CUSTOMIZED:
				return __( 'Changed in the Site Editor. The theme file has not changed since.', 'block-theme-ops' );
			case Drift::DB_ONLY:
				return __( 'Made in the Site Editor. There is no theme file for it.', 'block-theme-ops' );
			default:
				return __( 'The database copy matches the theme file. Safe to reset.', 'block-theme-ops' );
		}
	}

	/**
	 * Date and time in the site's format.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function date( int $time ): string {
		return $time ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '';
	}
}
