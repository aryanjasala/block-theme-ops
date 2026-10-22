<?php
/**
 * Block markup helpers: compare, pretty-print and clean template markup.
 *
 * @package AryanX\BlockThemeOps
 */

namespace AryanX\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Works on serialized block markup as text, so it gives the same answer on
 * every WordPress version and needs no parser.
 *
 * The Site Editor saves template parts with a `theme` attribute that theme
 * files leave out, writes block attributes in its own order and spaces HTML
 * its own way. None of that changes what the template shows, so comparisons
 * ignore it.
 */
final class Markup {

	/**
	 * Block delimiter, the same pattern core's block parser uses.
	 */
	private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Splits markup into block delimiters and the HTML between them.
	 *
	 * @param string $markup Block markup.
	 * @return array[] Each: type (open, close, void or html), name, attrs (array|null) and html.
	 */
	public static function tokens( string $markup ): array {
		$tokens = array();
		$offset = 0;

		if ( preg_match_all( self::DELIMITER, $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$start = (int) $match[0][1];
				if ( $start > $offset ) {
					$tokens[] = self::html( substr( $markup, $offset, $start - $offset ) );
				}

				$namespace = '' !== self::group( $match, 'namespace' ) ? self::group( $match, 'namespace' ) : 'core/';
				$attrs     = null;
				if ( '' !== trim( self::group( $match, 'attrs' ) ) ) {
					$decoded = json_decode( trim( self::group( $match, 'attrs' ) ), true );
					$attrs   = is_array( $decoded ) ? $decoded : null;
				}

				if ( '' !== self::group( $match, 'closer' ) ) {
					$type = 'close';
				} elseif ( '' !== self::group( $match, 'void' ) ) {
					$type = 'void';
				} else {
					$type = 'open';
				}

				$tokens[] = array(
					'type'  => $type,
					'name'  => $namespace . $match['name'][0],
					'attrs' => $attrs,
					'html'  => '',
				);
				$offset   = $start + strlen( $match[0][0] );
			}
		}

		if ( $offset < strlen( $markup ) ) {
			$tokens[] = self::html( substr( $markup, $offset ) );
		}

		return $tokens;
	}

