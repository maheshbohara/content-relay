<?php
/**
 * Shared state while discovering what a relay carries.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Discovery;

use ContentRelay\Content\Attributes_Codec;
use ContentRelay\Content\Block_Scanner;
use ContentRelay\Identity\Uuid;
use ContentRelay\Language\Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks referenced objects (and their strongest role), exported objects, requirements and warnings.
 *
 * Objects are keyed by their sender identity: `post:123` (attachments included), `term:45`, `user:6`.
 */
final class Export_Context {

	/**
	 * Created or overwritten on the receiver.
	 */
	public const ROLE_OWNED = 'owned';

	/**
	 * Must exist on the receiver; never modified.
	 */
	public const ROLE_REFERENCED = 'referenced';

	/**
	 * Only reported when missing (links).
	 */
	public const ROLE_SOFT = 'soft';

	/**
	 * Mapped to an existing receiver object with a fallback (users).
	 */
	public const ROLE_MAPPED = 'mapped';

	/**
	 * Role strength; a stronger role replaces a weaker one when an object is referenced twice.
	 */
	private const ROLE_RANK = array(
		self::ROLE_SOFT       => 1,
		self::ROLE_MAPPED     => 1,
		self::ROLE_REFERENCED => 2,
		self::ROLE_OWNED      => 3,
	);

	/**
	 * Language adapter.
	 *
	 * @var Adapter
	 */
	public Adapter $language;

	/**
	 * UUID service.
	 *
	 * @var Uuid
	 */
	public Uuid $uuid;

	/**
	 * Block scanner.
	 *
	 * @var Block_Scanner
	 */
	public Block_Scanner $scanner;

	/**
	 * Attribute codec.
	 *
	 * @var Attributes_Codec
	 */
	public Attributes_Codec $codec;

	/**
	 * Site home URL without trailing slash (the stored option, not a language-filtered value).
	 *
	 * @var string
	 */
	public string $home_url;

	/**
	 * Uploads base URL without trailing slash.
	 *
	 * @var string
	 */
	public string $uploads_url;

	/**
	 * Uploads base directory without trailing slash.
	 *
	 * @var string
	 */
	public string $uploads_dir;

	/**
	 * Wanted objects: role, missing-policy and the relations that reference them.
	 *
	 * @var array<string, array{role: string, on_missing: string, relations: string[]}>
	 */
	private array $wanted = array();

	/**
	 * Keys waiting to be exported.
	 *
	 * @var string[]
	 */
	private array $queue = array();

	/**
	 * Exported objects by key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $objects = array();

	/**
	 * Requirements the receiver must meet.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $requirements = array(
		'blocks'         => array(),
		'acf_fields'     => array(),
		'post_types'     => array(),
		'taxonomies'     => array(),
		'page_templates' => array(),
	);

	/**
	 * Warnings for the plan.
	 *
	 * @var string[]
	 */
	private array $warnings = array();

	/**
	 * Keys of referenced objects that do not exist on this site.
	 *
	 * @var string[]
	 */
	private array $absent = array();

	/**
	 * Constructor.
	 *
	 * @param Adapter $language Language adapter.
	 */
	public function __construct( Adapter $language ) {
		$uploads = wp_get_upload_dir();

		$this->language    = $language;
		$this->uuid        = new Uuid();
		$this->scanner     = new Block_Scanner();
		$this->codec       = new Attributes_Codec();
		$this->home_url    = untrailingslashit( (string) get_option( 'home' ) );
		$this->uploads_url = untrailingslashit( (string) $uploads['baseurl'] );
		$this->uploads_dir = untrailingslashit( (string) $uploads['basedir'] );
	}

