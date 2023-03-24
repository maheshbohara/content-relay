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
	 * Relays made with the key run as the administrator who issued it, so run this with `--user=<admin>`.
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
			\WP_CLI::error( __( 'Relays run as the administrator who issued the key. Run this with --user=<administrator>.', 'content-relay' ) );
		}

		$issued = ( new Receiver_Keys() )->generate( sanitize_text_field( $label ), $user->ID );

		/* translators: %s: key id. */
		\WP_CLI::log( sprintf( __( 'Key id: %s', 'content-relay' ), $issued['kid'] ) );
		\WP_CLI::log( $issued['key'] );
		\WP_CLI::success( __( 'Copy the key above to the sending site now. It is not stored here and cannot be shown again.', 'content-relay' ) );
	}
}
