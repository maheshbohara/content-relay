<?php
/**
 * Resolves manifest objects to objects on this site.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Receive;

defined( 'ABSPATH' ) || exit;

/**
 * Identity matching (spec §8.1). Always scoped by language; never guesses: more than one candidate is reported as
 * ambiguous and the planner blocks.
 */
final class Matcher {

	/**
	 * Post statuses that never count as an existing post.
	 */
	private const IGNORED_STATUSES = array( 'trash', 'auto-draft', 'inherit' );

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Results by object key.
	 *
	 * @var array<string, array{id: int|null, via: string, candidates: int[], path_conflict: bool}>
	 */
	private array $matches = array();

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 */
	public function __construct( Receive_Context $context ) {
		$this->context = $context;
	}

	/**
	 * Matches every object in a manifest.
	 *
	 * Posts are matched in three passes so the translation pass can use UUID matches: UUID, then a translation of an
	 * already-matched language version, then path.
	 *
	 * @param array<string, array<string, mixed>> $objects Manifest objects.
	 * @return array<string, array{id: int|null, via: string, candidates: int[], path_conflict: bool}>
	 */
	public function match_all( array $objects ): array {
		$this->matches = array();
		$posts         = array();

		foreach ( $objects as $key => $record ) {
			switch ( $record['kind'] ) {
				case 'user':
					$this->matches[ $key ] = $this->match_user( $record );
					break;
				case 'term':
					$this->matches[ $key ] = $this->match_term( $record );
					break;
				case 'attachment':
					$this->matches[ $key ] = $this->match_attachment( $record );
					break;
				default:
					$posts[ $key ]         = $record;
					$this->matches[ $key ] = $this->by_uuid( $record );
			}
		}

		foreach ( $posts as $key => $record ) {
			if ( null === $this->matches[ $key ]['id'] && array() === $this->matches[ $key ]['candidates'] ) {
				$this->matches[ $key ] = $this->by_translation( $record, $objects );
			}
		}

		foreach ( $posts as $key => $record ) {
			if ( null === $this->matches[ $key ]['id'] && array() === $this->matches[ $key ]['candidates'] ) {
				$this->matches[ $key ] = $this->result( $this->posts_at_path( $record ), 'path' );
			}
		}

		return $this->matches;
	}
}
