<?php
/**
 * What a statement does to the database.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Sql;

/**
 * Risk classes a statement can fall into, ordered from harmless to dangerous.
 */
enum StatementKind: string {

	/** SELECT, SHOW, DESCRIBE, EXPLAIN — never changes data. */
	case Read = 'read';

	/** INSERT, UPDATE, DELETE, REPLACE — changes rows. */
	case Write = 'write';

	/** CREATE, ALTER, DROP, TRUNCATE, RENAME — changes structure. */
	case Ddl = 'ddl';

	/** Everything else (SET, GRANT, LOCK, CALL, SELECT … INTO OUTFILE, …). */
	case Other = 'other';

	/**
	 * Whether running the statement can change anything.
	 */
	public function is_write(): bool {
		return self::Read !== $this;
	}
}
