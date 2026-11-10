<?php
/**
 * Tests for the SQL text helpers.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\Sql;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPDBase\Sql\SqlText;

#[CoversClass( SqlText::class )]
final class SqlTextTest extends TestCase {

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function leading_comments(): array {
		return array(
			'nothing to strip'          => array( 'SELECT 1', 'SELECT 1' ),
			'leading whitespace'        => array( "  \n\tSELECT 1", 'SELECT 1' ),
			'dash comment'              => array( "-- hi\nSELECT 1", 'SELECT 1' ),
			'hash comment'              => array( "# hi\nSELECT 1", 'SELECT 1' ),
			'block comment'             => array( '/* hi */ SELECT 1', 'SELECT 1' ),
			'stacked comments'          => array( "/* a */ -- b\n# c\n  SELECT 1", 'SELECT 1' ),
			'only a dash comment'       => array( '-- hi', '' ),
			'only a hash comment'       => array( '# hi', '' ),
			'unterminated block'        => array( '/* hi', '' ),
			'double dash without space' => array( '--1', '--1' ),
			'bare double dash'          => array( '--', '' ),
			'version comment is kept'   => array( '/*!50000 DROP TABLE t */', '/*!50000 DROP TABLE t */' ),
			'empty'                     => array( '', '' ),
			'slash-star-slash opening'  => array( '/*/ x */ SELECT 1', 'SELECT 1' ),
			'empty block comment'       => array( '/**/SELECT 1', 'SELECT 1' ),
			'no space after comment'    => array( '/* a */SELECT 1', 'SELECT 1' ),
		);
	}

	#[DataProvider( 'leading_comments' )]
	public function test_strips_leading_comments( string $sql, string $expected ): void {
		self::assertSame( $expected, SqlText::strip_leading_comments( $sql ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function first_keywords(): array {
		return array(
			'plain'               => array( 'select 1', 'SELECT' ),
			'after comment'       => array( "-- x\nupdate t set a=1", 'UPDATE' ),
			'after parentheses'   => array( '((SELECT 1)) UNION (SELECT 2)', 'SELECT' ),
			'keyword ends at sym' => array( 'SHOW;', 'SHOW' ),
			'no keyword'          => array( '123', '' ),
			'keyword not first'   => array( '1 select', '' ),
			'empty'               => array( '', '' ),
		);
	}

	#[DataProvider( 'first_keywords' )]
	public function test_reads_first_keyword( string $sql, string $expected ): void {
		self::assertSame( $expected, SqlText::first_keyword( $sql ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function masks(): array {
		return array(
			'single quoted'        => array( "SELECT 'for update'", 'SELECT  ' ),
			'doubled quote'        => array( "SELECT 'a''b' x", 'SELECT   x' ),
			'escaped quote'        => array( "SELECT 'a\\'b' x", 'SELECT   x' ),
			'double quoted'        => array( 'SELECT "x" y', 'SELECT   y' ),
			'backtick'             => array( 'SELECT `delete` FROM t', 'SELECT   FROM t' ),
			'block comment'        => array( 'SELECT /* drop */ 1', 'SELECT   1' ),
			'dash comment'         => array( "SELECT 1 -- drop\n", "SELECT 1  \n" ),
			'hash comment'         => array( 'SELECT 1 # drop', 'SELECT 1  ' ),
			'version comment kept' => array( 'SELECT /*!1 x */ 1', 'SELECT /*!1 x */ 1' ),
			'math is not comment'  => array( 'SELECT 1--1', 'SELECT 1--1' ),
		);
	}

	#[DataProvider( 'masks' )]
	public function test_masks_literals_and_comments( string $sql, string $expected ): void {
		self::assertSame( $expected, SqlText::mask_literals( $sql ) );
	}
}
