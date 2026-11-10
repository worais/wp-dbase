<?php
/**
 * The one place where dynamic SQL meets wpdb::prepare().
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Database;

use RuntimeException;
use wpdb;

/**
 * A database manager cannot use only literal SQL: table and column names come
 * from the schema. Callers build queries from Identifier-quoted names and
 * placeholders; values are always bound here.
 */
final class Query {

	/**
	 * Prepare a query whose identifiers were validated and quoted with Identifier.
	 *
	 * @param wpdb   $wpdb  Connection.
	 * @param string $query SQL with %s/%d placeholders.
	 * @param mixed  ...$args Values to bind.
	 * @throws RuntimeException When wpdb refuses the query (placeholder mismatch).
	 */
	public static function prepare( wpdb $wpdb, string $query, mixed ...$args ): string {
		/*
		 * Identifiers are dynamic by design (see Identifier), so the query is not a literal-string.
		 * @phpstan-ignore argument.type
		 */
		$prepared = $wpdb->prepare( $query, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the single, documented entry point for identifier-quoted dynamic SQL.

		if ( ! is_string( $prepared ) || '' === $prepared ) {
			throw new RuntimeException( 'Could not prepare the query.' );
		}

		return $prepared;
	}

	/**
	 * Normalise a result row to string|null values.
	 *
	 * @param array<mixed> $row Row from wpdb (ARRAY_A).
	 * @return array<string, string|null>
	 */
	public static function row( array $row ): array {
		$normalised = array();

		foreach ( $row as $column => $value ) {
			$normalised[ (string) $column ] = null === $value ? null : (string) $value;
		}

		return $normalised;
	}
}
