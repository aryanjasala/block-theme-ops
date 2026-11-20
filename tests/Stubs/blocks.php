<?php
/**
 * parse_blocks(), serialize_blocks() and wp_json_encode() for unit tests, built on the
 * plugin's own tokenizer. They give the same tree shape as core's parser for the
 * markup the merge fixtures use.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

// phpcs:ignoreFile

use AryanX\Axle\BlockThemeOps\Markup;

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $markup ) {
		$tokens = Markup::tokens( (string) $markup );
		$stack  = array();
		$root   = array();
		$new    = static function ( $name, $attrs ) {
			return array(
				'blockName'    => $name,
				'attrs'        => is_array( $attrs ) ? $attrs : array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		};
		$add    = static function ( array $block ) use ( &$stack, &$root ) {
			if ( $stack ) {
				$top = count( $stack ) - 1;
				$stack[ $top ]['innerBlocks'][]  = $block;
				$stack[ $top ]['innerContent'][] = null;
			} else {
				$root[] = $block;
			}
		};
		$html   = static function ( $text ) use ( &$stack, &$root, $new ) {
			if ( $stack ) {
				$top = count( $stack ) - 1;
				$stack[ $top ]['innerHTML']      .= $text;
				$stack[ $top ]['innerContent'][] = $text;
			} elseif ( '' !== $text ) {
				$block                = $new( null, array() );
				$block['innerHTML']   = $text;
				$block['innerContent'] = array( $text );
				$root[]               = $block;
			}
		};

		foreach ( $tokens as $token ) {
			if ( 'html' === $token['type'] ) {
				$html( $token['html'] );
			} elseif ( 'void' === $token['type'] ) {
				$add( $new( $token['name'], $token['attrs'] ) );
			} elseif ( 'open' === $token['type'] ) {
				$stack[] = $new( $token['name'], $token['attrs'] );
			} elseif ( $stack ) {
				$block = array_pop( $stack );
				$add( $block );
			}
		}

		return $root;
	}
}

if ( ! function_exists( 'serialize_block' ) ) {
	function serialize_block( $block ) {
		$name  = (string) $block['blockName'];
		$short = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		if ( '' === $name ) {
			return (string) $block['innerHTML'];
		}
		$attrs = $block['attrs'] ? ' ' . Markup::encode_attrs( $block['attrs'] ) : '';
		$out   = '';
		$i     = 0;
		foreach ( $block['innerContent'] as $chunk ) {
			if ( null === $chunk ) {
				$out .= serialize_block( $block['innerBlocks'][ $i++ ] );
			} else {
				$out .= $chunk;
			}
		}
		if ( ! $block['innerContent'] ) {
			return "<!-- wp:{$short}{$attrs} /-->";
		}

		return "<!-- wp:{$short}{$attrs} -->{$out}<!-- /wp:{$short} -->";
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( $blocks ) {
		return implode( '', array_map( 'serialize_block', $blocks ) );
	}
}
