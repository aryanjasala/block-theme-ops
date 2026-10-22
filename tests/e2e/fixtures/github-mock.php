<?php
/**
 * A stand-in for the GitHub REST API, for the end-to-end tests only. It is
 * never part of the plugin zip.
 *
 * The tests point Block Theme Ops at this file through the
 * block_theme_ops_github_api_url filter. It logs every request to
 * wp-content/bto-github-mock.json and answers with canned responses.
 * The token `test-token` is accepted; anything else gets a 401.
 *
 * @package AryanX\BlockThemeOps
 */

// phpcs:ignoreFile

$log_file = dirname( __DIR__, 5 ) . '/bto-github-mock.json';
$method   = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$path     = isset( $_SERVER['PATH_INFO'] ) ? $_SERVER['PATH_INFO'] : '';
$headers  = function_exists( 'getallheaders' ) ? array_change_key_case( getallheaders(), CASE_LOWER ) : array();
$auth     = isset( $headers['authorization'] ) ? $headers['authorization'] : ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '' );
$body     = json_decode( (string) file_get_contents( 'php://input' ), true );

$log   = is_readable( $log_file ) ? json_decode( (string) file_get_contents( $log_file ), true ) : array();
$log   = is_array( $log ) ? $log : array();
$log[] = array(
	'method' => $method,
	'path'   => $path,
	'auth'   => $auth,
	'body'   => $body,
);
file_put_contents( $log_file, json_encode( $log ) );

header( 'Content-Type: application/json' );

function bto_mock_reply( $status, $data ) {
	http_response_code( $status );
	echo json_encode( $data );
	exit;
}

if ( 'Bearer test-token' !== $auth ) {
	bto_mock_reply( 401, array( 'message' => 'Bad credentials' ) );
}

if ( 'GET' === $method && preg_match( '#^/repos/[^/]+/[^/]+/git/ref/heads/(.+)$#', $path, $m ) ) {
	if ( 'main' !== $m[1] ) {
		bto_mock_reply( 404, array( 'message' => 'Not Found' ) );
	}
	bto_mock_reply( 200, array( 'ref' => 'refs/heads/main', 'object' => array( 'sha' => 'base000', 'type' => 'commit' ) ) );
}
if ( 'GET' === $method && preg_match( '#^/repos/[^/]+/[^/]+/git/commits/base000$#', $path ) ) {
	bto_mock_reply( 200, array( 'sha' => 'base000', 'tree' => array( 'sha' => 'tree000' ) ) );
}
if ( 'POST' === $method && preg_match( '#^/repos/[^/]+/[^/]+/git/trees$#', $path ) ) {
	bto_mock_reply( 201, array( 'sha' => 'tree111' ) );
}
if ( 'POST' === $method && preg_match( '#^/repos/[^/]+/[^/]+/git/commits$#', $path ) ) {
	bto_mock_reply( 201, array( 'sha' => 'commit111' ) );
}
if ( 'POST' === $method && preg_match( '#^/repos/[^/]+/[^/]+/git/refs$#', $path ) ) {
	bto_mock_reply( 201, array( 'ref' => isset( $body['ref'] ) ? $body['ref'] : '' ) );
}
if ( 'POST' === $method && preg_match( '#^/repos/([^/]+)/([^/]+)/pulls$#', $path, $m ) ) {
	bto_mock_reply( 201, array( 'number' => 42, 'html_url' => 'https://github.com/' . $m[1] . '/' . $m[2] . '/pull/42' ) );
}

bto_mock_reply( 404, array( 'message' => 'Not Found' ) );
