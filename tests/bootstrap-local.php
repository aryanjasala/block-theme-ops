<?php
/**
 * Stubs for Block Theme Ops unit tests that the shared template does not carry.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

// phpcs:ignoreFile

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'unit-test-salt-' . $scheme;
	}
}

foreach ( array(
	'KB_IN_BYTES' => 1024,
	'MB_IN_BYTES' => 1048576,
) as $name => $value ) {
	defined( $name ) || define( $name, $value );
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {}
}
