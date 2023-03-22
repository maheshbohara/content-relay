<?php
/**
 * WP-CLI: receivers this site relays to.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

use ContentRelay\Config;
use ContentRelay\Connection\Client;
use ContentRelay\Connection\Connection_Exception;
use ContentRelay\Connection\Connections;
use ContentRelay\Connection\Key_Codec;
use ContentRelay\Connection\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * Adds, tests, lists and removes connections to receiving sites.
 */
final class Connections_Command {

	/**
	 * Adds a connection from a key issued by the receiver (`wp content-relay keys generate` there).
	 *
	 * The key is checked with a handshake before it is stored; its secret half is stored encrypted.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The `contentrelay1.…` key.
	 *
	 * --label=<label>
	 * : Name used with `--to`, e.g. `prod`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-relay connections add contentrelay1.eyJ2Ijox… --label=prod
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function add( array $args, array $assoc_args ): void {
		$store = self::store();
		$label = (string) ( $assoc_args['label'] ?? '' );
		if ( ! Connections::is_valid_label( $label ) ) {
			\WP_CLI::error( __( '--label is required: lowercase letters, digits, "-" and "_".', 'content-relay' ) );
		}

		$key = ( new Key_Codec() )->decode( (string) $args[0] );
		if ( null === $key ) {
			\WP_CLI::error( __( 'That is not a valid Content Relay key.', 'content-relay' ) );
			return;
		}
		if ( ! Config::is_allowed_receiver_url( $key['url'] ) ) {
			\WP_CLI::error( __( 'The receiver must use HTTPS (CONTENT_RELAY_ALLOW_HTTP allows http:// for local testing).', 'content-relay' ) );
		}

		$environment = self::handshake(
			new Client(
				array(
					'url'        => $key['url'],
					'kid'        => $key['kid'],
					'secret_key' => $key['secret_key'],
				)
			)
		);

		try {
			$store->add( $label, $key, get_current_user_id() );
		} catch ( Connection_Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		/* translators: 1: connection label, 2: URL of the receiving site, 3: WordPress version, 4: theme stylesheet, 5: theme version. */
		\WP_CLI::success( sprintf( __( 'Connection "%1$s" added: %2$s (WordPress %3$s, theme %4$s %5$s).', 'content-relay' ), $label, $environment['home_url'] ?? $key['url'], $environment['wp'] ?? '?', $environment['theme']['stylesheet'] ?? '?', $environment['theme']['version'] ?? '?' ) );
	}
}
