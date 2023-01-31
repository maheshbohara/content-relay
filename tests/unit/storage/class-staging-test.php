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

	/**
	 * Creating a session writes the record, manifest and the protection files.
	 */
	public function test_create_and_read(): void {
		$this->assertSame( Staging::STATUS_PLANNED, $this->staging->read( self::ID )['status'] ?? null );
		$this->assertSame( array( 'schema' => 1 ), $this->staging->manifest( self::ID ) );
		$this->assertFileExists( $this->uploads . '/content-relay/.htaccess' );
		$this->assertNull( $this->staging->read( 'not-a-uuid' ) );

		$updated = $this->staging->update( self::ID, array( 'status' => Staging::STATUS_APPLIED ) );
		$this->assertSame( Staging::STATUS_APPLIED, $updated['status'] );
	}

	/**
	 * Chunks append only at the received offset; an interrupted upload resumes and completes after SHA-1 verification.
	 */
	public function test_chunks_resume_and_verify(): void {
		$file = random_bytes( 2500 );
		$sha1 = sha1( $file );

		$this->assertSame( array( 'status' => 'partial', 'received' => 1000 ), $this->staging->write_chunk( self::ID, $sha1, 0, substr( $file, 0, 1000 ), 2500 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact expected value.
		$this->assertSame( 1000, $this->staging->received( self::ID, $sha1 ) );
		$this->assertFileDoesNotExist( $this->staging->files_dir( self::ID ) . '/' . $sha1 );

		// A retried first chunk and a skipped-ahead chunk are both refused with the real offset.
		$this->assertSame( array( 'status' => 'offset', 'received' => 1000 ), $this->staging->write_chunk( self::ID, $sha1, 0, substr( $file, 0, 1000 ), 2500 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact expected value.
		$this->assertSame( 'offset', $this->staging->write_chunk( self::ID, $sha1, 2000, substr( $file, 2000 ), 2500 )['status'] );

		// A chunk that overruns the declared size is refused.
		$this->assertSame( 'too-large', $this->staging->write_chunk( self::ID, $sha1, 1000, substr( $file, 1000 ) . 'extra', 2500 )['status'] );

		$this->assertSame( 'partial', $this->staging->write_chunk( self::ID, $sha1, 1000, substr( $file, 1000, 1000 ), 2500 )['status'] );
		$this->assertSame( array( 'status' => 'complete', 'received' => 2500 ), $this->staging->write_chunk( self::ID, $sha1, 2000, substr( $file, 2000 ), 2500 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact expected value.
		$this->assertSame( $file, file_get_contents( $this->staging->files_dir( self::ID ) . '/' . $sha1 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test file.
		$this->assertFileDoesNotExist( $this->staging->files_dir( self::ID ) . '/' . $sha1 . '.part' );

		// Completed files are idempotent.
		$this->assertSame( 'complete', $this->staging->write_chunk( self::ID, $sha1, 0, 'x', 2500 )['status'] );
	}

	/**
	 * Bytes that do not hash to the expected SHA-1 are discarded.
	 */
	public function test_hash_mismatch_discards_the_partial_file(): void {
		$file = random_bytes( 100 );
		$sha1 = sha1( $file );

		$this->assertSame( array( 'status' => 'mismatch', 'received' => 0 ), $this->staging->write_chunk( self::ID, $sha1, 0, random_bytes( 100 ), 100 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact expected value.
		$this->assertSame( 0, $this->staging->received( self::ID, $sha1 ) );
		$this->assertSame( 'complete', $this->staging->write_chunk( self::ID, $sha1, 0, $file, 100 )['status'] );
	}

	/**
	 * Expired unapplied sessions are deleted; expired applied sessions keep their record but lose their files.
	 */
	public function test_purge(): void {
		$applied = '1f8e7c1a-1b2c-4d5e-8f90-a1b2c3d4e5f6';
		$this->staging->create(
			$applied,
			array(
				'id'     => $applied,
				'status' => Staging::STATUS_APPLIED,
			),
			array( 'schema' => 1 )
		);

		$this->assertSame( 0, $this->staging->purge( time(), 3600 ) );
		$this->assertSame( 2, $this->staging->purge( time() + 7200, 3600 ) );
		$this->assertNull( $this->staging->read( self::ID ) );
		$this->assertSame( Staging::STATUS_APPLIED, $this->staging->read( $applied )['status'] ?? null );
		$this->assertNull( $this->staging->manifest( $applied ) );

		$this->staging->delete( $applied );
	}
}
