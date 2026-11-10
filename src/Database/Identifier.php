<?php
/**
 * Validation and quoting of SQL identifiers.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Database;

use InvalidArgumentException;

/**
 * Identifiers (database, table, column names) cannot be bound with prepare().
 *
 * Callers must first check the name against a list read from the server
 * (information_schema); this class adds a second, syntactic check and quotes.
 */
final class Identifier {

	/**
	 * Unquoted identifier characters accepted by MySQL/MariaDB, plus `-`, which
	 * is legal in quoted database names and common on shared hosts.
	 */
	private const PATTERN = '/^[A-Za-z0-9_$\-]{1,64}$/D';

	/**
	 * Whether a name is safe to quote and use.
	 *
	 * @param string $name Candidate identifier.
	 */
	public static function is_valid( string $name ): bool {
		return 1 === preg_match( self::PATTERN, $name );
	}

	/**
	 * Backtick-quote a validated identifier.
	 *
	 * @param string $name Identifier.
	 * @throws InvalidArgumentException When the name is not a valid identifier.
	 */
	public static function quote( string $name ): string {
		if ( ! self::is_valid( $name ) ) {
			throw new InvalidArgumentException( 'Invalid SQL identifier.' );
		}

		return '`' . $name . '`';
	}

	/**
	 * Quote `database`.`table`.
	 *
	 * @param string $database Database name.
	 * @param string $table    Table name.
	 * @throws InvalidArgumentException When either name is invalid.
	 */
	public static function qualified( string $database, string $table ): string {
		return self::quote( $database ) . '.' . self::quote( $table );
	}
}
