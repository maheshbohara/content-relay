<?php
/**
 * Tests for Ip_Matcher.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Support;

use ContentRelay\Support\Ip_Matcher;
use PHPUnit\Framework\TestCase;

/**
 * IP allowlist tests.
 */
final class Ip_Matcher_Test extends TestCase {

	/**
	 * IPv4 addresses and ranges.
	 */
	public function test_ipv4(): void {
		$this->assertTrue( Ip_Matcher::matches( '203.0.113.7', array( '203.0.113.7' ) ) );
		$this->assertTrue( Ip_Matcher::matches( '203.0.113.200', array( '198.51.100.1', '203.0.113.0/24' ) ) );
		$this->assertTrue( Ip_Matcher::matches( '10.1.2.3', array( '10.0.0.0/8' ) ) );
		$this->assertTrue( Ip_Matcher::matches( '192.168.1.130', array( '192.168.1.128/25' ) ) );
		$this->assertFalse( Ip_Matcher::matches( '192.168.1.127', array( '192.168.1.128/25' ) ) );
		$this->assertTrue( Ip_Matcher::matches( '1.2.3.4', array( '0.0.0.0/0' ) ) );
		$this->assertFalse( Ip_Matcher::matches( '203.0.114.1', array( '203.0.113.0/24' ) ) );
	}

	/**
	 * IPv6 addresses and ranges; families never cross-match.
	 */
	public function test_ipv6_and_family_separation(): void {
		$this->assertTrue( Ip_Matcher::matches( '2001:db8::1', array( '2001:db8::/32' ) ) );
		$this->assertTrue( Ip_Matcher::matches( '::1', array( '::1' ) ) );
		$this->assertFalse( Ip_Matcher::matches( '2001:db9::1', array( '2001:db8::/32' ) ) );
		$this->assertFalse( Ip_Matcher::matches( '127.0.0.1', array( '::1', '::/0' ) ) );
	}

	/**
	 * Malformed input never matches.
	 */
	public function test_malformed(): void {
		$this->assertFalse( Ip_Matcher::matches( '', array( '0.0.0.0/0' ) ) );
		$this->assertFalse( Ip_Matcher::matches( 'not-an-ip', array( '0.0.0.0/0' ) ) );
		$this->assertFalse( Ip_Matcher::matches( '10.0.0.1', array( '10.0.0.0/33', '10.0.0.0/x', 'garbage' ) ) );
		$this->assertFalse( Ip_Matcher::matches( '10.0.0.1', array() ) );
	}
}
