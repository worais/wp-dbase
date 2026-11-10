<?php
/**
 * Tests for identifier validation and quoting.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\Database;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPDBase\Database\Identifier;

#[CoversClass( Identifier::class )]
final class IdentifierTest extends TestCase {

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function names(): array {
		return array(
			'plain'             => array( 'wp_posts', true ),
			'digits and dollar' => array( 'a1$b', true ),
			'dash (hosted dbs)' => array( 'site-db', true ),
			'64 chars'          => array( str_repeat( 'a', 64 ), true ),
			'65 chars'          => array( str_repeat( 'a', 65 ), false ),
			'empty'             => array( '', false ),
			'backtick'          => array( 'a`b', false ),
			'dot'               => array( 'db.table', false ),
			'space'             => array( 'a b', false ),
			'quote'             => array( "a'b", false ),
			'semicolon'         => array( 'a;b', false ),
			'newline at end'    => array( "abc\n", false ),
			'unicode'           => array( 'tabela_ç', false ),
		);
	}

	#[DataProvider( 'names' )]
	public function test_validates_identifier( string $name, bool $valid ): void {
		self::assertSame( $valid, Identifier::is_valid( $name ) );
	}

	public function test_quotes_valid_identifier(): void {
		self::assertSame( '`wp_posts`', Identifier::quote( 'wp_posts' ) );
	}

	public function test_qualifies_database_and_table(): void {
		self::assertSame( '`db`.`wp_posts`', Identifier::qualified( 'db', 'wp_posts' ) );
	}

	public function test_refuses_to_quote_invalid_identifier(): void {
		$this->expectException( InvalidArgumentException::class );
		Identifier::quote( 'x` OR 1=1 -- ' );
	}

	public function test_refuses_to_qualify_invalid_table(): void {
		$this->expectException( InvalidArgumentException::class );
		Identifier::qualified( 'db', 'a.b' );
	}
}
