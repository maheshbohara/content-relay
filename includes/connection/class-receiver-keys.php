<?php
/**
 * Keys this site issued to senders.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

use ContentRelay\Support\Base64_Url;

defined( 'ABSPATH' ) || exit;

/**
 * Receiver key store (spec §5.2, §16). Holds public keys only: the secret half is shown once and never stored, so a
 * leaked receiver database cannot sign a request.
 */
final class Receiver_Keys implements Key_Store {

	/**
	 * Option holding the keys.
	 */
	public const OPTION = 'content_relay_receiver_keys';

	/**
	 * Minimum seconds between two `last_used` writes for one key.
	 */
	private const TOUCH_INTERVAL = 60;

	/**
	 * Issues a new key.
	 *
	 * @param string $label   Human label (who or what the key is for).
	 * @param int    $user_id Issuing administrator; relays with this key run as this user.
	 * @return array{kid: string, key: string} Key id and the connection key to paste on the sender (shown once).
	 */
	public function generate( string $label, int $user_id ): array {
		$pair = Signer::generate_keypair();
		$kid  = bin2hex( random_bytes( 8 ) );
		$keys = $this->all();

		$keys[ $kid ] = array(
			'public_key' => Base64_Url::encode( $pair['public_key'] ),
			'label'      => $label,
			'created_by' => $user_id,
			'created_at' => gmdate( 'c' ),
			'last_used'  => null,
			'revoked_at' => null,
		);
		$this->save( $keys );

		$key = ( new Key_Codec() )->encode( untrailingslashit( (string) get_option( 'home' ) ), $kid, $pair['secret_key'] );
		sodium_memzero( $pair['secret_key'] );

		return array(
			'kid' => $kid,
			'key' => $key,
		);
	}

	/**
	 * Every key, revoked ones included.
	 *
	 * @return array<string, array<string, mixed>> Kid => record.
	 */
	public function all(): array {
		$keys = get_option( self::OPTION, array() );

		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * One key record.
	 *
	 * @param string $kid Key id.
	 * @return array<string, mixed>|null
	 */
	public function find( string $kid ): ?array {
		return $this->all()[ $kid ] ?? null;
	}

	/**
	 * Revokes a key. Takes effect on the next request.
	 *
	 * @param string $kid Key id.
	 * @return bool False when the key does not exist or is already revoked.
	 */
	public function revoke( string $kid ): bool {
		$keys = $this->all();
		if ( ! isset( $keys[ $kid ] ) || ! empty( $keys[ $kid ]['revoked_at'] ) ) {
			return false;
		}

		$keys[ $kid ]['revoked_at'] = gmdate( 'c' );
		$this->save( $keys );

		return true;
	}

	/**
	 * Records that a key was used, at most once a minute.
	 *
	 * @param string $kid Key id.
	 * @param int    $now Unix time.
	 */
	public function touch( string $kid, int $now ): void {
		$keys = $this->all();
		if ( ! isset( $keys[ $kid ] ) ) {
			return;
		}

		$last = is_string( $keys[ $kid ]['last_used'] ?? null ) ? (int) strtotime( $keys[ $kid ]['last_used'] ) : 0;
		if ( $now - $last < self::TOUCH_INTERVAL ) {
			return;
		}

		$keys[ $kid ]['last_used'] = gmdate( 'c', $now );
		$this->save( $keys );
	}

	/**
	 * Binary public key of an active key.
	 *
	 * @param string $kid Key id.
	 * @return string|null Null when the key is unknown or revoked.
	 */
	public function public_key_for( string $kid ): ?string {
		$record = $this->find( $kid );
		if ( null === $record || ! empty( $record['revoked_at'] ) || ! is_string( $record['public_key'] ?? null ) ) {
			return null;
		}

		return Base64_Url::decode( $record['public_key'] );
	}

	/**
	 * Stores the keys without autoloading them.
	 *
	 * @param array<string, array<string, mixed>> $keys Keys.
	 */
	private function save( array $keys ): void {
		update_option( self::OPTION, $keys, false );
	}
}
