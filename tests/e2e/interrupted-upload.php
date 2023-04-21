<?php
/**
 * E2E scenario 16: an upload interrupted part-way is resumed by `push --resume`; a corrupted file is refused.
 *
 * Run on the SENDER against a receiver that does NOT have the page yet (so files are needed):
 *   wp --require=…/tests/e2e/local-sender.php eval-file …/tests/e2e/interrupted-upload.php <post-id> <connection> [bytes]
 *
 * Opens a session, sends the first <bytes> (default 300000) of the largest needed file and stops — the interruption.
 * Then proves the receiver refuses a duplicate chunk (409 with the real offset) and a full-length file whose bytes do
 * not match its SHA-1 (422, partial discarded). Prints SESSION=<id> for `wp content-relay push … --resume=<id>`.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

use ContentRelay\Connection\Client;
use ContentRelay\Connection\Connections;
use ContentRelay\Connection\Vault;

$post_id = (int) ( $args[0] ?? 4356 );
$label   = $args[1] ?? 'clone';
$partial = (int) ( $args[2] ?? 300000 );

$client   = new Client( ( new Connections( Vault::from_config() ) )->get( $label ) );
$manifest = ( new \ContentRelay\Discovery\Exporter( new \ContentRelay\Discovery\Export_Context( \ContentRelay\Language\Adapter_Factory::create() ) ) )->export( $post_id );
$uploads  = wp_get_upload_dir();
$paths    = array();
foreach ( $manifest['objects'] as $record ) {
	if ( isset( $record['file']['sha1'] ) ) {
		$paths[ $record['file']['sha1'] ] = $uploads['basedir'] . '/' . $record['file']['source'];
	}
}

$session = $client->create_session( $manifest, false );
if ( ! $session['ok'] ) {
	WP_CLI::error( 'create_session: ' . $session['message'] );
}
$id    = $session['data']['session'];
$files = $session['data']['files'];
if ( count( $files ) < 2 ) {
	WP_CLI::error( 'Need at least two files the receiver lacks; reset the receiver first.' );
}
usort( $files, static function ( $a, $b ) { return $b['bytes'] <=> $a['bytes']; } );
$large = $files[0];
$small = $files[ count( $files ) - 1 ];

$check = static function ( string $case, bool $pass, string $detail ): void {
	WP_CLI::log( sprintf( '%-4s %s — %s', $pass ? 'PASS' : 'FAIL', $case, $detail ) );
};

$bytes = (string) file_get_contents( $paths[ $large['sha1'] ], false, null, 0, $partial );
$r     = $client->upload( $id, $large['sha1'], 0, $bytes );
$check( 'first chunk stored', $r['ok'] && $partial === $r['data']['received'], sprintf( '%s received=%d of %d', $r['code'], $r['data']['received'] ?? -1, $large['bytes'] ) );

$r = $client->upload( $id, $large['sha1'], 0, $bytes );
$check( 'duplicate chunk refused with real offset', 409 === $r['status'] && 'offset_mismatch' === $r['code'] && $partial === $r['data']['received'], sprintf( 'HTTP %d %s received=%d', $r['status'], $r['code'], $r['data']['received'] ?? -1 ) );

$corrupt      = (string) file_get_contents( $paths[ $small['sha1'] ] );
$corrupt[100] = chr( ord( $corrupt[100] ) ^ 0xff );
$r            = $client->upload( $id, $small['sha1'], 0, $corrupt );
$check( 'corrupted file refused by SHA-1', 422 === $r['status'] && 'hash_mismatch' === $r['code'] && 0 === $r['data']['received'], sprintf( 'HTTP %d %s received=%d', $r['status'], $r['code'], $r['data']['received'] ?? -1 ) );

$state = $client->session( $id );
foreach ( $state['data']['files'] as $file ) {
	WP_CLI::log( sprintf( '  %s  %s  received=%d/%d', basename( $paths[ $file['sha1'] ] ), $file['complete'] ? 'complete' : 'pending ', $file['received'], $file['bytes'] ) );
}
WP_CLI::log( 'SESSION=' . $id );
