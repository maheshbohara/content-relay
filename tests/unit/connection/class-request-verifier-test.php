<?php
/**
 * Tests for Request_Verifier.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Connection;

use ContentRelay\Connection\Key_Store;
use ContentRelay\Connection\Replay_Guard;
use ContentRelay\Connection\Request_Verifier;
use ContentRelay\Connection\Signer;
use ContentRelay\Support\Base64_Url;
use PHPUnit\Framework\TestCase;

/**
 * Request verification tests (spec §5.3, scenario 11 logic).
 */
final class Request_Verifier_Test extends TestCase {

	private const KID   = '0123456789abcdef';
	private const NOW   = 1789000000;
	private const ROUTE = '/content-relay/v1/sessions/0f8e7c1a-1b2c-4d5e-8f90-a1b2c3d4e5f6/files/da39a3ee5e6b4b0d3255bfef95601890afd80709';

	/**
	 * Key pair.
	 *
	 * @var array{public_key: string, secret_key: string}
	 */
	private array $pair;

	/**
	 * Public keys by kid.
	 *
	 * @var array<string, string>
	 */
	private array $keys = array();

	/**
	 * Seen nonces.
	 *
	 * @var array<string, bool>
	 */
	private array $seen = array();

	/**
	 * Fresh key pair and stores.
	 */
	protected function setUp(): void {
		$this->pair = Signer::generate_keypair();
		$this->keys = array( self::KID => $this->pair['public_key'] );
		$this->seen = array();
	}
}
