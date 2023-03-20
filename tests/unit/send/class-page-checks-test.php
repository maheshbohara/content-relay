<?php
/**
 * Tests for Page_Checks.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Send;

use ContentRelay\Send\Page_Checks;
use PHPUnit\Framework\TestCase;

/**
 * Verification HTML checks.
 */
final class Page_Checks_Test extends TestCase {

	/**
	 * `<html lang>` is read in any attribute order and compared on the primary subtag.
	 */
	public function test_html_lang(): void {
		$this->assertSame( 'fr-FR', Page_Checks::html_lang( "<!doctype html>\n<html class=\"no-js\" lang=\"fr-FR\" prefix=\"og: https://ogp.me/ns#\">" ) );
		$this->assertSame( 'en', Page_Checks::html_lang( "<html lang='en'>" ) );
		$this->assertNull( Page_Checks::html_lang( '<html xml:lang="en">' ) );
		$this->assertNull( Page_Checks::html_lang( '<body lang="en">' ) );

		$this->assertTrue( Page_Checks::same_language( 'fr-FR', 'fr' ) );
		$this->assertTrue( Page_Checks::same_language( 'EN_us', 'en' ) );
		$this->assertFalse( Page_Checks::same_language( 'en-US', 'fr' ) );
		$this->assertFalse( Page_Checks::same_language( null, 'en' ) );
	}

	/**
	 * Only `rel="alternate"` links with hreflang count; entities are decoded.
	 */
	public function test_hreflang_links(): void {
		$html  = '<link rel="alternate" hreflang="en" href="https://example.test/export-navigator/india/" />';
		$html .= '<link href="https://example.test/fr/navigateur-dexportation/india/" hreflang="fr" rel="alternate">';
		$html .= '<link rel="canonical" href="https://example.test/export-navigator/india/">';
		$html .= '<link rel="alternate" type="application/rss+xml" href="https://example.test/feed/">';
		$html .= '<link rel="alternate" hreflang="x-default" href="https://example.test/?a=1&amp;b=2">';

		$links = Page_Checks::hreflang_links( $html );
		$this->assertCount( 3, $links );
		$this->assertSame( 'fr', $links[1]['hreflang'] );
		$this->assertSame( 'https://example.test/?a=1&b=2', $links[2]['href'] );

		$this->assertTrue( Page_Checks::has_alternate( $links, 'http://EXAMPLE.test/fr/navigateur-dexportation/india' ) );
		$this->assertFalse( Page_Checks::has_alternate( $links, 'https://example.test/fr/' ) );
	}

	/**
	 * Sender URLs are found in plain, JSON-escaped and encoded form, but not e-mails, copy or a receiver subdomain.
	 */
	public function test_sender_urls(): void {
		$html  = '<a href="https://wtcwinnipeg.com/about/">About</a>';
		$html .= '<script>{"url":"https:\/\/wtcwinnipeg.com\/wp-content\/x.jpg"}</script>';
		$html .= '<a href="/share?u=https%3A%2F%2Fwtcwinnipeg.com%2F">Share</a>';
		$html .= '<p>Mail info@wtcwinnipeg.com or visit wtcwinnipeg.com.</p>';
		$html .= '<img src="https://dev.wtcwinnipeg.com/wp-content/uploads/a.jpg">';
		$html .= '<a href="https://wtcwinnipeg.com.au/">Other</a>';

		$found = Page_Checks::sender_urls( $html, 'WTCWinnipeg.com' );
		$this->assertSame( 3, $found['count'] );
		$this->assertCount( 3, $found['snippets'] );
		$this->assertStringContainsString( 'wtcwinnipeg.com/about', $found['snippets'][0] );

		$this->assertSame( 0, Page_Checks::sender_urls( $html, 'example.test' )['count'] );
		$this->assertSame( 0, Page_Checks::sender_urls( $html, '' )['count'] );
		$this->assertCount( 1, Page_Checks::sender_urls( $html, 'wtcwinnipeg.com', 1 )['snippets'] );
	}
}
