<?php
/**
 * WP-CLI commands for the receiving side.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

use ContentRelay\Language\Adapter_Factory;
use ContentRelay\Receive\Bundle;
use ContentRelay\Receive\Planner;
use ContentRelay\Receive\Receive_Context;
use ContentRelay\Receive\Receive_Exception;
use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Plans and applies relay bundles on this site.
 */
final class Receive_Command {

	/**
	 * Shows what applying a bundle on this site — or relaying a page to a connected site — would do. Writes nothing.
	 *
	 * With --to, the page is exported and sent to that connection's receiving site, which plans it; the session is then
	 * cancelled there. No file is uploaded and nothing is written.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Bundle directory (manifest.json + files/), or with --to the id of a page on this site.
	 *
	 * [--to=<connection>]
	 * : Ask this connection's receiving site for its plan of the page.
	 *
	 * [--overwrite]
	 * : Plan as if changes made on the receiving site since the last relay may be overwritten.
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
	 *     wp content-relay plan /tmp/vietnam
	 *     wp content-relay plan 4356 --to=prod
	 *
	 * @param array<int, string>         $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function plan( array $args, array $assoc_args ): void {
		$overwrite = (bool) ( $assoc_args['overwrite'] ?? false );
		if ( isset( $assoc_args['to'] ) ) {
			$plan = $this->remote_plan( (int) $args[0], (string) $assoc_args['to'], $overwrite );
		} else {
			$plan = ( new Planner( new Receive_Context( Adapter_Factory::create() ) ) )->plan( $this->bundle( (string) $args[0] ), $overwrite );
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		Plan_Printer::print( $plan );
	}
}
