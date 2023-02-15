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

	/**
	 * Exports a post or attachment.
	 *
	 * @param string                                                       $key    Object key.
	 * @param int                                                          $id     Post id.
	 * @param array{role: string, on_missing: string, relations: string[]} $wanted How the object is wanted.
	 */
	private function export_post( string $key, int $id, array $wanted ): void {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			$this->context->warn( sprintf( '%s is referenced but does not exist on this site; it is not relayed.', $key ) );
			$this->context->mark_absent( $key );
			return;
		}
		if ( 'revision' === $post->post_type || ! post_type_exists( $post->post_type ) ) {
			// A stale id (e.g. a wp-image class or a field value) that now points at a revision or an unregistered
			// post type: the sender renders it as nothing, so it is not relayed and maps to 0 on the receiver.
			$this->context->warn( sprintf( '%1$s is a %2$s, not content that can be relayed; the reference is dropped.', $key, $post->post_type ) );
			$this->context->mark_absent( $key );
			return;
		}

		$language = $this->context->language;
		$owned    = Export_Context::ROLE_OWNED === $wanted['role'];
		$record   = array(
			'key'             => $key,
			'kind'            => 'attachment' === $post->post_type ? 'attachment' : 'post',
			'role'            => $wanted['role'],
			'on_missing'      => $wanted['on_missing'],
			'uuid'            => $owned ? $this->context->uuid->ensure_post( $id ) : $this->context->uuid->get_post( $id ),
			'post_type'       => $post->post_type,
			'title'           => $post->post_title,
			'language'        => $language->post_language( $id ),
			'source_language' => $language->post_source_language( $id ),
			'translations'    => $this->keys( 'post', $language->post_translations( $id ) ),
			'path'            => $this->path( $post ),
		);

		if ( ! $owned ) {
			$this->context->add_object( $key, $record );
			return;
		}

		$this->context->require( 'post_types', $post->post_type );

		$fields = array();
		foreach ( self::POST_FIELDS as $field ) {
			$fields[ $field ] = $post->{$field};
		}

		$record['fields']    = $fields;
		$record['content']   = $post->post_content;
		$record['locations'] = array();
		$record['meta']      = $this->post_meta( $id, 'attachment' === $post->post_type );
		$record['terms']     = $this->terms( $id );
		$record['author']    = null;
		$record['parent']    = null;

		if ( (int) $post->post_author > 0 ) {
			$record['author']      = $this->context->reference( 'user', (int) $post->post_author, Export_Context::ROLE_MAPPED, 'fallback', 'author' );
			$record['locations'][] = array(
				'in'    => 'field',
				'field' => 'post_author',
				'kind'  => 'user',
				'refs'  => array( $record['author'] ),
			);
		}

		if ( 'attachment' === $post->post_type ) {
			$record['file'] = $this->file( $id );
			if ( null === $record['file'] && Media_Fallback::skip_missing() ) {
				$this->context->warn( sprintf( '%s has no file on this site; it is skipped and its references are dropped.', $key ) );
				$this->context->mark_absent( $key );
				return;
			}
			// "Uploaded to": not a dependency, so it is not followed; the receiver sets it when the relay carries that post.
			$record['upload_parent'] = $post->post_parent > 0 ? 'post:' . (int) $post->post_parent : null;
		} elseif ( $post->post_parent > 0 ) {
			$record['parent']      = $this->context->reference( 'post', (int) $post->post_parent, Export_Context::ROLE_REFERENCED, 'block', 'parent' );
			$record['locations'][] = array(
				'in'    => 'field',
				'field' => 'post_parent',
				'kind'  => 'post',
				'refs'  => array( $record['parent'] ),
			);
		}

		$template = $record['meta']['_wp_page_template'][0] ?? '';
		if ( '' !== $template && 'default' !== $template ) {
			$this->context->require( 'page_templates', $template );
		}

		foreach ( $this->resolvers as $resolver ) {
			$resolver->collect( $post, $record, $this->context );
		}

		$record['locations'] = $this->dedupe_locations( $record['locations'] );

		$this->context->add_object( $key, $record );
	}

	/**
	 * Exports a term.
	 *
	 * @param string                                                       $key    Object key.
	 * @param int                                                          $id     Term id.
	 * @param array{role: string, on_missing: string, relations: string[]} $wanted How the object is wanted.
	 */
	private function export_term( string $key, int $id, array $wanted ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML filters get_term() by the current language; the relay needs the row itself.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.description, tt.parent FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d", $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			$this->context->warn( sprintf( '%s is referenced but does not exist on this site; it is not relayed.', $key ) );
			$this->context->mark_absent( $key );
			return;
		}
		if ( ! taxonomy_exists( (string) $row['taxonomy'] ) ) {
			// A leftover term of a taxonomy nothing registers any more (e.g. a stale primary-category id).
			$this->context->warn( sprintf( '%1$s belongs to taxonomy %2$s, which is not registered on this site; the reference is dropped.', $key, (string) $row['taxonomy'] ) );
			$this->context->mark_absent( $key );
			return;
		}

		$taxonomy = (string) $row['taxonomy'];
		$language = $this->context->language;
		$this->context->require( 'taxonomies', $taxonomy );

		$parent = (int) $row['parent'];
		$meta   = $this->term_meta( $id );

		$this->context->add_object(
			$key,
			array(
				'key'             => $key,
				'kind'            => 'term',
				'role'            => $wanted['role'],
				'on_missing'      => $wanted['on_missing'],
				'uuid'            => $this->context->uuid->get_term( $id ),
				'taxonomy'        => $taxonomy,
				'slug'            => (string) $row['slug'],
				'name'            => (string) $row['name'],
				'description'     => (string) $row['description'],
				'parent'          => $parent > 0 ? $this->context->reference( 'term', $parent, $wanted['role'], $wanted['on_missing'], 'parent' ) : null,
				'language'        => $language->term_language( $id, $taxonomy ),
				'source_language' => $language->term_source_language( $id, $taxonomy ),
				'translations'    => $this->keys( 'term', $language->term_translations( $id, $taxonomy ) ),
				'meta'            => $meta,
				'meta_refs'       => $this->term_meta_refs( $meta ),
			)
		);
	}

	/**
	 * Which term meta values are ACF fields holding ids, so a receiver that creates the term can map or drop those ids.
	 *
	 * The ids are not followed during discovery: a relayed page almost always reuses its terms, and a term's own images
	 * are not worth relaying with every page that uses it. The receiver reports what it had to drop.
	 *
	 * @param array<string, string[]> $meta Raw term meta.
	 * @return array<int, array{key: string, index: int, kind: string}>
	 */
	private function term_meta_refs( array $meta ): array {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return array();
		}

		$refs = array();
		foreach ( $meta as $meta_key => $rows ) {
			$meta_key  = (string) $meta_key;
			$field_key = $meta[ '_' . $meta_key ][0] ?? null;
			if ( '' === $meta_key || '_' === $meta_key[0] || ! is_string( $field_key ) || 0 !== strpos( $field_key, 'field_' ) ) {
				continue;
			}

			$field = acf_get_field( $field_key );
			$kind  = is_array( $field ) ? ( self::ACF_ID_TYPES[ (string) $field['type'] ] ?? null ) : null;
			if ( null === $kind ) {
				continue;
			}

			foreach ( array_keys( $rows ) as $index ) {
				$refs[] = array(
					'key'   => $meta_key,
					'index' => (int) $index,
					'kind'  => $kind,
				);
			}
		}

		return $refs;
	}

	/**
	 * Exports a user identity (never the account itself).
	 *
	 * @param string $key Object key.
	 * @param int    $id  User id.
	 */
	private function export_user( string $key, int $id ): void {
		$user = get_userdata( $id );

		$this->context->add_object(
			$key,
			array(
				'key'          => $key,
				'kind'         => 'user',
				'role'         => Export_Context::ROLE_MAPPED,
				'on_missing'   => 'fallback',
				'login'        => false === $user ? '' : (string) $user->user_login,
				'email'        => false === $user ? '' : (string) $user->user_email,
				'display_name' => false === $user ? '' : (string) $user->display_name,
			)
		);
	}

	/**
	 * Raw post meta rows (serialized strings kept byte-exact), minus denied keys.
	 *
	 * @param int  $post_id       Post id.
	 * @param bool $is_attachment Whether the post is an attachment.
	 * @return array<string, string[]>
	 */
	private function post_meta( int $post_id, bool $is_attachment ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- get_post_meta() unserializes values; the relay must carry the stored bytes.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $post_id ), ARRAY_A );

		$denied = self::denied_meta();
		if ( $is_attachment ) {
			$denied = array_merge( $denied, self::DENIED_ATTACHMENT_META );
		}

		return $this->filter_meta( (array) $rows, $denied );
	}

	/**
	 * Raw term meta rows, minus denied keys.
	 *
	 * @param int $term_id Term id.
	 * @return array<string, string[]>
	 */
	private function term_meta( int $term_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- get_term_meta() unserializes values; the relay must carry the stored bytes.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d ORDER BY meta_id", $term_id ), ARRAY_A );

		return $this->filter_meta( (array) $rows, self::denied_meta() );
	}

	/**
	 * Meta keys never relayed, filterable via `content_relay_meta_denylist`.
	 *
	 * @return string[]
	 */
	public static function denied_meta(): array {
		$denied = apply_filters( 'content_relay_meta_denylist', self::DENIED_META );

		return is_array( $denied ) ? array_map( 'strval', $denied ) : self::DENIED_META;
	}

	/**
	 * Groups meta rows by key, dropping denied keys and Content Relay's own keys.
	 *
	 * @param array<int, array<string, mixed>> $rows   Rows with meta_key and meta_value.
	 * @param string[]                         $denied Denied keys.
	 * @return array<string, string[]>
	 */
	private function filter_meta( array $rows, array $denied ): array {
		$meta = array();
		foreach ( $rows as $row ) {
			$meta_key = (string) $row['meta_key'];
			if ( in_array( $meta_key, $denied, true ) || 0 === strpos( $meta_key, '_content_relay_' ) || 0 === strpos( $meta_key, '_wp_trash_meta_' ) ) {
				continue;
			}
			$meta[ $meta_key ][] = (string) $row['meta_value'];
		}

		return $meta;
	}

	/**
	 * Assigned terms, grouped by taxonomy.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, string[]> Taxonomy => term keys.
	 */
	private function terms( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML scopes wp_get_object_terms() to the current language; the relay needs every assignment.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_id, tt.taxonomy FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d ORDER BY tt.taxonomy, tr.term_order, tt.term_id", $post_id ), ARRAY_A );

		$terms = array();
		foreach ( (array) $rows as $row ) {
			$terms[ (string) $row['taxonomy'] ][] = $this->context->reference( 'term', (int) $row['term_id'], Export_Context::ROLE_REFERENCED, 'create', 'assigned' );
		}

		return $terms;
	}

	/**
	 * File details of an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{relative: string, original: string|null, source: string, sha1: string, bytes: int, mime: string}|null
	 */
	private function file( int $attachment_id ): ?array {
		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $relative ) {
			$this->context->warn( sprintf( 'Attachment %d has no file.', $attachment_id ) );
			return null;
		}

		$original      = null;
		$original_path = wp_get_original_image_path( $attachment_id );
		if ( is_string( $original_path ) && 0 === strpos( $original_path, $this->context->uploads_dir . '/' ) ) {
			$candidate = substr( $original_path, strlen( $this->context->uploads_dir ) + 1 );
			$original  = $candidate === $relative ? null : $candidate;
		}

		$source = null !== $original && is_readable( $this->context->uploads_dir . '/' . $original ) ? $original : $relative;
		$path   = $this->context->uploads_dir . '/' . $source;
		if ( ! is_readable( $path ) && '' !== Media_Fallback::base_url() ) {
			$source = $relative;
			$path   = $this->context->uploads_dir . '/' . $source;
			$error  = Media_Fallback::fetch( $this->context->uploads_dir, $source );
			if ( '' === $error ) {
				$this->context->warn( sprintf( 'The file of attachment %1$d (%2$s) was missing on this site and was downloaded from the media fallback.', $attachment_id, $source ) );
			} else {
				$this->context->warn( sprintf( 'The file of attachment %1$d (%2$s) is missing and the media fallback failed: %3$s.', $attachment_id, $source, $error ) );
			}
		}
		if ( ! is_readable( $path ) ) {
			$this->context->warn( sprintf( 'The file of attachment %1$d (%2$s) is missing on this site.', $attachment_id, $source ) );
			return null;
		}

		return array(
			'relative' => $relative,
			'original' => $original,
			'source'   => $source,
			'sha1'     => (string) sha1_file( $path ),
			'bytes'    => (int) filesize( $path ),
			'mime'     => (string) get_post_mime_type( $attachment_id ),
		);
	}
}
