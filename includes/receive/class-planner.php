<?php
/**
 * Turns a bundle into a plan: what would be created, updated, reused or refused. Writes nothing.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Receive;

use ContentRelay\Identity\State_Fingerprint;

defined( 'ABSPATH' ) || exit;

/**
 * Planner (spec §8.2, §10).
 */
final class Planner {

	/**
	 * Seconds an edit lock is considered active.
	 */
	private const EDIT_LOCK_SECONDS = 150;

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Matches of the plan being built.
	 *
	 * @var array<string, array{id: int|null, via: string, candidates: int[], path_conflict: bool}>
	 */
	private array $matches = array();

	/**
	 * Keys of objects that will get a new receiver id in the plan being built (created or replaced).
	 *
	 * @var array<string, bool>
	 */
	private array $new_keys = array();

	/**
	 * References the sender could not resolve, mapped to 0 as the applier maps them.
	 *
	 * @var array<string, int>
	 */
	private array $absent = array();

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 */
	public function __construct( Receive_Context $context ) {
		$this->context = $context;
	}

	/**
	 * Plans a bundle.
	 *
	 * @param Bundle $bundle    Bundle.
	 * @param bool   $overwrite Whether changes made on this site since the last relay may be overwritten.
	 * @return array<string, mixed> Plan with blockers, warnings, items, matches, files_needed and hash.
	 */
	public function plan( Bundle $bundle, bool $overwrite ): array {
		$objects       = $bundle->objects();
		$preflight     = ( new Preflight( $this->context ) )->check( $bundle );
		$blockers      = $preflight['blockers'];
		$warnings      = $preflight['warnings'];
		$this->matches = ( new Matcher( $this->context ) )->match_all( $objects );
		$this->absent  = $bundle->absent_ids();
		$peer          = $bundle->source_home();
		$items         = array();
		$files         = array();

		// Attachments are decided first: a page must be rewritten when an attachment it uses gets a new id. Plain
		// lists keep the order deterministic on every PHP version, so the plan hash is stable.
		$attachment_keys = array();
		$other_keys      = array();
		foreach ( $objects as $key => $record ) {
			if ( 'attachment' === $record['kind'] ) {
				$attachment_keys[] = $key;
			} else {
				$other_keys[] = $key;
			}
		}

		$by_key         = array();
		$this->new_keys = array();
		foreach ( array_merge( $attachment_keys, $other_keys ) as $key ) {
			$record = $objects[ $key ];
			$found  = $this->matches[ $key ];
			$label  = $this->label( $record );

			if ( count( $found['candidates'] ) > 1 ) {
				$blockers[] = sprintf( '%1$s matches more than one object here (%2$s); refusing to guess.', $label, implode( ', ', $found['candidates'] ) );
			}

			$item = array(
				'key'      => $key,
				'kind'     => $record['kind'],
				'role'     => $record['role'],
				'language' => $record['language'] ?? null,
				'label'    => $label,
				'id'       => $found['id'],
				'via'      => $found['via'],
				'action'   => '',
				'note'     => '',
			);

			switch ( $record['kind'] ) {
				case 'user':
					$item['action'] = 'map';
					if ( 'fallback' === $found['via'] ) {
						$warnings[] = sprintf( 'User %1$s does not exist here; content is attributed to user %2$s.', $record['login'], (string) $found['id'] );
					}
					break;

				case 'term':
					$item['action'] = null !== $found['id'] ? 'reuse' : ( 'create' === $record['on_missing'] ? 'create' : 'missing' );
					if ( 'missing' === $item['action'] ) {
						$blockers[] = sprintf( '%s does not exist on this site.', $label );
					}
					break;

				case 'attachment':
				case 'post':
					if ( 'owned' === $record['role'] ) {
						$this->plan_owned( $record, $found, $peer, $overwrite, $item, $blockers, $warnings, $files, $bundle );
					} elseif ( null !== $found['id'] ) {
						$item['action'] = 'reuse';
					} elseif ( 'soft' === $record['role'] ) {
						$item['action'] = 'missing';
						$warnings[]     = sprintf( 'Link target %s does not exist on this site; the link will be broken.', $label );
					} else {
						$item['action'] = 'missing';
						$blockers[]     = sprintf( '%1$s is required (%2$s) but does not exist on this site.', $label, implode( ', ', (array) $record['relations'] ) );
					}
					break;
			}

			if ( in_array( $item['action'], array( 'create', 'create-new-file', 'replace-file' ), true ) ) {
				$this->new_keys[ $key ] = true;
			}
			$by_key[ $key ] = $item;
		}

		foreach ( array_keys( $objects ) as $key ) {
			$items[] = $by_key[ $key ];
		}

		$plan = array(
			'source'       => $peer,
			'receiver'     => $this->context->home_url,
			'overwrite'    => $overwrite,
			'blockers'     => array_values( array_unique( $blockers ) ),
			'warnings'     => array_values( array_unique( $warnings ) ),
			'items'        => $items,
			'files_needed' => array_values( array_unique( $files ) ),
		);

		$plan['hash']    = $this->context->fingerprint->of( $plan );
		$plan['matches'] = $this->matches;

		return $plan;
	}

