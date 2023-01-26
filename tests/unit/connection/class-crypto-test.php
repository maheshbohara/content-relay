<?php
/**
 * Tests for Key_Codec, Signer and Vault.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Connection;

use ContentRelay\Connection\Key_Codec;
use ContentRelay\Connection\Signer;
use ContentRelay\Connection\Vault;
use ContentRelay\Support\Base64_Url;
use PHPUnit\Framework\TestCase;

/**
 * Connection cryptography tests.
 */
final class Crypto_Test extends TestCase {

	/**
	 * A connection key round-trips; malformed keys are rejected.
	 */
	public function test_key_codec_round_trip_and_rejection(): void {
		$codec = new Key_Codec();
		$pair  = Signer::generate_keypair();
		$key   = $codec->encode( 'https://prod.example.com/', '0123456789abcdef', $pair['secret_key'] );

		$decoded = $codec->decode( "  {$key}\n" );
		$this->assertNotNull( $decoded );
		$this->assertSame( 'https://prod.example.com', $decoded['url'] );
		$this->assertSame( '0123456789abcdef', $decoded['kid'] );
		$this->assertSame( $pair['secret_key'], $decoded['secret_key'] );

		$this->assertNull( $codec->decode( 'nope' ) );
		$this->assertNull( $codec->decode( Key_Codec::PREFIX . 'not*base64' ) );
		$this->assertNull( $codec->decode( $codec->encode( 'https://x.test', 'UPPERCASE-KID!!!', $pair['secret_key'] ) ) );
		$this->assertNull( $codec->decode( $codec->encode( 'https://x.test', '0123456789abcdef', 'short' ) ) );
		$this->assertNull( $codec->decode( $codec->encode( 'ftp://x.test', '0123456789abcdef', $pair['secret_key'] ) ) );
	}

	/**
	 * A signature verifies only for the exact request it was made for.
	 */
	public function test_signature_binds_every_request_part(): void {
		$signer    = new Signer();
		$pair      = Signer::generate_keypair();
		$canonical = $signer->canonical(
			'post',
			'wp-json/content-relay/v1/sessions',
			array(
				'b' => 2,
				'a' => 'x y',
			),
			1789000000,
			'nonce1',
			'{"k":1}'
		);
		$signature = $signer->sign( $canonical, $pair['secret_key'] );

		$this->assertTrue( $signer->verify( $canonical, $signature, $pair['public_key'] ) );
		$this->assertSame(
			$canonical,
			$signer->canonical(
				'POST',
				'/wp-json/content-relay/v1/sessions',
				array(
					'a' => 'x y',
					'b' => 2,
				),
				1789000000,
				'nonce1',
				'{"k":1}'
			)
		);

		$tampered = array(
			$signer->canonical(
				'PUT',
				'/wp-json/content-relay/v1/sessions',
				array(
					'a' => 'x y',
					'b' => 2,
				),
				1789000000,
				'nonce1',
				'{"k":1}'
			),
			$signer->canonical(
				'POST',
				'/wp-json/content-relay/v1/other',
				array(
					'a' => 'x y',
					'b' => 2,
				),
				1789000000,
				'nonce1',
				'{"k":1}'
			),
			$signer->canonical(
				'POST',
				'/wp-json/content-relay/v1/sessions',
				array(
					'a' => 'x y',
					'b' => 3,
				),
				1789000000,
				'nonce1',
				'{"k":1}'
			),
			$signer->canonical(
				'POST',
				'/wp-json/content-relay/v1/sessions',
				array(
					'a' => 'x y',
					'b' => 2,
				),
				1789000001,
				'nonce1',
				'{"k":1}'
			),
			$signer->canonical(
				'POST',
				'/wp-json/content-relay/v1/sessions',
				array(
					'a' => 'x y',
					'b' => 2,
				),
				1789000000,
				'nonce2',
				'{"k":1}'
			),
			$signer->canonical(
				'POST',
				'/wp-json/content-relay/v1/sessions',
				array(
					'a' => 'x y',
					'b' => 2,
				),
				1789000000,
				'nonce1',
				'{"k":2}'
			),
		);
		foreach ( $tampered as $other ) {
			$this->assertFalse( $signer->verify( $other, $signature, $pair['public_key'] ) );
		}

		$this->assertFalse( $signer->verify( $canonical, $signature, Signer::generate_keypair()['public_key'] ) );
		$this->assertFalse( $signer->verify( $canonical, 'garbage', $pair['public_key'] ) );
		$this->assertFalse( $signer->verify( $canonical, $signature, 'short' ) );
	}

	/**
	 * Secrets decrypt only with the key material they were encrypted with.
	 */
	public function test_vault_round_trip_and_wrong_material(): void {
		$vault  = new Vault( str_repeat( 'a', 64 ) );
		$cipher = $vault->encrypt( 'secret-bytes' );

		$this->assertSame( 'secret-bytes', $vault->decrypt( $cipher ) );
		$this->assertNotSame( $cipher, $vault->encrypt( 'secret-bytes' ) );
		$this->assertNull( ( new Vault( str_repeat( 'b', 64 ) ) )->decrypt( $cipher ) );
		$this->assertNull( $vault->decrypt( 'garbage' ) );
		$this->assertNull( $vault->decrypt( Base64_Url::encode( 'too short' ) ) );
	}

	/**
	 * Without constants there is no vault.
	 */
	public function test_vault_needs_configured_constants(): void {
		$this->assertNull( Vault::from_config() );
	}
}
