<?php
/**
 * Tests for the value tree.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\ObjectView;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WPDBase\ObjectView\ValueTree;

#[CoversClass( ValueTree::class )]
final class ValueTreeTest extends TestCase {

	public function test_unserializes_without_instantiating_objects(): void {
		$result = ( new ValueTree() )->unserialize( 'O:8:"DateTime":0:{}' );

		self::assertTrue( $result['ok'] );
		self::assertInstanceOf( \__PHP_Incomplete_Class::class, $result['value'] );
	}

	public function test_trims_before_unserializing(): void {
		$result = ( new ValueTree() )->unserialize( " a:1:{i:0;s:1:\"x\";}\n" );

		self::assertTrue( $result['ok'] );
		self::assertSame( array( 'x' ), $result['value'] );
	}

	public function test_caps_containers_too(): void {
		$rows = ( new ValueTree() )->build( array_fill( 0, ValueTree::MAX_NODES + 10, array() ) );

		self::assertCount( ValueTree::MAX_NODES, $rows );
	}
	public function test_reports_malformed_data(): void {
		$result = ( new ValueTree() )->unserialize( 'a:1:{broken' );

		self::assertFalse( $result['ok'] );
	}

	public function test_serialized_false_is_ok(): void {
		$result = ( new ValueTree() )->unserialize( ' b:0; ' );

		self::assertTrue( $result['ok'] );
		self::assertFalse( $result['value'] );
	}

	public function test_flattens_nested_arrays(): void {
		$rows = ( new ValueTree() )->build(
			array(
				'administrator' => true,
				'meta'          => array(
					'n'    => 3,
					'f'    => 1.5,
					'none' => null,
				),
				'name'          => 'x',
				'off'           => false,
			)
		);

		self::assertSame(
			array(
				array(
					'depth' => 0,
					'key'   => 'administrator',
					'value' => 'true',
					'type'  => 'bool',
				),
				array(
					'depth' => 0,
					'key'   => 'meta',
					'value' => 'array(3)',
					'type'  => 'array',
				),
				array(
					'depth' => 1,
					'key'   => 'n',
					'value' => '3',
					'type'  => 'int',
				),
				array(
					'depth' => 1,
					'key'   => 'f',
					'value' => '1.5',
					'type'  => 'float',
				),
				array(
					'depth' => 1,
					'key'   => 'none',
					'value' => 'null',
					'type'  => 'null',
				),
				array(
					'depth' => 0,
					'key'   => 'name',
					'value' => 'x',
					'type'  => 'string',
				),
				array(
					'depth' => 0,
					'key'   => 'off',
					'value' => 'false',
					'type'  => 'bool',
				),
			),
			$rows
		);
	}

	public function test_describes_objects(): void {
		$object    = new \stdClass();
		$object->a = 1;

		$rows = ( new ValueTree() )->build( array( 'o' => $object ) );

		self::assertSame(
			array(
				'depth' => 0,
				'key'   => 'o',
				'value' => 'object(1)',
				'type'  => 'object',
			),
			$rows[0]
		);
		self::assertSame(
			array(
				'depth' => 1,
				'key'   => 'a',
				'value' => '1',
				'type'  => 'int',
			),
			$rows[1]
		);
	}

	public function test_scalar_root_is_a_single_row(): void {
		self::assertSame(
			array(
				array(
					'depth' => 0,
					'key'   => '',
					'value' => 'hi',
					'type'  => 'string',
				),
			),
			( new ValueTree() )->build( 'hi' )
		);
	}

	public function test_caps_the_number_of_nodes(): void {
		$rows = ( new ValueTree() )->build( range( 1, ValueTree::MAX_NODES + 50 ) );

		self::assertCount( ValueTree::MAX_NODES, $rows );
	}

	public function test_node_counter_resets_between_builds(): void {
		$tree = new ValueTree();
		$tree->build( range( 1, ValueTree::MAX_NODES ) );

		self::assertCount( 2, $tree->build( array( 1, 2 ) ) );
	}

	public function test_stops_descending_at_max_depth(): void {
		$value = 'leaf';
		for ( $i = 0; $i < ValueTree::MAX_DEPTH + 3; $i++ ) {
			$value = array( 'k' => $value );
		}

		$rows   = ( new ValueTree() )->build( $value );
		$depths = array_column( $rows, 'depth' );

		self::assertSame( ValueTree::MAX_DEPTH, max( $depths ) );
		self::assertNotContains( 'leaf', array_column( $rows, 'value' ) );
	}
}
