<?php
/**
 * Tests for Staging.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Storage;

use ContentRelay\Storage\Staging;
use PHPUnit\Framework\TestCase;

/**
 * Chunked, resumable, SHA-1-verified uploads (scenario 16 logic).
 */
final class Staging_Test extends TestCase {

	private const ID = '0f8e7c1a-1b2c-4d5e-8f90-a1b2c3d4e5f6';

	/**
	 * Temporary uploads directory.
	 *
	 * @var string
	 */
	private string $uploads;

	/**
	 * Staging under test.
	 *
	 * @var Staging
	 */
	private Staging $staging;

	/**
	 * Fresh staging area with one session.
	 */
	protected function setUp(): void {
		$this->uploads = sys_get_temp_dir() . '/content-relay-staging-test-' . bin2hex( random_bytes( 4 ) );
		$this->staging = new Staging( $this->uploads . '/content-relay/staging' );
		$this->staging->create(
			self::ID,
			array(
				'id'     => self::ID,
				'status' => Staging::STATUS_PLANNED,
			),
			array( 'schema' => 1 )
		);
	}

	/**
	 * Removes the temporary directory.
	 */
	protected function tearDown(): void {
		$this->staging->delete( self::ID );
		foreach ( array( '/content-relay/staging/index.php', '/content-relay/index.php', '/content-relay/.htaccess' ) as $file ) {
			if ( file_exists( $this->uploads . $file ) ) {
				unlink( $this->uploads . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}
		foreach ( array( '/content-relay/staging', '/content-relay', '' ) as $dir ) {
			if ( is_dir( $this->uploads . $dir ) ) {
				rmdir( $this->uploads . $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup.
			}
		}
	}
}
