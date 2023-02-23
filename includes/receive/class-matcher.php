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
}
