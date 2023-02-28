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

	/**
	 * Language checks.
	 *
	 * @param Bundle   $bundle   Bundle.
	 * @param string[] $blockers Blockers, appended to.
	 */
	private function check_languages( Bundle $bundle, array &$blockers ): void {
		$languages = array();
		foreach ( $bundle->objects() as $record ) {
			if ( is_string( $record['language'] ?? null ) ) {
				$languages[ $record['language'] ] = true;
			}
		}
		if ( array() === $languages ) {
			return;
		}

		$adapter = $this->context->language;
		if ( 'none' === $adapter->id() ) {
			$blockers[] = sprintf( 'The page has language versions (%s) but this site has no multilingual plugin.', implode( ', ', array_keys( $languages ) ) );
			return;
		}

		$active = $adapter->active_languages();
		foreach ( array_keys( $languages ) as $language ) {
			if ( ! in_array( $language, $active, true ) ) {
				$blockers[] = sprintf( 'Language "%s" is not active on this site.', $language );
			}
		}

		$source_default = $bundle->manifest['source']['language']['default'] ?? null;
		if ( is_string( $source_default ) && $adapter->default_language() !== $source_default ) {
			$blockers[] = sprintf( 'The default language is "%1$s" here and "%2$s" on the sender.', (string) $adapter->default_language(), $source_default );
		}
	}

	/**
	 * Transaction support and disk space.
	 *
	 * @param Bundle   $bundle   Bundle.
	 * @param string[] $blockers Blockers, appended to.
	 */
	private function check_storage( Bundle $bundle, array &$blockers ): void {
		global $wpdb;

		$tables = array();
		foreach ( self::TRANSACTIONAL_TABLES as $table ) {
			$tables[] = $wpdb->prefix . $table;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated for each table.
		$engines = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode( ',', array_fill( 0, count( $tables ), '%s' ) ) . ')', $tables ), ARRAY_A );
		foreach ( (array) $engines as $row ) {
			if ( 'InnoDB' !== $row['engine'] ) {
				$blockers[] = sprintf( 'Table %1$s uses %2$s; relaying needs InnoDB for an atomic commit.', $row['name'], $row['engine'] );
			}
		}

		$bytes = 0;
		foreach ( $bundle->objects() as $record ) {
			$bytes += (int) ( $record['file']['bytes'] ?? 0 );
		}
		$free = function_exists( 'disk_free_space' ) ? disk_free_space( $this->context->uploads_dir ) : false;
		if ( false !== $free && $free < 3 * $bytes ) {
			$blockers[] = sprintf( 'Not enough disk space in uploads: %1$s free, %2$s needed.', size_format( (int) $free ), size_format( 3 * $bytes ) );
		}
	}
}
