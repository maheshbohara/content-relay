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
}
