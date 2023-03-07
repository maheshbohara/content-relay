<?php
/**
 * Checks for content a page finds by query rather than by id.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

defined( 'ABSPATH' ) || exit;

/**
 * Query dependencies (spec §6.6).
 *
 * Some blocks list content they query for (events tagged with the page's term, for example), so discovery cannot see
 * it and Content Relay does not relay it. A theme declares such dependencies with the `content_relay_query_dependencies`
 * filter; the receiver runs each check after apply and reports what is missing as a warning.
 *
 *     add_filter( 'content_relay_query_dependencies', function ( array $deps, WP_Post $post ): array {
 *         $deps[] = array(
 *             'label' => 'Events for this market',
 *             'check' => function ( WP_Post $receiver_post ): bool { return … ; },
 *         );
 *         return $deps;
 *     }, 10, 2 );
 */
final class Query_Dependencies {

	/**
	 * Runs the dependency checks a theme declares for a post on this site.
	 *
	 * @param \WP_Post $post  Post as stored on this site after the relay.
	 * @param string   $label How the post is named in warnings.
	 * @return string[] Warnings.
	 */
	public static function check( \WP_Post $post, string $label ): array {
		/**
		 * Filters the content a post finds by query, which a relay cannot carry.
		 *
		 * @param array<int, array{label: string, check: callable}> $dependencies Dependencies: a label and a check that
		 *                                                                        returns true when the content exists.
		 * @param \WP_Post                                          $post         Post on this site.
		 */
		$dependencies = apply_filters( 'content_relay_query_dependencies', array(), $post );

		return self::run( (array) $dependencies, $post, $label );
	}
}
