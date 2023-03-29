<?php
/**
 * WP-CLI: relay many posts.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

use ContentRelay\Discovery\Exporter;
use ContentRelay\Send\Batch_Relay;
use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Relays a selection of posts, one translation group per session.
 */
final class Push_Many_Command {

	/**
	 * Relays many posts — each with every language version, its media and what it needs — to a connected site.
	 *
	 * Select posts by id, or by post type plus tag and/or category (matched in every language). Each
	 * translation group is relayed once, in its own session: one that fails or is blocked is reported and skipped.
	 * Without --yes the plan of every group is shown and one confirmation is asked before anything is written.
	 *
	 * ## OPTIONS
	 *
	 * [<post-id>...]
	 * : Ids of posts to relay (any language version). Leave out to select by --post_type/--tag/--category.
	 *
	 * --to=<connection>
	 * : Connection label.
	 *
	 * [--post_type=<post-type>]
	 * : Post type to select from.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--tag=<slug>]
	 * : Only posts with this tag.
	 *
	 * [--category=<slug>]
	 * : Only posts in this category.
	 *
	 * [--all]
	 * : Select every post of --post_type (with --post_status), without a tag or category filter.
	 *
	 * [--post_status=<status>]
	 * : Only posts with this status.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--existing=<mode>]
	 * : What to do when a post already exists on the receiver: replace it with this site's version, or leave the
	 * whole group alone and only relay groups that are new there.
	 * ---
	 * default: update
	 * options:
	 *   - update
	 *   - skip
	 * ---
	 *
	 * [--overwrite]
	 * : With --existing=update, also replace posts edited on the receiver since the last relay (a backup is kept).
	 *
	 * [--media-fallback=<url>]
	 * : Base URL of another copy of this site's uploads (e.g. https://example.com/wp-content/uploads). A media file
	 * missing on this site is downloaded from there at relay time (a dry run included) and saved into this site's
	 * uploads, instead of blocking the relay. The host must be public: private and local addresses are refused.
	 *
	 * [--skip-missing-media]
	 * : Relay a post even when one of its media files is missing here and from the fallback: that media is left out
	 * and references to it are dropped.
	 *
	 * [--dry-run]
	 * : Only ask the receiver for each plan; nothing is uploaded or written.
	 *
	 * [--yes]
	 * : Relay without asking for confirmation.
	 *
	 * [--format=<format>]
	 * : Summary format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-relay push-many --to=dev --post_type=post --tag=local-inspiration-and-updates --dry-run
	 *     wp content-relay push-many --to=dev --post_type=post --tag=local-inspiration-and-updates --yes
	 *     wp content-relay push-many --to=dev --post_type=post --all --dry-run
	 *     wp content-relay push-many 38 40 1155 --to=dev
	 *
	 * @param array<int, string>         $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$label     = (string) ( $assoc_args['to'] ?? '' );
		$overwrite = (bool) ( $assoc_args['overwrite'] ?? false );
		$dry_run   = (bool) ( $assoc_args['dry-run'] ?? false );
		$format    = (string) ( $assoc_args['format'] ?? 'table' );
		$skip      = 'skip' === ( $assoc_args['existing'] ?? 'update' );

		if ( isset( $assoc_args['media-fallback'] ) ) {
			$fallback = (string) $assoc_args['media-fallback'];
			add_filter(
				'content_relay_media_fallback_url',
				static function () use ( $fallback ): string {
					return $fallback;
				}
			);
		}

		if ( ! empty( $assoc_args['skip-missing-media'] ) ) {
			add_filter( 'content_relay_skip_missing_media', '__return_true' );
		}

		$ids = array() !== $args ? array_map( 'intval', $args ) : self::select( $assoc_args );
		if ( array() === $ids ) {
			\WP_CLI::error( __( 'No posts match the selection.', 'content-relay' ) );
		}

		$type = (string) ( $assoc_args['post_type'] ?? 'post' );
		if ( array() === $args && ! in_array( $type, Exporter::post_types(), true ) ) {
			/* translators: 1: post type, 2: comma-separated list of relayable post types. */
			\WP_CLI::error( sprintf( __( '%1$s cannot be relayed; relayable post types: %2$s.', 'content-relay' ), $type, implode( ', ', Exporter::post_types() ) ) );
		}

		try {
			$batch = new Batch_Relay( Sender::for_site() );
		} catch ( Send_Exception $e ) {
			\WP_CLI::error( $e->plain_message() );
			return;
		}

		$roots = Batch_Relay::group_roots( $ids );
		/* translators: 1: number of posts selected, 2: number of translation groups. */
		\WP_CLI::log( sprintf( __( 'Selected %1$d posts: %2$d translation groups.', 'content-relay' ), count( $ids ), count( $roots ) ) );

		$skipped = array();
		if ( ! $dry_run && ! ( $assoc_args['yes'] ?? false ) ) {
			$planned = $this->run( $batch, $roots, $label, $overwrite, true, $format, $skip );
			$ready   = count( array_filter( $planned, static fn( array $o ): bool => 'ready' === $o['status'] ) );
			$skipped = array_values( array_filter( $planned, static fn( array $o ): bool => in_array( $o['status'], array( 'failed', 'blocked' ), true ) ) );
			if ( 0 === $ready ) {
				if ( array() !== $skipped ) {
					\WP_CLI::error( __( 'Nothing to relay: every group that needs a change is blocked or failed (see above).', 'content-relay' ) );
				}
				\WP_CLI::success( __( 'Nothing to relay.', 'content-relay' ) );
				return;
			}
			/* translators: %d: number of translation groups. */
			fwrite( STDOUT, sprintf( _n( 'Relay %d group?', 'Relay %d groups?', $ready, 'content-relay' ), $ready ) . ' [y/n] ' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Interactive CLI prompt.
			if ( 'y' !== strtolower( trim( (string) fgets( STDIN ) ) ) ) {
				\WP_CLI::log( __( 'Cancelled; nothing was written.', 'content-relay' ) );
				return;
			}
			$roots = array_values( array_map( static fn( array $o ): int => (int) $o['post_id'], array_filter( $planned, static fn( array $o ): bool => 'ready' === $o['status'] ) ) );
		}

		$outcomes = array_merge( $skipped, $this->run( $batch, $roots, $label, $overwrite, $dry_run, $format, $skip ) );

		$failed = array_filter( $outcomes, static fn( array $o ): bool => in_array( $o['status'], array( 'failed', 'blocked', 'problems' ), true ) || false === $o['verified'] );
		if ( array() !== $failed ) {
			/* translators: %d: number of translation groups. */
			\WP_CLI::error( sprintf( _n( '%d group needs attention (see above).', '%d groups need attention (see above).', count( $failed ), 'content-relay' ), count( $failed ) ) );
		}
		\WP_CLI::success( $dry_run ? __( 'Dry run complete; nothing was written.', 'content-relay' ) : __( 'Every group was relayed and verified.', 'content-relay' ) );
	}

	/**
	 * Plans or relays each root, printing progress, then a summary and every warning, blocker and error.
	 *
	 * @param Batch_Relay $batch     Batch relay.
	 * @param int[]       $roots     One post id per translation group.
	 * @param string      $label     Connection label.
	 * @param bool        $overwrite Overwrite receiver changes.
	 * @param bool        $dry_run   Plan only.
	 * @param string      $format    Summary format.
	 * @param bool        $skip      Leave groups that already exist on the receiver alone.
	 * @return array<int, array<string, mixed>> Outcomes.
	 */
	private function run( Batch_Relay $batch, array $roots, string $label, bool $overwrite, bool $dry_run, string $format, bool $skip ): array {
		$outcomes = array();
		$total    = count( $roots );
		foreach ( $roots as $index => $root ) {
			$outcome    = $batch->relay_one( $root, $label, $overwrite, $dry_run, $skip );
			$outcomes[] = $outcome;
			/* translators: 1: position, 2: total, 3: post id, 4: status, 5: post title. */
			\WP_CLI::log( sprintf( __( '[%1$d/%2$d] %3$d %4$s — %5$s', 'content-relay' ), $index + 1, $total, $root, $outcome['status'], $outcome['title'] ) );
		}

		$rows = array_map(
			static function ( array $o ): array {
				$changes = array();
				foreach ( $o['changes'] as $action => $count ) {
					$changes[] = $action . ' ' . $count;
				}
				return array(
					'post_id'   => $o['post_id'],
					'title'     => wp_html_excerpt( (string) $o['title'], 60, '…' ),
					'languages' => implode( ',', $o['languages'] ),
					'status'    => $o['status'],
					'changes'   => implode( ', ', $changes ),
					'files'     => $o['files'],
					'warnings'  => count( $o['warnings'] ),
					'verified'  => null === $o['verified'] ? '' : ( $o['verified'] ? 'yes' : 'NO' ),
					'session'   => $o['session'],
				);
			},
			$outcomes
		);

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $outcomes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return $outcomes;
		}

		$fields = $dry_run ? array( 'post_id', 'title', 'languages', 'status', 'changes', 'files', 'warnings' ) : array( 'post_id', 'title', 'languages', 'status', 'changes', 'files', 'warnings', 'verified', 'session' );
		\WP_CLI\Utils\format_items( 'table', $rows, $fields );

		$totals = array_count_values( array_map( static fn( array $o ): string => (string) $o['status'], $outcomes ) );
		ksort( $totals );
		$parts = array();
		foreach ( $totals as $status => $count ) {
			$parts[] = $status . ': ' . $count;
		}
		/* translators: %s: counts by status. */
		\WP_CLI::log( sprintf( __( 'Totals — %s', 'content-relay' ), implode( ', ', $parts ) ) );

		foreach ( $outcomes as $o ) {
			foreach ( $o['blockers'] as $blocker ) {
				\WP_CLI::log( \WP_CLI::colorize( '%R' . __( 'Blocked:', 'content-relay' ) . '%n ' ) . $o['post_id'] . ' — ' . $blocker );
			}
			if ( '' !== $o['error'] ) {
				\WP_CLI::log( \WP_CLI::colorize( '%R' . __( 'Failed:', 'content-relay' ) . '%n ' ) . $o['post_id'] . ' — ' . $o['error'] );
			}
			foreach ( $o['warnings'] as $warning ) {
				\WP_CLI::warning( $o['post_id'] . ' — ' . $warning );
			}
		}

		return $outcomes;
	}

	/**
	 * Post ids matching --post_type, --tag, --category and --post_status, in every language.
	 *
	 * Language filtering is suppressed: a translation can carry a term its original lacks, and that group must be
	 * selected too. The slug then matches the term in each language.
	 *
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 * @return int[]
	 */
	private static function select( array $assoc_args ): array {
		$query = array(
			'post_type'        => (string) ( $assoc_args['post_type'] ?? 'post' ),
			'post_status'      => (string) ( $assoc_args['post_status'] ?? 'publish' ),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'date',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- WP-CLI only; the language filter must not hide translations (see above).
			'suppress_filters' => true,
		);
		if ( isset( $assoc_args['tag'] ) ) {
			$query['tag'] = (string) $assoc_args['tag'];
		}
		if ( isset( $assoc_args['category'] ) ) {
			$query['category_name'] = (string) $assoc_args['category'];
		}
		if ( ! isset( $query['tag'] ) && ! isset( $query['category_name'] ) && empty( $assoc_args['all'] ) ) {
			\WP_CLI::error( __( 'Give post ids, or select with --tag, --category or --all.', 'content-relay' ) );
		}

		return array_map( 'intval', get_posts( $query ) );
	}
}
