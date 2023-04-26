<?php
/**
 * E2E scenario 11: the receiver refuses bad signatures, skewed timestamps, replayed nonces and revoked keys.
 *
 * Run on the SENDER with the local TLS override:
 *   wp --require=…/tests/e2e/local-sender.php eval-file …/tests/e2e/security-checks.php <mode> <connection> [staging-dir]
 *
 * Modes:
 *   signed   — control, bad signature, ±301 s, replayed handshake, replayed POST /sessions (one session at most),
 *              tampered POST /sessions body; counts staging directories before/after (pass the receiver's staging dir).
 *   refused  — one valid handshake; expect 401 (use with a connection whose key was revoked on the receiver).
 *   absent   — one valid handshake; expect 404 (use while CONTENT_RELAY_RECEIVE is off on the receiver).
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

use ContentRelay\Connection\Connections;
use ContentRelay\Connection\Signer;
use ContentRelay\Connection\Vault;
use ContentRelay\Support\Base64_Url;

$mode    = $args[0] ?? 'signed';
$label   = $args[1] ?? 'clone';
$staging = $args[2] ?? '';
$conn    = ( new Connections( Vault::from_config() ) )->get( $label );

/**
 * Sends a hand-signed request.
 *
 * @return array{status:int, code:string, headers:array<string,string>, data:array}
 */
function prs_send( array $conn, string $method, string $route, string $body, array $over = array() ): array {
	$signer    = new Signer();
	$timestamp = $over['timestamp'] ?? time();
	$nonce     = $over['nonce'] ?? Base64_Url::encode( random_bytes( 32 ) );
	$signature = $signer->sign( $signer->canonical( $method, $route, array(), $timestamp, $nonce, $over['signed_body'] ?? $body ), $conn['secret_key'] );
	if ( ! empty( $over['corrupt'] ) ) {
		$signature = ( 'A' === $signature[0] ? 'B' : 'A' ) . substr( $signature, 1 );
	}

	$headers = $over['headers'] ?? array(
		'Content-Type'           => 'application/json',
		'X-Content-Relay-Kid'       => $conn['kid'],
		'X-Content-Relay-Timestamp' => (string) $timestamp,
		'X-Content-Relay-Nonce'     => $nonce,
		'X-Content-Relay-User'      => 'security-checks',
		'X-Content-Relay-Signature' => $signature,
	);

	$url      = $conn['url'] . '/wp-json' . $route;
	$response = wp_remote_request(
		$url,
		apply_filters(
			'content_relay_http_args',
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => 60,
				'redirection' => 0,
			),
			$url
		)
	);
	if ( is_wp_error( $response ) ) {
		return array( 'status' => 0, 'code' => $response->get_error_message(), 'headers' => $headers, 'data' => array() );
	}
	$json = json_decode( wp_remote_retrieve_body( $response ), true );

	return array(
		'status'  => (int) wp_remote_retrieve_response_code( $response ),
		'code'    => (string) ( $json['code'] ?? '' ),
		'headers' => $headers,
		'data'    => (array) ( $json['data'] ?? array() ),
	);
}

$sessions = static function () use ( $staging ): int {
	return '' === $staging ? -1 : count( glob( rtrim( $staging, '/' ) . '/*-*-*-*-*', GLOB_ONLYDIR ) ?: array() );
};

$rows   = array();
$record = static function ( string $case, int $expected, array $got ) use ( &$rows ): void {
	$rows[] = array(
		'case'     => $case,
		'expected' => $expected,
		'got'      => $got['status'],
		'code'     => $got['code'],
		'pass'     => $expected === $got['status'] ? 'PASS' : 'FAIL',
	);
};

$handshake = '/content-relay/v1/handshake';

if ( 'refused' === $mode ) {
	$record( 'revoked kid: handshake', 401, prs_send( $conn, 'POST', $handshake, '{}' ) );
} elseif ( 'absent' === $mode ) {
	$record( 'receive off: handshake', 404, prs_send( $conn, 'POST', $handshake, '{}' ) );
} else {
	$manifest = ( new \ContentRelay\Discovery\Exporter( new \ContentRelay\Discovery\Export_Context( \ContentRelay\Language\Adapter_Factory::create() ) ) )->export( 4356 );
	$body     = (string) wp_json_encode( array( 'manifest' => $manifest, 'overwrite' => false ) );
	$before   = $sessions();

	$record( 'control: signed handshake', 200, prs_send( $conn, 'POST', $handshake, '{}' ) );
	$record( 'bad signature: POST /sessions', 401, prs_send( $conn, 'POST', '/content-relay/v1/sessions', $body, array( 'corrupt' => true ) ) );
	$record( 'tampered body: POST /sessions', 401, prs_send( $conn, 'POST', '/content-relay/v1/sessions', $body, array( 'signed_body' => '{"manifest":{}}' ) ) );
	$record( 'timestamp -301 s: POST /sessions', 401, prs_send( $conn, 'POST', '/content-relay/v1/sessions', $body, array( 'timestamp' => time() - 301 ) ) );
	$record( 'timestamp +301 s: POST /sessions', 401, prs_send( $conn, 'POST', '/content-relay/v1/sessions', $body, array( 'timestamp' => time() + 301 ) ) );
	$record( 'timestamp -290 s: handshake (inside window)', 200, prs_send( $conn, 'POST', $handshake, '{}', array( 'timestamp' => time() - 290 ) ) );
	$after_refused = $sessions();

	$first = prs_send( $conn, 'POST', '/content-relay/v1/sessions', $body );
	$record( 'replay: original POST /sessions', 201, $first );
	$record( 'replay: same request again', 401, prs_send( $conn, 'POST', '/content-relay/v1/sessions', $body, array( 'headers' => $first['headers'] ) ) );
	$after_replay = $sessions();

	if ( ! empty( $first['data']['session'] ) ) {
		$record( 'cleanup: DELETE replayed session', 200, prs_send( $conn, 'DELETE', '/content-relay/v1/sessions/' . $first['data']['session'], '' ) );
	}

	WP_CLI::log( sprintf( 'Staging sessions: before=%1$d, after refused requests=%2$d (expect equal), after original+replay=%3$d (expect +1), now=%4$d', $before, $after_refused, $after_replay, $sessions() ) );
}

WP_CLI\Utils\format_items( 'table', $rows, array( 'case', 'expected', 'got', 'code', 'pass' ) );
