<?php
/**
 * Splits a SQL script into individual statements.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Sql;

/**
 * Splits on `;` while respecting quoted strings, quoted identifiers and comments.
 *
 * This is a lexer, not a parser: it only needs to know where a statement ends.
 * It is pure (no WordPress, no database) so it can be unit and mutation tested.
 */
final class StatementSplitter {

	/**
	 * Split a script into trimmed, non-empty statements.
	 *
	 * Comments are kept inside the statement they belong to; statements made only
	 * of comments and whitespace are dropped.
	 *
	 * @param string $script One or more SQL statements.
	 * @return list<string>
	 */
	public function split( string $script ): array {
		$statements = array();
		$current    = '';
		$length     = strlen( $script );
		$i          = 0;

		while ( $i < $length ) {
			$char = $script[ $i ];
			$next = $script[ $i + 1 ] ?? '';

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$end      = $this->quoted_end( $script, $i, $char );
				$current .= substr( $script, $i, $end - $i );
				$i        = $end;
				continue;
			}

			if ( $this->starts_line_comment( $script, $i ) ) {
				$end      = $this->line_end( $script, $i );
				$current .= substr( $script, $i, $end - $i );
				$i        = $end;
				continue;
			}

			if ( '/' === $char && '*' === $next ) {
				$close    = strpos( $script, '*/', $i + 2 );
				$end      = false === $close ? $length : $close + 2;
				$current .= substr( $script, $i, $end - $i );
				$i        = $end;
				continue;
			}

			if ( ';' === $char ) {
				$this->push( $statements, $current );
				$current = '';
				++$i;
				continue;
			}

			$current .= $char;
			++$i;
		}//end while

		$this->push( $statements, $current );

		return $statements;
	}

	/**
	 * Index just past the closing quote (or the end of the script if unterminated).
	 *
	 * Outside identifiers a backslash escapes the next character. A doubled quote
	 * (`'it''s'`) needs no special case: it lexes as two adjacent strings, which
	 * splits the script at exactly the same places.
	 *
	 * @param string $script Whole script.
	 * @param int    $start  Index of the opening quote.
	 * @param string $quote  The quote character.
	 */
	private function quoted_end( string $script, int $start, string $quote ): int {
		$length = strlen( $script );
		$i      = $start + 1;

		while ( $i < $length ) {
			$char = $script[ $i ];

			if ( '\\' === $char && '`' !== $quote ) {
				$i += 2;
				continue;
			}

			if ( $char === $quote ) {
				return $i + 1;
			}

			++$i;
		}

		return $length;
	}

	/**
	 * Whether a `#` or `-- ` comment starts at the given index.
	 *
	 * MySQL requires whitespace (or end of input) after `--`, so `1--1` is arithmetic.
	 *
	 * @param string $script Whole script.
	 * @param int    $i      Index to inspect.
	 */
	private function starts_line_comment( string $script, int $i ): bool {
		if ( '#' === $script[ $i ] ) {
			return true;
		}

		if ( '-' !== $script[ $i ] || '-' !== ( $script[ $i + 1 ] ?? '' ) ) {
			return false;
		}

		$after = $script[ $i + 2 ] ?? '';

		return '' === $after || ctype_space( $after );
	}

	/**
	 * Index of the newline that ends a line comment, or the end of the script.
	 *
	 * @param string $script Whole script.
	 * @param int    $start  Index where the comment starts.
	 */
	private function line_end( string $script, int $start ): int {
		$newline = strpos( $script, "\n", $start );

		return false === $newline ? strlen( $script ) : $newline;
	}

	/**
	 * Append a statement unless it holds nothing but comments and whitespace.
	 *
	 * @param list<string> $statements Collected statements (by reference).
	 * @param string       $statement  Candidate statement.
	 */
	private function push( array &$statements, string $statement ): void {
		$statement = trim( $statement );

		if ( '' !== SqlText::strip_leading_comments( $statement ) ) {
			$statements[] = $statement;
		}
	}
}
