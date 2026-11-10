<?php
/**
 * Numbers for the "Database health" overview.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Database;

use wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- read-only diagnostics; identifiers are allow-listed and quoted.

/**
 * Cheap health checks: information_schema for sizes, indexed queries for the rest.
 *
 * Each WordPress-specific check carries a MySQL optimizer hint that aborts it
 * after a few seconds (ignored as a comment by MariaDB), so a huge table can
 * slow the page down but never hang it.
 */
final class HealthRepository {

	private const HINT = '/*+ MAX_EXECUTION_TIME(5000) */';

	/**
	 * Constructor.
	 *
	 * @param wpdb             $wpdb       Site connection.
	 * @param SchemaRepository $schema     Schema reader.
	 * @param TableClassifier  $classifier Table classifier.
	 * @param string           $prefix     Table prefix.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly SchemaRepository $schema,
		private readonly TableClassifier $classifier,
		private readonly string $prefix,
	) {
	}

	/**
	 * Overview of one database.
	 *
	 * @param string $database Database (allow-listed).
	 * @return array<string, mixed>
	 */
	public function overview( string $database ): array {
		$tables     = $this->schema->tables( $database );
		$total      = 0;
		$engines    = array();
		$collations = array();
		$groups     = array();

		foreach ( $tables as $table ) {
			$total += $table['data_bytes'] + $table['index_bytes'];

			$engine             = $table['engine'] ?? 'unknown';
			$engines[ $engine ] = ( $engines[ $engine ] ?? 0 ) + 1;

			$collation                = $table['collation'] ?? 'unknown';
			$collations[ $collation ] = ( $collations[ $collation ] ?? 0 ) + 1;

			$group            = $this->classifier->classify( $table['name'] )->value;
			$groups[ $group ] = ( $groups[ $group ] ?? 0 ) + 1;
		}

		usort(
			$tables,
			static fn( array $a, array $b ): int => ( $b['data_bytes'] + $b['index_bytes'] ) <=> ( $a['data_bytes'] + $a['index_bytes'] )
		);

		return array(
			'database'   => $database,
			'size_bytes' => $total,
			'tables'     => count( $tables ),
			'engines'    => $engines,
			'collations' => $collations,
			'groups'     => $groups,
			'largest'    => array_slice( $tables, 0, 10 ),
			'checks'     => $this->checks( $database ),
		);
	}

	/**
	 * WordPress-specific checks; each is null when its tables do not exist.
	 *
	 * @param string $database Database.
	 * @return array<string, array{count: int, bytes?: int, sql: string}|null>
	 */
	private function checks( string $database ): array {
		$options  = $this->prefix . 'options';
		$posts    = $this->prefix . 'posts';
		$postmeta = $this->prefix . 'postmeta';

		$has_options = $this->schema->has_table( $database, $options );
		$has_posts   = $this->schema->has_table( $database, $posts );

		return array(
			'autoload'           => $has_options ? $this->autoload( $database, $options ) : null,
			'expired_transients' => $has_options ? $this->count(
				'SELECT ' . self::HINT . ' COUNT(*) FROM ' . Identifier::qualified( $database, $options )
					. " WHERE option_name LIKE '\\_transient\\_timeout\\_%' AND option_value < UNIX_TIMESTAMP()"
			) : null,
			'revisions'          => $has_posts ? $this->count(
				'SELECT ' . self::HINT . ' COUNT(*) FROM ' . Identifier::qualified( $database, $posts ) . " WHERE post_type = 'revision'"
			) : null,
			'trash'              => $has_posts ? $this->count(
				'SELECT ' . self::HINT . ' COUNT(*) FROM ' . Identifier::qualified( $database, $posts ) . " WHERE post_status = 'trash'"
			) : null,
			'orphan_postmeta'    => $has_posts && $this->schema->has_table( $database, $postmeta ) ? $this->count(
				'SELECT ' . self::HINT . ' COUNT(*) FROM ' . Identifier::qualified( $database, $postmeta ) . ' pm'
					. ' LEFT JOIN ' . Identifier::qualified( $database, $posts ) . ' p ON p.ID = pm.post_id WHERE p.ID IS NULL'
			) : null,
		);
	}

	/**
	 * Autoloaded options: count and total size.
	 *
	 * WordPress 6.6 replaced `yes` with `on` / `auto-on` / `auto`.
	 *
	 * @param string $database Database.
	 * @param string $options  Options table.
	 * @return array{count: int, bytes: int, sql: string}
	 */
	private function autoload( string $database, string $options ): array {
		$sql = 'SELECT ' . self::HINT . ' COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes FROM '
			. Identifier::qualified( $database, $options )
			. " WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')";

		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return array(
			'count' => is_array( $row ) ? (int) $row['n'] : 0,
			'bytes' => is_array( $row ) ? (int) $row['bytes'] : 0,
			'sql'   => $sql,
		);
	}

	/**
	 * Run a COUNT query.
	 *
	 * @param string $sql Query.
	 * @return array{count: int, sql: string}
	 */
	private function count( string $sql ): array {
		return array(
			'count' => (int) $this->wpdb->get_var( $sql ),
			'sql'   => $sql,
		);
	}
}
