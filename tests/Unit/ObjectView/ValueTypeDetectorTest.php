<?php
/**
 * Tests for value type detection.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\ObjectView;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPDBase\ObjectView\ValueType;
use WPDBase\ObjectView\ValueTypeDetector;

#[CoversClass( ValueTypeDetector::class )]
final class ValueTypeDetectorTest extends TestCase {

	/**
	 * @return array<string, array{string|null, ValueType}>
	 */
	public static function values(): array {
		return array(
			'null'                    => array( null, ValueType::Null ),
			'empty'                   => array( '', ValueType::Empty ),
			'serialized array'        => array( 'a:1:{s:13:"administrator";b:1;}', ValueType::Serialized ),
			'serialized string'       => array( 's:3:"abc";', ValueType::Serialized ),
			'serialized int'          => array( 'i:42;', ValueType::Serialized ),
			'serialized float'        => array( 'd:0.5;', ValueType::Serialized ),
			'serialized bool'         => array( 'b:0;', ValueType::Serialized ),
			'serialized null'         => array( 'N;', ValueType::Serialized ),
			'serialized object'       => array( 'O:8:"stdClass":0:{}', ValueType::Serialized ),
			'json object'             => array( '{"a":1}', ValueType::Json ),
			'json array'              => array( '[1,2]', ValueType::Json ),
			'broken json'             => array( '{"a":', ValueType::Text ),
			'json scalar is not json' => array( '"x"', ValueType::Text ),
			'bool word'               => array( 'true', ValueType::Boolean ),
			'bool yes'                => array( 'YES', ValueType::Boolean ),
			'bool off'                => array( 'off', ValueType::Boolean ),
			'integer'                 => array( '6371', ValueType::Integer ),
			'zero'                    => array( '0', ValueType::Integer ),
			'negative integer'        => array( '-3', ValueType::Integer ),
			'leading zero is text'    => array( '007', ValueType::Text ),
			'decimal'                 => array( '10.50', ValueType::Decimal ),
			'negative decimal'        => array( '-0.5', ValueType::Decimal ),
			'datetime'                => array( '2026-11-10 14:15:47', ValueType::DateTime ),
			'datetime T no secs'      => array( '2026-11-10T14:15', ValueType::DateTime ),
			'date'                    => array( '2026-11-10', ValueType::Date ),
			'url'                     => array( 'https://example.com/a?b=1', ValueType::Url ),
			'url with space'          => array( 'https://example.com/a b', ValueType::Text ),
			'email'                   => array( 'someone@example.com', ValueType::Email ),
			'html'                    => array( '<p class="x">Hi</p>', ValueType::Html ),
			'html closing tag'        => array( 'text</div>', ValueType::Html ),
			'less-than is not html'   => array( 'a < b and c > d', ValueType::Text ),
			'text'                    => array( 'Hello world', ValueType::Text ),
			'surrounding spaces'      => array( '  42  ', ValueType::Integer ),
			'prefixed decimal'        => array( 'x10.5', ValueType::Text ),
			'suffixed decimal'        => array( '10.5x', ValueType::Text ),
			'prefixed datetime'       => array( 'x2026-11-10 14:15', ValueType::Text ),
			'suffixed datetime'       => array( '2026-11-10 14:15:47x', ValueType::Text ),
			'prefixed date'           => array( 'x2026-11-10', ValueType::Text ),
			'suffixed date'           => array( '2026-11-10x', ValueType::Text ),
			'uppercase url'           => array( 'HTTPS://EXAMPLE.COM', ValueType::Url ),
			'url not at start'        => array( 'see https://example.com', ValueType::Text ),
			'uppercase html'          => array( '<DIV>x</DIV>', ValueType::Html ),
		);
	}

	#[DataProvider( 'values' )]
	public function test_detects_type( ?string $value, ValueType $expected ): void {
		self::assertSame( $expected, ( new ValueTypeDetector() )->detect( $value ) );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function serialized(): array {
		return array(
			'array'               => array( 'a:0:{}', true ),
			'object'              => array( 'O:3:"Foo":1:{s:1:"a";i:1;}', true ),
			'string'              => array( 's:1:"x";', true ),
			'string with newline' => array( "s:3:\"a\nb\";", true ),
			'missing semicolon'   => array( 'i:1', false ),
			'bad bool'            => array( 'b:2;', false ),
			'array without brace' => array( 'a:1:', false ),
			'text'                => array( 'hello', false ),
			'trailing garbage'    => array( 'a:0:{}x', false ),
			'leading garbage'     => array( 'xa:0:{}', false ),
			'array with newline'  => array( "a:1:{i:0;s:1:\"\n\";}", true ),
			'prefixed bool'       => array( 'xb:1;', false ),
			'suffixed bool'       => array( 'b:1;x', false ),
			'prefixed int'        => array( 'xi:1;', false ),
			'suffixed int'        => array( 'i:1;x', false ),
			'prefixed string'     => array( 'xs:1:"a";', false ),
			'suffixed string'     => array( 's:1:"a";x', false ),
		);
	}

	#[DataProvider( 'serialized' )]
	public function test_recognises_serialized_strings( string $value, bool $expected ): void {
		self::assertSame( $expected, ( new ValueTypeDetector() )->is_serialized( $value ) );
	}
}
