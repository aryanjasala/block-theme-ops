<?php
/**
 * Three-way merge of block trees.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps;

defined( 'ABSPATH' ) || exit;

/**
 * Merges a theme update into a customized template, block by block.
 *
 * Pure: it takes three `parse_blocks()` trees and returns a tree, so it needs
 * no WordPress and no database.
 *
 * - base: the theme file as it was when the site copy was saved.
 * - ours: the site copy in the database.
 * - theirs: the theme file now.
 *
 * Blocks have no IDs, so they are matched by name and attributes, then by
 * name and position between blocks that did match. A change that only one
 * side made is taken. A block that both sides changed differently, or that
 * one side changed and the other removed, is a conflict with two choices
 * (`ours` or `theirs`). Until a choice is made, the site's own version stays.
 */
final class BlockMerge {

	public const OURS   = 'ours';
	public const THEIRS = 'theirs';

	/**
	 * Conflict kinds.
	 */
	public const CHANGED       = 'changed';
	public const REMOVED_THEIR = 'removed-by-theme';
	public const REMOVED_OUR   = 'removed-by-site';
	public const ORDER         = 'order';

	/**
	 * What the merge has recorded so far.
	 *
	 * @var array<int, array>
	 */
	private array $conflicts = array();

	/**
	 * Changes taken from the theme without asking.
	 *
	 * @var array<int, array>
	 */
	private array $applied = array();

	/**
	 * Choices made for conflicts, by conflict ID.
	 *
	 * @var array<string, string>
	 */
	private array $choices = array();

	/**
	 * Merges three block trees.
	 *
	 * @param array[]               $base    Tree of the theme file at edit time.
	 * @param array[]               $ours    Tree of the database copy.
	 * @param array[]               $theirs  Tree of the theme file now.
	 * @param array<string, string> $choices Conflict ID => `ours` or `theirs`.
	 * @return array{blocks: array[], conflicts: array[], applied: array[], resolved: bool}
	 */
	public static function merge( array $base, array $ours, array $theirs, array $choices = array() ): array {
		$merger          = new self();
		$merger->choices = array_filter(
			$choices,
			static function ( $choice ): bool {
				return self::OURS === $choice || self::THEIRS === $choice;
			}
		);

		$blocks = $merger->merge_list( self::clean( $base ), self::clean( $ours ), self::clean( $theirs ), '' );

		$unresolved = array_filter(
			$merger->conflicts,
			static function ( array $conflict ): bool {
				return null === $conflict['choice'];
			}
		);

		return array(
			'blocks'    => $blocks,
			'conflicts' => $merger->conflicts,
			'applied'   => $merger->applied,
			'resolved'  => ! $unresolved,
		);
	}

	/**
	 * A hash of a block that ignores formatting, the template part `theme`
	 * attribute the Site Editor adds, and attribute order.
	 *
	 * @param array $block Block.
	 * @return string
	 */
	public static function hash( array $block ): string {
		return md5( (string) wp_json_encode( self::canonical( $block, true ) ) );
	}

