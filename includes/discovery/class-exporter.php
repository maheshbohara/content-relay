<?php
/**
 * Builds the manifest for relaying one page.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Discovery;

use ContentRelay\Config;

use ContentRelay\Discovery\Resolvers\Acf_Resolver;
use ContentRelay\Discovery\Resolvers\Block_References_Resolver;
use ContentRelay\Discovery\Resolvers\Html_Resolver;
use ContentRelay\Discovery\Resolvers\Meta_References_Resolver;
use ContentRelay\Discovery\Resolvers\Url_Resolver;
use ContentRelay\Support\Theme_Fingerprint;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the object graph from a root page and returns a manifest (spec §7).
 */
final class Exporter {

	/**
	 * Meta never relayed: the receiver keeps its own values.
	 */
	public const DENIED_META = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_encloseme',
		'_pingme',
		'_wpml_word_count',
		'_wpml_location_migration_done',
		'_yoast_indexnow_last_ping',
	);

	/**
	 * Attachment meta rebuilt by the receiver from the file itself.
	 */
	private const DENIED_ATTACHMENT_META = array(
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
	);

	/**
	 * Post fields relayed for posts and pages.
	 */
	private const POST_FIELDS = array(
		'post_title',
		'post_name',
		'post_status',
		'post_excerpt',
		'post_date',
		'post_date_gmt',
		'post_password',
		'menu_order',
		'comment_status',
		'ping_status',
		'post_mime_type',
	);

	/**
	 * ACF field types whose stored value is an id, with the kind of object the id names.
	 */
	private const ACF_ID_TYPES = array(
		'image'        => 'post',
		'file'         => 'post',
		'gallery'      => 'post',
		'post_object'  => 'post',
		'relationship' => 'post',
		'page_link'    => 'post',
		'taxonomy'     => 'term',
		'user'         => 'user',
	);

	/**
	 * Export context.
	 *
	 * @var Export_Context
	 */
	private Export_Context $context;

	/**
	 * Resolvers.
	 *
	 * @var Resolver[]
	 */
	private array $resolvers;

	/**
	 * Constructor.
	 *
	 * @param Export_Context  $context   Export context.
	 * @param Resolver[]|null $resolvers Resolvers; defaults to default_resolvers().
	 */
	public function __construct( Export_Context $context, ?array $resolvers = null ) {
		$this->context   = $context;
		$this->resolvers = null === $resolvers ? self::default_resolvers() : $resolvers;
	}

	/**
	 * Default resolvers, filterable via `content_relay_resolvers`.
	 *
	 * @return Resolver[]
	 */
	public static function default_resolvers(): array {
		$resolvers = array(
			new Block_References_Resolver(),
			new Acf_Resolver(),
			new Meta_References_Resolver(),
			new Html_Resolver(),
			new Url_Resolver(),
		);

		$filtered = apply_filters( 'content_relay_resolvers', $resolvers );
		if ( ! is_array( $filtered ) ) {
			return $resolvers;
		}

		return array_values(
			array_filter(
				$filtered,
				static function ( $resolver ): bool {
					return $resolver instanceof Resolver;
				}
			)
		);
	}

	/**
	 * Post types that can be relayed, filterable via `content_relay_post_types`.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = apply_filters( 'content_relay_post_types', Config::post_types() );

		return is_array( $types ) ? array_map( 'strval', $types ) : Config::post_types();
	}

	/**
	 * Exports a page and everything it needs.
	 *
	 * @param int $root_id Root post id.
	 * @return array<string, mixed> Manifest.
	 *
	 * @throws Export_Exception When the root is missing or not a relayable post type.
	 */
	public function export( int $root_id ): array {
		$root = get_post( $root_id );
		if ( ! $root instanceof \WP_Post ) {
			throw new Export_Exception( sprintf( 'Post %d does not exist.', (int) $root_id ) );
		}
		if ( ! in_array( $root->post_type, self::post_types(), true ) ) {
			throw new Export_Exception( sprintf( 'Post %1$d is a %2$s; only %3$s can be relayed.', (int) $root_id, esc_html( $root->post_type ), esc_html( implode( ', ', self::post_types() ) ) ) );
		}

		$language     = $this->context->language;
		$translations = $language->post_translations( $root_id );
		if ( ! in_array( $root_id, $translations, true ) ) {
			$translations = array( $root_id );
		}

		$roots = array();
		foreach ( $translations as $post_id ) {
			$roots[] = $this->context->reference( 'post', (int) $post_id, Export_Context::ROLE_OWNED, 'create', 'translation' );
		}

		$key = $this->context->next();
		while ( null !== $key ) {
			list( $kind, $id ) = explode( ':', $key );
			$wanted            = $this->context->wanted( $key );

			if ( 'post' === $kind ) {
				$this->export_post( $key, (int) $id, $wanted );
			} elseif ( 'term' === $kind ) {
				$this->export_term( $key, (int) $id, $wanted );
			} elseif ( 'user' === $kind ) {
				$this->export_user( $key, (int) $id );
			}

			$key = $this->context->next();
		}

		$theme = wp_get_theme();

		return array(
			'schema'       => CONTENT_RELAY_SCHEMA,
			'plugin'       => CONTENT_RELAY_VERSION,
			'created_at'   => gmdate( 'c' ),
			'source'       => array(
				'home_url'    => $this->context->home_url,
				'uploads_url' => $this->context->uploads_url,
				'theme'       => array(
					'stylesheet' => get_stylesheet(),
					'version'    => (string) $theme->get( 'Version' ),
					'files'      => Theme_Fingerprint::of( get_stylesheet_directory() ),
				),
				'language'    => array(
					'adapter' => $language->id(),
					'default' => $language->default_language(),
					'active'  => $language->active_languages(),
				),
				'plugins'     => array(
					'acf'   => function_exists( 'acf_get_field' ),
					'yoast' => defined( 'WPSEO_VERSION' ),
				),
			),
			'root'         => 'post:' . $root_id,
			'roots'        => $roots,
			'requirements' => $this->context->requirements(),
			'objects'      => $this->context->objects(),
			'warnings'     => $this->context->warnings(),
			'absent'       => $this->context->absent(),
		);
	}
}
