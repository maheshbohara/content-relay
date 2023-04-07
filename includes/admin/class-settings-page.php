<?php
/**
 * Settings → Content Relay.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Admin;

use ContentRelay\Config;
use ContentRelay\Connection\Client;
use ContentRelay\Connection\Connection_Exception;
use ContentRelay\Connection\Connections;
use ContentRelay\Connection\Key_Codec;
use ContentRelay\Connection\Receiver_Keys;
use ContentRelay\Connection\Vault;
use ContentRelay\Language\Adapter_Factory;
use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;
use ContentRelay\Storage\Log_Table;

defined( 'ABSPATH' ) || exit;

/**
 * This site's send/receive status, the keys it issued, and its connections (spec §14.1).
 *
 * Forms post back to the page itself and are handled on `load-…` before output, so an issued key can be shown once
 * in the same response without ever being stored.
 */
final class Settings_Page {

	/**
	 * Page slug.
	 */
	public const SLUG = 'content-relay';

	/**
	 * Notices for this request.
	 *
	 * @var array<int, array{type: string, message: string}>
	 */
	private array $notices = array();

	/**
	 * Key issued in this request, shown once.
	 *
	 * @var array{kid: string, key: string}|null
	 */
	private ?array $issued = null;

	/**
	 * Settings page URL.
	 */
	public static function url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Hooks the page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	/**
	 * Adds the menu entry.
	 */
	public function menu(): void {
		$hook = add_options_page( __( 'Content Relay', 'content-relay' ), __( 'Content Relay', 'content-relay' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Handles a submitted form before the page renders.
	 */
	public function load(): void {
		Admin::enqueue_style();

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Content Relay.', 'content-relay' ), 403 );
		}

		$action = isset( $_POST['content_relay_action'] ) ? sanitize_key( wp_unslash( $_POST['content_relay_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Names the nonce action verified on the next line.
		check_admin_referer( 'content_relay_' . $action );

		switch ( $action ) {
			case 'toggle_switch':
				$this->toggle_switch();
				break;
			case 'generate_key':
				$this->generate_key();
				break;
			case 'revoke_key':
				$this->revoke_key();
				break;
			case 'add_connection':
				$this->add_connection();
				break;
			case 'test_connection':
				$this->test_connection();
				break;
			case 'remove_connection':
				$this->remove_connection();
				break;
			case 'save_post_types':
				$this->save_post_types();
				break;
		}
	}
}
