<?php
/**
 * Tests for the three-way merge of block trees.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Tests\Unit;

use AryanX\Axle\BlockThemeOps\BlockMerge;
use AryanX\Axle\BlockThemeOps\Markup;
use Brain\Monkey\Functions;

final class BlockMergeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * Merges three markup strings.
	 *
	 * @param string $base    Theme file at edit time.
	 * @param string $ours    Database copy.
	 * @param string $theirs  Theme file now.
	 * @param array  $choices Conflict choices.
	 * @return array{markup: string, conflicts: array, applied: array, resolved: bool}
	 */
	private function run_merge( string $base, string $ours, string $theirs, array $choices = array() ): array {
		$result = BlockMerge::merge( parse_blocks( $base ), parse_blocks( $ours ), parse_blocks( $theirs ), $choices );

		return array(
			'markup'    => Markup::normalize( serialize_blocks( $result['blocks'] ) ),
			'conflicts' => $result['conflicts'],
			'applied'   => $result['applied'],
			'resolved'  => $result['resolved'],
		);
	}

	private static function p( string $text, array $attrs = array() ): string {
		$json = $attrs ? ' ' . Markup::encode_attrs( $attrs ) : '';

		return "<!-- wp:paragraph{$json} --><p>{$text}</p><!-- /wp:paragraph -->";
	}

	private static function group( string $inner, array $attrs = array(), string $class = 'wp-block-group' ): string {
		$json = $attrs ? ' ' . Markup::encode_attrs( $attrs ) : '';

		return "<!-- wp:group{$json} --><div class=\"{$class}\">{$inner}</div><!-- /wp:group -->";
	}

	private static function norm( string $markup ): string {
		return Markup::normalize( $markup );
	}

	public function test_the_theme_adding_a_block_is_taken_when_the_site_changed_another(): void {
		$base   = self::p( 'One' ) . self::p( 'Two' );
		$ours   = self::p( 'One' ) . self::p( 'Two, edited on site' );
		$theirs = self::p( 'One' ) . self::p( 'Two' ) . '<!-- wp:site-title /-->';

		$result = $this->run_merge( $base, $ours, $theirs );

		$this->assertTrue( $result['resolved'] );
		$this->assertSame( array(), $result['conflicts'] );
		$this->assertSame( self::norm( self::p( 'One' ) . self::p( 'Two, edited on site' ) . '<!-- wp:site-title /-->' ), $result['markup'] );
		$this->assertSame( 'added', $result['applied'][0]['action'] );
		$this->assertSame( 'core/site-title', $result['applied'][0]['name'] );
	}

	public function test_a_block_the_theme_adds_in_the_middle_lands_after_the_block_it_followed(): void {
		$base   = self::p( 'A' ) . self::p( 'B' );
		$ours   = self::p( 'A' ) . self::p( 'B' ) . self::p( 'Site only' );
		$theirs = self::p( 'A' ) . '<!-- wp:site-logo /-->' . self::p( 'B' );

		$result = $this->run_merge( $base, $ours, $theirs );

		$this->assertSame( self::norm( self::p( 'A' ) . '<!-- wp:site-logo /-->' . self::p( 'B' ) . self::p( 'Site only' ) ), $result['markup'] );
	}

	public function test_a_block_the_user_removed_stays_removed_while_the_theme_changes_another(): void {
		$base   = self::p( 'Keep' ) . self::p( 'Drop me' ) . self::p( 'Tail' );
		$ours   = self::p( 'Keep' ) . self::p( 'Tail' );
		$theirs = self::p( 'Keep, updated' ) . self::p( 'Drop me' ) . self::p( 'Tail' );

		$result = $this->run_merge( $base, $ours, $theirs );

		$this->assertTrue( $result['resolved'] );
		$this->assertSame( self::norm( self::p( 'Keep, updated' ) . self::p( 'Tail' ) ), $result['markup'] );
	}

	public function test_a_block_the_user_removed_and_the_theme_changed_is_a_conflict(): void {
		$base   = self::p( 'Keep' ) . self::p( 'Banner' );
		$ours   = self::p( 'Keep' );
		$theirs = self::p( 'Keep' ) . self::p( 'Banner, new wording' );

		$open = $this->run_merge( $base, $ours, $theirs );
		$this->assertFalse( $open['resolved'] );
		$this->assertSame( BlockMerge::REMOVED_OUR, $open['conflicts']['c1']['kind'] );
		// Until a choice is made the site's own version stands.
		$this->assertSame( self::norm( self::p( 'Keep' ) ), $open['markup'] );

		$theirs_choice = $this->run_merge( $base, $ours, $theirs, array( 'c1' => 'theirs' ) );
		$this->assertTrue( $theirs_choice['resolved'] );
		$this->assertSame( self::norm( self::p( 'Keep' ) . self::p( 'Banner, new wording' ) ), $theirs_choice['markup'] );

		$ours_choice = $this->run_merge( $base, $ours, $theirs, array( 'c1' => 'ours' ) );
		$this->assertTrue( $ours_choice['resolved'] );
		$this->assertSame( self::norm( self::p( 'Keep' ) ), $ours_choice['markup'] );
	}

	public function test_a_block_the_theme_removed_and_the_user_changed_is_a_conflict(): void {
		$base   = self::p( 'Keep' ) . self::p( 'Promo' );
		$ours   = self::p( 'Keep' ) . self::p( 'Promo, edited' );
		$theirs = self::p( 'Keep' );

		$open = $this->run_merge( $base, $ours, $theirs );
		$this->assertSame( BlockMerge::REMOVED_THEIR, $open['conflicts']['c1']['kind'] );
		$this->assertSame( self::norm( $ours ), $open['markup'] );

		$gone = $this->run_merge( $base, $ours, $theirs, array( 'c1' => 'theirs' ) );
		$this->assertSame( self::norm( self::p( 'Keep' ) ), $gone['markup'] );
	}

	public function test_a_block_the_theme_removed_and_the_user_left_alone_is_removed(): void {
		$base   = self::p( 'Keep' ) . self::p( 'Old' );
		$ours   = self::p( 'Keep, edited' ) . self::p( 'Old' );
		$theirs = self::p( 'Keep' );

		$result = $this->run_merge( $base, $ours, $theirs );

		$this->assertTrue( $result['resolved'] );
		$this->assertSame( self::norm( self::p( 'Keep, edited' ) ), $result['markup'] );
		$this->assertSame( 'removed', $result['applied'][0]['action'] );
	}

	public function test_a_block_both_sides_changed_differently_is_a_conflict_with_a_choice(): void {
		$base   = self::p( 'Hello' ) . self::p( 'World' );
		$ours   = self::p( 'Hello' ) . self::p( 'World from the site' );
		$theirs = self::p( 'Hello' ) . self::p( 'World from the theme' );

		$open = $this->run_merge( $base, $ours, $theirs );
		$this->assertFalse( $open['resolved'] );
		$this->assertCount( 1, $open['conflicts'] );
		$this->assertSame( BlockMerge::CHANGED, $open['conflicts']['c1']['kind'] );
		$this->assertSame( 'core/paragraph', $open['conflicts']['c1']['name'] );
		$this->assertSame( self::norm( $ours ), $open['markup'] );

		$take_theirs = $this->run_merge( $base, $ours, $theirs, array( 'c1' => 'theirs' ) );
		$this->assertTrue( $take_theirs['resolved'] );
		$this->assertSame( self::norm( $theirs ), $take_theirs['markup'] );

		$take_ours = $this->run_merge( $base, $ours, $theirs, array( 'c1' => 'ours' ) );
		$this->assertSame( self::norm( $ours ), $take_ours['markup'] );
	}

	public function test_both_sides_making_the_same_change_is_not_a_conflict(): void {
		$base   = self::p( 'Hello' );
		$both   = self::p( 'Hello there' );

		$result = $this->run_merge( $base, $both, $both );

		$this->assertTrue( $result['resolved'] );
		$this->assertSame( self::norm( $both ), $result['markup'] );
	}

	public function test_an_attribute_change_on_one_side_merges_with_a_text_change_in_another_block(): void {
		$base   = self::p( 'A', array( 'align' => 'left' ) ) . self::p( 'B' );
		$ours   = self::p( 'A', array( 'align' => 'center' ) ) . self::p( 'B' );
		$theirs = self::p( 'A', array( 'align' => 'left' ) ) . self::p( 'B changed' );

		$result = $this->run_merge( $base, $ours, $theirs );

		$this->assertTrue( $result['resolved'] );
		$this->assertSame( self::norm( self::p( 'A', array( 'align' => 'center' ) ) . self::p( 'B changed' ) ), $result['markup'] );
	}

	public function test_attribute_order_and_the_template_part_theme_do_not_make_a_change(): void {
		$base   = '<!-- wp:template-part {"slug":"header","tagName":"header"} /--><!-- wp:post-content /-->';
		$ours   = '<!-- wp:template-part {"tagName":"header","slug":"header","theme":"corner-bakery"} /--><!-- wp:post-content /-->';
		$theirs = '<!-- wp:template-part {"slug":"header","tagName":"header"} /--><!-- wp:post-content /--><!-- wp:site-title /-->';

		$result = $this->run_merge( $base, $ours, $theirs );

		$this->assertTrue( $result['resolved'] );
		$this->assertStringContainsString( 'site-title', $result['markup'] );
		$this->assertStringContainsString( '"theme":"corner-bakery"', serialize_blocks( BlockMerge::merge( parse_blocks( $base ), parse_blocks( $ours ), parse_blocks( $theirs ) )['blocks'] ) );
	}

	public function test_the_theme_reordering_blocks_is_taken_when_the_site_did_not_reorder(): void {
		$base   = self::p( 'A' ) . self::p( 'B' ) . self::p( 'C' );
		$ours   = self::p( 'A' ) . self::p( 'B edited' ) . self::p( 'C' );
		$theirs = self::p( 'C' ) . self::p( 'A' ) . self::p( 'B' );

		$result = $this->run_merge( $base, $ours, $theirs );

		$this->assertTrue( $result['resolved'] );
		$this->assertSame( self::norm( self::p( 'C' ) . self::p( 'A' ) . self::p( 'B edited' ) ), $result['markup'] );
	}

	public function test_the_site_reordering_blocks_is_kept_when_the_theme_did_not_reorder(): void {
		$base   = self::p( 'A' ) . self::p( 'B' ) . self::p( 'C' );
		$ours   = self::p( 'C' ) . self::p( 'A' ) . self::p( 'B' );
		$theirs = self::p( 'A' ) . self::p( 'B' ) . self::p( 'C, updated' );

		$result = $this->run_merge( $base, $ours, $theirs );

		$this->assertTrue( $result['resolved'] );
		$this->assertSame( self::norm( self::p( 'C, updated' ) . self::p( 'A' ) . self::p( 'B' ) ), $result['markup'] );
	}

	public function test_both_sides_reordering_differently_is_an_order_conflict(): void {
		$base   = self::p( 'A' ) . self::p( 'B' ) . self::p( 'C' );
		$ours   = self::p( 'B' ) . self::p( 'C' ) . self::p( 'A' );
		$theirs = self::p( 'C' ) . self::p( 'B' ) . self::p( 'A' );

		$open = $this->run_merge( $base, $ours, $theirs );
		$this->assertFalse( $open['resolved'] );
		$this->assertSame( BlockMerge::ORDER, $open['conflicts']['o']['kind'] );
		$this->assertSame( self::norm( $ours ), $open['markup'] );

		$choice = $this->run_merge( $base, $ours, $theirs, array( 'o' => 'theirs' ) );
		$this->assertTrue( $choice['resolved'] );
		$this->assertSame( self::norm( $theirs ), $choice['markup'] );
	}

	public function test_non_conflicting_changes_inside_a_nested_group_merge(): void {
		$base   = self::group( self::group( self::p( 'Deep one' ) . self::p( 'Deep two' ), array(), 'wp-block-group inner' ) . self::p( 'Outer' ) );
		$ours   = self::group( self::group( self::p( 'Deep one, site' ) . self::p( 'Deep two' ), array(), 'wp-block-group inner' ) . self::p( 'Outer' ) );
		$theirs = self::group( self::group( self::p( 'Deep one' ) . self::p( 'Deep two' ) . self::p( 'Deep three' ), array(), 'wp-block-group inner' ) . self::p( 'Outer, theme' ) );

		$result = $this->run_merge( $base, $ours, $theirs );

		$this->assertTrue( $result['resolved'] );
		$this->assertSame(
			self::norm( self::group( self::group( self::p( 'Deep one, site' ) . self::p( 'Deep two' ) . self::p( 'Deep three' ), array(), 'wp-block-group inner' ) . self::p( 'Outer, theme' ) ) ),
			$result['markup']
		);
	}

	public function test_a_conflict_inside_a_nested_group_is_reported_at_that_block_only(): void {
		$base   = self::group( self::p( 'Stable' ) . self::group( self::p( 'Target' ), array(), 'wp-block-group inner' ) );
		$ours   = self::group( self::p( 'Stable, site' ) . self::group( self::p( 'Target by site' ), array(), 'wp-block-group inner' ) );
		$theirs = self::group( self::p( 'Stable' ) . self::group( self::p( 'Target by theme' ), array(), 'wp-block-group inner' ) );

		$open = $this->run_merge( $base, $ours, $theirs );

		$this->assertFalse( $open['resolved'] );
		$this->assertSame( array( 'c0.1.0' ), array_keys( $open['conflicts'] ) );
		$this->assertSame( self::norm( $ours ), $open['markup'] );

		$choice = $this->run_merge( $base, $ours, $theirs, array( 'c0.1.0' => 'theirs' ) );
		$this->assertTrue( $choice['resolved'] );
		$this->assertSame( self::norm( self::group( self::p( 'Stable, site' ) . self::group( self::p( 'Target by theme' ), array(), 'wp-block-group inner' ) ) ), $choice['markup'] );
	}

	public function test_a_child_the_theme_adds_to_a_group_is_one_change_not_a_changed_group(): void {
		$base   = self::group( '<!-- wp:site-title /-->' . "\n\n" . self::p( 'Tagline' ) );
		$ours   = self::group( '<!-- wp:site-title /-->' . "\n\n" . self::p( 'Tagline, site' ) );
		$theirs = self::group( '<!-- wp:site-title /-->' . "\n\n" . '<!-- wp:navigation /-->' . "\n\n" . self::p( 'Tagline' ) );

		$result = $this->run_merge( $base, $ours, $theirs );

		$this->assertTrue( $result['resolved'] );
		$this->assertCount( 1, $result['applied'] );
		$this->assertSame( array( 'added', 'core/navigation' ), array( $result['applied'][0]['action'], $result['applied'][0]['name'] ) );
	}

	public function test_a_wrapper_changed_by_the_theme_and_children_changed_by_the_site_merge(): void {
		$base   = self::group( self::p( 'Inside' ), array( 'tagName' => 'div' ) );
		$ours   = self::group( self::p( 'Inside, site' ), array( 'tagName' => 'div' ) );
		$theirs = self::group( self::p( 'Inside' ) . self::p( 'Added' ), array( 'tagName' => 'header' ), 'wp-block-group header' );

		$result = $this->run_merge( $base, $ours, $theirs );

		$this->assertTrue( $result['resolved'] );
		$this->assertSame( self::norm( self::group( self::p( 'Inside, site' ) . self::p( 'Added' ), array( 'tagName' => 'header' ), 'wp-block-group header' ) ), $result['markup'] );
	}

	public function test_a_wrapper_both_sides_changed_differently_is_a_conflict_on_the_group(): void {
		$base   = self::group( self::p( 'Inside' ), array( 'tagName' => 'div' ) );
		$ours   = self::group( self::p( 'Inside' ), array( 'tagName' => 'section' ), 'wp-block-group s' );
		$theirs = self::group( self::p( 'Inside' ) . self::p( 'More' ), array( 'tagName' => 'header' ), 'wp-block-group h' );

		$open = $this->run_merge( $base, $ours, $theirs );

		$this->assertSame( array( 'c0' ), array_keys( $open['conflicts'] ) );
		$this->assertFalse( $open['resolved'] );
	}

	public function test_an_unchanged_site_copy_takes_the_whole_theme_file(): void {
		$base   = self::p( 'A' ) . self::p( 'B' );
		$theirs = self::p( 'A2' ) . self::p( 'B' ) . self::p( 'C' );

		$result = $this->run_merge( $base, $base, $theirs );

		$this->assertTrue( $result['resolved'] );
		$this->assertSame( self::norm( $theirs ), $result['markup'] );
	}

	public function test_an_unchanged_theme_file_keeps_the_site_copy(): void {
		$base = self::p( 'A' ) . self::p( 'B' );
		$ours = self::p( 'A' ) . self::p( 'Mine' ) . self::p( 'C' );

		$result = $this->run_merge( $base, $ours, $base );

		$this->assertTrue( $result['resolved'] );
		$this->assertSame( self::norm( $ours ), $result['markup'] );
		$this->assertSame( array(), $result['applied'] );
	}

	public function test_unknown_choices_are_ignored(): void {
		$base   = self::p( 'X' );
		$ours   = self::p( 'X site' );
		$theirs = self::p( 'X theme' );

		$result = $this->run_merge( $base, $ours, $theirs, array( 'c0' => 'both', 'c9' => 'theirs' ) );

		$this->assertFalse( $result['resolved'] );
	}
}
