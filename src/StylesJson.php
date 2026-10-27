<?php
/**
 * Global styles helpers: the user layer on top of theme.json.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Global styles changes are not a copy of theme.json: they are a layer of
 * values on top of it. Only the paths the layer sets hide the theme's
 * values, so drift is about those paths.
 */
final class StylesJson {

	/**
	 * Top-level keys of the user layer that change what the site shows.
	 */
	private const LAYER_KEYS = array( 'styles', 'settings' );

	/**
	 * Decodes JSON to an array, empty when it is not a JSON object.
	 *
	 * @param string $json JSON text.
	 * @return array
	 */
	public static function decode( string $json ): array {
		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * The parts of a user layer that set values: `styles` and `settings`,
	 * without empty branches.
	 *
	 * @param array $user Decoded user layer.
	 * @return array
	 */
	public static function changes( array $user ): array {
		$changes = array();
		foreach ( self::LAYER_KEYS as $key ) {
			if ( isset( $user[ $key ] ) && is_array( $user[ $key ] ) ) {
				$pruned = self::prune( $user[ $key ] );
				if ( array() !== $pruned ) {
					$changes[ $key ] = $pruned;
				}
			}
		}

		return $changes;
	}

	/**
	 * Theme data with the user layer applied, the way the site sees it.
	 * Lists, such as palettes, are replaced whole, as core does.
	 *
	 * @param array $theme Decoded theme.json.
	 * @param array $user  Decoded user layer.
	 * @return array
	 */
	public static function merge( array $theme, array $user ): array {
		return self::merge_values( $theme, self::changes( $user ) );
	}

	/**
	 * Whether the user layer sets nothing that differs from theme.json.
	 *
	 * @param array $theme Decoded theme.json.
	 * @param array $user  Decoded user layer.
	 * @return bool
	 */
	public static function is_noop( array $theme, array $user ): bool {
		$merged = self::flatten( self::merge( $theme, $user ) );
		$own    = self::flatten( $theme );
		ksort( $merged );
		ksort( $own );

		return $merged === $own;
	}

	/**
	 * Whether the theme changed any value the user layer overrides.
	 *
	 * @param array $before Decoded theme.json when the layer was saved.
	 * @param array $after  Decoded theme.json now.
	 * @param array $user   Decoded user layer.
	 * @return bool
	 */
	public static function overlaps( array $before, array $after, array $user ): bool {
		$old     = self::flatten( $before );
		$new     = self::flatten( $after );
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $path ) {
			if ( ! array_key_exists( $path, $old ) || ! array_key_exists( $path, $new ) || $old[ $path ] !== $new[ $path ] ) {
				$changed[] = (string) $path;
			}
		}

		foreach ( array_keys( self::flatten( self::changes( $user ) ) ) as $set ) {
			foreach ( $changed as $path ) {
				if ( self::related( (string) $set, $path ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Leaf values by dotted path. Lists count as one value.
	 *
	 * @param array  $data   Decoded JSON.
	 * @param string $prefix Path so far.
	 * @return array<string, mixed>
	 */
	public static function flatten( array $data, string $prefix = '' ): array {
		$flat = array();
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) && self::is_object( $value ) ) {
				$flat += self::flatten( $value, $path );
			} else {
				$flat[ $path ] = $value;
			}
		}

		return $flat;
	}

	/**
	 * Tab-indented JSON with unescaped slashes, like theme.json files.
	 *
	 * @param array  $data   Data.
	 * @param string $indent One level of indentation.
	 * @return string
	 */
	public static function pretty( array $data, string $indent = "\t" ): string {
		$json = (string) wp_json_encode( $data ? $data : new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return (string) preg_replace_callback(
			'/^(?: {4})+/m',
			static function ( array $found ) use ( $indent ): string {
				return str_repeat( $indent, (int) ( strlen( $found[0] ) / 4 ) );
			},
			$json
		);
	}

	/**
	 * Hash of a JSON file that ignores formatting.
	 *
	 * @param string $json JSON text.
	 * @return string
	 */
	public static function hash( string $json ): string {
		$data = json_decode( $json, true );

		return md5( null === $data ? trim( $json ) : (string) wp_json_encode( $data ) );
	}

	/**
	 * A user layer with no changes, the way core stores a reset.
	 *
	 * @param array $user The layer being reset, for its schema version.
	 * @return string
	 */
	public static function empty_layer( array $user ): string {
		return (string) wp_json_encode(
			array(
				'version'                     => isset( $user['version'] ) ? (int) $user['version'] : 3,
				'isGlobalStylesUserThemeJSON' => true,
			)
		);
	}

	/**
	 * Whether one path is the same as, or inside, the other.
	 *
	 * @param string $a Dotted path.
	 * @param string $b Dotted path.
	 * @return bool
	 */
	private static function related( string $a, string $b ): bool {
		return $a === $b || 0 === strpos( $a, $b . '.' ) || 0 === strpos( $b, $a . '.' );
	}

	/**
	 * Recursive merge where objects merge and everything else is replaced.
	 *
	 * @param array $base    Base values.
	 * @param array $changes Values that win.
	 * @return array
	 */
	private static function merge_values( array $base, array $changes ): array {
		foreach ( $changes as $key => $value ) {
			if ( is_array( $value ) && self::is_object( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && self::is_object( $base[ $key ] ) ) {
				$base[ $key ] = self::merge_values( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Drops empty objects and lists, which set nothing.
	 *
	 * @param array $data Decoded JSON.
	 * @return array
	 */
	private static function prune( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) && self::is_object( $value ) ) {
				$value = self::prune( $value );
				if ( array() === $value ) {
					unset( $data[ $key ] );
					continue;
				}
				$data[ $key ] = $value;
			} elseif ( array() === $value ) {
				unset( $data[ $key ] );
			}
		}

		return $data;
	}

	/**
	 * Whether an array is a JSON object rather than a list.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_object( array $value ): bool {
		return array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}
}
