<?php
/**
 * Constants PHPStan needs to know about. Each one is optional and set in
 * wp-config.php, so the code only reads it behind defined().
 *
 * @package AryanX\BlockThemeOps
 */

defined( 'BLOCK_THEME_OPS_GITHUB_TOKEN' ) || define( 'BLOCK_THEME_OPS_GITHUB_TOKEN', '' );

// Core's include folder, defined by wp-settings.php.
defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );
