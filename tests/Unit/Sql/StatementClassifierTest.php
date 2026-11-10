<?php
/**
 * Tests for the statement classifier.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\Sql;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPDBase\Sql\SqlText;
use WPDBase\Sql\StatementClassifier;
use WPDBase\Sql\StatementKind;

#[CoversClass( StatementClassifier::class )]
#[CoversClass( StatementKind::class )]
#[CoversClass( SqlText::class )]
final class StatementClassifierTest extends TestCase {

	/**
	 * @return array<string, array{string, StatementKind}>
	 */
	public static function statements(): array {
		return array(
			// Reads.
			'select'                      => array( 'SELECT * FROM wp_posts', StatementKind::Read ),
			'lowercase select'            => array( 'select 1', StatementKind::Read ),
			'select after comment'        => array( "-- hi\nSELECT 1", StatementKind::Read ),
			'parenthesised union'         => array( '(SELECT 1) UNION (SELECT 2)', StatementKind::Read ),
			'show'                        => array( 'SHOW TABLES', StatementKind::Read ),
			'describe table'              => array( 'DESCRIBE wp_posts', StatementKind::Read ),
			'desc table'                  => array( 'DESC wp_posts', StatementKind::Read ),
			'explain select'              => array( 'EXPLAIN SELECT * FROM wp_posts', StatementKind::Read ),
			'explain format json'         => array( 'EXPLAIN FORMAT=JSON SELECT 1', StatementKind::Read ),
			'explain table'               => array( 'EXPLAIN wp_posts', StatementKind::Read ),
			'table statement'             => array( 'TABLE wp_posts', StatementKind::Read ),
			'cte select'                  => array( 'WITH x AS (SELECT 1) SELECT * FROM x', StatementKind::Read ),
			'keyword in string'           => array( "SELECT 'DELETE FROM x; FOR UPDATE'", StatementKind::Read ),
			'keyword in identifier'       => array( 'SELECT `update` FROM t', StatementKind::Read ),
			'keyword-like column name'    => array( 'SELECT update_time FROM t', StatementKind::Read ),
			'keyword in comment'          => array( 'SELECT 1 /* DROP TABLE x */', StatementKind::Read ),
			// Selects that are not pure reads.
			'select into outfile'         => array( "SELECT * FROM wp_users INTO OUTFILE '/tmp/x'", StatementKind::Other ),
			'select into dumpfile'        => array( "SELECT 1 INTO DUMPFILE '/tmp/x'", StatementKind::Other ),
			'select into variable'        => array( 'SELECT 1 INTO @a', StatementKind::Other ),
			'select for update'           => array( 'SELECT * FROM t FOR UPDATE', StatementKind::Other ),
			'select for share'            => array( 'SELECT * FROM t FOR SHARE', StatementKind::Other ),
			'lock in share mode'          => array( 'SELECT * FROM t LOCK IN SHARE MODE', StatementKind::Other ),
			'cte delete'                  => array( 'WITH x AS (SELECT 1) DELETE FROM t', StatementKind::Write ),
			'lowercase cte delete'        => array( 'with x as (select 1) delete from t', StatementKind::Write ),
			'explain analyze delete'      => array( 'EXPLAIN ANALYZE DELETE FROM t', StatementKind::Write ),
			'explain update'              => array( 'EXPLAIN UPDATE t SET a = 1', StatementKind::Write ),
			'describe insert'             => array( 'DESCRIBE INSERT INTO t VALUES (1)', StatementKind::Write ),
			'explain drop is ddl'         => array( 'EXPLAIN DROP TABLE t', StatementKind::Ddl ),
			'explain select into outfile' => array( "EXPLAIN SELECT 1 INTO OUTFILE '/x'", StatementKind::Other ),
			'version comment'             => array( '/*!50000 DROP TABLE t */', StatementKind::Other ),
			'version comment inside'      => array( 'SELECT 1 /*!50000 , SLEEP(1) */', StatementKind::Other ),
			// Writes.
			'insert'                      => array( 'INSERT INTO t VALUES (1)', StatementKind::Write ),
			'update'                      => array( 'UPDATE t SET a = 1', StatementKind::Write ),
			'delete'                      => array( 'DELETE FROM t', StatementKind::Write ),
			'replace'                     => array( 'REPLACE INTO t VALUES (1)', StatementKind::Write ),
			// DDL.
			'create'                      => array( 'CREATE TABLE t (id INT)', StatementKind::Ddl ),
			'alter'                       => array( 'ALTER TABLE t ADD c INT', StatementKind::Ddl ),
			'drop'                        => array( 'DROP TABLE t', StatementKind::Ddl ),
			'truncate'                    => array( 'TRUNCATE t', StatementKind::Ddl ),
			'rename'                      => array( 'RENAME TABLE a TO b', StatementKind::Ddl ),
			// Other.
			'set'                         => array( 'SET GLOBAL x = 1', StatementKind::Other ),
			'grant'                       => array( 'GRANT ALL ON *.* TO x', StatementKind::Other ),
			'load data'                   => array( "LOAD DATA INFILE '/x' INTO TABLE t", StatementKind::Other ),
			'analyze table'               => array( 'ANALYZE TABLE t', StatementKind::Other ),
			'call'                        => array( 'CALL proc()', StatementKind::Other ),
			'empty'                       => array( '', StatementKind::Other ),
		);
	}

	#[DataProvider( 'statements' )]
	public function test_classifies_statement( string $sql, StatementKind $expected ): void {
		self::assertSame( $expected, ( new StatementClassifier() )->classify( $sql ) );
	}

	public function test_all_read_is_true_only_when_every_statement_reads(): void {
		$classifier = new StatementClassifier();

		self::assertTrue( $classifier->all_read( array() ) );
		self::assertTrue( $classifier->all_read( array( 'SELECT 1', 'SHOW TABLES' ) ) );
		self::assertFalse( $classifier->all_read( array( 'SELECT 1', 'DELETE FROM t' ) ) );
		self::assertFalse( $classifier->all_read( array( 'DELETE FROM t', 'SELECT 1' ) ) );
	}

	public function test_only_read_kind_is_not_a_write(): void {
		self::assertFalse( StatementKind::Read->is_write() );
		self::assertTrue( StatementKind::Write->is_write() );
		self::assertTrue( StatementKind::Ddl->is_write() );
		self::assertTrue( StatementKind::Other->is_write() );
	}
}
