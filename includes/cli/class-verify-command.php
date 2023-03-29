<?php
/**
 * WP-CLI: check a relay's pages on the receiving site.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the HTTP verification of an applied relay again (spec §12).
 */
final class Verify_Command {

	/**
	 * Fetches a relay's pages from the receiving site and checks status, address, language, hreflang, links left
	 * pointing at this site, and images.
	 *
	 * ## OPTIONS
	 *
	 * <session>
	 * : Relay session id printed by `wp content-relay push`.
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
	 * ## EXAMPLES
	 *
	 *     wp content-relay verify 0f8e7c1a-1b2c-4d5e-8f90-a1b2c3d4e5f6
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		try {
			$report = Sender::for_site()->verify( (string) $args[0] );
		} catch ( Send_Exception $e ) {
			\WP_CLI::error( $e->plain_message() );
			return;
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		} else {
			self::print( $report );
		}

		if ( ! $report['ok'] ) {
			$count = count( $report['problems'] );
			/* translators: %d: number of failed checks. */
			\WP_CLI::error( sprintf( _n( '%d check failed on the receiving site. Nothing was rolled back.', '%d checks failed on the receiving site. Nothing was rolled back.', $count, 'content-relay' ), $count ) );
		}
		\WP_CLI::success( __( 'Every check on the receiving site passed.', 'content-relay' ) );
	}
}
