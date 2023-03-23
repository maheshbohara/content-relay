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

	/**
	 * Lists connections.
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

		$option = get_option( Connections::OPTION, array() );
		$rows   = array();
		foreach ( is_array( $option ) ? $option : array() as $label => $record ) {
			$user   = get_userdata( (int) ( $record['added_by'] ?? 0 ) );
			$rows[] = array(
				'label'    => $label,
				'url'      => $record['url'] ?? '',
				'kid'      => $record['kid'] ?? '',
				'added_by' => $user instanceof \WP_User ? $user->user_login : '',
				'added_at' => $record['added_at'] ?? '',
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'label', 'url', 'kid', 'added_by', 'added_at' ) );
	}

	/**
	 * Runs a handshake with a receiver and prints its environment.
	 *
	 * ## OPTIONS
	 *
	 * <label>
	 * : Connection label.
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	public function test( array $args ): void {
		$environment = self::handshake( self::client( (string) $args[0] ) );

		$skew  = (int) ( $environment['server_time'] ?? 0 ) - time();
		$theme = wp_get_theme();
		$rows  = array(
			array(
				'field' => 'home_url',
				'value' => $environment['home_url'] ?? '',
			),
			array(
				'field' => 'plugin / schema',
				'value' => sprintf( '%1$s / %2$s', $environment['plugin'] ?? '?', $environment['schema'] ?? '?' ),
			),
			array(
				'field' => 'php / wp',
				'value' => sprintf( '%1$s / %2$s', $environment['php'] ?? '?', $environment['wp'] ?? '?' ),
			),
			array(
				'field' => 'theme',
				'value' => sprintf( '%1$s %2$s', $environment['theme']['stylesheet'] ?? '?', $environment['theme']['version'] ?? '?' ),
			),
			array(
				'field' => 'language',
				'value' => sprintf( '%1$s (default %2$s; %3$s)', $environment['language']['adapter'] ?? '?', $environment['language']['default'] ?? '-', implode( ', ', (array) ( $environment['language']['active'] ?? array() ) ) ),
			),
			array(
				'field' => 'plugins',
				'value' => (string) wp_json_encode( $environment['plugins'] ?? array() ),
			),
			array(
				'field' => 'max chunk',
				'value' => size_format( (int) ( $environment['limits']['max_chunk_bytes'] ?? 0 ) ),
			),
			array(
				'field' => 'clock difference',
				'value' => sprintf( '%+d s', $skew ),
			),
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );

		if ( abs( $skew ) > 60 ) {
			\WP_CLI::warning( __( 'The clocks of the two sites differ by more than a minute; requests are refused beyond 300 s.', 'content-relay' ) );
		}
		if ( ( $environment['theme']['stylesheet'] ?? '' ) !== get_stylesheet() || (string) ( $environment['theme']['version'] ?? '' ) !== (string) $theme->get( 'Version' ) ) {
			\WP_CLI::warning( __( 'The receiver runs a different theme or theme version; relays will be blocked until it is deployed.', 'content-relay' ) );
		}

		\WP_CLI::success( __( 'Handshake succeeded.', 'content-relay' ) );
	}

	/**
	 * Removes a connection. The receiver's key stays valid until it is revoked there.
	 *
	 * ## OPTIONS
	 *
	 * <label>
	 * : Connection label.
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	public function remove( array $args ): void {
		if ( ! self::store()->remove( (string) $args[0] ) ) {
			/* translators: %s: connection label. */
			\WP_CLI::error( sprintf( __( 'There is no connection named "%s".', 'content-relay' ), $args[0] ) );
		}

		/* translators: %s: connection label. */
		\WP_CLI::success( sprintf( __( 'Connection "%s" removed. Revoke its key on the receiver too.', 'content-relay' ), $args[0] ) );
	}

	/**
	 * Client for a stored connection, or stops with an error.
	 *
	 * @param string $label Connection label.
	 */
	public static function client( string $label ): Client {
		try {
			return new Client( self::store()->get( $label ) );
		} catch ( Connection_Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		exit( 1 );
	}

	/**
	 * Runs a handshake and checks the receiver speaks this site's manifest schema, or stops with an error.
	 *
	 * @param Client $client Client.
	 * @return array<string, mixed> Receiver environment.
	 */
	public static function handshake( Client $client ): array {
		$response = $client->handshake();
		if ( ! $response['ok'] ) {
			/* translators: 1: HTTP status code, 2: error code, 3: error message. */
			\WP_CLI::error( sprintf( __( 'Handshake failed (HTTP %1$d, %2$s): %3$s', 'content-relay' ), $response['status'], $response['code'], $response['message'] ) );
		}

		$environment = $response['data'];
		if ( CONTENT_RELAY_SCHEMA !== ( $environment['schema'] ?? null ) ) {
			/* translators: 1: manifest schema the receiver reads (JSON), 2: manifest schema this site writes. */
			\WP_CLI::error( sprintf( __( 'The receiver reads manifest schema %1$s; this site writes schema %2$d. Update the plugin on both sites.', 'content-relay' ), (string) wp_json_encode( $environment['schema'] ?? null ), (int) CONTENT_RELAY_SCHEMA ) );
		}

		return $environment;
	}

	/**
	 * Connection store, or stops when sending is off or no key material is configured.
	 */
	private static function store(): Connections {
		if ( ! Config::can_send() ) {
			\WP_CLI::error( __( 'Sending is off on this site. Switch it on under Settings → Content Relay first.', 'content-relay' ) );
		}

		$vault = Vault::from_config();
		if ( null === $vault ) {
			\WP_CLI::error( __( 'No key material to encrypt connection keys: define CONTENT_RELAY_ENCRYPTION_KEY (32+ characters) or real AUTH_KEY and SECURE_AUTH_KEY salts in wp-config.php.', 'content-relay' ) );
			exit( 1 );
		}

		return new Connections( $vault );
	}
}
