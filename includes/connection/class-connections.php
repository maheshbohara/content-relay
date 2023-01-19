<?php
/**
 * Receivers this site can relay to.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

use ContentRelay\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Connection store (spec §5.2, §16). The signing key is encrypted with key material from wp-config.php, so a copied
 * database carries connection records but not the ability to use them.
 */
final class Connections {

	/**
	 * Option holding the connections.
	 */
	public const OPTION = 'content_relay_connections';

	/**
	 * Vault.
	 *
	 * @var Vault
	 */
	private Vault $vault;

	/**
	 * Constructor.
	 *
	 * @param Vault $vault Vault built from wp-config.php key material.
	 */
	public function __construct( Vault $vault ) {
		$this->vault = $vault;
	}
}