	/**
	 * Drops the empty freeform blocks `parse_blocks()` puts between blocks.
	 *
	 * @param array[] $blocks Blocks.
	 * @return array[] Re-indexed.
	 */
	public static function clean( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) && empty( $block['innerBlocks'] ) ) {
				continue;
			}
			$block['innerBlocks'] = self::clean( (array) ( $block['innerBlocks'] ?? array() ) );
			$out[]                = $block;
		}

		return $out;
	}

	/**
	 * Merges the children of one parent.
	 *
	 * @param array[] $base   Base children.
	 * @param array[] $ours   Our children.
	 * @param array[] $theirs Their children.
	 * @param string  $path   Path of the parent, in base positions.
	 * @return array[]
	 */
	private function merge_list( array $base, array $ours, array $theirs, string $path ): array {
		$to_ours   = self::align( $base, $ours );
		$to_theirs = self::align( $base, $theirs );

		// Which side keeps the order: the one that did not move blocks around.
		$ours_moved   = self::moved( $to_ours );
		$theirs_moved = self::moved( $to_theirs );
		$primary      = self::OURS;
		$order_id     = 'o' . ( '' === $path ? '' : $path );
		if ( $theirs_moved && ! $ours_moved ) {
			$primary = self::THEIRS;
		} elseif ( $theirs_moved && $ours_moved ) {
			$same = self::order_of( $to_ours ) === self::order_of( $to_theirs );
			if ( ! $same ) {
				$this->conflicts[ $order_id ] = array(
					'id'     => $order_id,
					'kind'   => self::ORDER,
					'path'   => $path,
					'name'   => '',
					'base'   => null,
					'ours'   => null,
					'theirs' => null,
					'choice' => $this->choices[ $order_id ] ?? null,
				);
				if ( self::THEIRS === ( $this->choices[ $order_id ] ?? null ) ) {
					$primary = self::THEIRS;
				}
			}
		}

		$p_list = self::OURS === $primary ? $ours : $theirs;
		$s_list = self::OURS === $primary ? $theirs : $ours;
		$p_map  = self::OURS === $primary ? $to_ours : $to_theirs;
		$s_map  = self::OURS === $primary ? $to_theirs : $to_ours;

		// Reverse maps: index in a side => base index.
		$p_rev = array_flip( $p_map );
		$s_rev = array_flip( $s_map );

		// Output slots in the primary order.
		$slots = array();
		foreach ( $p_list as $pi => $p_block ) {
			if ( isset( $p_rev[ $pi ] ) ) {
				$bi = $p_rev[ $pi ];
				if ( isset( $s_map[ $bi ] ) ) {
					$s_block = $s_list[ $s_map[ $bi ] ];
					$slots[] = array(
						'block' => self::OURS === $primary
							? $this->merge_block( $base[ $bi ], $p_block, $s_block, self::child_path( $path, $bi ) )
							: $this->merge_block( $base[ $bi ], $s_block, $p_block, self::child_path( $path, $bi ) ),
						'sec'   => $s_map[ $bi ],
					);
					continue;
				}
				// The secondary side removed it.
				$kept = $this->merge_removed( $base[ $bi ], $p_block, self::OURS === $primary ? self::THEIRS : self::OURS, self::child_path( $path, $bi ) );
				if ( null !== $kept ) {
					$slots[] = array(
						'block' => $kept,
						'sec'   => null,
					);
				}
				continue;
			}

			// New on the primary side.
			if ( self::THEIRS === $primary ) {
				$this->applied[] = self::note( 'added', $p_block, $path );
			}
			$slots[] = array(
				'block' => $p_block,
				'sec'   => null,
			);
		}

		// Base blocks the primary side removed.
		foreach ( $base as $bi => $b_block ) {
			if ( isset( $p_map[ $bi ] ) ) {
				continue;
			}
			if ( ! isset( $s_map[ $bi ] ) ) {
				continue; // Both removed it.
			}
			$revived = $this->merge_removed( $b_block, $s_list[ $s_map[ $bi ] ], self::OURS === $primary ? self::OURS : self::THEIRS, self::child_path( $path, $bi ) );
			if ( null !== $revived ) {
				// The side that changed it comes back, after the block that came before it in that side.
				$slots = self::insert_after_secondary( $slots, $s_map[ $bi ], $revived );
			}
		}

		// Blocks only the secondary side has, placed after the block they followed there.
		foreach ( $s_list as $si => $s_block ) {
			if ( isset( $s_rev[ $si ] ) ) {
				continue;
			}
			if ( self::THEIRS === ( self::OURS === $primary ? self::THEIRS : self::OURS ) ) {
				$this->applied[] = self::note( 'added', $s_block, $path );
			}
			$slots = self::insert_after_secondary( $slots, $si, $s_block );
		}

		return array_values(
			array_map(
				static function ( array $slot ): array {
					return $slot['block'];
				},
				$slots
			)
		);
	}

	/**
	 * Places a secondary-side block after the slot that precedes it on the secondary side.
	 *
	 * @param array[] $slots  Slots, each with `block` and `sec` (index on the secondary side or null).
	 * @param int     $si     Index of the block on the secondary side.
	 * @param array   $block  The block to place.
	 * @return array[]
	 */
	private static function insert_after_secondary( array $slots, int $si, array $block ): array {
		$at = 0;
		foreach ( $slots as $position => $slot ) {
			if ( null !== $slot['sec'] && $slot['sec'] < $si ) {
				$at = max( $at, $position + 1 );
			} elseif ( isset( $slot['ins'] ) && $slot['ins'] < $si ) {
				$at = max( $at, $position + 1 );
			}
		}
		array_splice(
			$slots,
			$at,
			0,
			array(
				array(
					'block' => $block,
					'sec'   => null,
					'ins'   => $si,
				),
			)
		);

		return $slots;
	}

	/**
	 * Merges one block that exists in all three trees.
	 *
	 * @param array  $base   Base block.
	 * @param array  $ours   Our block.
	 * @param array  $theirs Their block.
	 * @param string $path   Path in base positions.
	 * @return array
	 */
	private function merge_block( array $base, array $ours, array $theirs, string $path ): array {
		$h_base   = self::hash( $base );
		$h_ours   = self::hash( $ours );
		$h_theirs = self::hash( $theirs );

		if ( $h_ours === $h_theirs || $h_theirs === $h_base ) {
			return $ours;
		}
		if ( $h_ours === $h_base ) {
			$this->applied[] = self::note( 'changed', $theirs, $path );
			return $theirs;
		}

		// Both changed. Look inside when only the children differ, or one side changed only the wrapper.
		$wrapper_base   = self::wrapper_hash( $base );
		$wrapper_ours   = self::wrapper_hash( $ours );
		$wrapper_theirs = self::wrapper_hash( $theirs );
		$has_children   = ! empty( $base['innerBlocks'] ) || ! empty( $ours['innerBlocks'] ) || ! empty( $theirs['innerBlocks'] );
		$same_name      = ( $base['blockName'] ?? null ) === ( $ours['blockName'] ?? null ) && ( $ours['blockName'] ?? null ) === ( $theirs['blockName'] ?? null );

		if ( $has_children && $same_name && ( $wrapper_ours === $wrapper_theirs || $wrapper_ours === $wrapper_base || $wrapper_theirs === $wrapper_base ) ) {
			$wrapper_from = $wrapper_ours === $wrapper_base ? $theirs : $ours;
			if ( $wrapper_from === $theirs && $wrapper_theirs !== $wrapper_base ) {
				$this->applied[] = self::note( 'changed', $theirs, $path );
			}
			$children = $this->merge_list( (array) $base['innerBlocks'], (array) $ours['innerBlocks'], (array) $theirs['innerBlocks'], $path );

			return self::with_children( $wrapper_from, $children );
		}

		return $this->conflict( self::CHANGED, $base, $ours, $theirs, $path );
	}

	/**
	 * A block that one side removed and the other kept.
	 *
	 * @param array  $base    Base block.
	 * @param array  $present The block on the side that kept it.
	 * @param string $removed Which side removed it: `ours` or `theirs`.
	 * @param string $path    Path in base positions.
	 * @return array|null The block to keep, or null to leave it out.
	 */
	private function merge_removed( array $base, array $present, string $removed, string $path ): ?array {
		if ( self::hash( $present ) === self::hash( $base ) ) {
			if ( self::THEIRS === $removed ) {
				$this->applied[] = self::note( 'removed', $base, $path );
			}
			return null;
		}

		$kind     = self::THEIRS === $removed ? self::REMOVED_THEIR : self::REMOVED_OUR;
		$resolved = $this->conflict(
			$kind,
			$base,
			self::THEIRS === $removed ? $present : null,
			self::THEIRS === $removed ? null : $present,
			$path
		);

		return $resolved;
	}

	/**
	 * Records a conflict and returns what the current choice gives.
	 *
	 * @param string     $kind   Conflict kind.
	 * @param array      $base   Base block.
	 * @param array|null $ours   Our block, null when the site removed it.
	 * @param array|null $theirs Their block, null when the theme removed it.
	 * @param string     $path   Path in base positions.
	 * @return array|null
	 */
	private function conflict( string $kind, array $base, ?array $ours, ?array $theirs, string $path ): ?array {
		$id     = 'c' . $path;
		$choice = $this->choices[ $id ] ?? null;

		$this->conflicts[ $id ] = array(
			'id'     => $id,
			'kind'   => $kind,
			'path'   => $path,
			'name'   => (string) ( $base['blockName'] ?? '' ),
			'base'   => $base,
			'ours'   => $ours,
			'theirs' => $theirs,
			'choice' => $choice,
		);

		if ( self::THEIRS === $choice ) {
			$this->applied[] = self::note( null === $theirs ? 'removed' : 'changed', $base, $path );
			return $theirs;
		}

		// No choice yet shows the site's own version.
		return $ours;
	}

	/**
	 * Matches blocks of a side to blocks of the base: base index => side index.
	 *
	 * Blocks that are identical match first (longest common run, so the order is
	 * respected where it can be). Between those anchors, blocks with the same
	 * name and attributes match, then blocks with the same name in order.
	 *
	 * @param array[] $base Base blocks.
	 * @param array[] $side Side blocks.
	 * @return array<int, int>
	 */
	private static function align( array $base, array $side ): array {
		$full_base = array_map( array( self::class, 'hash' ), $base );
		$full_side = array_map( array( self::class, 'hash' ), $side );

		$map = array();

		// Exact matches by longest common subsequence.
		$n = count( $full_base );
		$m = count( $full_side );
		$t = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$t[ $i ][ $j ] = $full_base[ $i ] === $full_side[ $j ] ? $t[ $i + 1 ][ $j + 1 ] + 1 : max( $t[ $i + 1 ][ $j ], $t[ $i ][ $j + 1 ] );
			}
		}
		$i = 0;
		$j = 0;
		while ( $i < $n && $j < $m ) {
			if ( $full_base[ $i ] === $full_side[ $j ] ) {
				$map[ $i ] = $j;
				++$i;
				++$j;
			} elseif ( $t[ $i + 1 ][ $j ] >= $t[ $i ][ $j + 1 ] ) {
				++$i;
			} else {
				++$j;
			}
		}

		$used = array_flip( $map );

		// The same block somewhere else: it moved.
		foreach ( $base as $bi => $b_block ) {
			if ( isset( $map[ $bi ] ) ) {
				continue;
			}
			foreach ( $side as $si => $s_block ) {
				if ( ! isset( $used[ $si ] ) && $full_base[ $bi ] === $full_side[ $si ] ) {
					$map[ $bi ]  = $si;
					$used[ $si ] = true;
					break;
				}
			}
		}

		// A changed block between the neighbours that matched: the same name, in order.
		foreach ( $base as $bi => $b_block ) {
			if ( isset( $map[ $bi ] ) ) {
				continue;
			}
			$low  = -1;
			$high = $m;
			foreach ( $map as $mb => $ms ) {
				if ( $mb < $bi ) {
					$low = max( $low, $ms );
				} else {
					$high = min( $high, $ms );
				}
			}
			for ( $si = $low + 1; $si < $high; $si++ ) {
				if ( ! isset( $used[ $si ] ) && ( $side[ $si ]['blockName'] ?? null ) === ( $b_block['blockName'] ?? null ) ) {
					$map[ $bi ]  = $si;
					$used[ $si ] = true;
					break;
				}
			}
		}

		// Still unmatched: the same name and attributes anywhere, when the attributes say something.
		foreach ( $base as $bi => $b_block ) {
			if ( isset( $map[ $bi ] ) || ! self::canonical_attrs( $b_block ) ) {
				continue;
			}
			foreach ( $side as $si => $s_block ) {
				if ( ! isset( $used[ $si ] ) && ( $s_block['blockName'] ?? null ) === ( $b_block['blockName'] ?? null ) && self::canonical_attrs( $s_block ) === self::canonical_attrs( $b_block ) ) {
					$map[ $bi ]  = $si;
					$used[ $si ] = true;
					break;
				}
			}
		}

		ksort( $map );

		return $map;
	}

	/**
	 * Whether the matched blocks of a side are in another order than in the base.
	 *
	 * @param array<int, int> $map Base index => side index.
	 * @return bool
	 */
	private static function moved( array $map ): bool {
		$last = -1;
		foreach ( $map as $side_index ) {
			if ( $side_index < $last ) {
				return true;
			}
			$last = $side_index;
		}

		return false;
	}

	/**
	 * Base indices in the order a side has them.
	 *
	 * @param array<int, int> $map Base index => side index.
	 * @return int[]
	 */
	private static function order_of( array $map ): array {
		asort( $map );

		return array_keys( $map );
	}

	/**
	 * Path of a child, in base positions.
	 *
	 * @param string $parent_path Parent path.
	 * @param int    $index  Index in the base.
	 * @return string
	 */
	private static function child_path( string $parent_path, int $index ): string {
		return '' === $parent_path ? (string) $index : $parent_path . '.' . $index;
	}

	/**
	 * A short note for the list of what the theme changed.
	 *
	 * @param string $action added, removed or changed.
	 * @param array  $block  The block.
	 * @param string $path   Path.
	 * @return array{action: string, name: string, path: string}
	 */
	private static function note( string $action, array $block, string $path ): array {
		return array(
			'action' => $action,
			'name'   => (string) ( $block['blockName'] ?? '' ),
			'path'   => $path,
		);
	}

	/**
	 * Hash of a block without its children.
	 *
	 * @param array $block Block.
	 * @return string
	 */
	private static function wrapper_hash( array $block ): string {
		return md5( (string) wp_json_encode( self::canonical( $block, false ) ) );
	}

	/**
	 * A block as plain data that compares the same however it was formatted.
	 *
	 * @param array $block    Block.
	 * @param bool  $children Include the children.
	 * @return array
	 */
	private static function canonical( array $block, bool $children ): array {
		$parts = array();
		foreach ( (array) ( $block['innerContent'] ?? array( $block['innerHTML'] ?? '' ) ) as $chunk ) {
			$parts[] = null === $chunk ? null : trim( (string) preg_replace( '/\s+/', ' ', (string) $chunk ) );
		}
		// Whitespace between children is not content.
		$parts = array_values(
			array_filter(
				$parts,
				static function ( $chunk ): bool {
					return null === $chunk || '' !== $chunk;
				}
			)
		);

		$data = array(
			'n' => $block['blockName'] ?? null,
			'a' => self::canonical_attrs( $block ),
			'h' => $parts,
		);
		if ( $children ) {
			$data['c'] = array_map(
				static function ( array $child ) {
					return self::canonical( $child, true );
				},
				array_values( (array) ( $block['innerBlocks'] ?? array() ) )
			);
		}

		return $data;
	}

	/**
	 * Attributes with a stable key order and without the template part `theme`.
	 *
	 * @param array $block Block.
	 * @return array
	 */
	private static function canonical_attrs( array $block ): array {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( 'core/template-part' === ( $block['blockName'] ?? '' ) ) {
			unset( $attrs['theme'] );
		}

		return self::sort_keys( $attrs );
	}

	/**
	 * Sorts the keys of nested arrays.
	 *
	 * @param array $value Value.
	 * @return array
	 */
	private static function sort_keys( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::sort_keys( $item );
			}
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}

		return $value;
	}

	/**
	 * A block's wrapper with other children.
	 *
	 * @param array   $block    Block that gives the wrapper (attributes and the HTML around the children).
	 * @param array[] $children New children.
	 * @return array
	 */
	private static function with_children( array $block, array $children ): array {
		$chunks = array_values( (array) ( $block['innerContent'] ?? array() ) );
		$first  = '';
		$last   = '';
		if ( $chunks ) {
			$first = (string) ( null === $chunks[0] ? '' : $chunks[0] );
			$tail  = $chunks[ count( $chunks ) - 1 ];
			$last  = count( $chunks ) > 1 ? (string) ( null === $tail ? '' : $tail ) : '';
		}

		$content = array( $first );
		foreach ( $children as $index => $child ) {
			unset( $child );
			if ( $index > 0 ) {
				$content[] = "\n\n";
			}
			$content[] = null;
		}
		$content[] = $last;

		$block['innerBlocks']  = $children;
		$block['innerContent'] = $children ? $content : array( $first . $last );
		$block['innerHTML']    = implode(
			'',
			array_map(
				static function ( $chunk ): string {
					return (string) $chunk;
				},
				$block['innerContent']
			)
		);

		return $block;
	}
}
