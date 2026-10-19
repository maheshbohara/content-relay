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

	/**
	 * Adds a connection after a successful handshake.
	 */
	private function add_connection(): void {
		try {
			$sender = Sender::for_site();
		} catch ( Send_Exception $e ) {
			$this->notice( 'error', $e->plain_message() );
			return;
		}

		$label = strtolower( self::posted( 'label' ) );
		if ( ! Connections::is_valid_label( $label ) ) {
			$this->notice( 'error', __( 'Labels use lowercase letters, digits, hyphens and underscores, for example prod.', 'content-relay' ) );
			return;
		}

		$key = ( new Key_Codec() )->decode( self::posted( 'key' ) );
		if ( null === $key ) {
			$this->notice( 'error', __( 'That is not a valid Content Relay key. Paste the whole key; it starts with contentrelay1.', 'content-relay' ) );
			return;
		}

		try {
			$environment = Sender::handshake_client( new Client( $key ) );
			$sender->connections()->add( $label, $key, get_current_user_id() );
		} catch ( Send_Exception $e ) {
			$this->notice( 'error', $e->plain_message() );
			return;
		} catch ( Connection_Exception $e ) {
			$this->notice( 'error', html_entity_decode( $e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			return;
		}

		$this->notice(
			'success',
			sprintf(
				/* translators: 1: connection label, 2: receiver address, 3: WordPress version, 4: theme name, 5: theme version. */
				__( 'Connection %1$s added: %2$s (WordPress %3$s, theme %4$s %5$s).', 'content-relay' ),
				$label,
				(string) ( $environment['home_url'] ?? $key['url'] ),
				(string) ( $environment['wp'] ?? '?' ),
				(string) ( $environment['theme']['stylesheet'] ?? '?' ),
				(string) ( $environment['theme']['version'] ?? '?' )
			)
		);
	}

	/**
	 * Runs a handshake with a connection.
	 */
	private function test_connection(): void {
		$label = self::posted( 'label' );

		try {
			$environment = Sender::for_site()->handshake( $label );
		} catch ( Send_Exception $e ) {
			$this->notice( 'error', $e->plain_message() );
			return;
		}

		$skew  = (int) ( $environment['server_time'] ?? 0 ) - time();
		$theme = wp_get_theme();
		$this->notice(
			'success',
			sprintf(
				/* translators: 1: connection label, 2: receiver address, 3: WordPress version, 4: PHP version, 5: theme name, 6: theme version, 7: language setup, 8: clock difference in seconds. */
				__( 'Handshake with %1$s succeeded: %2$s, WordPress %3$s, PHP %4$s, theme %5$s %6$s, languages %7$s, clock difference %8$+d s.', 'content-relay' ),
				$label,
				(string) ( $environment['home_url'] ?? '' ),
				(string) ( $environment['wp'] ?? '?' ),
				(string) ( $environment['php'] ?? '?' ),
				(string) ( $environment['theme']['stylesheet'] ?? '?' ),
				(string) ( $environment['theme']['version'] ?? '?' ),
				implode( ', ', (array) ( $environment['language']['active'] ?? array() ) ),
				$skew
			)
		);

		if ( ( $environment['theme']['stylesheet'] ?? '' ) !== get_stylesheet() || (string) ( $environment['theme']['version'] ?? '' ) !== (string) $theme->get( 'Version' ) ) {
			$this->notice( 'warning', __( 'The receiver runs a different theme or theme version. Relays are blocked until the theme is deployed there.', 'content-relay' ) );
		}
		if ( abs( $skew ) > 60 ) {
			$this->notice( 'warning', __( 'The clocks of the two sites differ by more than a minute. Requests are refused beyond five minutes.', 'content-relay' ) );
		}
	}

	/**
	 * Removes a connection (works even when its key can no longer be decrypted here).
	 */
	private function remove_connection(): void {
		$label       = self::posted( 'label' );
		$connections = get_option( Connections::OPTION, array() );
		if ( ! is_array( $connections ) || ! isset( $connections[ $label ] ) ) {
			/* translators: %s: connection label. */
			$this->notice( 'error', sprintf( __( 'There is no connection named %s.', 'content-relay' ), $label ) );
			return;
		}

		unset( $connections[ $label ] );
		update_option( Connections::OPTION, $connections, false );
		/* translators: %s: connection label. */
		$this->notice( 'success', sprintf( __( 'Connection %s removed. Revoke its key on the receiving site too.', 'content-relay' ), $label ) );
	}

	/**
	 * Shows a just-issued key once.
	 *
	 * @param array{kid: string, key: string} $issued Issued key.
	 */
	private function render_issued_key( array $issued ): void {
		echo '<div class="notice notice-warning content-relay-issued">';
		/* translators: %s: key id. */
		echo '<p><strong>' . esc_html( sprintf( __( 'Key %s issued. Copy it now: it is not stored on this site and cannot be shown again.', 'content-relay' ), $issued['kid'] ) ) . '</strong></p>';
		echo '<p><textarea class="large-text code" rows="3" readonly onclick="this.select()">' . esc_textarea( $issued['key'] ) . '</textarea></p>';
		echo '<p>' . esc_html__( 'On the sending site, open Settings → Content Relay and paste it under Add connection. The key lets that site relay content here, and nothing else; it stops working if it is revoked or you are no longer an administrator.', 'content-relay' ) . '</p>';
		echo '</div>';
	}

	/**
	 * This site's status.
	 */
	private function render_status(): void {
		$language  = Adapter_Factory::create();
		$allowlist = Config::ip_allowlist();

		echo '<h2>' . esc_html__( 'This site', 'content-relay' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		self::switch_row(
			'send',
			__( 'Sending', 'content-relay' ),
			__( 'Lets this site relay content to its connections.', 'content-relay' )
		);
		self::switch_row(
			'receive',
			__( 'Receiving', 'content-relay' ),
			__( 'Lets sites holding an active key issued here relay content to this site. When off, the relay routes do not exist.', 'content-relay' )
		);

		$this->post_types_row();

		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html__( 'Plugin', 'content-relay' ),
			/* translators: 1: plugin version, 2: manifest schema number. */
			esc_html( sprintf( __( 'Version %1$s, manifest schema %2$d', 'content-relay' ), CONTENT_RELAY_VERSION, (int) CONTENT_RELAY_SCHEMA ) )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html__( 'Languages', 'content-relay' ),
			esc_html(
				'none' === $language->id()
					? __( 'No multilingual plugin', 'content-relay' )
					/* translators: 1: multilingual plugin, 2: default language code, 3: active language codes. */
					: sprintf( __( '%1$s, default %2$s, active %3$s', 'content-relay' ), strtoupper( $language->id() ), (string) $language->default_language(), implode( ', ', $language->active_languages() ) )
			)
		);
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html__( 'Connection keys', 'content-relay' ),
			esc_html(
				null === Vault::from_config()
					? __( 'No key material: define CONTENT_RELAY_ENCRYPTION_KEY, or real AUTH_KEY and SECURE_AUTH_KEY salts, in wp-config.php before adding connections.', 'content-relay' )
					: __( 'Stored encrypted with key material from wp-config.php.', 'content-relay' )
			)
		);
		if ( Config::can_receive() ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
				esc_html__( 'Sender IP allowlist', 'content-relay' ),
				esc_html( null === $allowlist ? __( 'None (any IP with a valid key)', 'content-relay' ) : implode( ', ', $allowlist ) )
			);
		}
		printf(
			'<tr><th scope="row">%1$s</th><td><a href="%2$s">%3$s</a></td></tr>',
			esc_html__( 'History', 'content-relay' ),
			esc_url( History_Page::url() ),
			/* translators: %d: number of logged relay sessions. */
			esc_html( sprintf( _n( '%d relay session logged', '%d relay sessions logged', ( new Log_Table() )->count( null ), 'content-relay' ), ( new Log_Table() )->count( null ) ) )
		);

		echo '</tbody></table>';
	}

	/**
	 * Receiver keys section.
	 */
	private function render_keys(): void {
		echo '<h2>' . esc_html__( 'Receiver keys', 'content-relay' ) . '</h2>';

		if ( ! Config::can_receive() ) {
			echo '<p>' . esc_html__( 'Receiving is off, so this site issues no keys. Keys issued earlier stay listed but are not accepted.', 'content-relay' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'A key lets one sending site relay content here: posts, their media and their terms. It gives no access to users, plugins, themes or settings. This site keeps only the public half; the key is shown once when it is issued.', 'content-relay' ) . '</p>';
		}

		$keys = ( new Receiver_Keys() )->all();
		if ( array() !== $keys ) {
			echo '<table class="widefat striped content-relay-table"><thead><tr>';
			foreach ( array( __( 'Key id', 'content-relay' ), __( 'Label', 'content-relay' ), __( 'Issued by', 'content-relay' ), __( 'Issued', 'content-relay' ), __( 'Last used', 'content-relay' ), __( 'Status', 'content-relay' ), '' ) as $heading ) {
				echo '<th scope="col">' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $keys as $kid => $record ) {
				$user    = get_userdata( (int) ( $record['created_by'] ?? 0 ) );
				$revoked = ! empty( $record['revoked_at'] );
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $kid ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $record['label'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( $user instanceof \WP_User ? $user->user_login : __( '(deleted user)', 'content-relay' ) ) . '</td>';
				echo '<td>' . esc_html( Admin::time( $record['created_at'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( Admin::time( $record['last_used'] ?? '' ) ) . '</td>';
				/* translators: %s: date and time the key was revoked. */
				echo '<td>' . esc_html( $revoked ? sprintf( __( 'Revoked %s', 'content-relay' ), Admin::time( $record['revoked_at'] ) ) : __( 'Active', 'content-relay' ) ) . '</td>';
				echo '<td>';
				if ( ! $revoked ) {
					self::form_open( 'revoke_key' );
					printf( '<input type="hidden" name="kid" value="%s" />', esc_attr( (string) $kid ) );
					printf(
						'<button type="submit" class="button button-link-delete" onclick="return confirm(%1$s)">%2$s</button>',
						esc_attr( (string) wp_json_encode( __( 'Revoke this key? The sending site that uses it can no longer relay here.', 'content-relay' ) ) ),
						esc_html__( 'Revoke', 'content-relay' )
					);
					echo '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( Config::can_receive() ) {
			echo '<h3>' . esc_html__( 'Issue a key', 'content-relay' ) . '</h3>';
			self::form_open( 'generate_key' );
			printf(
				'<p><label for="content-relay-key-label">%1$s</label><br /><input type="text" id="content-relay-key-label" name="label" class="regular-text" required placeholder="%2$s" /></p>',
				esc_html__( 'Label', 'content-relay' ),
				esc_attr__( 'dev.example.com', 'content-relay' )
			);
			submit_button( __( 'Issue key', 'content-relay' ), 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	/**
	 * Connections section.
	 */
	private function render_connections(): void {
		echo '<h2>' . esc_html__( 'Connections', 'content-relay' ) . '</h2>';

		if ( ! Config::can_send() ) {
			echo '<p>' . esc_html__( 'Sending is off, so this site cannot relay content. Connections stored earlier stay listed.', 'content-relay' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'A connection is a site this one relays content to, added with a key issued by that site.', 'content-relay' ) . '</p>';
		}

		$connections = get_option( Connections::OPTION, array() );
		$connections = is_array( $connections ) ? $connections : array();
		if ( array() !== $connections ) {
			echo '<table class="widefat striped content-relay-table"><thead><tr>';
			foreach ( array( __( 'Label', 'content-relay' ), __( 'Receiver', 'content-relay' ), __( 'Key id', 'content-relay' ), __( 'Added by', 'content-relay' ), __( 'Added', 'content-relay' ), '' ) as $heading ) {
				echo '<th scope="col">' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $connections as $label => $record ) {
				$user = get_userdata( (int) ( $record['added_by'] ?? 0 ) );
				echo '<tr>';
				echo '<td><strong>' . esc_html( (string) $label ) . '</strong></td>';
				printf( '<td><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></td>', esc_url( (string) ( $record['url'] ?? '' ) ), esc_html( (string) ( $record['url'] ?? '' ) ) );
				echo '<td><code>' . esc_html( (string) ( $record['kid'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( $user instanceof \WP_User ? $user->user_login : '' ) . '</td>';
				echo '<td>' . esc_html( Admin::time( $record['added_at'] ?? '' ) ) . '</td>';
				echo '<td class="content-relay-actions">';
				if ( Config::can_send() ) {
					self::form_open( 'test_connection' );
					printf( '<input type="hidden" name="label" value="%s" />', esc_attr( (string) $label ) );
					printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Test', 'content-relay' ) );
					echo '</form>';
				}
				self::form_open( 'remove_connection' );
				printf( '<input type="hidden" name="label" value="%s" />', esc_attr( (string) $label ) );
				printf(
					'<button type="submit" class="button button-link-delete" onclick="return confirm(%1$s)">%2$s</button>',
					esc_attr( (string) wp_json_encode( __( 'Remove this connection? Its key stays valid on the receiver until revoked there.', 'content-relay' ) ) ),
					esc_html__( 'Remove', 'content-relay' )
				);
				echo '</form></td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( Config::can_send() ) {
			echo '<h3>' . esc_html__( 'Add connection', 'content-relay' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'On the receiving site, issue a key under Settings → Content Relay, then paste it here. The key is checked with a handshake before it is stored.', 'content-relay' ) . '</p>';
			self::form_open( 'add_connection' );
			printf(
				'<p><label for="content-relay-connection-label">%1$s</label><br /><input type="text" id="content-relay-connection-label" name="label" class="regular-text" required pattern="[a-z0-9][a-z0-9_\-]{0,63}" placeholder="%2$s" /></p>',
				esc_html__( 'Label', 'content-relay' ),
				esc_attr__( 'prod', 'content-relay' )
			);
			printf(
				'<p><label for="content-relay-connection-key">%1$s</label><br /><textarea id="content-relay-connection-key" name="key" class="large-text code" rows="3" required placeholder="contentrelay1.…"></textarea></p>',
				esc_html__( 'Key', 'content-relay' )
			);
			submit_button( __( 'Add connection', 'content-relay' ), 'primary', 'submit', false );
			echo '</form>';
		}
	}

	/**
	 * One on/off switch row of the status table. Each switch is its own form, so a click saves it; a switch pinned in
	 * wp-config.php is shown disabled.
	 *
	 * @param string $name        `send` or `receive`.
	 * @param string $label       Row label.
	 * @param string $description What "on" means.
	 */
	private static function switch_row( string $name, string $label, string $description ): void {
		$on     = Config::is_on( $name );
		$locked = Config::is_locked( $name );
		$id     = 'content-relay-switch-' . $name;

		printf( '<tr><th scope="row" id="%1$s-label">%2$s</th><td>', esc_attr( $id ), esc_html( $label ) );
		self::form_open( 'toggle_switch' );
		printf( '<input type="hidden" name="switch" value="%s" />', esc_attr( $name ) );
		printf( '<input type="hidden" name="on" value="%s" />', $on ? '0' : '1' );
		printf(
			'<button type="submit" id="%1$s" class="content-relay-switch" role="switch" aria-checked="%2$s" aria-labelledby="%1$s-label" aria-describedby="%1$s-description"%3$s><span class="content-relay-switch-track" aria-hidden="true"><span class="content-relay-switch-thumb"></span></span><span class="content-relay-switch-state">%4$s</span></button>',
			esc_attr( $id ),
			$on ? 'true' : 'false',
			$locked ? ' disabled' : '',
			$on ? esc_html__( 'On', 'content-relay' ) : esc_html__( 'Off', 'content-relay' )
		);
		echo '</form>';

		printf( '<p class="description" id="%1$s-description">%2$s', esc_attr( $id ), esc_html( $description ) );
		if ( $locked ) {
			/* translators: %s: wp-config.php constant name. */
			echo ' ' . esc_html( sprintf( __( 'Set by %s in wp-config.php, so it cannot be changed here.', 'content-relay' ), Config::SWITCHES[ $name ] ) );
		}
		echo '</p></td></tr>';
	}

	/**
	 * Row with a checkbox per post type that can be relayed from this site.
	 */
	private function post_types_row(): void {
		$chosen = Config::post_types();
		$locked = has_filter( 'content_relay_post_types' );

		printf( '<tr><th scope="row">%s</th><td>', esc_html__( 'Relayable post types', 'content-relay' ) );
		self::form_open( 'save_post_types' );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Relayable post types', 'content-relay' ) . '</legend>';
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( ! Config::is_relayable_post_type( $type->name ) ) {
				continue;
			}
			printf(
				'<label style="display:inline-block;margin:0 1.5em .5em 0"><input type="checkbox" name="post_types[]" value="%1$s"%2$s /> %3$s <code>%1$s</code></label>',
				esc_attr( $type->name ),
				checked( in_array( $type->name, $chosen, true ), true, false ),
				esc_html( $type->labels->name )
			);
		}
		echo '</fieldset>';
		submit_button( __( 'Save post types', 'content-relay' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Posts of these types get a "Relay to…" action and can be relayed with WP-CLI. Each relay still carries every language version and the media it uses.', 'content-relay' );
		if ( $locked ) {
			echo ' ' . esc_html__( 'A content_relay_post_types filter in code also adjusts this list.', 'content-relay' );
		}
		echo '</p></td></tr>';
	}

	/**
	 * Opens a form for an action, with its nonce.
	 *
	 * @param string $action Action.
	 */
	private static function form_open( string $action ): void {
		echo '<form method="post" class="content-relay-form">';
		wp_nonce_field( 'content_relay_' . $action );
		printf( '<input type="hidden" name="content_relay_action" value="%s" />', esc_attr( $action ) );
	}

	/**
	 * A submitted text value.
	 *
	 * @param string $name Field name.
	 */
	private static function posted( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only called from load(), after check_admin_referer().
		return isset( $_POST[ $name ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) ) : '';
	}

	/**
	 * Queues a notice.
	 *
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Plain-text message.
	 */
	private function notice( string $type, string $message ): void {
		$this->notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
	}
}
