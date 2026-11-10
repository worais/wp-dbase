<?php
/**
 * Reads and edits rows of any allowed table.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Database;

use InvalidArgumentException;
use wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers are allow-listed against information_schema and quoted by Identifier; every value is bound with prepare().

/**
 * Row access by fully qualified name (`db`.`table`) through the site connection.
 */
final class RowRepository {

	/**
	 * Characters kept per cell in grid pages; the full value comes with the object view.
	 */
	public const CELL_LIMIT = 300;

	/**
	 * Constructor.
	 *
	 * @param wpdb             $wpdb   Site connection.
	 * @param SchemaRepository $schema Schema reader (identifier allow-list).
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly SchemaRepository $schema,
	) {
	}

	/**
	 * One page of rows.
	 *
	 * @param string      $database Database name (allow-listed).
	 * @param string      $table    Table name.
	 * @param int         $page     1-based page.
	 * @param int         $per_page Rows per page (1–500).
	 * @param string|null $order_by Column to sort by (must exist), or null for the primary key.
	 * @param bool        $desc     Descending order.
	 * @return array{columns: list<string>, rows: list<array<string, string|null>>, truncated: list<array{int, string}>, page: int, per_page: int, estimate: int, primary_key: list<string>, sql: string}
	 * @throws InvalidArgumentException When the table or column does not exist.
	 */
	public function page( string $database, string $table, int $page, int $per_page, ?string $order_by, bool $desc ): array {
		$this->assert_table( $database, $table );

		$columns  = array_column( $this->schema->columns( $database, $table ), 'name' );
		$primary  = $this->schema->primary_key( $database, $table );
		$per_page = max( 1, min( 500, $per_page ) );
		$page     = max( 1, $page );

		$order_columns = null === $order_by ? $primary : array( $order_by );
		foreach ( $order_columns as $column ) {
			if ( ! in_array( $column, $columns, true ) ) {
				throw new InvalidArgumentException( 'Unknown column.' );
			}
		}

		$direction = $desc ? 'DESC' : 'ASC';
		$order_sql = '' === implode( '', $order_columns ) ? '' : ' ORDER BY ' . implode(
			', ',
			array_map( static fn( string $column ): string => Identifier::quote( $column ) . ' ' . $direction, $order_columns )
		);

		$sql = Query::prepare(
			$this->wpdb,
			'SELECT * FROM ' . Identifier::qualified( $database, $table ) . $order_sql . ' LIMIT %d OFFSET %d',
			$per_page,
			( $page - 1 ) * $per_page
		);

		$raw = $this->wpdb->get_results( $sql, ARRAY_A );
		$raw = is_array( $raw ) ? $raw : array();

		$rows      = array();
		$truncated = array();
		foreach ( array_values( $raw ) as $index => $row ) {
			$row = Query::row( (array) $row );
			foreach ( $row as $column => $value ) {
				if ( null !== $value && mb_strlen( (string) $value ) > self::CELL_LIMIT ) {
					$row[ $column ] = mb_substr( (string) $value, 0, self::CELL_LIMIT );
					$truncated[]    = array( $index, $column );
				}
			}
			$rows[] = $row;
		}

		$estimate = 0;
		foreach ( $this->schema->tables( $database ) as $info ) {
			if ( $info['name'] === $table ) {
				$estimate = $info['rows'];
			}
		}

		return array(
			'columns'     => $columns,
			'rows'        => $rows,
			'truncated'   => $truncated,
			'page'        => $page,
			'per_page'    => $per_page,
			'estimate'    => max( $estimate, ( $page - 1 ) * $per_page + count( $rows ) ),
			'primary_key' => $primary,
			'sql'         => $sql,
		);
	}

