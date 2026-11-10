<?php
/**
 * Reads structure from information_schema — never COUNT(*).
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Database;

use wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- this class is a database inspector; there is no WP API for information_schema. Values are bound through Query::prepare(), which WPCS cannot follow.

/**
 * Tables, columns, indexes and DDL of any allowed database.
 *
 * All queries go through the site connection with the database name bound as a
 * value, so they work for every database on the same server.
 */
final class SchemaRepository {

	/**
	 * Per-request cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = array();

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb Site connection.
	 */
	public function __construct( private readonly wpdb $wpdb ) {
	}

	/**
	 * Tables of a database with size and row estimates.
	 *
	 * @param string $database Database name (already allow-listed).
	 * @return list<array{name: string, engine: string|null, rows: int, data_bytes: int, index_bytes: int, collation: string|null, updated: string|null, auto_increment: int|null}>
	 */
	public function tables( string $database ): array {
		$key = 'tables:' . $database;

		if ( ! isset( $this->cache[ $key ] ) ) {
			$rows = $this->wpdb->get_results(
				Query::prepare(
					$this->wpdb,
					'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, TABLE_COLLATION, UPDATE_TIME, AUTO_INCREMENT
					FROM information_schema.TABLES
					WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = %s
					ORDER BY TABLE_NAME',
					$database,
					'BASE TABLE'
				),
				ARRAY_A
			);

			$this->cache[ $key ] = array_map(
				static fn( array $row ): array => array(
					'name'           => (string) $row['TABLE_NAME'],
					'engine'         => null === $row['ENGINE'] ? null : (string) $row['ENGINE'],
					'rows'           => (int) $row['TABLE_ROWS'],
					'data_bytes'     => (int) $row['DATA_LENGTH'],
					'index_bytes'    => (int) $row['INDEX_LENGTH'],
					'collation'      => null === $row['TABLE_COLLATION'] ? null : (string) $row['TABLE_COLLATION'],
					'updated'        => null === $row['UPDATE_TIME'] ? null : (string) $row['UPDATE_TIME'],
					'auto_increment' => null === $row['AUTO_INCREMENT'] ? null : (int) $row['AUTO_INCREMENT'],
				),
				is_array( $rows ) ? $rows : array()
			);
		}//end if

		return $this->cache[ $key ];
	}

	/**
	 * Names of the tables of a database.
	 *
	 * @param string $database Database name.
	 * @return list<string>
	 */
	public function table_names( string $database ): array {
		return array_column( $this->tables( $database ), 'name' );
	}

	/**
	 * Whether a table exists in a database (the identifier allow-list check).
	 *
	 * @param string $database Database name.
	 * @param string $table    Table name.
	 */
	public function has_table( string $database, string $table ): bool {
		return in_array( $table, $this->table_names( $database ), true );
	}

	/**
	 * Columns of a table.
	 *
	 * @param string $database Database name.
	 * @param string $table    Table name.
	 * @return list<array{name: string, type: string, nullable: bool, default: string|null, key: string, extra: string}>
	 */
	public function columns( string $database, string $table ): array {
		$rows = $this->wpdb->get_results(
			Query::prepare(
				$this->wpdb,
				'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
				ORDER BY ORDINAL_POSITION',
				$database,
				$table
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'name'     => (string) $row['COLUMN_NAME'],
				'type'     => (string) $row['COLUMN_TYPE'],
				'nullable' => 'YES' === $row['IS_NULLABLE'],
				'default'  => null === $row['COLUMN_DEFAULT'] ? null : (string) $row['COLUMN_DEFAULT'],
				'key'      => (string) $row['COLUMN_KEY'],
				'extra'    => (string) $row['EXTRA'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Primary-key columns of a table, in key order.
	 *
	 * @param string $database Database name.
	 * @param string $table    Table name.
	 * @return list<string>
	 */
	public function primary_key( string $database, string $table ): array {
		foreach ( $this->indexes( $database, $table ) as $index ) {
			if ( 'PRIMARY' === $index['name'] ) {
				return $index['columns'];
			}
		}

		return array();
	}

	/**
	 * Every table => column names of a database, in one query (for meta detection).
	 *
	 * @param string $database Database name.
	 * @return array<string, list<string>>
	 */
	public function column_map( string $database ): array {
		$key = 'map:' . $database;

		if ( ! isset( $this->cache[ $key ] ) ) {
			$rows = $this->wpdb->get_results(
				Query::prepare(
					$this->wpdb,
					'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
					WHERE TABLE_SCHEMA = %s ORDER BY TABLE_NAME, ORDINAL_POSITION',
					$database
				),
				ARRAY_A
			);

			$map = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$map[ (string) $row['TABLE_NAME'] ][] = (string) $row['COLUMN_NAME'];
			}

			$this->cache[ $key ] = $map;
		}

		return $this->cache[ $key ];
	}

	/**
	 * Indexes of a table, columns grouped in key order.
	 *
	 * @param string $database Database name.
	 * @param string $table    Table name.
	 * @return list<array{name: string, columns: list<string>, unique: bool, cardinality: int|null}>
	 */
	public function indexes( string $database, string $table ): array {
		$rows = $this->wpdb->get_results(
			Query::prepare(
				$this->wpdb,
				'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, CARDINALITY
				FROM information_schema.STATISTICS
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
				ORDER BY INDEX_NAME = %s DESC, INDEX_NAME, SEQ_IN_INDEX',
				$database,
				$table,
				'PRIMARY'
			),
			ARRAY_A
		);

		$indexes = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = (string) $row['INDEX_NAME'];

			if ( ! isset( $indexes[ $name ] ) ) {
				$indexes[ $name ] = array(
					'name'        => $name,
					'columns'     => array(),
					'unique'      => '0' === (string) $row['NON_UNIQUE'],
					'cardinality' => null === $row['CARDINALITY'] ? null : (int) $row['CARDINALITY'],
				);
			}

			$indexes[ $name ]['columns'][] = (string) $row['COLUMN_NAME'];
		}

		return array_values( $indexes );
	}

	/**
	 * `SHOW CREATE TABLE` output.
	 *
	 * @param string $database Database name.
	 * @param string $table    Table name (must exist — see has_table()).
	 */
	public function create_statement( string $database, string $table ): string {
		// Identifiers are allow-listed by the caller and quoted here.
		$row = $this->wpdb->get_row( 'SHOW CREATE TABLE ' . Identifier::qualified( $database, $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $row ) ? (string) ( $row[1] ?? '' ) : '';
	}

	/**
	 * Server name and version, e.g. "MySQL 8.0.36" or "MariaDB 10.6.16".
	 */
	public function server_label(): string {
		$version = (string) $this->wpdb->get_var( 'SELECT VERSION()' );
		$product = str_contains( strtolower( $version ), 'mariadb' ) ? 'MariaDB' : 'MySQL';

		return $product . ' ' . (string) preg_replace( '/-.*$/', '', $version );
	}

	/**
	 * Whether the server is MariaDB (statement timeouts differ).
	 */
	public function is_mariadb(): bool {
		return str_starts_with( $this->server_label(), 'MariaDB' );
	}
}
