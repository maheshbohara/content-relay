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

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap content-relay">';
		echo '<h1>' . esc_html__( 'Content Relay', 'content-relay' ) . '</h1>';

		foreach ( $this->notices as $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}
		if ( null !== $this->issued ) {
			$this->render_issued_key( $this->issued );
		}

		$this->render_status();
		$this->render_keys();
		$this->render_connections();

		echo '</div>';
	}

	/**
	 * Turns sending or receiving on or off.
	 */
	private function toggle_switch(): void {
		$name = self::posted( 'switch' );
		$on   = '1' === self::posted( 'on' );

		if ( ! isset( Config::SWITCHES[ $name ] ) ) {
			return;
		}
		if ( ! Config::set( $name, $on ) ) {
			/* translators: %s: wp-config.php constant name. */
			$this->notice( 'error', sprintf( __( 'This switch is set by %s in wp-config.php and cannot be changed here.', 'content-relay' ), Config::SWITCHES[ $name ] ) );
			return;
		}

		$messages = array(
			'send'    => $on ? __( 'Sending is on. This site can relay content to its connections.', 'content-relay' ) : __( 'Sending is off.', 'content-relay' ),
			'receive' => $on ? __( 'Receiving is on. Sites holding an active key issued here can relay content to this site.', 'content-relay' ) : __( 'Receiving is off. Relay requests to this site are refused.', 'content-relay' ),
		);
		$this->notice( 'success', $messages[ $name ] );
	}

	/**
	 * Saves which post types can be relayed from this site.
	 */
	private function save_post_types(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only called from load(), after check_admin_referer(); each value is sanitized below.
		$raw    = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? wp_unslash( $_POST['post_types'] ) : array();
		$posted = array_map( 'sanitize_key', array_filter( $raw, 'is_string' ) );
		$saved  = Config::set_post_types( $posted );

		/* translators: %s: comma-separated list of post type names. */
		$this->notice( 'success', sprintf( __( 'Relayable post types: %s.', 'content-relay' ), implode( ', ', $saved ) ) );
	}

	/**
	 * Issues a receiver key.
	 */
	private function generate_key(): void {
		if ( ! Config::can_receive() ) {
			$this->notice( 'error', __( 'Receiving is off on this site, so it cannot issue keys.', 'content-relay' ) );
			return;
		}

		$label = self::posted( 'label' );
		if ( '' === $label ) {
			$this->notice( 'error', __( 'Give the key a label, for example the address of the site that will use it.', 'content-relay' ) );
			return;
		}

		$this->issued = ( new Receiver_Keys() )->generate( $label, get_current_user_id() );
	}

	/**
	 * Revokes a receiver key.
	 */
	private function revoke_key(): void {
		$kid = self::posted( 'kid' );
		if ( ( new Receiver_Keys() )->revoke( $kid ) ) {
			/* translators: %s: key id. */
			$this->notice( 'success', sprintf( __( 'Key %s revoked. Requests signed with it are refused from now on.', 'content-relay' ), $kid ) );
		} else {
			/* translators: %s: key id. */
			$this->notice( 'error', sprintf( __( 'There is no active key %s.', 'content-relay' ), $kid ) );
		}
	}
}
