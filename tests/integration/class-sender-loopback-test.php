<?php
/**
 * A site relaying to itself through its own signed REST API.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Integration;

use ContentRelay\Apply\Rollback;
use ContentRelay\Connection\Key_Codec;
use ContentRelay\Connection\Receiver_Keys;
use ContentRelay\Identity\Uuid;
use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;
use ContentRelay\Storage\Log_Table;
use ContentRelay\Storage\Staging;

/**
 * Sender → Client → receiver REST controller → Applier, with HTTP served in-process.
 */
final class Sender_Loopback_Test extends Test_Case {

	/**
	 * Registers both REST controllers and serves this site's HTTP requests in-process.
	 */
	public function set_up(): void {
		parent::set_up();

		$GLOBALS['wp_rest_server'] = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound --A fresh REST server per test.
		\ContentRelay\Connection\Rest_Controller::register();
		\ContentRelay\Admin\Rest_Controller::register();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		add_filter( 'pre_http_request', array( $this, 'serve_locally' ), 10, 3 );
	}

	/**
	 * Drops the REST server.
	 */
	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound --Resets the server set up above.
		parent::tear_down();
	}

	/**
	 * Relay, apply with an object-cache flush, verify, then notice a rollback made on the receiver.
	 */
	public function test_relay_verify_and_receiver_rollback(): void {
		$issued = ( new Receiver_Keys() )->generate( 'loopback', $this->admin_id );
		$key    = ( new Key_Codec() )->decode( $issued['key'] );
		$this->assertIsArray( $key );

		$sender = Sender::for_site();
		$sender->connections()->add( 'self', $key, $this->admin_id );

		$page_id  = $this->page();
		$image_id = $this->image( $page_id );
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => self::image_block( $image_id ),
			)
		);

		$state   = $sender->start( $page_id, 'self', false );
		$session = (string) $state['session'];
		$this->assertTrue( Uuid::is_valid( $session ) );
		$this->assertSame( untrailingslashit( (string) wp_get_upload_dir()['baseurl'] ), $state['uploads_url'] );
		$this->assertFalse( $state['object_cache'] );
		while ( empty( $state['progress']['done'] ) ) {
			$state = $sender->upload( $session, 5.0 );
		}

		$plan = $sender->plan( $session )['plan'];
		$this->assertSame( array(), $plan['blockers'] );
		$this->assertSame( 'adopt', self::items( $plan )[ 'post:' . $page_id ]['action'] );

		$applied = $sender->apply( $session, (string) $plan['hash'], null, true );
		$this->assertTrue( $applied['result']['object_cache_flushed'] );

		$log = new Log_Table();
		$row = $log->find_session( $session, Log_Table::OUT );
		$this->assertSame( Log_Table::STATUS_APPLIED, $row['status'] );
		$this->assertSame( array( 'post:' . $page_id ), array_column( $row['result']['verify']['targets'], 'key' ) );

		$report = $sender->verify( $session );
		$this->assertTrue( $report['ok'], implode( "\n", $report['problems'] ) );
		$this->assertTrue( $log->find_session( $session, Log_Table::OUT )['result']['verification']['ok'] );

		$this->assertSame( Log_Table::STATUS_APPLIED, $sender->refresh_status( $session ) );

		// The receiver rolls the relay back on its own, as its History screen does.
		$staging = Staging::for_site();
		( new Rollback( $this->context() ) )->rollback( (string) $staging->read( $session )['apply_session'], true );
		$staging->update( $session, array( 'status' => Staging::STATUS_ROLLED_BACK ) );

		$this->assertSame( Log_Table::STATUS_APPLIED, $sender->refresh_status( $session ), 'A recent confirmation is trusted for a while.' );

		try {
			$sender->rollback( $session, false );
			$this->fail( 'Rolling back a relay the receiver already rolled back must be refused.' );
		} catch ( Send_Exception $e ) {
			$this->assertSame( 'not_applied', $e->error_code() );
		}
		$this->assertSame( Log_Table::STATUS_ROLLED_BACK, $log->find_session( $session, Log_Table::OUT )['status'], 'The refused rollback brought the log up to date.' );
		$this->assertSame( Log_Table::STATUS_ROLLED_BACK, $sender->refresh_status( $session ) );

		$this->expectException( Send_Exception::class );
		$sender->verify( $session );
	}

	/**
	 * Serves requests to this site in-process: REST routes through the REST server, any other page as plain HTML.
	 *
	 * @param false|array<string, mixed>|\WP_Error $pre  Short-circuit value.
	 * @param array<string, mixed>                 $args Request arguments.
	 * @param string                               $url  URL.
	 * @return false|array<string, mixed>|\WP_Error
	 */
	public function serve_locally( $pre, $args, $url ) {
		$home = home_url();
		if ( 0 !== strpos( $url, $home ) ) {
			return $pre;
		}

		$rest = home_url( '/wp-json' );
		if ( 0 !== strpos( $url, $rest ) ) {
			return self::response( 200, '<!doctype html><html><body><p>Relayed page</p></body></html>' );
		}

		$parts = wp_parse_url( substr( $url, strlen( $rest ) ) );
		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		$request = new \WP_REST_Request( (string) $args['method'], (string) ( $parts['path'] ?? '/' ) );
		$request->set_query_params( $query );
		foreach ( (array) $args['headers'] as $name => $value ) {
			$request->set_header( (string) $name, (string) $value );
		}
		if ( is_string( $args['body'] ?? null ) ) {
			$request->set_body( $args['body'] );
		}

		$user     = get_current_user_id();
		$response = rest_do_request( $request );
		wp_set_current_user( $user );

		return self::response( $response->get_status(), (string) wp_json_encode( rest_get_server()->response_to_data( $response, false ) ) );
	}

	/**
	 * A response in the shape wp_remote_request() returns.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Body.
	 * @return array<string, mixed>
	 */
	private static function response( int $code, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
