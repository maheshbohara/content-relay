<?php
/**
 * WP-CLI: keys this site issues to senders.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

use ContentRelay\Config;
use ContentRelay\Connection\Receiver_Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Issues, lists and revokes the keys that let another site relay content to this one.
 */
final class Keys_Command {

	/**
	 * Issues a key. It is printed once: paste it into `wp content-relay connections add` on the sending site.
	 *
	 * A key is accepted only while the administrator who issued it keeps that role, so run this with `--user=<admin>`.
	 *
	 * ## OPTIONS
	 *
	 * --label=<label>
	 * : What the key is for, e.g. "dev.example.com".
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-relay keys generate --label=dev --user=admin
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function generate( array $args, array $assoc_args ): void {
		unset( $args );
		self::require_receiving();

		$label = trim( (string) ( $assoc_args['label'] ?? '' ) );
		if ( '' === $label ) {
			\WP_CLI::error( __( '--label is required.', 'content-relay' ) );
		}

		$user = wp_get_current_user();
		if ( ! $user->exists() || ! user_can( $user, 'manage_options' ) ) {
			\WP_CLI::error( __( 'A key must be issued by an administrator. Run this with --user=<administrator>.', 'content-relay' ) );
		}

		$issued = ( new Receiver_Keys() )->generate( sanitize_text_field( $label ), $user->ID );

		/* translators: %s: key id. */
		\WP_CLI::log( sprintf( __( 'Key id: %s', 'content-relay' ), $issued['kid'] ) );
		\WP_CLI::log( $issued['key'] );
		\WP_CLI::success( __( 'Copy the key above to the sending site now. It is not stored here and cannot be shown again.', 'content-relay' ) );
	}

	/**
	 * Lists issued keys (never their secrets — this site does not have them).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		unset( $args );

		$rows = array();
		foreach ( ( new Receiver_Keys() )->all() as $kid => $record ) {
			$user   = get_userdata( (int) ( $record['created_by'] ?? 0 ) );
			$rows[] = array(
				'kid'        => $kid,
				'label'      => $record['label'] ?? '',
				'created_by' => $user instanceof \WP_User ? $user->user_login : '(deleted user)',
				'created_at' => $record['created_at'] ?? '',
				'last_used'  => $record['last_used'] ?? '',
				'status'     => empty( $record['revoked_at'] ) ? 'active' : 'revoked ' . $record['revoked_at'],
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'kid', 'label', 'created_by', 'created_at', 'last_used', 'status' ) );
	}

	/**
	 * Revokes a key. Requests signed with it are refused from now on.
	 *
	 * ## OPTIONS
	 *
	 * <kid>
	 * : Key id.
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	public function revoke( array $args ): void {
		if ( ! ( new Receiver_Keys() )->revoke( (string) $args[0] ) ) {
			/* translators: %s: key id. */
			\WP_CLI::error( sprintf( __( 'No active key %s.', 'content-relay' ), $args[0] ) );
		}

		/* translators: %s: key id. */
		\WP_CLI::success( sprintf( __( 'Key %s revoked.', 'content-relay' ), $args[0] ) );
	}

	/**
	 * Stops unless receiving is on.
	 */
	private static function require_receiving(): void {
		if ( ! Config::can_receive() ) {
			\WP_CLI::error( __( 'Receiving is off on this site. Switch it on under Settings → Content Relay first.', 'content-relay' ) );
		}
	}
}
