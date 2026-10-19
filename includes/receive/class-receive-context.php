<?php
/**
 * Services the receiver needs while planning and applying.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Receive;

use ContentRelay\Content\Attributes_Codec;
use ContentRelay\Content\Block_Scanner;
use ContentRelay\Content\Content_Editor;
use ContentRelay\Identity\Fingerprint;
use ContentRelay\Identity\State_Fingerprint;
use ContentRelay\Identity\Sync_State;
use ContentRelay\Identity\Uuid;
use ContentRelay\Language\Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Receiver context.
 */
final class Receive_Context {

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
	 * Content editor.
	 *
	 * @var Content_Editor
	 */
	public Content_Editor $editor;

	/**
	 * Fingerprint hasher.
	 *
	 * @var Fingerprint
	 */
	public Fingerprint $fingerprint;

	/**
	 * Receiver state fingerprints.
	 *
	 * @var State_Fingerprint
	 */
	public State_Fingerprint $state;

	/**
	 * Sync state store.
	 *
	 * @var Sync_State
	 */
	public Sync_State $sync;

	/**
	 * Receiver home URL without trailing slash.
	 *
	 * @var string
	 */
	public string $home_url;

	/**
	 * Receiver uploads base URL without trailing slash.
	 *
	 * @var string
	 */
	public string $uploads_url;

	/**
	 * Receiver uploads base directory without trailing slash.
	 *
	 * @var string
	 */
	public string $uploads_dir;

	/**
	 * User who becomes the author of content whose author has no account here; 0 picks the current user, then the
	 * first administrator.
	 *
	 * @var int
	 */
	public int $fallback_author;

	/**
	 * Constructor.
	 *
	 * @param Adapter $language        Language adapter.
	 * @param int     $fallback_author Author for content whose author has no account here.
	 */
	public function __construct( Adapter $language, int $fallback_author = 0 ) {
		$uploads = wp_get_upload_dir();

		$this->language    = $language;
		$this->uuid        = new Uuid();
		$this->scanner     = new Block_Scanner();
		$this->codec       = new Attributes_Codec();
		$this->editor      = new Content_Editor( $this->scanner, $this->codec );
		$this->fingerprint = new Fingerprint();
		$this->state       = new State_Fingerprint( $this->fingerprint, $language );
		$this->sync        = new Sync_State();
		$this->home_url    = untrailingslashit( (string) get_option( 'home' ) );
		$this->uploads_url = untrailingslashit( (string) $uploads['baseurl'] );
		$this->uploads_dir = untrailingslashit( (string) $uploads['basedir'] );

		$this->fallback_author = $fallback_author;
	}

	/**
	 * SHA-1 of the file an attachment was uploaded as (the original, not the scaled copy).
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string|null Null when the file is missing.
	 */
	public function attachment_sha1( int $attachment_id ): ?string {
		$path = wp_get_original_image_path( $attachment_id );
		if ( ! is_string( $path ) ) {
			$path = get_attached_file( $attachment_id, true );
		}

		return is_string( $path ) && is_readable( $path ) ? (string) sha1_file( $path ) : null;
	}
}
