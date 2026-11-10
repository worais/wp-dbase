<?php
/**
 * Tests for the statement splitter.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\Sql;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPDBase\Sql\SqlText;
use WPDBase\Sql\StatementSplitter;

#[CoversClass( StatementSplitter::class )]
#[CoversClass( SqlText::class )]
final class StatementSplitterTest extends TestCase {

	/**
	 * @return array<string, array{string, list<string>}>
	 */
	public static function scripts(): array {
		return array(
			'empty script'                      => array( '', array() ),
			'only whitespace and semicolons'    => array( " ;\n ; ", array() ),
			'single statement without ;'        => array( 'SELECT 1', array( 'SELECT 1' ) ),
			'single statement with ;'           => array( "SELECT 1;\n", array( 'SELECT 1' ) ),
			'two statements'                    => array( 'SELECT 1; SELECT 2;', array( 'SELECT 1', 'SELECT 2' ) ),
			'semicolon in single quotes'        => array( "SELECT 'a;b'; SELECT 2", array( "SELECT 'a;b'", 'SELECT 2' ) ),
			'semicolon in double quotes'        => array( 'SELECT "a;b"', array( 'SELECT "a;b"' ) ),
			'semicolon in backticks'            => array( 'SELECT `a;b` FROM t', array( 'SELECT `a;b` FROM t' ) ),
			'doubled quote escape'              => array( "SELECT 'it''s;'; SELECT 2", array( "SELECT 'it''s;'", 'SELECT 2' ) ),
			'backslash quote escape'            => array( "SELECT 'it\\';'; SELECT 2", array( "SELECT 'it\\';'", 'SELECT 2' ) ),
			'backslash is literal in backtick'  => array( 'SELECT `a\\`; SELECT 2', array( 'SELECT `a\\`', 'SELECT 2' ) ),
			'doubled backtick escape'           => array( 'SELECT `a``;b`; SELECT 2', array( 'SELECT `a``;b`', 'SELECT 2' ) ),
			'semicolon in dash comment'         => array( "SELECT 1 -- a;b\n; SELECT 2", array( 'SELECT 1 -- a;b', 'SELECT 2' ) ),
			'semicolon in hash comment'         => array( "SELECT 1 # a;b\n; SELECT 2", array( 'SELECT 1 # a;b', 'SELECT 2' ) ),
			'semicolon in block comment'        => array( 'SELECT /* a;b */ 1; SELECT 2', array( 'SELECT /* a;b */ 1', 'SELECT 2' ) ),
			'double dash without space is math' => array( 'SELECT 1--1; SELECT 2', array( 'SELECT 1--1', 'SELECT 2' ) ),
			'dash comment at end of input'      => array( 'SELECT 1; --', array( 'SELECT 1' ) ),
			'comment-only statement dropped'    => array( "SELECT 1; -- bye\n", array( 'SELECT 1' ) ),
			'block-comment-only dropped'        => array( 'SELECT 1; /* bye */', array( 'SELECT 1' ) ),
			'unterminated block comment'        => array( 'SELECT 1 /* ; ', array( 'SELECT 1 /* ;' ) ),
			'unterminated string'               => array( "SELECT 'a; SELECT 2", array( "SELECT 'a; SELECT 2" ) ),
			'dash comment at end of file'       => array( 'SELECT 1 -- ; x', array( 'SELECT 1 -- ; x' ) ),
			'hash comment at end of file'       => array( 'SELECT 1 # ; x', array( 'SELECT 1 # ; x' ) ),
			'leading comment kept'              => array( "-- note\nSELECT 1", array( "-- note\nSELECT 1" ) ),
			'version comment kept'              => array( '/*!50000 SELECT 1 */', array( '/*!50000 SELECT 1 */' ) ),
			// Boundaries written to kill mutants.
			'division is not a comment'         => array( 'SELECT 4/2; SELECT 1', array( 'SELECT 4/2', 'SELECT 1' ) ),
			'multiplication is not a comment'   => array( 'SELECT 2*3; SELECT 1', array( 'SELECT 2*3', 'SELECT 1' ) ),
			'comment opening slash-star-slash'  => array( 'SELECT /*/ ; */ 1; SELECT 2', array( 'SELECT /*/ ; */ 1', 'SELECT 2' ) ),
			'empty block comment'               => array( 'SELECT 1 /**/; SELECT 2', array( 'SELECT 1 /**/', 'SELECT 2' ) ),
			'semicolon right after comment'     => array( 'SELECT 1 /* c */; SELECT 2', array( 'SELECT 1 /* c */', 'SELECT 2' ) ),
			'semicolon at end of comment'       => array( 'SELECT /* a; */ 1; SELECT 2', array( 'SELECT /* a; */ 1', 'SELECT 2' ) ),
			'star right after comment'          => array( 'SELECT /* a */* 2; SELECT 3', array( 'SELECT /* a */* 2', 'SELECT 3' ) ),
			'empty string'                      => array( "SELECT ''; SELECT 2", array( "SELECT ''", 'SELECT 2' ) ),
			'escaped quote right after open'    => array( "SELECT '\\''; SELECT 2", array( "SELECT '\\''", 'SELECT 2' ) ),
			'escape after another string'       => array( "SELECT \"x\", 'a\\'; b'; SELECT 2", array( "SELECT \"x\", 'a\\'; b'", 'SELECT 2' ) ),
			'single dash then space'            => array( 'SELECT 5 -1 ; SELECT 2', array( 'SELECT 5 -1', 'SELECT 2' ) ),
		);
	}

	/**
	 * @param list<string> $expected
	 */
	#[DataProvider( 'scripts' )]
	public function test_splits_script_into_statements( string $script, array $expected ): void {
		self::assertSame( $expected, ( new StatementSplitter() )->split( $script ) );
	}
}
