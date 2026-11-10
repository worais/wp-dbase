<?php
/**
 * Tests for the index advisor.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WPDBase\Database\IndexAdvisor;

#[CoversClass( IndexAdvisor::class )]
final class IndexAdvisorTest extends TestCase {

	/**
	 * @param list<string> $columns
	 * @return array{name: string, columns: list<string>, unique: bool}
	 */
	private static function index( string $name, array $columns, bool $unique = false ): array {
		return array(
			'name'    => $name,
			'columns' => $columns,
			'unique'  => $unique,
		);
	}

	/**
	 * @param list<array<string, mixed>> $result
	 * @return array<string, array{string, string|null}>
	 */
	private static function verdicts( array $result ): array {
		$verdicts = array();
		foreach ( $result as $index ) {
			$verdicts[ $index['name'] ] = array( $index['assessment'], $index['covered_by'] );
		}
		return $verdicts;
	}

	public function test_marks_later_identical_index_as_duplicate(): void {
		$result = ( new IndexAdvisor() )->assess(
			array(
				self::index( 'a', array( 'x', 'y' ) ),
				self::index( 'b', array( 'x', 'y' ) ),
			)
		);

		self::assertSame(
			array(
				'a' => array( 'ok', null ),
				'b' => array( 'duplicate', 'a' ),
			),
			self::verdicts( $result )
		);
	}

	public function test_marks_left_prefix_as_redundant(): void {
		$result = ( new IndexAdvisor() )->assess(
			array(
				self::index( 'PRIMARY', array( 'id' ), true ),
				self::index( 'short', array( 'x' ) ),
				self::index( 'long', array( 'x', 'y' ) ),
				self::index( 'other', array( 'y' ) ),
			)
		);

		self::assertSame(
			array(
				'PRIMARY' => array( 'ok', null ),
				'short'   => array( 'redundant', 'long' ),
				'long'    => array( 'ok', null ),
				'other'   => array( 'ok', null ),
			),
			self::verdicts( $result )
		);
	}

	public function test_duplicate_wins_over_redundant(): void {
		$result = ( new IndexAdvisor() )->assess(
			array(
				self::index( 'a', array( 'x' ) ),
				self::index( 'b', array( 'x' ) ),
				self::index( 'c', array( 'x', 'y' ) ),
			)
		);

		self::assertSame(
			array(
				'a' => array( 'redundant', 'c' ),
				'b' => array( 'duplicate', 'a' ),
				'c' => array( 'ok', null ),
			),
			self::verdicts( $result )
		);
	}

	public function test_longer_index_is_not_redundant_to_a_shorter_one(): void {
		$result = ( new IndexAdvisor() )->assess(
			array(
				self::index( 'long', array( 'x', 'y' ) ),
				self::index( 'short', array( 'x' ) ),
			)
		);

		self::assertSame( 'ok', self::verdicts( $result )['long'][0] );
	}
	public function test_unique_indexes_are_never_redundant_or_duplicate(): void {
		$result = ( new IndexAdvisor() )->assess(
			array(
				self::index( 'a', array( 'x', 'y' ) ),
				self::index( 'u', array( 'x' ), true ),
				self::index( 'u2', array( 'x', 'y' ), true ),
			)
		);

		self::assertSame( 'ok', self::verdicts( $result )['u'][0] );
		self::assertSame( 'ok', self::verdicts( $result )['u2'][0] );
	}

	public function test_same_columns_in_other_order_are_not_a_prefix(): void {
		$result = ( new IndexAdvisor() )->assess(
			array(
				self::index( 'a', array( 'y' ) ),
				self::index( 'b', array( 'x', 'y' ) ),
			)
		);

		self::assertSame( 'ok', self::verdicts( $result )['a'][0] );
	}

	public function test_keeps_original_fields(): void {
		$result = ( new IndexAdvisor() )->assess( array( self::index( 'a', array( 'x' ) ) ) );

		self::assertSame( array( 'x' ), $result[0]['columns'] );
		self::assertFalse( $result[0]['unique'] );
	}
}
