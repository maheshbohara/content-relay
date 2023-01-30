<?php
/**
 * The relay log: sessions sent from and received by this site.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * `{prefix}content_relay_log` (spec §16). One row per relay session and direction; the history screen and
 * `wp content-relay history` read it, rollback updates it.
 */
final class Log_Table {

	/**
	 * Schema version of the table.
	 */
	public const DB_VERSION = 1;

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'content_relay_db_version';

	/**
	 * Directions.
	 */
	public const OUT = 'out';
	public const IN  = 'in';

	/**
	 * Statuses.
	 */
	public const STATUS_PLANNED     = 'planned';
	public const STATUS_APPLIED     = 'applied';
	public const STATUS_ABORTED     = 'aborted';
	public const STATUS_ROLLED_BACK = 'rolled-back';

	/**
	 * Columns stored as JSON.
	 */
	private const JSON_COLUMNS = array( 'plan', 'result' );

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'content_relay_log';
	}

	/**
	 * Creates or upgrades the table.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session varchar(36) NOT NULL,
				direction varchar(3) NOT NULL,
				connection varchar(64) NOT NULL DEFAULT '',
				peer_url varchar(255) NOT NULL DEFAULT '',
				kid varchar(16) NOT NULL DEFAULT '',
				root_uuid varchar(36) NOT NULL DEFAULT '',
				root_title text NOT NULL,
				root_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				languages varchar(64) NOT NULL DEFAULT '',
				user_login varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT '',
				apply_session varchar(36) NOT NULL DEFAULT '',
				plan longtext NULL,
				result longtext NULL,
				error text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY session_direction (session,direction),
				KEY created_at (created_at)
			) {$collate};"
		);

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Installs the table when this site runs an older schema (activation hooks do not run on plugin updates).
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Adds a row.
	 *
	 * @param array<string, mixed> $row Columns.
	 * @return int Row id.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = $this->encode(
			array_merge(
				array(
					'root_title' => '',
					'created_at' => $now,
					'updated_at' => $now,
				),
				$row
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
		$wpdb->insert( self::table(), $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates the row of a session.
	 *
	 * @param string               $session   Session id.
	 * @param string               $direction `out` or `in`.
	 * @param array<string, mixed> $changes   Columns.
	 */
	public function update( string $session, string $direction, array $changes ): void {
		global $wpdb;

		$changes['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->update(
			self::table(),
			$this->encode( $changes ),
			array(
				'session'   => $session,
				'direction' => $direction,
			)
		);
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );

		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/**
	 * The row of a session.
	 *
	 * @param string $session   Session id.
	 * @param string $direction `out` or `in`.
	 * @return array<string, mixed>|null
	 */
	public function find_session( string $session, string $direction ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE session = %s AND direction = %s ORDER BY id DESC LIMIT 1', self::table(), $session, $direction ), ARRAY_A );

		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/**
	 * Most recent rows, newest first, without the plan and result columns.
	 *
	 * @param string|null $direction `out`, `in`, or null for both.
	 * @param int         $limit     Rows.
	 * @param int         $offset    Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( ?string $direction, int $limit, int $offset = 0 ): array {
		global $wpdb;

		if ( null === $direction ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, session, direction, connection, peer_url, kid, root_uuid, root_title, root_post_id, languages, user_login, status, apply_session, error, created_at, updated_at FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), $limit, $offset ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, session, direction, connection, peer_url, kid, root_uuid, root_title, root_post_id, languages, user_login, status, apply_session, error, created_at, updated_at FROM %i WHERE direction = %s ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), $direction, $limit, $offset ), ARRAY_A );
		}

		return is_array( $rows ) ? $rows : array();
	}
}
