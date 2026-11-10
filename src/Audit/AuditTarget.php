<?php
/**
 * Where the audit trail is stored.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Audit;

use WPDBase\Database\Identifier;

/**
 * Resolves the value of the `wpdbase_audit_table` filter into a safe table reference.
 *
 * `wp_wpdbase_audit` (the default) lives in the site database and is created by
 * the plugin. `other_db.some_table` (or any other name) is "external": whoever
 * set the filter creates it, using AuditLog::create_table_sql().
 */
final class AuditTarget {

	/**
	 * Constructor.
	 *
	 * @param string|null $database Database name, or null for the site database.
	 * @param string      $table    Table name.
	 * @param bool        $external Whether the filter moved the table away from the default.
	 */
	public function __construct(
		public readonly ?string $database,
		public readonly string $table,
		public readonly bool $external,
	) {
	}

	/**
	 * Resolve a filtered value; anything invalid falls back to the default table.
	 *
	 * @param mixed  $filtered Value returned by the filter: `table` or `database.table`.
	 * @param string $fallback Default table name.
	 */
	public static function resolve( mixed $filtered, string $fallback ): self {
		$default = new self( null, $fallback, false );

		if ( ! is_string( $filtered ) || $filtered === $fallback ) {
			return $default;
		}

		$parts = explode( '.', $filtered );

		if ( count( $parts ) > 2 ) {
			return $default;
		}

		foreach ( $parts as $part ) {
			if ( ! Identifier::is_valid( $part ) ) {
				return $default;
			}
		}

		return 2 === count( $parts )
			? new self( $parts[0], $parts[1], true )
			: new self( null, $parts[0], true );
	}

	/**
	 * Quoted reference for SQL: `table` or `database`.`table`.
	 */
	public function sql(): string {
		return null === $this->database ? Identifier::quote( $this->table ) : Identifier::qualified( $this->database, $this->table );
	}
}
