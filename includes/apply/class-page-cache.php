<?php
/**
 * Page-cache purging after a relay or rollback.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

defined( 'ABSPATH' ) || exit;

/**
 * Purges page caches for the URLs and posts a relay changed.
 *
 * A relay writes rows directly (spec §8.5), so the `save_post` hooks page-cache plugins purge on never fire. Each known
 * plugin is called through its public API when it is active; anything else can hook `content_relay_purge_page_cache`, or
 * add an integration with the `content_relay_page_cache_integrations` filter.
 */
final class Page_Cache {

	/**
	 * Integrations: name => availability test and purge callback.
	 *
	 * @var array<string, mixed>
	 */
	private array $integrations;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $integrations Integrations; defaults to default_integrations().
	 */
	public function __construct( ?array $integrations = null ) {
		$this->integrations = null === $integrations ? self::default_integrations() : $integrations;
	}

	/**
	 * Built-in integrations, filterable via `content_relay_page_cache_integrations`.
	 *
	 * Each entry is `array( 'available' => callable(): bool, 'purge' => callable( string[] $urls, int[] $post_ids ) )`.
	 * Plugin functions are called by name so this class loads without them.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_integrations(): array {
		$integrations = array(
			'wp-rocket'       => array(
				'available' => static function (): bool {
					return function_exists( 'rocket_clean_post' );
				},
				'purge'     => static function ( array $urls, array $post_ids ): void {
					foreach ( $post_ids as $post_id ) {
						self::call( 'rocket_clean_post', $post_id );
					}
					if ( array() !== $urls ) {
						self::call( 'rocket_clean_files', $urls );
					}
				},
			),
			'litespeed-cache' => array(
				'available' => static function (): bool {
					return defined( 'LSCWP_V' );
				},
				'purge'     => static function ( array $urls, array $post_ids ): void {
					foreach ( $post_ids as $post_id ) {
						do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's public purge hook.
					}
					foreach ( $urls as $url ) {
						do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's public purge hook.
					}
				},
			),
			'w3-total-cache'  => array(
				'available' => static function (): bool {
					return function_exists( 'w3tc_flush_post' );
				},
				'purge'     => static function ( array $urls, array $post_ids ): void {
					foreach ( $post_ids as $post_id ) {
						self::call( 'w3tc_flush_post', $post_id );
					}
					foreach ( $urls as $url ) {
						self::call( 'w3tc_flush_url', $url );
					}
				},
			),
			'wp-super-cache'  => array(
				'available' => static function (): bool {
					return function_exists( 'wp_cache_post_change' );
				},
				'purge'     => static function ( array $urls, array $post_ids ): void {
					unset( $urls );
					foreach ( $post_ids as $post_id ) {
						self::call( 'wp_cache_post_change', $post_id );
					}
				},
			),
			'sg-cachepress'   => array(
				'available' => static function (): bool {
					return function_exists( 'sg_cachepress_purge_cache' );
				},
				'purge'     => static function ( array $urls, array $post_ids ): void {
					unset( $post_ids );
					foreach ( $urls as $url ) {
						self::call( 'sg_cachepress_purge_cache', $url );
					}
				},
			),
			'breeze'          => array(
				'available' => static function (): bool {
					return defined( 'BREEZE_VERSION' );
				},
				'purge'     => static function ( array $urls, array $post_ids ): void {
					unset( $urls, $post_ids );
					// Breeze (Cloudways) has no per-URL purge API: clear its page cache and Varnish.
					do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's public purge hook.
					do_action( 'breeze_clear_varnish' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's public purge hook.
				},
			),
		);

		/**
		 * Filters the page-cache integrations Content Relay purges after a relay or rollback.
		 *
		 * @param array<string, mixed> $integrations Name => `array( 'available' => callable, 'purge' => callable )`.
		 */
		return (array) apply_filters( 'content_relay_page_cache_integrations', $integrations );
	}
}
