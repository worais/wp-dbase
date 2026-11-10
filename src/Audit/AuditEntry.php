<?php
/**
 * One audited statement.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Audit;

/**
 * Value object handed to AuditLog::record().
 */
final class AuditEntry {

	/**
	 * Constructor.
	 *
	 * @param string      $database      Database the statement ran against.
	 * @param string      $mode          Safety mode at the time.
	 * @param string      $kind          Statement kind (write, ddl, other, row-edit).
	 * @param string      $statement     The SQL.
	 * @param int|null    $affected_rows Rows affected, null on error.
	 * @param int         $duration_ms   Execution time.
	 * @param string|null $error         Error message, if any.
	 */
	public function __construct(
		public readonly string $database,
		public readonly string $mode,
		public readonly string $kind,
		public readonly string $statement,
		public readonly ?int $affected_rows,
		public readonly int $duration_ms,
		public readonly ?string $error = null,
	) {
	}
}
