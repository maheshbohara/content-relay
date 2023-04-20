<?php
/**
 * HTTP verification against a scripted receiver.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Integration;

use ContentRelay\Send\Verifier;

/**
 * Verifier checks, with a fake transport standing in for the receiving site.
 */
final class Verifier_Test extends Test_Case {

	/**
	 * A passing page, a page failing three checks, a skipped draft and an unreachable page.
	 */
	public function test_checks(): void {
		$pages    = array(
			'https://receiver.test/india/'       => '<html lang="en-US"><head><link rel="alternate" hreflang="en" href="https://receiver.test/india/"><link rel="alternate" hreflang="fr" href="https://receiver.test/fr/inde/"></head><body><img src="https://receiver.test/wp-content/uploads/a.jpg" srcset="https://receiver.test/wp-content/uploads/a-300x200.jpg 300w"></body></html>',
			'https://receiver.test/fr/inde/'     => '<html lang="fr-FR"><head><link rel="alternate" hreflang="fr" href="https://receiver.test/fr/inde/"></head><body><a href="https://sender.test/fr/inde/">x</a><img src="https://receiver.test/wp-content/uploads/missing.jpg"></body></html>',
			'https://receiver.test/no-hreflang/' => '<html lang="en"><head></head><body><p>This site outputs no hreflang links.</p></body></html>',
		);
		$assets   = array(
			'https://receiver.test/wp-content/uploads/a.jpg'         => 200,
			'https://receiver.test/wp-content/uploads/a-300x200.jpg' => 405,
			'https://receiver.test/wp-content/uploads/missing.jpg'   => 404,
		);
		$requests = array();

		$transport = static function ( string $url, array $args ) use ( $pages, $assets, &$requests ) {
			$requests[] = $args['method'] . ' ' . $url;
			if ( 'https://receiver.test/down/' === $url ) {
				return new \WP_Error( 'http_request_failed', 'Connection refused' );
			}
			if ( 'GET' === $args['method'] && isset( $pages[ $url ] ) ) {
				return self::response( 200, $pages[ $url ] );
			}
			if ( isset( $assets[ $url ] ) ) {
				return self::response( 405 === $assets[ $url ] && 'GET' === $args['method'] ? 200 : $assets[ $url ], '' );
			}
			return self::response( 404, '' );
		};

		$both   = array( 'post:1', 'post:2' );
		$report = ( new Verifier( $transport ) )->verify(
			array(
				array(
					'key'          => 'post:1',
					'url'          => 'https://receiver.test/india/',
					'language'     => 'en',
					'status'       => 'publish',
					'translations' => $both,
				),
				array(
					'key'          => 'post:2',
					'url'          => 'https://receiver.test/fr/inde/',
					'language'     => 'fr',
					'status'       => 'publish',
					'translations' => $both,
				),
				array(
					'key'          => 'post:3',
					'url'          => 'https://receiver.test/draft/',
					'language'     => null,
					'status'       => 'draft',
					'translations' => array(),
				),
				array(
					'key'          => 'post:4',
					'url'          => 'https://receiver.test/down/',
					'language'     => null,
					'status'       => 'publish',
					'translations' => array(),
				),
				array(
					'key'          => 'post:5',
					'url'          => 'https://receiver.test/no-hreflang/',
					'language'     => 'en',
					'status'       => 'publish',
					'translations' => array( 'post:5', 'post:1' ),
				),
			),
			'https://sender.test',
			'https://receiver.test/wp-content/uploads'
		);

		$this->assertFalse( $report['ok'] );
		$this->assertCount( 5, $report['pages'] );

		$first = self::results( $report['pages'][0] );
		$this->assertSame(
			array(
				'status'      => true,
				'address'     => true,
				'language'    => true,
				'hreflang'    => true,
				'sender_urls' => true,
				'images'      => true,
			),
			$first
		);

		$second = self::results( $report['pages'][1] );
		$this->assertTrue( $second['language'] );
		$this->assertFalse( $second['hreflang'] );
		$this->assertFalse( $second['sender_urls'] );
		$this->assertFalse( $second['images'] );

		$this->assertTrue( $report['pages'][2]['skipped'] );
		$this->assertStringContainsString( 'draft', $report['pages'][2]['note'] );
		$this->assertSame( array( 'reachable' => false ), self::results( $report['pages'][3] ) );

		// A site that outputs no hreflang links is not a failed relay.
		$fifth = self::results( $report['pages'][4] );
		$this->assertTrue( $fifth['hreflang'] );
		$this->assertStringContainsString( 'outputs no hreflang links', $report['pages'][4]['checks'][3]['message'] );

		$this->assertCount( 4, $report['problems'] );
		$this->assertStringContainsString( 'https://receiver.test/fr/inde/: hreflang does not list https://receiver.test/india/', $report['problems'][0] );
		$this->assertContains( 'HEAD https://receiver.test/wp-content/uploads/a-300x200.jpg', $requests );
		$this->assertContains( 'GET https://receiver.test/wp-content/uploads/a-300x200.jpg', $requests );
		$this->assertNotContains( 'GET https://receiver.test/draft/', $requests );
	}
}
