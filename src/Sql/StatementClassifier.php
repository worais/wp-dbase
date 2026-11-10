<?php
/**
 * Decides what a single SQL statement can do.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Sql;

/**
 * Conservative classifier: when in doubt, a statement is never `Read`.
 *
 * The classification gates what a safety mode allows. Read-only execution is
 * additionally wrapped in a `READ ONLY` transaction by the runner, so this class
 * is the first line of defence, not the only one.
 */
final class StatementClassifier {

	private const READ_KEYWORDS = array( 'SELECT', 'SHOW', 'TABLE', 'VALUES', 'WITH', 'HELP' );

	private const EXPLAIN_KEYWORDS = array( 'EXPLAIN', 'DESCRIBE', 'DESC' );

	private const WRITE_KEYWORDS = array( 'INSERT', 'UPDATE', 'DELETE', 'REPLACE' );

	private const DDL_KEYWORDS = array( 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME' );

	/**
	 * Patterns that turn an otherwise read-only statement into something else
	 * (file output, variable assignment or row locks).
	 */
	private const NOT_PURE_READ = '/\bINTO\s+(?:OUTFILE|DUMPFILE|@)|\bFOR\s+(?:UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i';

	/**
	 * Classify one statement (use StatementSplitter first for scripts).
	 *
	 * @param string $sql A single statement.
	 */
	public function classify( string $sql ): StatementKind {
		$masked = SqlText::mask_literals( $sql );

		// Executable version comments can hide anything.
		if ( str_contains( $masked, '/*!' ) ) {
			return StatementKind::Other;
		}

		$keyword = SqlText::first_keyword( $masked );

		if ( in_array( $keyword, self::EXPLAIN_KEYWORDS, true ) ) {
			return $this->classify_explain( $masked );
		}

		if ( in_array( $keyword, self::READ_KEYWORDS, true ) ) {
			return $this->classify_read_candidate( $masked );
		}

		if ( in_array( $keyword, self::WRITE_KEYWORDS, true ) ) {
			return StatementKind::Write;
		}

		if ( in_array( $keyword, self::DDL_KEYWORDS, true ) ) {
			return StatementKind::Ddl;
		}

		return StatementKind::Other;
	}

	/**
	 * Whether every statement of a list is read-only.
	 *
	 * @param list<string> $statements Statements from StatementSplitter.
	 */
	public function all_read( array $statements ): bool {
		foreach ( $statements as $statement ) {
			if ( StatementKind::Read !== $this->classify( $statement ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * SELECT-like statements are only reads if they don't write files, assign
	 * variables, lock rows or (for CTEs) end in a data-changing statement.
	 *
	 * @param string $masked Statement with literals masked.
	 */
	private function classify_read_candidate( string $masked ): StatementKind {
		if ( 1 === preg_match( self::NOT_PURE_READ, $masked ) ) {
			return StatementKind::Other;
		}

		if ( 1 === preg_match( '/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $masked ) ) {
			return StatementKind::Write;
		}

		return StatementKind::Read;
	}

	/**
	 * `EXPLAIN ANALYZE` (MySQL 8) and `ANALYZE` (MariaDB) really execute the
	 * statement, so EXPLAIN inherits the risk of what it explains.
	 *
	 * @param string $masked Statement with literals masked.
	 */
	private function classify_explain( string $masked ): StatementKind {
		$rest = preg_replace(
			'/^\W*(?:EXPLAIN|DESCRIBE|DESC)\s+(?:(?:ANALYZE|EXTENDED|PARTITIONS|FORMAT\s*=\s*\w+)\s+)*/i',
			'',
			SqlText::strip_leading_comments( $masked )
		) ?? '';

		$keyword = SqlText::first_keyword( $rest );

		if ( in_array( $keyword, self::WRITE_KEYWORDS, true ) ) {
			return StatementKind::Write;
		}

		if ( in_array( $keyword, self::DDL_KEYWORDS, true ) ) {
			return StatementKind::Ddl;
		}

		return $this->classify_read_candidate( $rest );
	}
}