	/**
	 * Records a reference to an object and queues it for export.
	 *
	 * @param string $kind       `post`, `term` or `user`.
	 * @param int    $id         Sender id.
	 * @param string $role       One of the ROLE_ constants.
	 * @param string $on_missing `create`, `block`, `warn` or `fallback`.
	 * @param string $relation   Why it is referenced (for the plan), e.g. `parent`, `acf:image`.
	 * @return string Object key.
	 */
	public function reference( string $kind, int $id, string $role, string $on_missing, string $relation ): string {
		$key = $kind . ':' . $id;

		if ( ! isset( $this->wanted[ $key ] ) ) {
			$this->wanted[ $key ] = array(
				'role'       => $role,
				'on_missing' => $on_missing,
				'relations'  => array( $relation ),
			);
			$this->queue[]        = $key;
			return $key;
		}

		if ( ! in_array( $relation, $this->wanted[ $key ]['relations'], true ) ) {
			$this->wanted[ $key ]['relations'][] = $relation;
		}

		if ( self::ROLE_RANK[ $role ] > self::ROLE_RANK[ $this->wanted[ $key ]['role'] ] ) {
			$this->wanted[ $key ]['role']       = $role;
			$this->wanted[ $key ]['on_missing'] = $on_missing;
			if ( isset( $this->objects[ $key ] ) ) {
				unset( $this->objects[ $key ] );
				$this->queue[] = $key;
			}
		}

		return $key;
	}

	/**
	 * Next key to export, or null when the queue is empty.
	 */
	public function next(): ?string {
		$key = array_shift( $this->queue );

		return null === $key ? null : (string) $key;
	}

	/**
	 * How a key is wanted.
	 *
	 * @param string $key Object key.
	 * @return array{role: string, on_missing: string, relations: string[]}
	 */
	public function wanted( string $key ): array {
		return $this->wanted[ $key ];
	}

	/**
	 * Stores an exported object.
	 *
	 * @param string               $key    Object key.
	 * @param array<string, mixed> $record Object.
	 */
	public function add_object( string $key, array $record ): void {
		$record['relations']   = $this->wanted[ $key ]['relations'] ?? array();
		$this->objects[ $key ] = $record;
	}

	/**
	 * Exported objects, with the relations recorded after each was exported.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function objects(): array {
		foreach ( $this->objects as $key => $record ) {
			$this->objects[ $key ]['relations'] = $this->wanted[ $key ]['relations'];
		}

		return $this->objects;
	}

	/**
	 * Records a requirement.
	 *
	 * @param string $type  `blocks`, `acf_fields`, `post_types`, `taxonomies`, `page_templates`.
	 * @param string $name  Requirement name.
	 * @param string $value Detail (the ACF field type).
	 */
	public function require( string $type, string $name, string $value = '' ): void {
		$this->requirements[ $type ][ $name ] = $value;
	}

	/**
	 * Requirements: `acf_fields` as key => type, everything else as a sorted list.
	 *
	 * @return array<string, array<int|string, string>>
	 */
	public function requirements(): array {
		$result = array();
		foreach ( $this->requirements as $type => $items ) {
			if ( 'acf_fields' === $type ) {
				ksort( $items );
				$result[ $type ] = $items;
				continue;
			}
			$names = array_map( 'strval', array_keys( $items ) );
			sort( $names );
			$result[ $type ] = $names;
		}

		return $result;
	}

	/**
	 * Adds a warning for the plan.
	 *
	 * @param string $message Message.
	 */
	public function warn( string $message ): void {
		if ( ! in_array( $message, $this->warnings, true ) ) {
			$this->warnings[] = $message;
		}
	}

	/**
	 * Warnings.
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Records a referenced object that does not exist on this site.
	 *
	 * @param string $key Object key.
	 */
	public function mark_absent( string $key ): void {
		if ( ! in_array( $key, $this->absent, true ) ) {
			$this->absent[] = $key;
		}
	}

	/**
	 * Keys of referenced objects that do not exist on this site.
	 *
	 * @return string[]
	 */
	public function absent(): array {
		return $this->absent;
	}

	/**
	 * Absolute URLs on this site found in text.
	 *
	 * @param string $text Text or HTML.
	 * @return string[]
	 */
	public function urls_in( string $text ): array {
		$host = wp_parse_url( $this->home_url, PHP_URL_HOST );
		if ( ! is_string( $host ) || false === stripos( $text, $host ) ) {
			return array();
		}

		$pattern = '#https?://' . preg_quote( $host, '#' ) . '(?::\d+)?(?:/[^\s"\'<>()\\\\]*)?#i';
		if ( ! preg_match_all( $pattern, $text, $matches ) ) {
			return array();
		}

		return array_values( array_unique( $matches[0] ) );
	}
}
