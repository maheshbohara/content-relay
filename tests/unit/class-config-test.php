<?php
/**
 * Tests for Config.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit;

use ContentRelay\Config;
use PHPUnit\Framework\TestCase;

/**
 * Send/receive switch tests.
 */
final class Config_Test extends TestCase {

	/**
	 * Starts every test with no stored switches.
	 */
	protected function setUp(): void {
		$GLOBALS['content_relay_test_options'] = array();
	}

	/**
	 * Both switches are off until they are switched on.
	 */
	public function test_off_by_default(): void {
		$this->assertFalse( Config::can_send() );
		$this->assertFalse( Config::can_receive() );
		$this->assertFalse( Config::is_locked( 'send' ) );
	}

	/**
	 * The dashboard option turns each switch on and off independently.
	 */
	public function test_set_switches_independently(): void {
		$this->assertTrue( Config::set( 'receive', true ) );
		$this->assertTrue( Config::can_receive() );
		$this->assertFalse( Config::can_send() );

		$this->assertTrue( Config::set( 'send', true ) );
		$this->assertTrue( Config::set( 'receive', false ) );
		$this->assertTrue( Config::can_send() );
		$this->assertFalse( Config::can_receive() );
	}

	/**
	 * Only a stored boolean true counts as on; unknown switches are refused.
	 */
	public function test_strict_values_and_unknown_switch(): void {
		$GLOBALS['content_relay_test_options'][ Config::OPTION ] = array(
			'send'    => '1',
			'receive' => 1,
		);
		$this->assertFalse( Config::can_send() );
		$this->assertFalse( Config::can_receive() );

		$GLOBALS['content_relay_test_options'][ Config::OPTION ] = 'on';
		$this->assertFalse( Config::can_send() );

		$this->assertFalse( Config::set( 'everything', true ) );
		$this->assertFalse( Config::is_on( 'everything' ) );
	}

	/**
	 * A wp-config.php constant overrides the option and locks the switch.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_locks_switch(): void {
		define( 'CONTENT_RELAY_SEND', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The plugin's own constant.
		$GLOBALS['content_relay_test_options'][ Config::OPTION ] = array( 'send' => true );

		$this->assertTrue( Config::is_locked( 'send' ) );
		$this->assertFalse( Config::can_send() );
		$this->assertFalse( Config::set( 'send', true ) );
		$this->assertSame( array( 'send' => true ), $GLOBALS['content_relay_test_options'][ Config::OPTION ] );

		$this->assertFalse( Config::is_locked( 'receive' ) );
		$this->assertTrue( Config::set( 'receive', true ) );
		$this->assertTrue( Config::can_receive() );
	}
}
