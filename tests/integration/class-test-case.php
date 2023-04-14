<?php
/**
 * Base class for integration tests.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Integration;

use ContentRelay\Config;
use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Exporter;
use ContentRelay\Language\Adapter_Factory;
use ContentRelay\Receive\Bundle;
use ContentRelay\Receive\Receive_Context;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test fixtures write temporary files.

/**
 * Fixtures for relays on one plain WordPress site: pages, real image attachments, manifests and bundle directories.
 */
abstract class Test_Case extends \WP_UnitTestCase {

	/**
	 * Administrator the relay runs as.
	 *
	 * @var int
	 */
	protected int $admin_id = 0;

	/**
	 * Temporary directories to remove after the test.
	 *
	 * @var string[]
	 */
	private array $temp_dirs = array();

	/**
	 * Logs in an administrator and switches sending and receiving on.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		Config::set( 'send', true );
		Config::set( 'receive', true );
	}

	/**
	 * Removes temporary directories.
	 */
	public function tear_down(): void {
		foreach ( $this->temp_dirs as $dir ) {
			self::remove_dir( $dir );
		}
		$this->temp_dirs = array();

		parent::tear_down();
	}
}
