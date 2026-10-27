<?php
/**
 * Saved settings.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's settings option: the email alert and the
 * GitHub repository. The token is never shown again once saved, and the
 * BLOCK_THEME_OPS_GITHUB_TOKEN constant or environment variable wins over it.
 */
class Settings {

	public const OPTION = 'block_theme_ops_settings';

	public const TOKEN_NAME = 'BLOCK_THEME_OPS_GITHUB_TOKEN';

	private const DEFAULTS = array(
		'notify' => false,
		'repo'   => '',
		'branch' => 'main',
		'path'   => '',
		'token'  => '',
	);

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array{notify: bool, repo: string, branch: string, path: string, token: string}
	 */
	public function all(): array {
		$saved = get_option( self::OPTION, array() );
		$all   = array_merge( self::DEFAULTS, is_array( $saved ) ? array_intersect_key( $saved, self::DEFAULTS ) : array() );

		return array(
			'notify' => (bool) $all['notify'],
			'repo'   => (string) $all['repo'],
			'branch' => '' !== (string) $all['branch'] ? (string) $all['branch'] : 'main',
			'path'   => (string) $all['path'],
			'token'  => (string) $all['token'],
		);
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Changes some settings and keeps the rest. Values are cleaned first;
	 * an invalid repository or branch is stored empty.
	 *
	 * @param array $changes Setting name => value.
	 */
	public function update( array $changes ): void {
		$all = $this->all();
		foreach ( array_intersect_key( $changes, self::DEFAULTS ) as $key => $value ) {
			switch ( $key ) {
				case 'notify':
					$all['notify'] = (bool) $value;
					break;
				case 'repo':
					$all['repo'] = self::clean_repo( (string) $value );
					break;
				case 'branch':
					$all['branch'] = self::clean_ref( (string) $value );
					break;
				case 'path':
					$all['path'] = self::clean_path( (string) $value );
					break;
				case 'token':
					$all['token'] = trim( (string) $value );
					break;
			}
		}

		update_option( self::OPTION, $all, false );
	}

	/**
	 * The GitHub token in use: the constant, then the environment variable, then the saved one.
	 *
	 * @return string
	 */
	public function token(): string {
		if ( defined( self::TOKEN_NAME ) && '' !== (string) constant( self::TOKEN_NAME ) ) {
			return (string) constant( self::TOKEN_NAME );
		}

		$env = getenv( self::TOKEN_NAME );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}

		return (string) $this->get( 'token' );
	}

	/**
	 * Where the token comes from: constant, environment, option, or empty for none.
	 *
	 * @return string
	 */
	public function token_source(): string {
		if ( defined( self::TOKEN_NAME ) && '' !== (string) constant( self::TOKEN_NAME ) ) {
			return 'constant';
		}

		$env = getenv( self::TOKEN_NAME );
		if ( is_string( $env ) && '' !== $env ) {
			return 'environment';
		}

		return '' !== (string) $this->get( 'token' ) ? 'option' : '';
	}

	/**
	 * `owner/repo`, also taken from a pasted github.com address. Empty when invalid.
	 *
	 * @param string $repo Input.
	 * @return string
	 */
	public static function clean_repo( string $repo ): string {
		$repo = trim( $repo );
		$repo = (string) preg_replace( '#^(?:https?://)?(?:www\.)?github\.com/#i', '', $repo );
		$repo = (string) preg_replace( '#\.git$#', '', trim( $repo, '/' ) );

		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	/**
	 * A Git branch name, or empty when Git would refuse it.
	 *
	 * @param string $ref Branch name.
	 * @return string
	 */
	public static function clean_ref( string $ref ): string {
		$ref = trim( $ref );
		if ( '' === $ref || ! preg_match( '#^[A-Za-z0-9._/-]+$#', $ref ) || false !== strpos( $ref, '..' ) || false !== strpos( $ref, '//' ) ) {
			return '';
		}
		if ( '/' === $ref[0] || '/' === substr( $ref, -1 ) || '.' === substr( $ref, -1 ) || '.lock' === substr( $ref, -5 ) ) {
			return '';
		}

		return $ref;
	}

	/**
	 * Folder of the theme inside the repository, without leading or trailing slashes.
	 *
	 * @param string $path Input.
	 * @return string
	 */
	public static function clean_path( string $path ): string {
		$path = trim( str_replace( '\\', '/', trim( $path ) ), '/' );
		if ( '' === $path || ! preg_match( '#^[A-Za-z0-9_.@/-]+$#', $path ) || preg_match( '#(^|/)\.\.?(/|$)#', $path ) ) {
			return '';
		}

		return (string) preg_replace( '#/+#', '/', $path );
	}
}
