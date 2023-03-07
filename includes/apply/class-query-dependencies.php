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

	/**
	 * Runs declared checks.
	 *
	 * @param array<int|string, mixed> $dependencies Declared dependencies.
	 * @param mixed                    $subject      Value handed to each check (the receiver post).
	 * @param string                   $label        How the post is named in warnings.
	 * @return string[] Warnings.
	 */
	public static function run( array $dependencies, $subject, string $label ): array {
		$warnings = array();

		foreach ( $dependencies as $dependency ) {
			if ( ! is_array( $dependency ) || ! isset( $dependency['check'] ) || ! is_callable( $dependency['check'] ) ) {
				$warnings[] = sprintf( '%s: a query dependency is malformed; it needs a label and a check callable.', $label );
				continue;
			}

			$name = is_string( $dependency['label'] ?? null ) && '' !== trim( $dependency['label'] ) ? trim( $dependency['label'] ) : 'Unnamed dependency';

			try {
				$found = true === call_user_func( $dependency['check'], $subject );
			} catch ( \Throwable $e ) {
				$warnings[] = sprintf( '%1$s: checking "%2$s" failed: %3$s', $label, $name, $e->getMessage() );
				continue;
			}

			if ( ! $found ) {
				$warnings[] = sprintf( '%1$s: "%2$s" found nothing on this site. Content Relay does not relay content a page lists by query; add it here.', $label, $name );
			}
		}

		return $warnings;
	}
}