	/**
	 * Plans an owned post or attachment.
	 *
	 * @param array<string, mixed>                                                     $record    Manifest object.
	 * @param array{id: int|null, via: string, candidates: int[], path_conflict: bool} $found     Match.
	 * @param string                                                                   $peer      Sender home URL.
	 * @param bool                                                                     $overwrite Overwrite allowed.
	 * @param array<string, mixed>                                                     $item      Plan item, modified.
	 * @param string[]                                                                 $blockers  Blockers, appended to.
	 * @param string[]                                                                 $warnings  Warnings, appended to.
	 * @param string[]                                                                 $files     Files needed, appended to.
	 * @param Bundle                                                                   $bundle    Bundle.
	 */
	private function plan_owned( array $record, array $found, string $peer, bool $overwrite, array &$item, array &$blockers, array &$warnings, array &$files, Bundle $bundle ): void {
		$label         = $item['label'];
		$is_attachment = 'attachment' === $record['kind'];

		if ( $is_attachment && ! is_array( $record['file'] ?? null ) ) {
			$item['action'] = 'missing';
			$blockers[]     = sprintf( '%s has no file on the sender.', $label );
			return;
		}

		if ( null === $found['id'] ) {
			if ( $is_attachment ) {
				$item['action'] = $found['path_conflict'] ? 'create-new-file' : 'create';
				$item['note']   = $found['path_conflict'] ? 'A different file already uses this name here; the upload gets a new name.' : '';
				$this->need_file( $record, $label, $files, $blockers, $bundle );
			} else {
				$item['action'] = 'create';
			}
			return;
		}

		if ( 'uuid' !== $found['via'] ) {
			$item['action'] = 'adopt';
			$item['note']   = sprintf( 'Exists here (found by %s) but was never relayed; it will be replaced after confirmation.', $found['via'] );
			$this->edit_lock_warning( (int) $found['id'], $label, $warnings );
			return;
		}

		$file_changed = $is_attachment && $this->context->attachment_sha1( (int) $found['id'] ) !== $record['file']['sha1'];
		$state        = $this->context->sync->get_post( (int) $found['id'], $peer );

		if ( null === $state ) {
			$item['action'] = $file_changed ? 'replace-file' : 'adopt';
			$item['note']   = 'Has the same identity but no relay history with this sender; it will be replaced after confirmation.';
		} elseif ( $this->context->state->post( (int) $found['id'] ) !== $state['local'] ) {
			if ( $overwrite ) {
				$item['action'] = $file_changed ? 'replace-file' : 'overwrite';
				$item['note']   = 'Changed here since the last relay; those changes will be overwritten (a backup is kept).';
			} else {
				$item['action'] = 'conflict';
				$blockers[]     = sprintf( '%s was changed on this site since the last relay. Relay with overwrite to replace those changes.', $label );
			}
		} elseif ( $file_changed ) {
			$item['action'] = 'replace-file';
		} elseif ( $this->context->state->incoming( $record, $this->resolved( $record ) ) === $state['incoming'] ) {
			$item['action'] = 'unchanged';
		} else {
			$item['action'] = 'update';
		}

		if ( 'replace-file' === $item['action'] ) {
			$item['note'] = 'The file changed on the sender: a new attachment gets the new file; the existing one keeps its file.';
			$this->need_file( $record, $label, $files, $blockers, $bundle );
		}

		if ( 'unchanged' !== $item['action'] ) {
			$this->edit_lock_warning( (int) $found['id'], $label, $warnings );
		}
	}
}
