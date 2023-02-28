<?php
/**
 * Checks that this site can hold what a manifest carries.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Receive;

use ContentRelay\Support\Theme_Fingerprint;

defined( 'ABSPATH' ) || exit;

/**
 * Environment checks (spec §8.2): theme, blocks, ACF field types, content types, languages, storage.
 */
final class Preflight {

	/**
	 * Tables the content commit writes inside one transaction.
	 */
	private const TRANSACTIONAL_TABLES = array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'icl_translations' );

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 */
	public function __construct( Receive_Context $context ) {
		$this->context = $context;
	}
}
