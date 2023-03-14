<?php
/**
 * Relays many root posts, one translation group at a time.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Send;

use ContentRelay\Language\Adapter_Factory;

defined( 'ABSPATH' ) || exit;

/**
 * Selects translation groups and relays (or plans) each one as its own session.
 *
 * Each group is independent: a failure is recorded and the batch moves on, so one bad post never stops the rest.
 */
final class Batch_Relay {

	/**
	 * Plan actions that write something on the receiver.
	 */
	public const CHANGE_ACTIONS = array( 'create', 'create-new-file', 'replace-file', 'update', 'overwrite', 'adopt' );

	/**
	 * Seconds each upload step may run before progress is saved and the next step starts.
	 */
	private const UPLOAD_BUDGET_SECONDS = 30.0;

	/**
	 * Sender.
	 *
	 * @var Sender
	 */
	private Sender $sender;

	/**
	 * Constructor.
	 *
	 * @param Sender $sender Sender.
	 */
	public function __construct( Sender $sender ) {
		$this->sender = $sender;
	}

	/**
	 * Reduces post ids to one id per translation group, keeping the first id seen for each group.
	 *
	 * Relaying any language version relays the whole group, so a group must be relayed once.
	 *
	 * @param int[] $ids Post ids.
	 * @return int[]
	 */
	public static function group_roots( array $ids ): array {
		$language = Adapter_Factory::create();
		$roots    = array();
		$seen     = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( isset( $seen[ $id ] ) || false === get_post_type( $id ) ) {
				continue;
			}
			foreach ( $language->post_translations( $id ) as $member ) {
				$seen[ (int) $member ] = true;
			}
			$seen[ $id ] = true;
			$roots[]     = $id;
		}

		return $roots;
	}

	/**
	 * Plans or relays one translation group.
	 *
	 * @param int    $post_id   Any post of the group.
	 * @param string $label     Connection label.
	 * @param bool   $overwrite Replace changes made on the receiver since the last relay.
	 * @param bool   $dry_run   Only ask the receiver for its plan; nothing is uploaded or written.
	 * @param bool   $skip_existing Leave the group alone when any of its posts already exists on the receiver.
	 * @return array<string, mixed> Outcome: post_id, title, languages, status, changes, files, warnings, blockers,
	 *                              session, written, verified, error. Failures are recorded, never thrown.
	 */
	public function relay_one( int $post_id, string $label, bool $overwrite, bool $dry_run, bool $skip_existing = false ): array {
		$outcome = array(
			'post_id'   => $post_id,
			'title'     => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'languages' => array(),
			'status'    => 'failed',
			'changes'   => array(),
			'files'     => 0,
			'warnings'  => array(),
			'blockers'  => array(),
			'session'   => '',
			'written'   => array(),
			'verified'  => null,
			'error'     => '',
		);

		$session = '';
		try {
			$state                = $this->sender->start( $post_id, $label, $overwrite );
			$session              = (string) $state['session'];
			$outcome['languages'] = (array) $state['languages'];

			if ( $skip_existing ) {
				$existing = self::existing_posts( (array) ( $this->sender->plan( $session )['plan'] ?? array() ) );
				if ( array() !== $existing ) {
					$outcome['status']     = 'skipped';
					$outcome['warnings'][] = sprintf( 'Already on the receiver, left unchanged: %s.', implode( ', ', $existing ) );
					$this->abort( $session );
					return $outcome;
				}
			}

			if ( ! $dry_run ) {
				while ( empty( $state['progress']['done'] ) ) {
					$state = $this->sender->upload( $session, self::UPLOAD_BUDGET_SECONDS );
				}
			}

			$data = $this->sender->plan( $session );
			$plan = (array) ( $data['plan'] ?? array() );
			if ( array() === $plan ) {
				$outcome['error'] = __( 'The receiver returned no plan.', 'content-relay' );
				$this->abort( $session );
				return $outcome;
			}

			$outcome['changes']  = self::count_changes( $plan );
			$outcome['files']    = count( (array) $plan['files_needed'] );
			$outcome['warnings'] = array_values( (array) $plan['warnings'] );
			$outcome['blockers'] = $dry_run ? self::without_pending_files( (array) $plan['blockers'] ) : array_values( (array) $plan['blockers'] );

			if ( array() !== $outcome['blockers'] ) {
				$outcome['status'] = 'blocked';
				$this->abort( $session );
				return $outcome;
			}
			if ( array() === $outcome['changes'] ) {
				$outcome['status'] = 'unchanged';
				$this->abort( $session );
				return $outcome;
			}
			if ( $dry_run ) {
				$outcome['status'] = 'ready';
				$this->abort( $session );
				return $outcome;
			}

			$outcome['session']  = $session;
			$applied             = $this->sender->apply( $session, (string) $plan['hash'] );
			$result              = (array) $applied['result'];
			$outcome['written']  = (array) $result['written'];
			$outcome['status']   = array() === (array) $result['problems'] ? 'applied' : 'problems';
			$outcome['warnings'] = array_values( array_unique( array_merge( $outcome['warnings'], (array) $result['problems'], (array) ( $result['warnings'] ?? array() ) ) ) );

			try {
				$outcome['verified'] = (bool) $this->sender->verify( $session )['ok'];
			} catch ( Send_Exception $e ) {
				$outcome['verified']   = false;
				$outcome['warnings'][] = $e->plain_message();
			}
		} catch ( \Throwable $e ) {
			$outcome['error'] = $e instanceof Send_Exception ? $e->plain_message() : get_class( $e ) . ': ' . $e->getMessage();
			if ( '' !== $session ) {
				// Refused by the receiver once it has applied; the session id stays in the outcome for verify/rollback.
				$this->abort( $session );
			}
		}

		return $outcome;
	}

	/**
	 * Counts the plan's writing actions by action.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array<string, int>
	 */
	public static function count_changes( array $plan ): array {
		$counts = array();
		foreach ( (array) $plan['items'] as $item ) {
			$action = (string) ( $item['action'] ?? '' );
			if ( in_array( $action, self::CHANGE_ACTIONS, true ) ) {
				$counts[ $action ] = ( $counts[ $action ] ?? 0 ) + 1;
			}
		}
		ksort( $counts );

		return $counts;
	}

	/**
	 * Labels of the group's own posts (not media) that the receiver already has.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return string[]
	 */
	public static function existing_posts( array $plan ): array {
		$labels = array();
		foreach ( (array) ( $plan['items'] ?? array() ) as $item ) {
			if ( 'post' === ( $item['kind'] ?? '' ) && 'owned' === ( $item['role'] ?? '' ) && null !== ( $item['id'] ?? null ) && 'create' !== ( $item['action'] ?? '' ) ) {
				$labels[] = (string) $item['label'];
			}
		}

		return $labels;
	}

	/**
	 * Drops the blockers a plan reports only because files were not uploaded yet, which a dry run never does.
	 *
	 * @param string[] $blockers Blockers.
	 * @return string[]
	 */
	public static function without_pending_files( array $blockers ): array {
		return array_values(
			array_filter(
				$blockers,
				static function ( $blocker ): bool {
					return 1 !== preg_match( '/^The file for .+ is not in the bundle\.$/', (string) $blocker );
				}
			)
		);
	}

	/**
	 * Aborts a session on the receiver, ignoring a failure (the session expires there on its own).
	 *
	 * @param string $session Session id.
	 */
	private function abort( string $session ): void {
		try {
			$this->sender->abort( $session );
		} catch ( Send_Exception $e ) {
			unset( $e );
		}
	}
}
