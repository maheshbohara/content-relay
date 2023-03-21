<?php
/**
 * WP-CLI commands.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Export_Exception;
use ContentRelay\Discovery\Exporter;
use ContentRelay\Language\Adapter_Factory;

defined( 'ABSPATH' ) || exit;

/**
 * Relays a page — every language version, its media and the content it needs — to another site.
 */
final class Command {

	/**
	 * Shows what a relay of a page would carry.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : Id of the page (any language version).
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
	 *     wp content-relay discover 4356
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function discover( array $args, array $assoc_args ): void {
		$manifest = $this->export( (int) $args[0] );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$rows = array();
		foreach ( $manifest['objects'] as $key => $record ) {
			$rows[] = array(
				'key'       => $key,
				'kind'      => $record['kind'],
				'role'      => $record['role'],
				'language'  => $record['language'] ?? '',
				'what'      => $this->describe( $record ),
				'locations' => isset( $record['locations'] ) ? count( $record['locations'] ) : '',
				'via'       => implode( ', ', $record['relations'] ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'kind', 'role', 'language', 'what', 'locations', 'via' ) );

		foreach ( $manifest['requirements'] as $type => $items ) {
			/* translators: 1: requirement type, 2: number of required items. */
			\WP_CLI::log( sprintf( __( 'Requires %1$s: %2$d', 'content-relay' ), $type, count( $items ) ) );
		}
		foreach ( $manifest['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
	}
}
