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
 * A saved token is encrypted with a key made from the site's salts, so a
 * copy of the database alone does not give it away. It is never returned by
 * the REST API or abilities.
 */
class Settings {

	public const OPTION = 'block_theme_ops_settings';

	public const TOKEN_NAME = 'BLOCK_THEME_OPS_GITHUB_TOKEN';

	/**
	 * Prefix of a stored token that is encrypted.
	 */
	private const ENCRYPTED = 'enc1:';

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
			'token'  => self::decrypt( (string) $all['token'] ),
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

		if ( '' !== $all['token'] ) {
			// With no way to encrypt it, the token is not stored: use the constant instead.
			$all['token'] = self::can_encrypt() ? self::encrypt( $all['token'] ) : '';
		}

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Encrypts a token saved in plain text by an earlier version. Runs on admin_init, and only writes when there is something to change.
	 */
	public function protect_token(): void {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) || empty( $saved['token'] ) || 0 === strpos( (string) $saved['token'], self::ENCRYPTED ) ) {
			return;
		}

		$this->update( array( 'token' => (string) $saved['token'] ) );
	}

	/**
	 * Whether this server can encrypt a token to store it.
	 *
	 * @return bool
	 */
	public static function can_encrypt(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) || ( function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' ) );
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

	/**
	 * Encrypts a secret with a key made from the site's salts.
	 *
	 * @param string $plain Secret.
	 * @return string `enc1:` and base64 text.
	 */
	private static function encrypt( string $plain ): string {
		$key = hash( 'sha256', wp_salt( 'auth' ) . self::TOKEN_NAME, true );

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			return self::ENCRYPTED . 's' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text, not obfuscation.
		}

		$iv     = random_bytes( 16 );
		$cipher = (string) openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$mac    = hash_hmac( 'sha256', $iv . $cipher, $key, true );

		return self::ENCRYPTED . 'o' . base64_encode( $mac . $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text, not obfuscation.
	}

	/**
	 * Reads a stored token. Text without the prefix is a token saved in plain text by an earlier version.
	 *
	 * @param string $stored Stored value.
	 * @return string Empty when it cannot be decrypted, for example after the salts changed.
	 */
	private static function decrypt( string $stored ): string {
		if ( 0 !== strpos( $stored, self::ENCRYPTED ) ) {
			return $stored;
		}

		$mode = substr( $stored, strlen( self::ENCRYPTED ), 1 );
		$raw  = base64_decode( substr( $stored, strlen( self::ENCRYPTED ) + 1 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary from text, not obfuscation.
		$key  = hash( 'sha256', wp_salt( 'auth' ) . self::TOKEN_NAME, true );
		if ( false === $raw ) {
			return '';
		}

		if ( 's' === $mode && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $raw ) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );

			return false === $plain ? '' : $plain;
		}

		if ( 'o' === $mode && function_exists( 'openssl_decrypt' ) && strlen( $raw ) > 48 ) {
			$mac    = substr( $raw, 0, 32 );
			$iv     = substr( $raw, 32, 16 );
			$cipher = substr( $raw, 48 );
			if ( ! hash_equals( $mac, hash_hmac( 'sha256', $iv . $cipher, $key, true ) ) ) {
				return '';
			}
			$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			return false === $plain ? '' : $plain;
		}

		return '';
	}
}
