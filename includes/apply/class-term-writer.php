<?php
/**
 * Creates terms a relay needs but this site lacks.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Identity\Uuid;
use ContentRelay\Receive\Receive_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Term creation in the term's own language.
 *
 * Term meta is copied as stored, except ACF fields that hold ids (an image, a post, a term, a user): those ids are
 * mapped to this site's objects when the relay carries them, and removed with a warning when it does not, so a created
 * term never points at an unrelated object that happens to have the same id here.
 */
final class Term_Writer {

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Warnings from the terms created so far.
	 *
	 * @var string[]
	 */
	private array $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 */
	public function __construct( Receive_Context $context ) {
		$this->context = $context;
	}
}
