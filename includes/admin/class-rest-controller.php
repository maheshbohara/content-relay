<?php
/**
 * Own-site admin API behind the relay screen.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Admin;

use ContentRelay\Connection\Client;
use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Export_Exception;
use ContentRelay\Discovery\Exporter;
use ContentRelay\Language\Adapter_Factory;
use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * `content-relay/v1/admin/*` — cookie-authenticated routes (WordPress REST nonce) for administrators of this site. They
 * wrap the Sender, so the browser drives a relay step by step and never holds a signing key. Registered only when
 * sending is on.
 */
final class Rest_Controller {

	/**
	 * Seconds each upload step may run.
	 */
	private const UPLOAD_BUDGET_SECONDS = 8.0;

	/**
	 * Hooks the routes.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( new self(), 'routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		$relay  = '/admin/relays/(?P<session>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})';
		$routes = array(
			array( '/admin/discover', 'GET', 'discover' ),
			array( '/admin/relays', 'POST', 'start' ),
			array( $relay . '/upload', 'POST', 'upload' ),
			array( $relay . '/plan', 'GET', 'plan' ),
			array( $relay . '/apply', 'POST', 'apply' ),
			array( $relay, 'DELETE', 'abort' ),
			array( $relay . '/rollback', 'POST', 'rollback' ),
			array( $relay . '/verify', 'POST', 'verify' ),
		);

		foreach ( $routes as $route ) {
			register_rest_route(
				Client::REST_NAMESPACE,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => array( $this, 'permission' ),
				)
			);
		}
	}

	/**
	 * Administrators only (D12).
	 */
	public function permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * `GET /admin/discover?post=` — what a relay of the page would carry.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function discover( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = absint( $request->get_param( 'post' ) );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::error( 'forbidden', __( 'You cannot edit that content.', 'content-relay' ), 403 );
		}

		try {
			$manifest = ( new Exporter( new Export_Context( Adapter_Factory::create() ) ) )->export( $post_id );
		} catch ( Export_Exception $e ) {
			return self::error( 'export_failed', html_entity_decode( $e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 400 );
		}

		$objects = array();
		$files   = array();
		$bytes   = 0;
		foreach ( (array) $manifest['objects'] as $key => $record ) {
			$file_bytes = (int) ( $record['file']['bytes'] ?? 0 );
			$sha1       = (string) ( $record['file']['sha1'] ?? '' );
			if ( '' !== $sha1 && ! isset( $files[ $sha1 ] ) ) {
				$files[ $sha1 ] = true;
				$bytes         += $file_bytes;
			}
			$objects[] = array(
				'key'       => (string) $key,
				'kind'      => (string) $record['kind'],
				'role'      => (string) $record['role'],
				'language'  => $record['language'] ?? null,
				'label'     => self::describe( $record ),
				'bytes'     => $file_bytes,
				'relations' => implode( ', ', (array) ( $record['relations'] ?? array() ) ),
			);
		}

		$roots = array();
		foreach ( (array) $manifest['roots'] as $key ) {
			$record  = (array) $manifest['objects'][ $key ];
			$roots[] = array(
				'key'      => (string) $key,
				'language' => $record['language'] ?? null,
				'title'    => (string) ( $record['title'] ?? '' ),
				'status'   => (string) ( $record['fields']['post_status'] ?? '' ),
				'path'     => (string) ( $record['path'] ?? '' ),
			);
		}

		$requirements = array();
		foreach ( (array) $manifest['requirements'] as $type => $items ) {
			$requirements[ (string) $type ] = count( (array) $items );
		}

		return self::ok(
			array(
				'roots'        => $roots,
				'objects'      => $objects,
				'files'        => count( $files ),
				'bytes'        => $bytes,
				'requirements' => $requirements,
				'warnings'     => array_values( (array) $manifest['warnings'] ),
			)
		);
	}
}
