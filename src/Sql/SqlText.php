<?php
/**
 * Small text helpers shared by the SQL lexer classes.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Sql;

/**
 * Stateless helpers for looking at the start of a statement.
 */
final class SqlText {

	/**
	 * Remove whitespace, comments and opening parentheses that precede the first keyword.
	 *
	 * `/*! ... *\/` version comments are executable in MySQL, so they are kept:
	 * hiding them would let `/*!50000 DROP TABLE x *\/` pass as harmless.
	 *
	 * @param string $sql A single statement.
	 */
	public static function strip_leading_comments( string $sql ): string {
		$sql = ltrim( $sql );

		while ( '' !== $sql ) {
			if ( str_starts_with( $sql, '/*!' ) ) {
				return $sql;
			}

			if ( str_starts_with( $sql, '/*' ) ) {
				$close = strpos( $sql, '*/', 2 );
				$sql   = false === $close ? '' : ltrim( substr( $sql, $close + 2 ) );
				continue;
			}

			if ( str_starts_with( $sql, '#' ) || preg_match( '/^--(\s|$)/', $sql ) ) {
				$newline = strpos( $sql, "\n" );
				$sql     = false === $newline ? '' : ltrim( substr( $sql, $newline ) );
				continue;
			}

			return $sql;
		}

		return '';
	}

	/**
	 * The first keyword of a statement, upper-cased (empty string when there is none).
	 *
	 * Leading parentheses are skipped so `(SELECT 1) UNION (SELECT 2)` reads as SELECT.
	 *
	 * @param string $sql A single statement.
	 */
	public static function first_keyword( string $sql ): string {
		$sql = ltrim( self::strip_leading_comments( $sql ), "( \t\n\r" );

		if ( 1 === preg_match( '/^[A-Za-z]+/', $sql, $match ) ) {
			return strtoupper( $match[0] );
		}

		return '';
	}

	/**
	 * Blank out the contents of strings, quoted identifiers and plain comments.
	 *
	 * The result has the same keywords in the same order, so a regular expression
	 * can look for `FOR UPDATE` without being fooled by `WHERE note = 'for update'`.
	 * Executable `/*! … *\/` comments are kept verbatim.
	 *
	 * @param string $sql A single statement.
	 */
	public static function mask_literals( string $sql ): string {
		$masked = preg_replace_callback(
			'/\/\*!.*?(?:\*\/|$)|\/\*.*?(?:\*\/|$)|(?:#|--(?=\s|$))[^\n]*|\'(?:[^\'\\\\]|\\\\.|\'\')*(?:\'|$)|"(?:[^"\\\\]|\\\\.|"")*(?:"|$)|`(?:[^`]|``)*(?:`|$)/s',
			static function ( array $found ): string {
				return str_starts_with( $found[0], '/*!' ) ? $found[0] : ' ';
			},
			$sql
		);

		return $masked ?? '';
	}
}