	/**
	 * Markup reduced to what changes the output, for comparing two copies.
	 *
	 * @param string $markup Block markup.
	 * @return string
	 */
	public static function normalize( string $markup ): string {
		$lines = array();
		foreach ( self::tokens( $markup ) as $token ) {
			$line = self::render( $token );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Whether two copies of a template show the same thing.
	 *
	 * @param string $a Block markup.
	 * @param string $b Block markup.
	 * @return bool
	 */
	public static function same( string $a, string $b ): bool {
		return self::normalize( $a ) === self::normalize( $b );
	}

	/**
	 * Markup with one block delimiter per line, indented by nesting, for diffs.
	 *
	 * @param string $markup Block markup.
	 * @param string $indent One level of indentation.
	 * @return string
	 */
	public static function pretty( string $markup, string $indent = "\t" ): string {
		$lines = array();
		$depth = 0;

		foreach ( self::tokens( $markup ) as $token ) {
			if ( 'close' === $token['type'] ) {
				$depth = max( 0, $depth - 1 );
			}

			$prefix = str_repeat( $indent, $depth );
			if ( 'html' === $token['type'] ) {
				foreach ( explode( "\n", (string) preg_replace( '/>\s*</', ">\n<", self::render( $token ) ) ) as $line ) {
					if ( '' !== trim( $line ) ) {
						$lines[] = $prefix . trim( $line );
					}
				}
			} else {
				$lines[] = $prefix . self::render( $token );
			}

			if ( 'open' === $token['type'] ) {
				++$depth;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Removes the `theme` attribute the Site Editor adds to template part
	 * blocks, so the markup works in any theme folder. Everything else stays
	 * byte for byte.
	 *
	 * @param string $markup Block markup.
	 * @return string
	 */
	public static function strip_theme_attribute( string $markup ): string {
		return (string) preg_replace_callback(
			self::DELIMITER,
			static function ( array $found ): string {
				$name = ( '' !== $found['namespace'] ? $found['namespace'] : '' ) . $found['name'];
				if ( ! in_array( $name, array( 'template-part', 'core/template-part' ), true ) || ! isset( $found['attrs'] ) || '' === trim( $found['attrs'] ) ) {
					return $found[0];
				}

				$attrs = json_decode( trim( $found['attrs'] ), true );
				if ( ! is_array( $attrs ) || ! array_key_exists( 'theme', $attrs ) ) {
					return $found[0];
				}

				unset( $attrs['theme'] );
				$void = ! empty( $found['void'] ) ? '/' : '';

				return $attrs
					? '<!-- wp:' . $name . ' ' . self::encode_attrs( $attrs ) . ' ' . $void . '-->'
					: '<!-- wp:' . $name . ' ' . $void . '-->';
			},
			$markup
		);
	}

	/**
	 * Hash of a template file that ignores whitespace, so reformatting a file
	 * does not count as a change.
	 *
	 * @param string $markup Block markup.
	 * @return string
	 */
	public static function hash( string $markup ): string {
		return md5( trim( (string) preg_replace( '/\s+/', ' ', $markup ) ) );
	}

	/**
	 * Encodes block attributes the way core's serializer does.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function encode_attrs( array $attrs ): string {
		$json = (string) wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return str_replace(
			array( '--', '<', '>', '&', '\\"' ),
			array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022' ),
			$json
		);
	}

	/**
	 * One named group of a match made with PREG_OFFSET_CAPTURE, or empty when
	 * it did not take part.
	 *
	 * @param array  $found Match.
	 * @param string $name  Group name.
	 * @return string
	 */
	private static function group( array $found, string $name ): string {
		return isset( $found[ $name ][0] ) && is_string( $found[ $name ][0] ) ? $found[ $name ][0] : '';
	}

	/**
	 * An HTML token.
	 *
	 * @param string $html HTML between delimiters.
	 * @return array
	 */
	private static function html( string $html ): array {
		return array(
			'type'  => 'html',
			'name'  => '',
			'attrs' => null,
			'html'  => $html,
		);
	}

	/**
	 * One token in canonical form: sorted attributes without the template
	 * part `theme`, and HTML with whitespace collapsed.
	 *
	 * @param array $token Token from tokens().
	 * @return string
	 */
	private static function render( array $token ): string {
		if ( 'html' === $token['type'] ) {
			$html = (string) preg_replace( '/\s+/', ' ', $token['html'] );

			return trim( (string) preg_replace( '/>\s+</', '><', $html ) );
		}

		if ( 'close' === $token['type'] ) {
			return '<!-- /wp:' . self::short_name( $token['name'] ) . ' -->';
		}

		$attrs = is_array( $token['attrs'] ) ? $token['attrs'] : array();
		if ( 'core/template-part' === $token['name'] ) {
			unset( $attrs['theme'] );
		}
		$attrs = self::sort( $attrs );

		$line = '<!-- wp:' . self::short_name( $token['name'] ) . ' ';
		if ( $attrs ) {
			$line .= self::encode_attrs( $attrs ) . ' ';
		}

		return $line . ( 'void' === $token['type'] ? '/-->' : '-->' );
	}

	/**
	 * Block name as written in markup: core blocks without `core/`.
	 *
	 * @param string $name Full block name.
	 * @return string
	 */
	private static function short_name( string $name ): string {
		return 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
	}

	/**
	 * Sorts object keys at every level, leaving lists in their order.
	 *
	 * @param array $value Attributes.
	 * @return array
	 */
	private static function sort( array $value ): array {
		foreach ( $value as $key => $child ) {
			if ( is_array( $child ) ) {
				$value[ $key ] = self::sort( $child );
			}
		}

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}
}
