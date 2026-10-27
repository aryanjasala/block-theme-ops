<?php
/**
 * Tests for comparing, pretty-printing and cleaning block markup.
 *
 * @package AryanX\Axle\BlockThemeOps
 */

namespace AryanX\Axle\BlockThemeOps\Tests\Unit;

use AryanX\Axle\BlockThemeOps\Markup;

final class MarkupTest extends TestCase {

	public function test_copies_that_differ_only_in_formatting_are_the_same(): void {
		$file   = "<!-- wp:group {\"tagName\":\"header\",\"layout\":{\"type\":\"constrained\"}} -->\n<header class=\"wp-block-group\">\n\t<!-- wp:site-title /-->\n</header>\n<!-- /wp:group -->\n";
		$editor = '<!-- wp:group {"layout":{"type":"constrained"},"tagName":"header"} --><header class="wp-block-group"><!-- wp:site-title /--></header><!-- /wp:group -->';

		$this->assertTrue( Markup::same( $file, $editor ) );
		$this->assertFalse( Markup::same( $file, str_replace( 'site-title', 'site-tagline', $editor ) ) );
	}

	public function test_the_theme_attribute_of_template_parts_does_not_count(): void {
		$file   = '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->';
		$editor = '<!-- wp:template-part {"slug":"header","theme":"corner-bakery","tagName":"header"} /-->';

		$this->assertTrue( Markup::same( $file, $editor ) );
		$this->assertSame( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->', Markup::normalize( $editor ) );
	}

	public function test_pretty_puts_each_block_on_its_own_line_indented_by_nesting(): void {
		$markup = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

		$this->assertSame(
			"<!-- wp:group -->\n\t<div class=\"wp-block-group\">\n\t<!-- wp:paragraph -->\n\t\t<p>Hi</p>\n\t<!-- /wp:paragraph -->\n\t</div>\n<!-- /wp:group -->",
			Markup::pretty( $markup )
		);
		$this->assertStringContainsString( "··<p>Hi</p>", Markup::pretty( $markup, '·' ) );
	}

	public function test_strip_theme_attribute_only_touches_template_parts(): void {
		$markup = '<!-- wp:template-part {"slug":"footer","theme":"corner-bakery","tagName":"footer"} /-->' . "\n" .
			'<!-- wp:template-part {"theme":"corner-bakery"} /-->' . "\n" .
			'<!-- wp:paragraph {"theme":"keep-me"} --><p>A -- B</p><!-- /wp:paragraph -->';

		$this->assertSame(
			'<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' . "\n" .
			'<!-- wp:template-part /-->' . "\n" .
			'<!-- wp:paragraph {"theme":"keep-me"} --><p>A -- B</p><!-- /wp:paragraph -->',
			Markup::strip_theme_attribute( $markup )
		);
	}

	public function test_attributes_are_encoded_like_core_does(): void {
		$this->assertSame( '{"content":"\u003cb\u003eA\u0026B \u002d\u002d \u0022x\u0022\u003c/b\u003e"}', Markup::encode_attrs( array( 'content' => '<b>A&B -- "x"</b>' ) ) );
	}

	public function test_hash_ignores_whitespace_only(): void {
		$this->assertSame( Markup::hash( "<p>\n\tHi</p>\n" ), Markup::hash( '<p> Hi</p>' ) );
		$this->assertNotSame( Markup::hash( '<p>Hi</p>' ), Markup::hash( '<p>Hello</p>' ) );
	}

	public function test_tokens_name_core_and_namespaced_blocks(): void {
		$tokens = Markup::tokens( 'x<!-- wp:acme/card {"a":1} --><!-- /wp:acme/card --><!-- wp:spacer /-->' );

		$this->assertSame( array( 'html', 'open', 'close', 'void' ), array_column( $tokens, 'type' ) );
		$this->assertSame( array( '', 'acme/card', 'acme/card', 'core/spacer' ), array_column( $tokens, 'name' ) );
		$this->assertSame( array( 'a' => 1 ), $tokens[1]['attrs'] );
	}
}
