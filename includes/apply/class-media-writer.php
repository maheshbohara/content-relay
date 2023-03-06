<?php
/**
 * Places relayed files in uploads and creates their attachment rows.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Receive\Bundle;
use ContentRelay\Receive\Receive_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Media writer (spec §8.4).
 *
 * One physical file per SHA-1: WPML keeps one attachment row per language pointing at the same file, so sub-sizes
 * are generated once and later language rows copy the file meta. Existing files are never overwritten; a different
 * file with the same name gets a unique name and the URL map records the rename.
 */
final class Media_Writer {

	/**
	 * Marks attachments created by an unfinished relay.
	 */
	public const PENDING_META = '_content_relay_pending';

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Bundle holding the files.
	 *
	 * @var Bundle
	 */
	private Bundle $bundle;

	/**
	 * Row writer.
	 *
	 * @var Post_Writer
	 */
	private Post_Writer $writer;

	/**
	 * Session id.
	 *
	 * @var string
	 */
	private string $session;

	/**
	 * Files placed this session: SHA-1 => attached file meta and attachment metadata.
	 *
	 * @var array<string, array{attached: string, metadata: string}>
	 */
	private array $placed = array();

	/**
	 * Absolute paths of files created this session.
	 *
	 * @var string[]
	 */
	private array $created_files = array();

	/**
	 * Attachment ids created this session.
	 *
	 * @var int[]
	 */
	private array $created_ids = array();

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 * @param Bundle          $bundle  Bundle.
	 * @param Post_Writer     $writer  Row writer.
	 * @param string          $session Session id.
	 */
	public function __construct( Receive_Context $context, Bundle $bundle, Post_Writer $writer, string $session ) {
		$this->context = $context;
		$this->bundle  = $bundle;
		$this->writer  = $writer;
		$this->session = $session;
	}

	/**
	 * Creates an attachment: places its file (once per SHA-1), inserts its row and generates its metadata.
	 *
	 * Fields, content and meta are written later, inside the content commit.
	 *
	 * @param array<string, mixed> $record Manifest attachment.
	 * @param Url_Map              $urls   URL map; receives renames.
	 * @return int Attachment id.
	 *
	 * @throws Apply_Exception When the file cannot be placed.
	 */
	public function create( array $record, Url_Map $urls ): int {
		$file = (array) $record['file'];
		$sha1 = (string) $file['sha1'];

		$attachment_id       = $this->writer->reserve( 'attachment' );
		$this->created_ids[] = $attachment_id;
		$this->writer->add_meta( $attachment_id, self::PENDING_META, $this->session );

		if ( isset( $this->placed[ $sha1 ] ) ) {
			$this->writer->add_meta( $attachment_id, '_wp_attached_file', $this->placed[ $sha1 ]['attached'] );
			$this->writer->add_meta( $attachment_id, '_wp_attachment_metadata', $this->placed[ $sha1 ]['metadata'] );
			return $attachment_id;
		}

		$source    = (string) $file['source'];
		$directory = dirname( $source );
		$directory = '.' === $directory ? '' : $directory;
		$absolute  = $this->context->uploads_dir . ( '' === $directory ? '' : '/' . $directory );

		if ( ! wp_mkdir_p( $absolute ) ) {
			throw new Apply_Exception( esc_html( sprintf( 'Cannot create uploads directory %s.', $directory ) ) );
		}

		$name   = wp_basename( $source );
		$target = $absolute . '/' . $name;
		$reuse  = file_exists( $target ) && sha1_file( $target ) === $sha1;
		if ( file_exists( $target ) && ! $reuse ) {
			$name   = wp_unique_filename( $absolute, $name );
			$target = $absolute . '/' . $name;
		}

		$before = $this->listing( $absolute );

		if ( ! $reuse ) {
			$from = $this->bundle->file_path( $sha1 );
			if ( null === $from || ! copy( $from, $target ) || sha1_file( $target ) !== $sha1 ) {
				throw new Apply_Exception( esc_html( sprintf( 'Could not place file %s.', $source ) ) );
			}
		}

		$placed = ( '' === $directory ? '' : $directory . '/' ) . $name;
		$urls->add_rename( $source, $placed );
		$this->writer->add_meta( $attachment_id, '_wp_attached_file', $placed );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		if ( 0 === strpos( (string) $file['mime'], 'image/' ) && file_is_displayable_image( $target ) ) {
			wp_create_image_subsizes( $target, $attachment_id );
		} else {
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $target ) );
		}

		$this->created_files = array_merge( $this->created_files, array_diff( $this->listing( $absolute ), $before ) );

		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $attached !== $placed && '' !== $attached ) {
			$urls->add_rename( (string) $file['relative'], $attached );
		}

		$this->placed[ $sha1 ] = array(
			'attached' => $attached,
			'metadata' => (string) maybe_serialize( wp_get_attachment_metadata( $attachment_id, true ) ),
		);

		return $attachment_id;
	}

	/**
	 * GUID for a created attachment: the URL of its file.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function guid( int $attachment_id ): string {
		$original = wp_get_original_image_url( $attachment_id );

		return is_string( $original ) ? $original : (string) wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Ids of attachments created this session.
	 *
	 * @return int[]
	 */
	public function created_ids(): array {
		return $this->created_ids;
	}

	/**
	 * Absolute paths of files created this session.
	 *
	 * @return string[]
	 */
	public function created_files(): array {
		return $this->created_files;
	}

	/**
	 * Removes the attachments and files this session created.
	 */
	public function cleanup(): void {
		foreach ( $this->created_ids as $attachment_id ) {
			$this->writer->delete( $attachment_id );
		}
		foreach ( $this->created_files as $path ) {
			wp_delete_file( $path );
		}
		$this->created_ids   = array();
		$this->created_files = array();
	}

	/**
	 * Absolute paths of the files in a directory.
	 *
	 * @param string $directory Directory.
	 * @return string[]
	 */
	private function listing( string $directory ): array {
		$paths = array();
		foreach ( (array) scandir( $directory ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry && is_file( $directory . '/' . $entry ) ) {
				$paths[] = $directory . '/' . $entry;
			}
		}

		return $paths;
	}
}
