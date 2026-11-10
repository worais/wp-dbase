<?php
/**
 * Runs console scripts under the rules of the current safety mode.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Sql;

use mysqli;
use mysqli_result;
use Throwable;
use WPDBase\Audit\AuditEntry;
use WPDBase\Audit\AuditLog;
use WPDBase\Security\SafetyMode;
use wpdb;

// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions -- the console needs unbuffered results and per-statement errors, which wpdb does not expose.

/**
 * Executes a script statement by statement.
 *
 * Guarantees, in order:
 * 1. Every statement is classified before anything runs; one forbidden
 *    statement rejects the whole script.
 * 2. Scripts with writes need `$confirmation` to equal the database name.
 * 3. Read-only scripts run inside `START TRANSACTION READ ONLY`, so even a
 *    misclassified statement cannot write.
 * 4. A per-statement timeout and a row cap protect the server.
 * 5. Every statement that can write is recorded in the audit log.
 */
final class QueryRunner {

	/**
	 * Constructor.
	 *
	 * @param StatementSplitter   $splitter   Script splitter.
	 * @param StatementClassifier $classifier Statement classifier.
	 * @param AuditLog            $audit      Audit log.
	 * @param int                 $timeout    Seconds per statement.
	 * @param int                 $max_rows   Rows returned per statement.
	 * @param bool                $mariadb    Whether the server is MariaDB.
	 */
	public function __construct(
		private readonly StatementSplitter $splitter,
		private readonly StatementClassifier $classifier,
		private readonly AuditLog $audit,
		private readonly int $timeout,
		private readonly int $max_rows,
		private readonly bool $mariadb,
	) {
	}

	/**
	 * Check a script without running it.
	 *
	 * @param string     $script SQL script.
	 * @param SafetyMode $mode   Current safety mode.
	 * @return array{statements: list<array{sql: string, kind: string, allowed: bool}>, writes: bool, allowed: bool}
	 */
	public function plan( string $script, SafetyMode $mode ): array {
		$statements = array();
		$writes     = false;
		$allowed    = true;

		foreach ( $this->splitter->split( $script ) as $sql ) {
			$kind         = $this->classifier->classify( $sql );
			$ok           = $mode->allows_statement( $kind );
			$writes       = $writes || $kind->is_write();
			$allowed      = $allowed && $ok;
			$statements[] = array(
				'sql'     => $sql,
				'kind'    => $kind->value,
				'allowed' => $ok,
			);
		}

		return array(
			'statements' => $statements,
			'writes'     => $writes,
			'allowed'    => $allowed && array() !== $statements,
		);
	}

	/**
	 * Run a script.
	 *
	 * @param wpdb       $connection   Connection already pointing at the database.
	 * @param string     $database     Database name (for confirmation and audit).
	 * @param string     $script       SQL script.
	 * @param SafetyMode $mode         Current safety mode.
	 * @param string     $confirmation What the user typed to confirm writes.
	 * @return array{ok: bool, error: string|null, plan: array<string, mixed>, results: list<array<string, mixed>>}
	 */
	public function run( wpdb $connection, string $database, string $script, SafetyMode $mode, string $confirmation ): array {
		$plan = $this->plan( $script, $mode );

		if ( ! $plan['allowed'] ) {
			return $this->refusal( $plan, array() === $plan['statements'] ? 'empty' : 'mode' );
		}

		if ( $plan['writes'] && $confirmation !== $database ) {
			return $this->refusal( $plan, 'confirmation' );
		}

		$mysqli = $this->handle( $connection );
		if ( null === $mysqli ) {
			return $this->refusal( $plan, 'connection' );
		}

		$this->set_timeout( $mysqli, $this->timeout );

		if ( ! $plan['writes'] ) {
			$mysqli->query( 'START TRANSACTION READ ONLY' );
		}

		$results = array();
		$ok      = true;

		try {
			foreach ( $plan['statements'] as $statement ) {
				$result    = $this->execute( $mysqli, $statement['sql'] );
				$results[] = $result + array( 'kind' => $statement['kind'] );

				if ( 'read' !== $statement['kind'] ) {
					$this->audit->record(
						new AuditEntry(
							$database,
							$mode->value,
							$statement['kind'],
							$statement['sql'],
							$result['affected'],
							$result['ms'],
							$result['error']
						)
					);
				}

				if ( null !== $result['error'] ) {
					$ok = false;
					break;
				}
			}//end foreach
		} finally {
			if ( ! $plan['writes'] ) {
				$mysqli->query( 'ROLLBACK' );
			}
			$this->set_timeout( $mysqli, 0 );
		}//end try

		return array(
			'ok'      => $ok,
			'error'   => $ok ? null : 'statement',
			'plan'    => $plan,
			'results' => $results,
		);
	}

