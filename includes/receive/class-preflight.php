<?php
/**
 * Checks that this site can hold what a manifest carries.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Receive;

use ContentRelay\Support\Theme_Fingerprint;

defined( 'ABSPATH' ) || exit;

/**
 * Environment checks (spec §8.2): theme, blocks, ACF field types, content types, languages, storage.
 */
final class Preflight {

	/**
	 * Tables the content commit writes inside one transaction.
	 */
	private const TRANSACTIONAL_TABLES = array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'icl_translations' );

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 */
	public function __construct( Receive_Context $context ) {
		$this->context = $context;
	}

	/**
	 * Runs every check.
	 *
	 * @param Bundle $bundle Bundle.
	 * @return array{blockers: string[], warnings: string[]}
	 */
	public function check( Bundle $bundle ): array {
		$manifest = $bundle->manifest;
		$blockers = array();
		$warnings = array();

		$theme = wp_get_theme();
		if ( get_stylesheet() !== $manifest['source']['theme']['stylesheet'] ) {
			$blockers[] = sprintf( 'The active theme is "%1$s"; the page was built with "%2$s".', get_stylesheet(), $manifest['source']['theme']['stylesheet'] );
		} elseif ( (string) $theme->get( 'Version' ) !== (string) $manifest['source']['theme']['version'] ) {
			$blockers[] = sprintf( 'Theme version is %1$s on this site and %2$s on the sender. Deploy the theme first.', (string) $theme->get( 'Version' ), $manifest['source']['theme']['version'] );
		} elseif ( isset( $manifest['source']['theme']['files']['hash'] ) ) {
			$local = Theme_Fingerprint::of( get_stylesheet_directory() );
			if ( $local['hash'] !== $manifest['source']['theme']['files']['hash'] ) {
				$warnings[] = sprintf( 'Theme files differ from the sender\'s although both report version %s. Check that the theme deploy is complete before relaying.', (string) $theme->get( 'Version' ) );
			}
		}

		$requirements = $manifest['requirements'];

		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( (array) ( $requirements['blocks'] ?? array() ) as $block ) {
			if ( 'core/freeform' !== $block && ! $registry->is_registered( (string) $block ) ) {
				$blockers[] = sprintf( 'Block type %s is not registered on this site.', $block );
			}
		}

		$acf_fields = (array) ( $requirements['acf_fields'] ?? array() );
		if ( array() !== $acf_fields && ! function_exists( 'acf_get_field' ) ) {
			$blockers[] = 'The page uses ACF fields but ACF is not active on this site.';
		} else {
			foreach ( $acf_fields as $field_key => $type ) {
				$field = acf_get_field( (string) $field_key );
				if ( ! is_array( $field ) ) {
					$blockers[] = sprintf( 'ACF field %s does not exist on this site.', $field_key );
				} elseif ( $field['type'] !== $type ) {
					$blockers[] = sprintf( 'ACF field %1$s is a %2$s here and a %3$s on the sender.', $field_key, $field['type'], $type );
				}
			}
		}

		foreach ( (array) ( $requirements['post_types'] ?? array() ) as $post_type ) {
			if ( ! post_type_exists( (string) $post_type ) ) {
				$blockers[] = sprintf( 'Post type %s is not registered on this site.', $post_type );
			}
		}
		foreach ( (array) ( $requirements['taxonomies'] ?? array() ) as $taxonomy ) {
			if ( ! taxonomy_exists( (string) $taxonomy ) ) {
				$blockers[] = sprintf( 'Taxonomy %s is not registered on this site.', $taxonomy );
			}
		}
		$templates = $theme->get_page_templates();
		foreach ( (array) ( $requirements['page_templates'] ?? array() ) as $template ) {
			if ( ! isset( $templates[ $template ] ) ) {
				$blockers[] = sprintf( 'Page template %s does not exist in the active theme.', $template );
			}
		}

		$this->check_languages( $bundle, $blockers );
		$this->check_storage( $bundle, $blockers );

		$yoast_meta = false;
		foreach ( $bundle->objects() as $record ) {
			foreach ( array_keys( (array) ( $record['meta'] ?? array() ) ) as $meta_key ) {
				$yoast_meta = $yoast_meta || 0 === strpos( (string) $meta_key, '_yoast_wpseo_' );
			}
		}
		if ( $yoast_meta && ! defined( 'WPSEO_VERSION' ) ) {
			$warnings[] = 'The page carries Yoast SEO data but Yoast SEO is not active here; the data is stored but not used.';
		}

		foreach ( (array) ( $manifest['warnings'] ?? array() ) as $warning ) {
			$warnings[] = 'Sender: ' . $warning;
		}

		return array(
			'blockers' => $blockers,
			'warnings' => $warnings,
		);
	}
}
