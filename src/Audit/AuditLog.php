<?php
/**
 * Append-only record of every write made through the plugin.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Audit;

use WPDBase\Database\Query;
use wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the audit table is the plugin's own custom table. Values are bound through Query::prepare(), which WPCS cannot follow.

/**
 * Stores who ran what, where, when and with which effect.
 *
 * Lives in the site's own database (base prefix, so one table per network).
 */
final class AuditLog {

	public const SCHEMA_VERSION = '1';

	public const VERSION_OPTION = 'wpdbase_audit_schema';

	/**
	 * Longest statement kept verbatim.
	 */
	private const MAX_STATEMENT = 65535;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Site connection.
	 */
	public function __construct( private readonly wpdb $wpdb ) {
	}

	/**
	 * Audit table name.
	 */
	public function table(): string {
		return $this->wpdb->base_prefix . 'wpdbase_audit';
	}

	/**
	 * Create or upgrade the table when the stored schema version differs.
	 *
	 * Called lazily (first write or first audit read), so it also works when the
	 * plugin is loaded as a must-use plugin, where activation hooks never run.
	 */
	public function install(): void {
		if ( self::SCHEMA_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $this->wpdb->get_charset_collate();
		$table   = $this->table();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_login varchar(60) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				database_name varchar(64) NOT NULL DEFAULT '',
				safety_mode varchar(16) NOT NULL DEFAULT '',
				kind varchar(16) NOT NULL DEFAULT '',
				statement longtext NOT NULL,
				affected_rows bigint(20) DEFAULT NULL,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				error text,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY user_id (user_id)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Record one statement.
	 *
	 * @param AuditEntry $entry What happened.
	 */
	public function record( AuditEntry $entry ): void {
		$this->install();

		$user = wp_get_current_user();

		$this->wpdb->insert(
			$this->table(),
			array(
				'created_at'    => current_time( 'mysql', true ),
				'user_id'       => (int) $user->ID,
				'user_login'    => (string) $user->user_login,
				'ip'            => $this->client_ip(),
				'database_name' => $entry->database,
				'safety_mode'   => $entry->mode,
				'kind'          => $entry->kind,
				'statement'     => mb_substr( $entry->statement, 0, self::MAX_STATEMENT ),
				'affected_rows' => $entry->affected_rows,
				'duration_ms'   => $entry->duration_ms,
				'error'         => $entry->error,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Most recent entries, newest first.
	 *
	 * @param int $limit  Page size (1–200).
	 * @param int $offset Offset.
	 * @return list<array<string, string|null>>
	 */
	public function recent( int $limit = 50, int $offset = 0 ): array {
		$this->install();

		$table = $this->table();
		$rows  = $this->wpdb->get_results(
			Query::prepare(
				$this->wpdb,
				"SELECT id, created_at, user_id, user_login, ip, database_name, safety_mode, kind, statement, affected_rows, duration_ms, error FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is the plugin's own.
				max( 1, min( 200, $limit ) ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Drop the table and option (uninstall).
	 */
	public function uninstall(): void {
		$this->wpdb->query( 'DROP TABLE IF EXISTS ' . $this->table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table.
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Remote address as seen by PHP. Proxy headers are not trusted: a site behind
	 * a proxy should fix REMOTE_ADDR at the web server.
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip;
	}
}
