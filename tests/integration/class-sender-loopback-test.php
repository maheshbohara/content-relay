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
}
