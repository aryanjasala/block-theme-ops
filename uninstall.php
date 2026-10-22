<?php
/**
 * Removes Block Theme Ops settings, alerts, snapshots, backups and its scheduled check, on every site.
 *
 * Site Editor changes themselves stay: they belong to WordPress, not to this plugin.
 *
 * @package BlockThemeOps
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Block Theme Ops data from the current site.
 */
function block_theme_ops_uninstall_site(): void {
	delete_option( 'block_theme_ops_settings' );
	delete_option( 'block_theme_ops_alerts' );
	wp_clear_scheduled_hook( 'block_theme_ops_check' );

	foreach ( array( '_block_theme_ops_snapshot', '_block_theme_ops_kept', '_block_theme_ops_editor', '_block_theme_ops_notified' ) as $block_theme_ops_key ) {
		delete_post_meta_by_key( $block_theme_ops_key );
	}

	// Backups, a batch at a time. Their meta goes with them.
	do {
		$block_theme_ops_backups = get_posts(
			array(
				'post_type'        => 'bto_backup',
				'post_status'      => 'any',
				'posts_per_page'   => 100,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		foreach ( $block_theme_ops_backups as $block_theme_ops_backup ) {
			wp_delete_post( (int) $block_theme_ops_backup, true );
		}
	} while ( $block_theme_ops_backups );
}

if ( is_multisite() ) {
	$block_theme_ops_offset = 0;
	do {
		$block_theme_ops_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $block_theme_ops_offset,
			)
		);

		foreach ( $block_theme_ops_sites as $block_theme_ops_site ) {
			switch_to_blog( $block_theme_ops_site );
			block_theme_ops_uninstall_site();
			restore_current_blog();
		}

		$block_theme_ops_offset += 100;
	} while ( $block_theme_ops_sites );
} else {
	block_theme_ops_uninstall_site();
}
