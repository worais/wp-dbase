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
 * By default it lives in the site's own database (base prefix, so one table per
 * network). The `wpdbase_audit_table` filter can move it, e.g. to one central
 * table for many sites on the same server.
 */
final class AuditLog {

	public const SCHEMA_VERSION = '1';

	public const VERSION_OPTION = 'wpdbase_audit_schema';

	/**
	 * Longest statement kept verbatim.
	 */
	private const MAX_STATEMENT = 65535;

	/**
	 * Where entries are written.
	 *
	 * @var AuditTarget
	 */
	private AuditTarget $target;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Site connection.
	 */
	public function __construct( private readonly wpdb $wpdb ) {
		$default = $this->wpdb->base_prefix . 'wpdbase_audit';

		/**
		 * Filters where the audit trail is stored.
		 *
		 * Return a table name, or `database.table` for a table in another database
		 * on the same server (the connection uses the wp-config.php credentials).
		 * A table other than the default is not created by the plugin: create it
		 * with the statement from AuditLog::create_table_sql().
		 *
		 * @since 0.2.0
		 *
		 * @param string $table Default: `{$wpdb->base_prefix}wpdbase_audit`.
		 */
		$this->target = AuditTarget::resolve( apply_filters( 'wpdbase_audit_table', $default ), $default );
	}

	/**
	 * Where entries are written.
	 */
	public function target(): AuditTarget {
		return $this->target;
	}

	/**
	 * The CREATE TABLE statement for an audit table (as dbDelta expects it).
	 *
	 * @param string $table   Table reference, already quoted if needed.
	 * @param string $charset `DEFAULT CHARACTER SET … COLLATE …` clause.
	 */
	public static function create_table_sql( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
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
			) {$charset};";
	}

	/**
	 * Create or upgrade the table when the stored schema version differs.
	 *
	 * Called lazily (first write or first audit read), so it also works when the
	 * plugin is loaded as a must-use plugin, where activation hooks never run.
	 */
	public function install(): void {
		// An external table belongs to whoever pointed the filter at it.
		if ( $this->target->external || self::SCHEMA_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::create_table_sql( $this->target->table, $this->wpdb->get_charset_collate() ) );

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

		// Not wpdb::insert(): it cannot address a table in another database.
		$this->wpdb->query(
			Query::prepare(
				$this->wpdb,
				'INSERT INTO ' . $this->target->sql() . ' (created_at, user_id, user_login, ip, database_name, safety_mode, kind, statement, affected_rows, duration_ms, error)'
					. ' VALUES (%s, %d, %s, %s, %s, %s, %s, %s, ' . ( null === $entry->affected_rows ? 'NULL' : '%d' ) . ', %d, ' . ( null === $entry->error ? 'NULL' : '%s' ) . ')',
				...array_values(
					array_filter(
						array(
							current_time( 'mysql', true ),
							(int) $user->ID,
							(string) $user->user_login,
							$this->client_ip(),
							$entry->database,
							$entry->mode,
							$entry->kind,
							mb_substr( $entry->statement, 0, self::MAX_STATEMENT ),
							$entry->affected_rows,
							$entry->duration_ms,
							$entry->error,
						),
						static fn( $value ): bool => null !== $value
					)
				)
			)
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

		$table = $this->target->sql();
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
		if ( $this->target->external ) {
			return;
		}

		$this->wpdb->query( 'DROP TABLE IF EXISTS ' . $this->target->sql() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table.
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
