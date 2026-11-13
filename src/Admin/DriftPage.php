<?php
/**
 * Appearance > Theme Drift.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Admin;

use AryanX\Axle\BlockThemeOps\Abilities;
use AryanX\Axle\BlockThemeOps\Alerts;
use AryanX\Axle\BlockThemeOps\Backups;
use AryanX\Axle\BlockThemeOps\Drift;
use AryanX\Axle\BlockThemeOps\Exporter;
use AryanX\Axle\BlockThemeOps\Importer;
use AryanX\Axle\BlockThemeOps\Locks;
use AryanX\Axle\BlockThemeOps\Markup;
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
	 * Import.
	 *
	 * @var Importer
	 */
	private Importer $importer;

	/**
	 * Locks.
	 *
	 * @var Locks
	 */
	private Locks $locks;

	/**
	 * Sets up the screen.
	 *
	 * @param Drift      $drift      Inventory.
	 * @param Operations $operations Reset, undo and keep.
	 * @param Exporter   $exporter   Export.
	 * @param Backups    $backups    Backups.
	 * @param Settings   $settings   Settings.
	 * @param Alerts     $alerts     Pending alerts.
	 * @param Importer   $importer   Import.
	 * @param Locks      $locks      Locks.
	 */
	public function __construct( Drift $drift, Operations $operations, Exporter $exporter, Backups $backups, Settings $settings, Alerts $alerts, Importer $importer, Locks $locks ) {
		$this->drift      = $drift;
		$this->operations = $operations;
		$this->exporter   = $exporter;
		$this->backups    = $backups;
		$this->settings   = $settings;
		$this->alerts     = $alerts;
		$this->importer   = $importer;
		$this->locks      = $locks;
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
			add_action( "load-{$hook}", array( $this, 'help' ) );
		}
	}

	/**
	 * Help tabs: an overview, and one tab for each job of the tab that is open.
	 */
	public function help(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		$tabs = array(
			array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'block-theme-ops' ),
				'content' => '<p>' . esc_html__( 'When someone saves a template, a template part or the styles in the Site Editor, WordPress keeps a copy in the database and the site ignores the theme file from then on. Theme Drift lists those copies and how each one relates to its theme file.', 'block-theme-ops' ) . '</p>',
			),
		);

		if ( 'import' === $tab ) {
			$tabs[] = array(
				'id'      => 'import-preview',
				'title'   => __( 'Preview', 'block-theme-ops' ),
				'content' => '<p>' . esc_html__( 'Upload a zip from Download zip on another site. Nothing changes until you choose items and press Import. Each item is new, changed, identical or for another theme. Items you changed here too are not ticked for you.', 'block-theme-ops' ) . '</p>',
			);
			$tabs[] = array(
				'id'      => 'import-undo',
				'title'   => __( 'Undo', 'block-theme-ops' ),
				'content' => '<p>' . esc_html__( 'Every import keeps a backup of what it overwrites. One Undo on the Backups tab puts everything back for 30 days. An item that someone changed again since is left alone.', 'block-theme-ops' ) . '</p>',
			);
		} elseif ( 'locks' === $tab ) {
			$tabs[] = array(
				'id'      => 'locks-how',
				'title'   => __( 'How locks work', 'block-theme-ops' ),
				'content' => '<p>' . esc_html__( 'A locked template cannot be saved from the Site Editor or the REST API. The save is refused with a message, and the Site Editor shows a notice when someone opens it. Reset, import and Undo here still work.', 'block-theme-ops' ) . '</p>',
			);
			$tabs[] = array(
				'id'      => 'locks-env',
				'title'   => __( 'Environments', 'block-theme-ops' ),
				'content' => '<p>' . esc_html__( 'Locks only apply on the environment types you tick, production by default. Set WP_ENVIRONMENT_TYPE in wp-config.php to tell WordPress which one a site is.', 'block-theme-ops' ) . '</p>',
			);
		} elseif ( 'backups' === $tab ) {
			$tabs[] = array(
				'id'      => 'backups-undo',
				'title'   => __( 'Undo', 'block-theme-ops' ),
				'content' => '<p>' . esc_html__( 'Each reset and each import keeps a backup. Undo puts the database copy back as it was. A backup is deleted when its undo time has passed.', 'block-theme-ops' ) . '</p>',
			);
		} elseif ( 'settings' === $tab ) {
			$tabs[] = array(
				'id'      => 'settings-github',
				'title'   => __( 'GitHub token', 'block-theme-ops' ),
				'content' => '<p>' . esc_html__( 'A pull request needs a fine-grained token for one repository, with read and write access to Contents and Pull requests. A saved token is encrypted. Defining BLOCK_THEME_OPS_GITHUB_TOKEN in wp-config.php keeps it out of the database.', 'block-theme-ops' ) . '</p>',
			);
		} else {
			$tabs[] = array(
				'id'      => 'statuses',
				'title'   => __( 'Statuses', 'block-theme-ops' ),
				'content' => '<p>' . esc_html__( 'Theme changed: the theme file changed after the edit, so the site ignores it. Customized: changed in the Site Editor and the file has not changed since. Database only: no theme file behind it. Identical: the same as the theme file, safe to reset.', 'block-theme-ops' ) . '</p>',
			);
			$tabs[] = array(
				'id'      => 'reset-keep',
				'title'   => __( 'Reset and keep', 'block-theme-ops' ),
				'content' => '<p>' . esc_html__( 'Reset to theme file deletes the database copy so the theme file takes over. A backup is kept and you can undo it. Keep says the copy is on purpose and hides it until its theme file changes again.', 'block-theme-ops' ) . '</p>',
			);
			$tabs[] = array(
				'id'      => 'export',
				'title'   => __( 'Export', 'block-theme-ops' ),
				'content' => '<p>' . esc_html__( 'Download zip makes a zip laid out like the theme, which you can import on another site. Open pull request sends the same files to your theme repository as one commit.', 'block-theme-ops' ) . '</p>',
			);
		}

		AxleUi::help_tabs( $tabs );
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
	 * Styles and the screen's script.
	 */
	public function enqueue(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'block-theme-ops-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		wp_enqueue_script( 'block-theme-ops-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks', 'wp-a11y' ), VERSION, true );
		wp_localize_script(
			'block-theme-ops-admin',
			'blockThemeOpsAdmin',
			array(
				'cancel'  => __( 'Cancel', 'block-theme-ops' ),
				'working' => __( 'Working. Please wait.', 'block-theme-ops' ),
			)
		);
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
		$review  = isset( $_GET['review'] ) ? sanitize_key( wp_unslash( $_GET['review'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap axle-ui bto-page">';
		if ( $item_id ) {
			$this->render_item( $item_id );
		} else {
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Theme Drift', 'block-theme-ops' ) . '</h1>';
			echo '<hr class="wp-header-end">';
			$this->render_notice();
			$this->render_tabs( $tab );
			echo '<div id="bto-confirm-slot" class="bto-confirm-slot"></div>';
			if ( 'backups' === $tab ) {
				$this->render_backups();
			} elseif ( 'settings' === $tab ) {
				$this->render_settings();
			} elseif ( 'import' === $tab ) {
				$this->render_import( $review );
			} elseif ( 'locks' === $tab ) {
				$this->render_locks();
			} else {
				$this->render_list();
			}
		}
		AxleUi::footer();
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
			'import'   => __( 'Import', 'block-theme-ops' ),
			'locks'    => __( 'Locks', 'block-theme-ops' ),
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
		<p class="axle-intro bto-intro">
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

		<div class="axle-stats bto-cards">
			<?php foreach ( Drift::STATUSES as $status ) : ?>
				<a class="axle-stat axle-stat--<?php echo esc_attr( self::tone( $status ) ); ?> bto-card bto-card--<?php echo esc_attr( $status ); ?><?php echo $filter === $status ? ' is-current' : ''; ?>" href="<?php echo esc_url( self::url( array( 'status' => $status ) ) ); ?>"<?php echo $filter === $status ? ' aria-current="true"' : ''; ?>>
					<span class="axle-stat__value bto-card__count"><?php echo esc_html( number_format_i18n( $counts[ $status ] ) ); ?></span>
					<span class="axle-stat__label bto-card__label"><?php echo esc_html( Drift::status_label( $status ) ); ?></span>
					<span class="axle-muted bto-card__help"><?php echo esc_html( self::status_help( $status ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<?php if ( ! $all ) : ?>
			<div class="axle-empty bto-empty">
				<p><strong><?php esc_html_e( 'Nothing in the database overrides the theme.', 'block-theme-ops' ); ?></strong></p>
				<p><?php esc_html_e( 'Everything comes from the theme files. When someone saves a template, a template part or the styles in the Site Editor, it shows up here with its status, who saved it and how it differs from the theme file.', 'block-theme-ops' ); ?></p>
				<p>
					<?php if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && current_user_can( 'edit_theme_options' ) ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'site-editor.php' ) ); ?>"><?php esc_html_e( 'Open the Site Editor', 'block-theme-ops' ); ?></a>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( self::url( array( 'tab' => 'import' ) ) ); ?>"><?php esc_html_e( 'Import changes from another site', 'block-theme-ops' ); ?></a>
				</p>
			</div>
			<?php
			return;
		endif;
		?>

		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( self::url() ); ?>"<?php echo '' === $filter ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'All', 'block-theme-ops' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts['all'] ) ); ?>)</span></a> |</li>
			<?php foreach ( Drift::STATUSES as $status ) : ?>
				<li><a href="<?php echo esc_url( self::url( array( 'status' => $status ) ) ); ?>"<?php echo $filter === $status ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( Drift::status_label( $status ) ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $status ] ) ); ?>)</span></a> |</li>
			<?php endforeach; ?>
			<li><a href="<?php echo esc_url( self::url( array( 'status' => 'kept' ) ) ); ?>"<?php echo 'kept' === $filter ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Kept', 'block-theme-ops' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts['kept'] ) ); ?>)</span></a></li>
		</ul>

		<?php if ( ! $items ) : ?>
			<div class="axle-empty bto-empty">
				<p><?php esc_html_e( 'No items with this status.', 'block-theme-ops' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Show all items', 'block-theme-ops' ); ?></a></p>
			</div>
			<?php
			return;
		endif;
		?>

		<div class="axle-toolbar bto-toolbar">
			<label for="bto-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'block-theme-ops' ); ?></label>
			<select name="bulk_action" id="bto-bulk-action" form="bto-list-form">
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
			<button type="submit" class="button action" form="bto-list-form"><?php esc_html_e( 'Apply', 'block-theme-ops' ); ?></button>
			<?php if ( $counts[ Drift::IDENTICAL ] ) : ?>
				<span class="axle-toolbar__end">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form bto-identical" data-bto-confirm-reset_identical="<?php echo esc_attr( $this->reset_identical_message( $counts[ Drift::IDENTICAL ] ) ); ?>">
						<?php $this->hidden( 'reset_identical' ); ?>
						<button type="submit" class="button">
							<?php
							/* translators: %d: number of items. */
							echo esc_html( sprintf( _n( 'Reset %d identical item', 'Reset all %d identical items', $counts[ Drift::IDENTICAL ], 'block-theme-ops' ), $counts[ Drift::IDENTICAL ] ) );
							?>
						</button>
					</form>
				</span>
			<?php endif; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bto-list-form" class="bto-form bto-list-form" <?php $this->confirm_attributes(); ?>>
			<?php $this->hidden( 'bulk', 0, array( 'status' => $filter ) ); ?>
			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Site Editor changes', 'block-theme-ops' ); ?>">
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
								<?php echo wp_kses( AxleUi::relative_time( (int) $item['modified'] ), self::time_tags() ); ?><br>
								<span class="axle-muted bto-muted">
									<?php
									/* translators: %s: user name. */
									echo esc_html( sprintf( __( 'by %s', 'block-theme-ops' ), $item['author_name'] ) );
									?>
								</span>
							</td>
							<td>
								<?php
								if ( $item['file_modified'] ) {
									echo wp_kses( AxleUi::relative_time( (int) $item['file_modified'] ), self::time_tags() );
								} else {
									echo '<span class="axle-muted bto-muted">' . esc_html__( 'No theme file', 'block-theme-ops' ) . '</span>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
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
			<span class="axle-status"><?php $this->badge( $item ); ?></span>
		</h1>
		<hr class="wp-header-end">
		<?php $this->render_notice(); ?>
		<div id="bto-confirm-slot" class="bto-confirm-slot"></div>

		<p class="axle-intro bto-explain"><?php echo esc_html( self::status_help( $item['status'], $item['probable'] ) ); ?></p>
		<?php if ( $item['kept'] ) : ?>
			<p class="axle-intro bto-explain"><?php esc_html_e( 'You chose to keep this database copy. It stays out of the list and the alerts until the theme file changes again.', 'block-theme-ops' ); ?></p>
		<?php endif; ?>

		<section class="axle-card">
		<dl class="axle-facts bto-facts">
			<dt><?php esc_html_e( 'Theme file', 'block-theme-ops' ); ?></dt>
			<dd><?php $this->file_cell( $item ); ?></dd>
			<dt><?php esc_html_e( 'Saved in the Site Editor', 'block-theme-ops' ); ?></dt>
			<dd>
				<?php
				/* translators: 1: relative time such as "5 minutes ago", 2: user name. */
				echo wp_kses( sprintf( esc_html__( '%1$s by %2$s', 'block-theme-ops' ), AxleUi::relative_time( (int) $item['modified'] ), esc_html( $item['author_name'] ) ), self::time_tags() );
				?>
			</dd>
			<?php if ( $item['file_modified'] ) : ?>
				<dt><?php esc_html_e( 'Theme file changed', 'block-theme-ops' ); ?></dt>
				<dd><?php echo wp_kses( AxleUi::relative_time( (int) $item['file_modified'] ), self::time_tags() ); ?></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Snapshot of the theme file', 'block-theme-ops' ); ?></dt>
			<dd>
				<?php
				if ( ! $snapshot ) {
					esc_html_e( 'None. This was saved before Block Theme Ops was active.', 'block-theme-ops' );
				} elseif ( 'baseline' === $snapshot['source'] ) {
					/* translators: %s: relative time such as "5 minutes ago". */
					echo wp_kses( sprintf( esc_html__( 'Taken %s, when Block Theme Ops first saw this item. Older theme changes are not in it.', 'block-theme-ops' ), AxleUi::relative_time( $snapshot['time'] ) ), self::time_tags() );
				} else {
					/* translators: %s: relative time such as "5 minutes ago". */
					echo wp_kses( sprintf( esc_html__( 'Taken %s, when the database copy was saved.', 'block-theme-ops' ), AxleUi::relative_time( $snapshot['time'] ) ), self::time_tags() );
				}
				?>
			</dd>
		</dl>
		</section>

		<section class="axle-card">
		<div class="axle-actions bto-actions">
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
		</section>

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
			echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Database copy', 'block-theme-ops' ) . '</h2>';
			echo '<pre class="bto-code" dir="ltr">' . esc_html( $views['database'] ) . '</pre></section>';
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
			echo '<p class="axle-muted bto-muted">' . esc_html__( 'There is no snapshot of the theme file from the time of the edit, so the theme\'s own changes cannot be shown apart from the customization.', 'block-theme-ops' ) . '</p>';
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
		<section class="axle-card bto-diff" id="<?php echo esc_attr( $id ); ?>">
			<h2 class="axle-card__title"><?php echo esc_html( $title ); ?></h2>
			<p class="axle-muted"><?php echo esc_html( $help ); ?></p>
			<?php if ( '' === $diff ) : ?>
				<div class="notice notice-success inline bto-same"><p><?php esc_html_e( 'No differences.', 'block-theme-ops' ); ?></p></div>
			<?php else : ?>
				<?php // Block markup and JSON read left to right, in every language. ?>
				<div class="axle-scroll bto-diff__table" dir="ltr" tabindex="0" role="region" aria-label="<?php echo esc_attr( $title ); ?>"><?php echo wp_kses_post( $diff ); ?></div>
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
		<p class="axle-intro bto-intro">
			<?php
			/* translators: %d: number of days. */
			echo esc_html( sprintf( _n( 'Every reset and every import keeps a backup of what it changes for %d day. Undo puts it back as it was.', 'Every reset and every import keeps a backup of what it changes for %d days. Undo puts it back as it was.', Backups::days(), 'block-theme-ops' ), Backups::days() ) );
			?>
		</p>
		<?php if ( ! $backups ) : ?>
			<div class="axle-empty bto-empty">
				<p><strong><?php esc_html_e( 'No resets or imports to undo.', 'block-theme-ops' ); ?></strong></p>
				<p><?php esc_html_e( 'When you reset an item to its theme file, or import changes from another site, the backup shows up here until its undo time has passed.', 'block-theme-ops' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>
		<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Backups', 'block-theme-ops' ); ?>">
		<table class="wp-list-table widefat fixed striped bto-backups">
			<thead>
				<tr>
					<th scope="col" class="column-primary"><?php esc_html_e( 'Item', 'block-theme-ops' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Theme', 'block-theme-ops' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Changed', 'block-theme-ops' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Can be undone until', 'block-theme-ops' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'block-theme-ops' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $backups as $backup ) : ?>
					<?php $is_import = 'import' === ( $backup['kind'] ?? '' ); ?>
					<tr class="bto-backup" data-id="<?php echo esc_attr( (string) $backup['id'] ); ?>">
						<td class="column-primary">
							<strong><?php echo esc_html( (string) ( $backup['item']['title'] ?? $backup['title'] ) ); ?></strong>
							<?php if ( $is_import ) : ?>
								<span class="bto-type">
									<?php
									echo esc_html(
										implode(
											', ',
											array_map(
												static function ( array $record ): string {
													return (string) $record['key'];
												},
												(array) $backup['records']
											)
										)
									);
									?>
								</span>
							<?php else : ?>
								<span class="bto-type"><?php echo esc_html( Drift::type_label( (string) $backup['post_type'] ) ); ?> &middot; <code dir="ltr"><?php echo esc_html( (string) ( $backup['item']['key'] ?? $backup['slug'] ) ); ?></code></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $backup['theme'] ); ?></td>
						<td>
							<?php
							/* translators: 1: relative time such as "5 minutes ago", 2: user name. */
							echo wp_kses( sprintf( esc_html__( '%1$s by %2$s', 'block-theme-ops' ), AxleUi::relative_time( (int) $backup['reset_at'] ), esc_html( Drift::user_name( (int) $backup['reset_by'] ) ) ), self::time_tags() );
							?>
						</td>
						<td><?php echo wp_kses( AxleUi::relative_time( (int) $backup['undo_until'] ), self::time_tags() ); ?></td>
						<td>
							<?php $blocker = $is_import ? '' : $this->operations->undo_blocker( $backup ); ?>
							<?php if ( '' === $blocker ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form">
									<?php $this->hidden( 'undo', (int) $backup['id'], array( 'tab' => 'backups' ) ); ?>
									<button type="submit" class="button"><?php echo esc_html( $is_import ? __( 'Undo import', 'block-theme-ops' ) : __( 'Undo reset', 'block-theme-ops' ) ); ?></button>
								</form>
							<?php else : ?>
								<p class="bto-blocked" data-reason="<?php echo esc_attr( $blocker ); ?>">
									<span class="axle-badge axle-badge--<?php echo esc_attr( Operations::RESTORED === $blocker ? 'good' : 'warning' ); ?>"><?php echo esc_html( Operations::RESTORED === $blocker ? __( 'Back already', 'block-theme-ops' ) : __( 'Customized again since', 'block-theme-ops' ) ); ?></span><br>
									<?php echo esc_html( Operations::blocked_message( $blocker, $backup ) ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Prints the Import tab: the upload form, or the preview of an uploaded zip.
	 *
	 * @param string $token Token of an uploaded zip, empty for the upload form.
	 */
	private function render_import( string $token ): void {
		if ( ! current_user_can( Importer::capability() ) ) {
			echo '<p>' . esc_html__( 'You are not allowed to import.', 'block-theme-ops' ) . '</p>';
			return;
		}

		$payload = '' !== $token ? $this->importer->stashed( $token ) : null;
		if ( '' !== $token && null === $payload ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'That upload has expired or does not exist. Upload the zip again.', 'block-theme-ops' ) . '</p></div>';
		}

		if ( null === $payload ) {
			$this->render_upload();
			return;
		}

		$this->render_review( $token, $this->importer->plan( $payload ) );
	}

	/**
	 * Prints the upload form.
	 */
	private function render_upload(): void {
		$limits = Importer::limits();
		?>
		<p class="axle-intro bto-intro"><?php esc_html_e( 'Move the template and style changes made on one site, such as staging, to this one. Upload the zip from Download zip, check what would change, then choose what to import. Nothing changes until you press Import, and one Undo puts everything back.', 'block-theme-ops' ); ?></p>
		<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'Upload a zip', 'block-theme-ops' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="bto-form bto-upload" data-bto-busy="<?php esc_attr_e( 'Reading the zip.', 'block-theme-ops' ); ?>">
				<?php $this->hidden( 'import_upload', 0, array( 'tab' => 'import' ) ); ?>
				<p>
					<label for="bto-zip"><?php esc_html_e( 'Zip file', 'block-theme-ops' ); ?></label><br>
					<input type="file" id="bto-zip" name="zip" accept=".zip,application/zip" required aria-describedby="bto-zip-help">
				</p>
				<p class="description" id="bto-zip-help">
					<?php
					/* translators: 1: largest file in the zip, 2: most files in the zip. */
					echo esc_html( sprintf( __( 'The zip Download zip makes, or any zip laid out like the theme: templates/*.html, parts/*.html and theme.json. Files up to %1$s each and %2$d files at most. Nothing in the zip is run.', 'block-theme-ops' ), size_format( $limits['file'] ), $limits['files'] ) );
					?>
				</p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and preview', 'block-theme-ops' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	/**
	 * Prints what an uploaded zip would change.
	 *
	 * @param string $token Token of the stashed zip.
	 * @param array  $plan  From Importer::plan().
	 */
	private function render_review( string $token, array $plan ): void {
		$status  = $plan['theme_status'];
		$entries = $plan['entries'];
		$notes   = $plan['notes'];
		$usable  = array_filter(
			$entries,
			static function ( array $entry ): bool {
				return in_array( $entry['status'], array( Importer::NEW, Importer::CHANGED ), true );
			}
		);
		$can_run = Importer::THEME_OTHER !== $status && $usable;
		$tone    = array(
			Importer::NEW         => 'info',
			Importer::CHANGED     => 'warning',
			Importer::IDENTICAL   => 'good',
			Importer::OTHER_THEME => 'error',
		);
		$labels  = array(
			Importer::NEW         => __( 'New', 'block-theme-ops' ),
			Importer::CHANGED     => __( 'Changed', 'block-theme-ops' ),
			Importer::IDENTICAL   => __( 'Identical', 'block-theme-ops' ),
			Importer::OTHER_THEME => __( 'Not for this theme', 'block-theme-ops' ),
		);
		$indent  = str_repeat( "\u{00A0}", 4 );
		?>
		<p class="axle-intro bto-intro">
			<?php
			if ( '' !== $plan['theme'] ) {
				/* translators: 1: theme folder name in the zip, 2: active theme folder name. */
				echo esc_html( sprintf( __( 'This zip is for the theme %1$s. The active theme is %2$s.', 'block-theme-ops' ), $plan['theme'], $plan['stylesheet'] ) );
			} else {
				esc_html_e( 'This zip does not say which theme it is for, so it is read as the active theme.', 'block-theme-ops' );
			}
			?>
		</p>

		<?php if ( Importer::THEME_OTHER === $status ) : ?>
			<div class="notice notice-error inline bto-theme-other"><p><?php esc_html_e( 'These are not the files of this theme, so nothing can be imported. Export from a site that runs the same theme, or from its child or parent theme.', 'block-theme-ops' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $notes['site_url'] ) : ?>
			<section class="axle-card bto-note">
				<h2 class="axle-card__title"><?php esc_html_e( 'Site address', 'block-theme-ops' ); ?></h2>
				<p><?php esc_html_e( 'Addresses of the other site in the markup are rewritten to this site.', 'block-theme-ops' ); ?></p>
				<div class="axle-diff">
					<del><span class="screen-reader-text"><?php esc_html_e( 'Before:', 'block-theme-ops' ); ?></span> <?php echo esc_html( $notes['site_url'][0] ); ?></del>
					<ins><span class="screen-reader-text"><?php esc_html_e( 'After:', 'block-theme-ops' ); ?></span> <?php echo esc_html( $notes['site_url'][1] ); ?></ins>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $notes['navigation'] ) : ?>
			<div class="notice notice-warning inline bto-note-navigation">
				<p><?php esc_html_e( 'These navigation menus do not exist on this site. Navigation blocks that used them will show this site\'s default menu. Create them with the same slug and upload the zip again to link them.', 'block-theme-ops' ); ?></p>
				<ul class="bto-list">
				<?php
				foreach ( $notes['navigation'] as $menu ) :
					?>
					<li><code dir="ltr"><?php echo esc_html( $menu ); ?></code></li><?php endforeach; ?></ul>
			</div>
		<?php endif; ?>

		<?php if ( $notes['media'] ) : ?>
			<div class="notice notice-warning inline bto-note-media">
				<p><?php esc_html_e( 'These files are not in this site\'s Media Library. The blocks keep the address but are not linked to a Media Library item. Upload the files to the same folder and upload the zip again to link them.', 'block-theme-ops' ); ?></p>
				<ul class="bto-list">
				<?php
				foreach ( $notes['media'] as $media ) :
					?>
					<li><code dir="ltr"><?php echo esc_html( $media ); ?></code></li><?php endforeach; ?></ul>
			</div>
		<?php endif; ?>

		<?php if ( $plan['rejected'] ) : ?>
			<div class="notice notice-warning inline bto-note-rejected">
				<p><?php esc_html_e( 'These files in the zip were skipped:', 'block-theme-ops' ); ?></p>
				<ul class="bto-list">
				<?php
				foreach ( $plan['rejected'] as $name => $reason ) :
					?>
					<li><code dir="ltr"><?php echo esc_html( (string) $name ); ?></code> <?php echo esc_html( (string) $reason ); ?></li><?php endforeach; ?></ul>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form bto-review" data-bto-confirm-import_apply="<?php esc_attr_e( 'Import {count} selected item(s)? Each one it overwrites is backed up first. You can undo the whole import from the Backups tab for 30 days.', 'block-theme-ops' ); ?>" data-bto-busy="<?php esc_attr_e( 'Importing.', 'block-theme-ops' ); ?>">
			<?php $this->hidden( 'import_apply', 0, array( 'tab' => 'import' ) ); ?>
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">

			<?php if ( Importer::THEME_RELATED === $status ) : ?>
				<p class="bto-confirm-theme">
					<input type="checkbox" id="bto-confirm-theme" name="confirm_theme" value="1">
					<label for="bto-confirm-theme">
						<?php
						/* translators: 1: theme in the zip, 2: active theme. */
						echo esc_html( sprintf( __( 'The theme %1$s and the active theme, %2$s, are a child theme and its parent. Import the changes anyway.', 'block-theme-ops' ), $plan['theme'], $plan['stylesheet'] ) );
						?>
					</label>
				</p>
			<?php endif; ?>

			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'What the zip would change', 'block-theme-ops' ); ?>">
			<table class="wp-list-table widefat fixed striped bto-import-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><span class="screen-reader-text"><?php esc_html_e( 'Import', 'block-theme-ops' ); ?></span></td>
						<th scope="col" class="column-primary"><?php esc_html_e( 'Item', 'block-theme-ops' ); ?></th>
						<th scope="col"><?php esc_html_e( 'In the zip', 'block-theme-ops' ); ?></th>
						<th scope="col"><?php esc_html_e( 'On this site', 'block-theme-ops' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<?php
						$selectable = in_array( $entry['status'], array( Importer::NEW, Importer::CHANGED ), true ) && Importer::THEME_OTHER !== $status;
						$overwrite  = Importer::CHANGED === $entry['status'] && $entry['customized'];
						$ticked     = $selectable && ! $overwrite;
						$id         = 'bto-import-' . sanitize_html_class( str_replace( '/', '-', $entry['key'] ) );
						?>
						<tr class="bto-import-row bto-import-row--<?php echo esc_attr( $entry['status'] ); ?>" data-key="<?php echo esc_attr( $entry['key'] ); ?>">
							<th scope="row" class="check-column">
								<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">
									<?php
									/* translators: %s: item name. */
									echo esc_html( sprintf( __( 'Import %s', 'block-theme-ops' ), $entry['title'] ) );
									?>
								</label>
								<input type="checkbox" name="keys[]" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $entry['key'] ); ?>" <?php checked( $ticked ); ?> <?php disabled( ! $selectable ); ?>>
							</th>
							<td class="column-primary">
								<strong><?php echo esc_html( $entry['title'] ); ?></strong>
								<span class="bto-type"><?php echo esc_html( $entry['type_label'] ); ?> &middot; <code dir="ltr"><?php echo esc_html( $entry['key'] ); ?></code></span>
							</td>
							<td>
								<span class="axle-badge axle-badge--<?php echo esc_attr( $tone[ $entry['status'] ] ?? 'neutral' ); ?> bto-badge bto-badge--<?php echo esc_attr( $entry['status'] ); ?>"><?php echo esc_html( $labels[ $entry['status'] ] ?? $entry['status'] ); ?></span>
							</td>
							<td>
								<?php if ( $overwrite ) : ?>
									<span class="axle-badge axle-badge--warning bto-badge bto-badge--overwrites"><?php esc_html_e( 'Overwrites a change made here', 'block-theme-ops' ); ?></span>
									<span class="axle-muted bto-muted"><?php esc_html_e( 'Not ticked. Tick it to replace this site\'s version.', 'block-theme-ops' ); ?></span>
								<?php elseif ( null !== $entry['live'] && Importer::IDENTICAL !== $entry['status'] ) : ?>
									<span class="axle-muted bto-muted"><?php esc_html_e( 'The theme file, no Site Editor change.', 'block-theme-ops' ); ?></span>
								<?php elseif ( Importer::IDENTICAL === $entry['status'] ) : ?>
									<span class="axle-muted bto-muted"><?php esc_html_e( 'The same as here. Nothing to import.', 'block-theme-ops' ); ?></span>
								<?php else : ?>
									<span class="axle-muted bto-muted"><?php esc_html_e( 'Nothing yet.', 'block-theme-ops' ); ?></span>
								<?php endif; ?>
								<?php if ( $selectable || Importer::IDENTICAL === $entry['status'] ) : ?>
									<?php $this->import_preview( $entry, $indent ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<p class="submit bto-review-actions">
				<?php if ( $can_run ) : ?>
					<button type="submit" class="button button-primary bto-import-go"><?php esc_html_e( 'Import selected items', 'block-theme-ops' ); ?></button>
				<?php endif; ?>
				<button type="submit" name="task" value="import_discard" class="button" formnovalidate><?php esc_html_e( 'Discard this upload', 'block-theme-ops' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Prints the diff of an item in the preview, folded away.
	 *
	 * @param array  $entry  Plan entry.
	 * @param string $indent One level of indentation for the diff.
	 */
	private function import_preview( array $entry, string $indent ): void {
		$pretty   = static function ( string $type, ?string $text ) use ( $indent ): ?string {
			if ( null === $text ) {
				return null;
			}

			return Themes::GLOBAL_STYLES === $type ? str_replace( "\t", $indent, $text ) : Markup::pretty( $text, $indent );
		};
		$incoming = (string) $pretty( $entry['type'], (string) $entry['content'] );
		$live     = $pretty( $entry['type'], $entry['live'] );
		$theme    = $pretty( $entry['type'], $entry['theme_file'] );
		$left     = null !== $live ? $live : $theme;
		?>
		<details class="bto-import-diff">
			<summary><?php esc_html_e( 'Show changes', 'block-theme-ops' ); ?></summary>
			<?php
			if ( null === $left ) {
				echo '<pre class="bto-code" dir="ltr">' . esc_html( $incoming ) . '</pre>';
			} else {
				$this->render_diff_table( $left, $incoming, null !== $live ? __( 'On this site now', 'block-theme-ops' ) : __( 'Theme file', 'block-theme-ops' ), __( 'In the zip', 'block-theme-ops' ) );
				if ( $entry['customized'] && null !== $theme && Importer::CHANGED === $entry['status'] ) {
					echo '<p class="axle-muted bto-muted">' . esc_html__( 'Three-way view: this site\'s version above also differs from its theme file. The theme file compared with the zip:', 'block-theme-ops' ) . '</p>';
					$this->render_diff_table( $theme, $incoming, __( 'Theme file', 'block-theme-ops' ), __( 'In the zip', 'block-theme-ops' ) );
				}
			}
			?>
		</details>
		<?php
	}

	/**
	 * Prints a line diff table, or a note when there is no difference.
	 *
	 * @param string $left        Left text.
	 * @param string $right       Right text.
	 * @param string $left_title  Left title.
	 * @param string $right_title Right title.
	 */
	private function render_diff_table( string $left, string $right, string $left_title, string $right_title ): void {
		$diff = wp_text_diff(
			$left,
			$right,
			array(
				'title_left'      => $left_title,
				'title_right'     => $right_title,
				'show_split_view' => true,
			)
		);

		if ( '' === $diff ) {
			echo '<p class="axle-muted bto-muted">' . esc_html__( 'No differences.', 'block-theme-ops' ) . '</p>';
			return;
		}

		echo '<div class="axle-scroll bto-diff bto-diff__table" dir="ltr" tabindex="0" role="region" aria-label="' . esc_attr( $left_title . ' / ' . $right_title ) . '">' . wp_kses_post( $diff ) . '</div>';
	}

	/**
	 * Prints the Locks tab.
	 */
	private function render_locks(): void {
		$locks  = $this->locks->get();
		$items  = $this->locks->theme_items();
		$can    = current_user_can( Locks::capability() );
		$env    = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$status = array();
		foreach ( $this->drift->items() as $item ) {
			$status[ $item['key'] ] = $item;
		}
		?>
		<p class="axle-intro bto-intro"><?php esc_html_e( 'Stop Site Editor changes to templates that are deployed from your theme repository. A locked item cannot be saved from the Site Editor or the REST API: the save is refused with a message, and the Site Editor shows a notice when someone opens it. Reset, import and Undo on this screen still work.', 'block-theme-ops' ); ?></p>

		<p class="bto-lock-state">
			<?php if ( $this->locks->enforced() ) : ?>
				<span class="axle-badge axle-badge--good bto-badge"><?php esc_html_e( 'Enforced here', 'block-theme-ops' ); ?></span>
				<?php
				/* translators: %s: environment type such as production. */
				echo esc_html( sprintf( __( 'This site is a %s environment, so locked items are refused.', 'block-theme-ops' ), $env ) );
				?>
			<?php else : ?>
				<span class="axle-badge axle-badge--neutral bto-badge"><?php esc_html_e( 'Not enforced here', 'block-theme-ops' ); ?></span>
				<?php
				/* translators: %s: environment type such as local. */
				echo esc_html( sprintf( __( 'This site is a %s environment. Locks are saved, but saves are not refused here.', 'block-theme-ops' ), $env ) );
				?>
			<?php endif; ?>
		</p>

		<?php if ( ! $items ) : ?>
			<div class="axle-empty bto-empty">
				<p><strong><?php esc_html_e( 'The active theme has no templates, parts or theme.json to lock.', 'block-theme-ops' ); ?></strong></p>
				<p><?php esc_html_e( 'Locks apply to things that come from the theme files. Activate a block theme and they show up here.', 'block-theme-ops' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bto-form bto-locks" data-bto-busy="<?php esc_attr_e( 'Saving the locks.', 'block-theme-ops' ); ?>">
			<?php $this->hidden( 'save_locks', 0, array( 'tab' => 'locks' ) ); ?>
			<section class="axle-card">
				<h2 class="axle-card__title"><?php esc_html_e( 'What is locked', 'block-theme-ops' ); ?></h2>
				<p>
					<input type="checkbox" id="bto-lock-all" name="lock_all" value="1" <?php checked( $locks['all'] ); ?> <?php disabled( ! $can ); ?>>
					<label for="bto-lock-all"><strong><?php esc_html_e( 'Everything that comes from the theme', 'block-theme-ops' ); ?></strong></label>
				</p>
				<p class="description"><?php esc_html_e( 'Covers every template, template part and the global styles that have a theme file, now and after a deploy adds more. Templates made only in the Site Editor stay open.', 'block-theme-ops' ); ?></p>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Environments where locks are enforced', 'block-theme-ops' ); ?></legend>
					<p><strong><?php esc_html_e( 'Enforce on', 'block-theme-ops' ); ?></strong></p>
					<?php foreach ( Locks::ENVIRONMENTS as $type ) : ?>
						<label class="bto-env"><input type="checkbox" name="environments[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $locks['environments'], true ) ); ?> <?php disabled( ! $can ); ?>> <?php echo esc_html( $type ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'The environment type comes from WP_ENVIRONMENT_TYPE in wp-config.php. Production only is the default.', 'block-theme-ops' ); ?></p>
				</fieldset>
			</section>

			<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Items that can be locked', 'block-theme-ops' ); ?>">
			<table class="wp-list-table widefat fixed striped bto-locks-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column"><span class="screen-reader-text"><?php esc_html_e( 'Locked', 'block-theme-ops' ); ?></span></td>
						<th scope="col" class="column-primary"><?php esc_html_e( 'Item', 'block-theme-ops' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Theme file', 'block-theme-ops' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Site Editor copy', 'block-theme-ops' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php $id = 'bto-lock-' . sanitize_html_class( str_replace( '/', '-', $item['key'] ) ); ?>
						<tr data-key="<?php echo esc_attr( $item['key'] ); ?>">
							<th scope="row" class="check-column">
								<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">
									<?php
									/* translators: %s: item name. */
									echo esc_html( sprintf( __( 'Lock %s', 'block-theme-ops' ), $item['title'] ) );
									?>
								</label>
								<input type="checkbox" name="lock_items[]" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $item['key'] ); ?>" <?php checked( in_array( $item['key'], $locks['items'], true ) || $locks['all'] ); ?> <?php disabled( ! $can || $locks['all'] ); ?>>
							</th>
							<td class="column-primary">
								<strong><?php echo esc_html( $item['title'] ); ?></strong>
								<span class="bto-type"><?php echo esc_html( Drift::type_label( $item['type'] ) ); ?> &middot; <code dir="ltr"><?php echo esc_html( $item['key'] ); ?></code></span>
							</td>
							<td><code dir="ltr"><?php echo esc_html( $item['file'] ); ?></code></td>
							<td>
								<?php if ( isset( $status[ $item['key'] ] ) ) : ?>
									<?php $this->badge( $status[ $item['key'] ] ); ?>
								<?php else : ?>
									<span class="axle-muted bto-muted"><?php esc_html_e( 'None. The theme file is used.', 'block-theme-ops' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p class="description"><?php esc_html_e( 'A lock stops new saves. It does not remove a copy that was saved before: reset it on the Drift tab.', 'block-theme-ops' ); ?></p>
			<?php if ( $can ) : ?>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save locks', 'block-theme-ops' ); ?></button></p>
			<?php endif; ?>
		</form>
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
			<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'Alerts', 'block-theme-ops' ); ?></h2>
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
			</section>

			<section class="axle-card">
			<h2 class="axle-card__title"><?php esc_html_e( 'GitHub pull requests', 'block-theme-ops' ); ?></h2>
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
							<p class="axle-muted bto-token-status">
								<?php
								echo esc_html(
									'constant' === $source
										? __( 'Set in wp-config.php with BLOCK_THEME_OPS_GITHUB_TOKEN.', 'block-theme-ops' )
										: __( 'Set in the server environment as BLOCK_THEME_OPS_GITHUB_TOKEN.', 'block-theme-ops' )
								);
								?>
							</p>
						<?php elseif ( ! Settings::can_encrypt() && 'option' !== $source ) : ?>
							<p class="axle-muted bto-token-status"><?php esc_html_e( 'This server cannot encrypt a token, so none is stored. Define BLOCK_THEME_OPS_GITHUB_TOKEN in wp-config.php instead.', 'block-theme-ops' ); ?></p>
						<?php else : ?>
							<input type="password" id="bto-token" name="token" class="regular-text code" value="" autocomplete="new-password" placeholder="<?php echo 'option' === $source ? esc_attr__( 'Saved. Type a new one to replace it.', 'block-theme-ops' ) : 'github_pat_...'; ?>">
							<?php if ( 'option' === $source ) : ?>
								<p class="axle-muted bto-token-status"><?php esc_html_e( 'A token is saved, encrypted. It is never shown again.', 'block-theme-ops' ); ?></p>
								<label><input type="checkbox" name="remove_token" value="1"> <?php esc_html_e( 'Remove the saved token', 'block-theme-ops' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'A fine-grained token for this one repository, with read and write access to Contents and Pull requests. Better: define BLOCK_THEME_OPS_GITHUB_TOKEN in wp-config.php, so the token is not stored in the database.', 'block-theme-ops' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			</section>
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
			case 'import_upload':
				$this->handle_upload();
				break;
			case 'import_apply':
				$this->handle_import();
				break;
			case 'import_discard':
				$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
				$this->importer->forget( $token );
				$this->done( 'success', __( 'Upload discarded. Nothing was changed.', 'block-theme-ops' ), array( 'tab' => 'import' ) );
				break;
			case 'save_locks':
				$this->handle_locks();
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
		foreach ( $items as $item ) {
			// The capability of the screen is not enough: each post has its own.
			if ( ! current_user_can( 'edit_post', (int) $item['id'] ) ) {
				/* translators: %s: item name. */
				$this->done( 'error', sprintf( __( 'You are not allowed to change %s. Nothing was changed.', 'block-theme-ops' ), $item['title'] ), $back );
				return;
			}
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
		$result = $this->operations->reset_identical( array( Abilities::class, 'can_touch' ) );
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
	 * Undoes a reset or an import.
	 *
	 * @param int   $backup_id Backup ID.
	 * @param array $back      Where to go afterwards.
	 */
	private function handle_undo( int $backup_id, array $back ): void {
		unset( $back['item'] );
		$result = $this->operations->undo_change( $backup_id );
		if ( is_wp_error( $result ) ) {
			$this->done( 'error', $result->get_error_message(), $back );
			return;
		}

		if ( 'import' === $result['kind'] ) {
			$message = sprintf(
				/* translators: %d: number of items. */
				_n( 'Import undone. %d item is back as it was.', 'Import undone. %d items are back as they were.', count( $result['undone'] ), 'block-theme-ops' ),
				count( $result['undone'] )
			);
			if ( $result['skipped'] ) {
				$list = array();
				foreach ( $result['skipped'] as $key => $reason ) {
					$list[] = $key . ': ' . $reason;
				}
				/* translators: %s: list of items and reasons. */
				$message .= ' ' . sprintf( __( 'Left alone: %s', 'block-theme-ops' ), implode( ' ', $list ) );
			}
			$this->done( $result['skipped'] ? 'warning' : 'success', $message, array() );
			return;
		}

		$this->done( 'success', __( 'Reset undone. The database copy is back.', 'block-theme-ops' ), array( 'item' => $result['post_id'] ) );
	}

	/**
	 * Reads an uploaded zip and shows its preview.
	 */
	private function handle_upload(): void {
		$back = array( 'tab' => 'import' );
		if ( ! current_user_can( Importer::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to import.', 'block-theme-ops' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in handle(). Only the error code and the temporary path are read, and the zip is parsed, never run.
		$file = isset( $_FILES['zip'] ) && is_array( $_FILES['zip'] ) ? $_FILES['zip'] : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		$tmp   = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		if ( UPLOAD_ERR_NO_FILE === $error || '' === $tmp ) {
			$this->done( 'error', __( 'Choose a zip file first.', 'block-theme-ops' ), $back );
			return;
		}
		if ( UPLOAD_ERR_OK !== $error || ! is_uploaded_file( $tmp ) ) {
			/* translators: %s: largest upload size. */
			$this->done( 'error', sprintf( __( 'The upload failed. This server accepts files up to %s. Try again, or ask your host to raise the limit.', 'block-theme-ops' ), size_format( wp_max_upload_size() ) ), $back );
			return;
		}

		$payload = $this->importer->read_zip( $tmp );
		if ( is_wp_error( $payload ) ) {
			$this->done( 'error', $payload->get_error_message(), $back );
			return;
		}

		$token = $this->importer->stash( $payload );
		$this->done( 'success', __( 'Zip read. Nothing has changed yet: choose what to import.', 'block-theme-ops' ), array_merge( $back, array( 'review' => $token ) ) );
	}

	/**
	 * Imports the items chosen in the preview.
	 */
	private function handle_import(): void {
		if ( ! current_user_can( Importer::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to import.', 'block-theme-ops' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in handle().
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$keys  = isset( $_POST['keys'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['keys'] ) ) : array();
		$theme = ! empty( $_POST['confirm_theme'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$payload = $this->importer->stashed( $token );
		$back    = array(
			'tab'    => 'import',
			'review' => $token,
		); // phpcs:ignore Universal.WhiteSpace.PrecisionAlignment -- Short inline array.
		if ( null === $payload ) {
			$this->done( 'error', __( 'That upload has expired. Upload the zip again.', 'block-theme-ops' ), array( 'tab' => 'import' ) );
			return;
		}
		if ( ! $keys ) {
			$this->done( 'error', __( 'Choose at least one item to import.', 'block-theme-ops' ), $back );
			return;
		}

		$result = $this->importer->apply(
			$payload,
			$keys,
			array(
				'confirm_theme' => $theme,
				'check_caps'    => true,
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->done( 'error', $result->get_error_message(), $back );
			return;
		}

		$this->importer->forget( $token );
		$count   = count( $result['created'] ) + count( $result['updated'] );
		$message = sprintf(
			/* translators: 1: number of items, 2: number created, 3: number changed. */
			_n( '%1$d item imported (%2$d new, %3$d changed). A backup is kept.', '%1$d items imported (%2$d new, %3$d changed). A backup is kept.', $count, 'block-theme-ops' ),
			$count,
			count( $result['created'] ),
			count( $result['updated'] )
		);
		$type = 'success';
		if ( $result['notes']['navigation'] || $result['notes']['media'] ) {
			$type     = 'warning';
			$message .= ' ' . sprintf(
				/* translators: 1: number of navigation menus, 2: number of media files. */
				__( 'Not on this site: %1$d navigation menu(s) and %2$d media file(s). Upload the zip again after adding them to link them.', 'block-theme-ops' ),
				count( $result['notes']['navigation'] ),
				count( $result['notes']['media'] )
			);
		}

		$this->done( $type, $message, array(), array( 'undo' => $result['backup'] ) );
	}

	/**
	 * Saves the locks.
	 */
	private function handle_locks(): void {
		$back = array( 'tab' => 'locks' );
		if ( ! current_user_can( Locks::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to change the locks.', 'block-theme-ops' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in handle().
		$all   = ! empty( $_POST['lock_all'] );
		$items = isset( $_POST['lock_items'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['lock_items'] ) ) : array();
		$envs  = isset( $_POST['environments'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['environments'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$saved = $this->locks->get();
		$this->locks->update(
			array(
				'all'          => $all,
				// With "everything" ticked the single boxes are disabled and not sent: keep what was chosen before.
				'items'        => $all ? $saved['items'] : $items,
				'environments' => $envs,
			)
		);

		$now = $this->locks->get();
		if ( ! $now['environments'] ) {
			$this->done( 'warning', __( 'Locks saved, but no environment is ticked, so nothing is refused anywhere.', 'block-theme-ops' ), $back );
			return;
		}

		$this->done( 'success', __( 'Locks saved.', 'block-theme-ops' ), $back );
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
		$zip   = $this->exporter->zip( $files, $items );
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
	 * @param string $type    success, warning or error.
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

		$type = in_array( $notice['type'] ?? '', array( 'error', 'warning' ), true ) ? (string) $notice['type'] : 'success';
		// The "inline" class keeps core's script from moving the notice after the page loads: it is printed where it belongs, and a move while someone clicks Undo drops the click.
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> inline is-dismissible bto-notice" data-bto-speak="<?php echo esc_attr( (string) $notice['message'] ); ?>">
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
	 * Prints the confirmation messages admin.js shows in place before a reset or a pull request.
	 */
	private function confirm_attributes(): void {
		$target = $this->exporter->provider()->target();
		printf(
			'data-bto-confirm-reset="%s" data-bto-confirm-pr="%s"',
			esc_attr__( 'Reset to the theme file? The database copy is deleted and the site uses the theme file again. A backup is kept, and you can undo this for 30 days from the Backups tab.', 'block-theme-ops' ),
			esc_attr(
				'' !== $target
					/* translators: %s: repository and branch. */
					? sprintf( __( 'Open a pull request on %s with these changes? This sends the exported files to GitHub. It cannot be undone from here, but nothing is merged.', 'block-theme-ops' ), $target )
					: ''
			)
		);
	}

	/**
	 * The tone of a status: theme changed needs a look, customized is a fact,
	 * database only is not comparable, identical is safe.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function tone( string $status ): string {
		switch ( $status ) {
			case Drift::THEME_CHANGED:
				return 'warning';
			case Drift::CUSTOMIZED:
				return 'info';
			case Drift::IDENTICAL:
				return 'good';
			default:
				return 'neutral';
		}
	}

	/**
	 * Prints a status badge.
	 *
	 * @param array $item Item.
	 */
	private function badge( array $item ): void {
		printf(
			'<span class="axle-badge axle-badge--%s bto-badge bto-badge--%s">%s</span>',
			esc_attr( self::tone( $item['status'] ) ),
			esc_attr( $item['status'] ),
			esc_html( Drift::status_label( $item['status'] ) )
		);
		if ( $item['probable'] ) {
			echo ' <span class="axle-muted bto-probable" title="' . esc_attr__( 'Saved before Block Theme Ops was active: a guess from file dates.', 'block-theme-ops' ) . '">' . esc_html__( 'probably', 'block-theme-ops' ) . '</span>';
		}
		if ( $item['kept'] ) {
			echo ' <span class="axle-badge axle-badge--neutral bto-badge bto-badge--kept">' . esc_html__( 'Kept', 'block-theme-ops' ) . '</span>';
		}
		if ( $this->locks->is_locked( (string) $item['type'], (string) $item['slug'] ) ) {
			echo ' <span class="axle-badge axle-badge--info bto-badge bto-badge--locked" title="' . esc_attr__( 'Saves from the Site Editor are refused on the environments you chose.', 'block-theme-ops' ) . '">' . esc_html__( 'Locked', 'block-theme-ops' ) . '</span>';
		}
	}

	/**
	 * Prints the theme file path, noting a parent theme.
	 *
	 * @param array $item Item.
	 */
	private function file_cell( array $item ): void {
		if ( '' === $item['file'] ) {
			echo '<span class="axle-muted bto-muted">' . esc_html__( 'None', 'block-theme-ops' ) . '</span>';
			return;
		}

		echo '<code dir="ltr">' . esc_html( $item['file'] ) . '</code>';
		if ( $item['file_theme'] !== $item['theme'] ) {
			/* translators: %s: parent theme folder name. */
			echo '<br><span class="axle-muted bto-muted">' . esc_html( sprintf( __( 'in the parent theme, %s', 'block-theme-ops' ), $item['file_theme'] ) ) . '</span>';
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
	 * The tags and attributes AxleUi::relative_time() prints.
	 *
	 * @return array
	 */
	public static function time_tags(): array {
		return array(
			'time' => array(
				'class'    => true,
				'datetime' => true,
				'title'    => true,
			),
			'span' => array( 'class' => true ),
		);
	}

	/**
	 * What to ask before resetting every identical item.
	 *
	 * @param int $count Number of items.
	 * @return string
	 */
	private function reset_identical_message( int $count ): string {
		return sprintf(
			/* translators: %d: number of items. */
			_n( 'Reset %d item that matches its theme file? Nothing changes on the site. The database copy is backed up first and you can undo this for 30 days.', 'Reset all %d items that match their theme files? Nothing changes on the site. The database copies are backed up first and you can undo this for 30 days.', $count, 'block-theme-ops' ),
			$count
		);
	}
}