	/**
	 * Execute one statement and collect up to max_rows rows.
	 *
	 * @param mysqli $mysqli Connection.
	 * @param string $sql    Statement.
	 * @return array{sql: string, columns: list<string>, rows: list<list<string|null>>, more: bool, affected: int|null, ms: int, error: string|null, errno: int}
	 */
	private function execute( mysqli $mysqli, string $sql ): array {
		$start   = hrtime( true );
		$columns = array();
		$rows    = array();
		$more    = false;
		$error   = null;
		$errno   = 0;

		try {
			$ok = $mysqli->real_query( $sql );
		} catch ( Throwable $e ) {
			// mysqli may run in exception mode (PHP 8.1+ default).
			$ok = false;
		}

		$affected = null;

		if ( $ok ) {
			$result = $mysqli->use_result();

			if ( $result instanceof mysqli_result ) {
				foreach ( $result->fetch_fields() as $field ) {
					$columns[] = (string) $field->name;
				}

				while ( null !== ( $row = $result->fetch_row() ) && false !== $row ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- standard fetch loop.
					if ( count( $rows ) >= $this->max_rows ) {
						$more = true;
						break;
					}
					$rows[] = array_values( array_map( static fn( $value ): ?string => null === $value ? null : (string) $value, $row ) );
				}

				$result->free();
				$affected = count( $rows );
			} else {
				$affected = (int) $mysqli->affected_rows;
			}
		} else {
			$errno = (int) $mysqli->errno;
			$error = '' !== $mysqli->error ? $mysqli->error : 'Unknown error.';
		}//end if

		return array(
			'sql'      => $sql,
			'columns'  => $columns,
			'rows'     => $rows,
			'more'     => $more,
			'affected' => $affected,
			'ms'       => (int) round( ( hrtime( true ) - $start ) / 1e6 ),
			'error'    => $error,
			'errno'    => $errno,
		);
	}

	/**
	 * Set (or, with 0, clear) the per-statement timeout for the session.
	 *
	 * @param mysqli $mysqli  Connection.
	 * @param int    $seconds Seconds, 0 for none.
	 */
	private function set_timeout( mysqli $mysqli, int $seconds ): void {
		try {
			if ( $this->mariadb ) {
				$mysqli->query( 'SET SESSION max_statement_time = ' . (int) $seconds );
			} else {
				$mysqli->query( 'SET SESSION MAX_EXECUTION_TIME = ' . ( (int) $seconds * 1000 ) );
			}
		} catch ( Throwable $e ) {
			// Older servers without the variable: the query still runs, just without a timeout.
			unset( $e );
		}
	}

	/**
	 * The mysqli handle behind a wpdb connection.
	 *
	 * @param wpdb $connection Connection.
	 */
	private function handle( wpdb $connection ): ?mysqli {
		$handle = $connection->__get( 'dbh' );

		return $handle instanceof mysqli ? $handle : null;
	}

	/**
	 * Result for a script that was not run.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param string               $reason Machine-readable reason.
	 * @return array{ok: bool, error: string, plan: array<string, mixed>, results: list<array<string, mixed>>}
	 */
	private function refusal( array $plan, string $reason ): array {
		return array(
			'ok'      => false,
			'error'   => $reason,
			'plan'    => $plan,
			'results' => array(),
		);
	}
}