	/**
	 * One full row by single-column primary key, or null.
	 *
	 * @param string $database Database name.
	 * @param string $table    Table name.
	 * @param string $id       Primary key value.
	 * @return array{row: array<string, string|null>|null, sql: string}
	 * @throws InvalidArgumentException When the table has no single-column primary key.
	 */
	public function find( string $database, string $table, string $id ): array {
		$primary = $this->single_primary_key( $database, $table );

		$sql = Query::prepare(
			$this->wpdb,
			'SELECT * FROM ' . Identifier::qualified( $database, $table ) . ' WHERE ' . Identifier::quote( $primary ) . ' = %s LIMIT 1',
			$id
		);

		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return array(
			'row' => is_array( $row ) ? Query::row( $row ) : null,
			'sql' => $sql,
		);
	}

	/**
	 * Build the UPDATE for one row (by primary key) without running it.
	 *
	 * @param string                     $database Database name.
	 * @param string                     $table    Table name.
	 * @param string                     $id       Primary key value.
	 * @param array<string, string|null> $changes  Column => new value (null = SQL NULL).
	 * @throws InvalidArgumentException On unknown columns, empty changes or PK changes.
	 */
	public function update_sql( string $database, string $table, string $id, array $changes ): string {
		$primary = $this->single_primary_key( $database, $table );
		$columns = array_column( $this->schema->columns( $database, $table ), 'name' );

		if ( array() === $changes ) {
			throw new InvalidArgumentException( 'Nothing to change.' );
		}

		$sets   = array();
		$values = array();
		foreach ( $changes as $column => $value ) {
			$column = (string) $column;

			if ( ! in_array( $column, $columns, true ) ) {
				throw new InvalidArgumentException( 'Unknown column.' );
			}

			if ( $column === $primary ) {
				throw new InvalidArgumentException( 'The primary key cannot be edited inline.' );
			}

			if ( null === $value ) {
				$sets[] = Identifier::quote( $column ) . ' = NULL';
				continue;
			}

			$sets[]   = Identifier::quote( $column ) . ' = %s';
			$values[] = $value;
		}

		$values[] = $id;

		return Query::prepare(
			$this->wpdb,
			'UPDATE ' . Identifier::qualified( $database, $table ) . ' SET ' . implode( ', ', $sets )
				. ' WHERE ' . Identifier::quote( $primary ) . ' = %s LIMIT 1',
			$values
		);
	}

	/**
	 * Build the DELETE for one row (by primary key) without running it.
	 *
	 * @param string $database Database name.
	 * @param string $table    Table name.
	 * @param string $id       Primary key value.
	 */
	public function delete_sql( string $database, string $table, string $id ): string {
		$primary = $this->single_primary_key( $database, $table );

		return Query::prepare(
			$this->wpdb,
			'DELETE FROM ' . Identifier::qualified( $database, $table ) . ' WHERE ' . Identifier::quote( $primary ) . ' = %s LIMIT 1',
			$id
		);
	}

	/**
	 * Run a statement built by update_sql()/delete_sql(); returns affected rows or null on error.
	 *
	 * @param string $sql Prepared statement.
	 */
	public function execute( string $sql ): ?int {
		$result = $this->wpdb->query( $sql );

		return false === $result ? null : (int) $result;
	}

	/**
	 * Last database error of the site connection.
	 */
	public function last_error(): string {
		return (string) $this->wpdb->last_error;
	}

	/**
	 * The single primary-key column, or an exception for composite/missing keys.
	 *
	 * @param string $database Database name.
	 * @param string $table    Table name.
	 * @throws InvalidArgumentException When the key is missing or composite.
	 */
	public function single_primary_key( string $database, string $table ): string {
		$this->assert_table( $database, $table );
		$primary = $this->schema->primary_key( $database, $table );

		if ( 1 !== count( $primary ) ) {
			throw new InvalidArgumentException( 'This table has no single-column primary key.' );
		}

		return $primary[0];
	}

	/**
	 * Throw unless the table exists in the database.
	 *
	 * @param string $database Database name.
	 * @param string $table    Table name.
	 * @throws InvalidArgumentException When the table is unknown.
	 */
	private function assert_table( string $database, string $table ): void {
		if ( ! $this->schema->has_table( $database, $table ) ) {
			throw new InvalidArgumentException( 'Unknown table.' );
		}
	}
}
