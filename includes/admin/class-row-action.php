<?php
/**
 * "Relay to…" row action.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Admin;

use ContentRelay\Config;
use ContentRelay\Discovery\Exporter;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Relay to…" to rows of relayable post types (filter `content_relay_post_types`, default pages).
 */
final class Row_Action {

	/**
	 * Hooks the filters.
	 */
	public function register(): void {
		add_filter( 'page_row_actions', array( $this, 'add' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add' ), 10, 2 );
	}

	/**
	 * Adds the action when sending is on, a connection exists and the user is an administrator.
	 *
	 * @param mixed $actions Row actions.
	 * @param mixed $post    Post.
	 * @return mixed
	 */
	public function add( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			return $actions;
		}
		if ( ! Config::can_send() || ! current_user_can( 'manage_options' ) || 0 === Admin::connection_count() || ! in_array( $post->post_type, Exporter::post_types(), true ) ) {
			return $actions;
		}

		$actions['content_relay'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( Relay_Screen::url( $post->ID ) ),
			/* translators: %s: page title. */
			esc_attr( sprintf( __( 'Relay “%s” to another site', 'content-relay' ), $post->post_title ) ),
			esc_html__( 'Relay to…', 'content-relay' )
		);

		return $actions;
	}
}
