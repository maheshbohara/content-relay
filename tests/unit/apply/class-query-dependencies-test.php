<?php
/**
 * Tests for Query_Dependencies.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Apply;

use ContentRelay\Apply\Query_Dependencies;
use PHPUnit\Framework\TestCase;

/**
 * Query dependency checks.
 */
final class Query_Dependencies_Test extends TestCase {

	/**
	 * Passing checks are silent; failing, throwing and malformed ones become warnings, and checks get the subject.
	 */
	public function test_run(): void {
		$subject = (object) array( 'ID' => 200052 );
		$seen    = array();

		$warnings = Query_Dependencies::run(
			array(
				array(
					'label' => 'Events for India',
					'check' => static function ( $post ) use ( &$seen ): bool {
						$seen[] = $post->ID;
						return true;
					},
				),
				array(
					'label' => 'Funding programs',
					'check' => static function (): bool {
						return false;
					},
				),
				array(
					'label' => 'Partners',
					'check' => static function (): bool {
						throw new \RuntimeException( 'taxonomy missing' );
					},
				),
				array(
					'check' => static function () {
						return 1;
					},
				),
				array( 'label' => 'No check' ),
				'not an array',
			),
			$subject,
			'Page /export-navigator/india/ [en]'
		);

		$this->assertSame( array( 200052 ), $seen );
		$this->assertCount( 5, $warnings );
		$this->assertStringContainsString( '"Funding programs" found nothing on this site', $warnings[0] );
		$this->assertStringContainsString( 'checking "Partners" failed: taxonomy missing', $warnings[1] );
		$this->assertStringContainsString( '"Unnamed dependency" found nothing', $warnings[2] );
		$this->assertStringStartsWith( 'Page /export-navigator/india/ [en]: a query dependency is malformed', $warnings[3] );
		$this->assertStringContainsString( 'malformed', $warnings[4] );
	}

	/**
	 * No declared dependencies, no warnings.
	 */
	public function test_nothing_declared(): void {
		$this->assertSame( array(), Query_Dependencies::run( array(), null, 'Page' ) );
	}
}
