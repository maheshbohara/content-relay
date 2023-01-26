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

	/**
	 * A correctly signed request verifies once; the same request again is a replay.
	 */
	public function test_valid_request_then_replay(): void {
		$request = $this->signed( 'PUT', array( 'offset' => '1048576' ), 'chunk-bytes', self::NOW );

		$this->assertSame( array( 'ok' => true, 'reason' => '', 'kid' => self::KID ), $this->verify( $request ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact expected value.
		$this->assertSame( 'replay', $this->verify( $request )['reason'] );
	}

	/**
	 * Timestamps within 300 s pass; 301 s either way fails.
	 */
	public function test_clock_skew(): void {
		$this->assertTrue( $this->verify( $this->signed( 'POST', array(), '{}', self::NOW - 300 ) )['ok'] );
		$this->assertTrue( $this->verify( $this->signed( 'POST', array(), '{}', self::NOW + 300 ) )['ok'] );
		$this->assertSame( 'skew', $this->verify( $this->signed( 'POST', array(), '{}', self::NOW - 301 ) )['reason'] );
		$this->assertSame( 'skew', $this->verify( $this->signed( 'POST', array(), '{}', self::NOW + 301 ) )['reason'] );
	}

	/**
	 * Changing any signed part — or signing with another key — fails the signature, and a failed request does not
	 * consume its nonce.
	 */
	public function test_tampering_fails_signature_without_burning_the_nonce(): void {
		$request = $this->signed( 'PUT', array( 'offset' => '0' ), 'chunk', self::NOW );

		$tampered = array(
			array_merge( $request, array( 'body' => 'chunk!' ) ),
			array_merge( $request, array( 'method' => 'POST' ) ),
			array_merge( $request, array( 'route' => '/content-relay/v1/sessions' ) ),
			array_merge( $request, array( 'query' => array( 'offset' => '1' ) ) ),
		);
		foreach ( $tampered as $other ) {
			$this->assertSame( 'signature', $this->verify( $other )['reason'] );
		}

		$foreign = Signer::generate_keypair();
		$signer  = new Signer();
		$forged  = $request;

		$forged['headers'][ Request_Verifier::HEADER_SIGNATURE ] = $signer->sign( $signer->canonical( 'PUT', self::ROUTE, array( 'offset' => '0' ), self::NOW, $request['headers'][ Request_Verifier::HEADER_NONCE ], 'chunk' ), $foreign['secret_key'] );
		$this->assertSame( 'signature', $this->verify( $forged )['reason'] );

		$this->assertTrue( $this->verify( $request )['ok'] );
	}

	/**
	 * Unknown and revoked keys, malformed headers and array query values are refused.
	 */
	public function test_refusals(): void {
		$request = $this->signed( 'POST', array(), '{}', self::NOW );

		$unknown = $request;

		$unknown['headers'][ Request_Verifier::HEADER_KID ] = 'fedcba9876543210';
		$this->assertSame( 'kid', $this->verify( $unknown )['reason'] );

		$this->keys = array();
		$this->assertSame( 'kid', $this->verify( $request )['reason'] );
		$this->keys = array( self::KID => $this->pair['public_key'] );

		foreach ( array( Request_Verifier::HEADER_KID, Request_Verifier::HEADER_TIMESTAMP, Request_Verifier::HEADER_NONCE, Request_Verifier::HEADER_SIGNATURE ) as $header ) {
			$missing = $request;
			unset( $missing['headers'][ $header ] );
			$this->assertSame( 'headers', $this->verify( $missing )['reason'], $header );
		}

		$short = $request;

		$short['headers'][ Request_Verifier::HEADER_NONCE ] = 'abc';
		$this->assertSame( 'headers', $this->verify( $short )['reason'] );

		$array_query = array_merge( $request, array( 'query' => array( 'offset' => array( '1' ) ) ) );
		$this->assertSame( 'query', $this->verify( $array_query )['reason'] );
	}

	/**
	 * Builds a signed request.
	 *
	 * @param string                $method    Method.
	 * @param array<string, string> $query     Query.
	 * @param string                $body      Body.
	 * @param int                   $timestamp Timestamp.
	 * @return array{method: string, route: string, query: array<string, mixed>, headers: array<string, string>, body: string}
	 */
	private function signed( string $method, array $query, string $body, int $timestamp ): array {
		$signer = new Signer();
		$nonce  = Base64_Url::encode( random_bytes( 32 ) );

		return array(
			'method'  => $method,
			'route'   => self::ROUTE,
			'query'   => $query,
			'headers' => array(
				Request_Verifier::HEADER_KID       => self::KID,
				Request_Verifier::HEADER_TIMESTAMP => (string) $timestamp,
				Request_Verifier::HEADER_NONCE     => $nonce,
				Request_Verifier::HEADER_SIGNATURE => $signer->sign( $signer->canonical( $method, self::ROUTE, $query, $timestamp, $nonce, $body ), $this->pair['secret_key'] ),
			),
			'body'    => $body,
		);
	}

	/**
	 * Verifies a request against the test stores.
	 *
	 * @param array{method: string, route: string, query: array<string, mixed>, headers: array<string, string>, body: string} $request Request.
	 * @return array{ok: bool, reason: string, kid: string}
	 */
	private function verify( array $request ): array {
		$test = $this;

		$keys = new class( $test ) implements Key_Store {
			/**
			 * Test case.
			 *
			 * @var Request_Verifier_Test
			 */
			private Request_Verifier_Test $test;

			/**
			 * Constructor.
			 *
			 * @param Request_Verifier_Test $test Test case.
			 */
			public function __construct( Request_Verifier_Test $test ) {
				$this->test = $test;
			}

			/**
			 * Public key lookup.
			 *
			 * @param string $kid Key id.
			 */
			public function public_key_for( string $kid ): ?string {
				return $this->test->key( $kid );
			}
		};

		$replay = new class( $test ) implements Replay_Guard {
			/**
			 * Test case.
			 *
			 * @var Request_Verifier_Test
			 */
			private Request_Verifier_Test $test;

			/**
			 * Constructor.
			 *
			 * @param Request_Verifier_Test $test Test case.
			 */
			public function __construct( Request_Verifier_Test $test ) {
				$this->test = $test;
			}

			/**
			 * Nonce store.
			 *
			 * @param string $kid     Key id.
			 * @param string $nonce   Nonce.
			 * @param int    $seconds Seconds.
			 */
			public function remember( string $kid, string $nonce, int $seconds ): bool {
				return $this->test->remember( $kid . '|' . $nonce );
			}
		};

		return ( new Request_Verifier( new Signer(), $keys, $replay ) )->verify( $request['method'], $request['route'], $request['query'], $request['headers'], $request['body'], self::NOW );
	}

	/**
	 * Test key store.
	 *
	 * @param string $kid Key id.
	 */
	public function key( string $kid ): ?string {
		return $this->keys[ $kid ] ?? null;
	}

	/**
	 * Test replay store.
	 *
	 * @param string $id Kid + nonce.
	 */
	public function remember( string $id ): bool {
		if ( isset( $this->seen[ $id ] ) ) {
			return false;
		}
		$this->seen[ $id ] = true;

		return true;
	}
}
