<?php
/**
 * Receivers this site can relay to.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

use ContentRelay\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Connection store (spec §5.2, §16). The signing key is encrypted with key material from wp-config.php, so a copied
 * database carries connection records but not the ability to use them.
 */
final class Connections {

	/**
	 * Option holding the connections.
	 */
	public const OPTION = 'content_relay_connections';

	/**
	 * Vault.
	 *
	 * @var Vault
	 */
	private Vault $vault;

	/**
	 * Constructor.
	 *
	 * @param Vault $vault Vault built from wp-config.php key material.
	 */
	public function __construct( Vault $vault ) {
		$this->vault = $vault;
	}

	/**
	 * Whether a label is acceptable: lowercase letters, digits, `-` and `_`, up to 64 characters.
	 *
	 * @param string $label Label.
	 */
	public static function is_valid_label( string $label ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $label );
	}

	/**
	 * Stores a connection, replacing one with the same label.
	 *
	 * @param string                                              $label   Label.
	 * @param array{url: string, kid: string, secret_key: string} $key     Decoded connection key.
	 * @param int                                                 $user_id Administrator adding the connection.
	 *
	 * @throws Connection_Exception When the label or URL is not acceptable.
	 */
	public function add( string $label, array $key, int $user_id ): void {
		if ( ! self::is_valid_label( $label ) ) {
			throw new Connection_Exception( 'Labels use lowercase letters, digits, "-" and "_" (up to 64 characters).' );
		}
		if ( ! Config::is_allowed_receiver_url( $key['url'] ) ) {
			throw new Connection_Exception( 'The receiver must use HTTPS (CONTENT_RELAY_ALLOW_HTTP allows http:// for local testing).' );
		}

		$connections           = $this->raw();
		$connections[ $label ] = array(
			'url'          => $key['url'],
			'kid'          => $key['kid'],
			'sk_encrypted' => $this->vault->encrypt( $key['secret_key'] ),
			'added_by'     => $user_id,
			'added_at'     => gmdate( 'c' ),
		);
		update_option( self::OPTION, $connections, false );
	}

	/**
	 * Every connection, without secrets.
	 *
	 * @return array<string, array{label: string, url: string, kid: string, added_by: int, added_at: string}>
	 */
	public function all(): array {
		$list = array();
		foreach ( $this->raw() as $label => $record ) {
			$list[ (string) $label ] = array(
				'label'    => (string) $label,
				'url'      => (string) ( $record['url'] ?? '' ),
				'kid'      => (string) ( $record['kid'] ?? '' ),
				'added_by' => (int) ( $record['added_by'] ?? 0 ),
				'added_at' => (string) ( $record['added_at'] ?? '' ),
			);
		}

		return $list;
	}

	/**
	 * A connection with its decrypted signing key.
	 *
	 * @param string $label Label.
	 * @return array{label: string, url: string, kid: string, secret_key: string}
	 *
	 * @throws Connection_Exception When the connection does not exist or cannot be decrypted here.
	 */
	public function get( string $label ): array {
		$record = $this->raw()[ $label ] ?? null;
		if ( ! is_array( $record ) ) {
			throw new Connection_Exception( sprintf( 'There is no connection named "%s".', esc_html( $label ) ) );
		}

		$secret = $this->vault->decrypt( (string) ( $record['sk_encrypted'] ?? '' ) );
		if ( null === $secret ) {
			throw new Connection_Exception( sprintf( 'The key of connection "%s" cannot be decrypted on this site (it was stored with other wp-config.php key material). Add the connection again.', esc_html( $label ) ) );
		}
		if ( ! Config::is_allowed_receiver_url( (string) $record['url'] ) ) {
			throw new Connection_Exception( sprintf( 'Connection "%s" uses plain HTTP, which this site does not allow.', esc_html( $label ) ) );
		}

		return array(
			'label'      => $label,
			'url'        => (string) $record['url'],
			'kid'        => (string) $record['kid'],
			'secret_key' => $secret,
		);
	}

	/**
	 * Removes a connection.
	 *
	 * @param string $label Label.
	 * @return bool False when it does not exist.
	 */
	public function remove( string $label ): bool {
		$connections = $this->raw();
		if ( ! isset( $connections[ $label ] ) ) {
			return false;
		}

		unset( $connections[ $label ] );
		update_option( self::OPTION, $connections, false );

		return true;
	}

	/**
	 * Stored records.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function raw(): array {
		$connections = get_option( self::OPTION, array() );

		return is_array( $connections ) ? $connections : array();
	}
}
