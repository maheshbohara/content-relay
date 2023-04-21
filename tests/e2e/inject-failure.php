<?php
/**
 * E2E scenario 12: throw inside the content transaction and confirm nothing survives.
 *
 * Usage (on the receiver): wp eval-file tests/e2e/inject-failure.php <bundle-dir>
 * Then run verify-state.php and compare the uploads listing: no new posts, files or pending markers.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

add_action(
	'content_relay_before_commit',
	static function () {
		throw new \RuntimeException( 'injected failure' );
	}
);

$context = new \ContentRelay\Receive\Receive_Context( \ContentRelay\Language\Adapter_Factory::create() );
$bundle  = \ContentRelay\Receive\Bundle::from_directory( $args[0] );
$plan    = ( new \ContentRelay\Receive\Planner( $context ) )->plan( $bundle, false );
echo '  planned actions: ' . json_encode( array_count_values( array_column( $plan['items'], 'action' ) ) ) . "\n";

try {
	( new \ContentRelay\Apply\Applier( $context ) )->apply( $bundle, false, $plan['hash'] );
	echo "  UNEXPECTED: apply succeeded\n";
} catch ( \ContentRelay\Apply\Apply_Exception $e ) {
	echo '  apply threw: ' . $e->getMessage() . "\n";
}
