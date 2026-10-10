<?php
/**
 * The relay screen.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Admin;

use ContentRelay\Config;
use ContentRelay\Connection\Connections;
use ContentRelay\Discovery\Exporter;

defined( 'ABSPATH' ) || exit;

/**
 * A screen without a menu entry, reached from the "Relay to…" row action: choose a connection, review what is relayed
 * and the receiver's plan, relay, roll back. Rendered by `assets/admin/relay.js` against the admin REST routes.
 */
final class Relay_Screen {

	/**
	 * Page slug.
	 */
	public const SLUG = 'content-relay-relay';

	/**
	 * Screen URL for a page.
	 *
	 * @param int $post_id Page id.
	 */
	public static function url( int $post_id ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'post' => $post_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Hooks the screen.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	/**
	 * Registers the screen without a menu entry.
	 */
	public function menu(): void {
		$hook = add_submenu_page( '', __( 'Relay content', 'content-relay' ), __( 'Relay content', 'content-relay' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Enqueues assets for a valid page.
	 */
	public function load(): void {
		Admin::enqueue_style();

		$post = self::requested_post();

		// A page without a menu entry gets no title from WordPress, which then passes null to strip_tags(); set one.
		global $title;
		/* translators: %s: page title. */
		$title = null === $post ? __( 'Relay content', 'content-relay' ) : sprintf( __( 'Relay “%s”', 'content-relay' ), $post->post_title ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The documented way to title an admin page that has no menu entry.

		if ( null === $post || ! Config::can_send() ) {
			return;
		}

		add_action(
			'admin_enqueue_scripts',
			static function () use ( $post ): void {
				self::scripts( $post );
			}
		);
	}

	/**
	 * Renders the screen shell.
	 */
	public function render(): void {
		$post = self::requested_post();

		echo '<div class="wrap content-relay">';
		if ( null === $post ) {
			echo '<h1>' . esc_html__( 'Relay content', 'content-relay' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Choose what to relay from a content list, such as Pages.', 'content-relay' ) . '</p></div></div>';
			return;
		}

		/* translators: %s: page title. */
		echo '<h1>' . esc_html( sprintf( __( 'Relay “%s”', 'content-relay' ), $post->post_title ) ) . '</h1>';

		if ( ! Config::can_send() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Sending is off on this site. Switch it on under Settings → Content Relay to relay content.', 'content-relay' ) . '</p></div></div>';
			return;
		}
		if ( 0 === Admin::connection_count() ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div></div>',
				esc_html__( 'This site has no connections yet.', 'content-relay' ),
				esc_url( Settings_Page::url() ),
				esc_html__( 'Add one in Settings → Content Relay.', 'content-relay' )
			);
			return;
		}

		echo '<div id="content-relay-app" class="content-relay-app"><p>' . esc_html__( 'Loading…', 'content-relay' ) . '</p></div>';
		echo '<noscript><div class="notice notice-error"><p>' . esc_html__( 'The relay screen needs JavaScript. You can also relay with WP-CLI: wp content-relay push.', 'content-relay' ) . '</p></div></noscript>';
		echo '</div>';
	}

	/**
	 * Enqueues the app with its configuration.
	 *
	 * @param \WP_Post $post Page.
	 */
	private static function scripts( \WP_Post $post ): void {
		wp_enqueue_script( 'content-relay-relay', CONTENT_RELAY_URL . 'assets/admin/relay.js', array( 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' ), CONTENT_RELAY_VERSION, true );
		wp_set_script_translations( 'content-relay-relay', 'content-relay', CONTENT_RELAY_DIR . 'languages' );

		$connections = array();
		$stored      = get_option( Connections::OPTION, array() );
		foreach ( is_array( $stored ) ? $stored : array() as $label => $record ) {
			$connections[] = array(
				'label' => (string) $label,
				'url'   => (string) ( $record['url'] ?? '' ),
			);
		}

		$config = array(
			'post'        => array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'type'    => $post->post_type,
				'status'  => $post->post_status,
				'editUrl' => (string) get_edit_post_link( $post->ID, 'raw' ),
			),
			'connections' => $connections,
			'settingsUrl' => Settings_Page::url(),
			'historyUrl'  => History_Page::url(),
			'listUrl'     => admin_url( 'edit.php?post_type=' . $post->post_type ),
		);

		wp_add_inline_script( 'content-relay-relay', 'window.contentRelayConfig = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	/**
	 * The page named in the URL, when it may be relayed by the current user.
	 */
	private static function requested_post(): ?\WP_Post {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen; every action on it is a nonce-checked REST request.
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Exporter::post_types(), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return null;
		}

		return $post;
	}
}
