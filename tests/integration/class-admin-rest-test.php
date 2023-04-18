<?php
/**
 * The own-site admin API.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Integration;

/**
 * `content-relay/v1/admin/*` permissions and responses.
 */
final class Admin_Rest_Test extends Test_Case {

	/**
	 * Registers the admin routes on a fresh REST server.
	 */
	public function set_up(): void {
		parent::set_up();

		$GLOBALS['wp_rest_server'] = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound --A fresh REST server per test.
		\ContentRelay\Admin\Rest_Controller::register();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	/**
	 * Drops the REST server.
	 */
	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound --Resets the server set up above.
		parent::tear_down();
	}

	/**
	 * Only administrators may discover what a relay would carry (D12).
	 */
	public function test_discover_needs_an_administrator(): void {
		$page = $this->page();

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->discover( $page )->get_status() );

		wp_set_current_user( $this->admin_id );
		$response = $this->discover( $page );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'post:' . $page, $data['data']['roots'][0]['key'] );
	}

	/**
	 * Checking a relay this site never sent answers 404 with a Content Relay error body.
	 */
	public function test_verify_unknown_relay(): void {
		$response = rest_do_request( new \WP_REST_Request( 'POST', '/content-relay/v1/admin/relays/' . wp_generate_uuid4() . '/verify' ) );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'unknown_session', $data['code'] );
	}

	/**
	 * `GET admin/discover?post=`.
	 *
	 * @param int $page Page id.
	 */
	private function discover( int $page ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/content-relay/v1/admin/discover' );
		$request->set_query_params( array( 'post' => (string) $page ) );

		return rest_do_request( $request );
	}
}
