<?php
/**
 * Tests for the safety modes.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPDBase\Security\SafetyMode;
use WPDBase\Sql\StatementKind;

#[CoversClass( SafetyMode::class )]
final class SafetyModeTest extends TestCase {

	/**
	 * @return array<string, array{mixed, SafetyMode}>
	 */
	public static function raw_values(): array {
		return array(
			'read'                  => array( 'read', SafetyMode::Read ),
			'assisted'              => array( 'assisted', SafetyMode::Assisted ),
			'expert'                => array( 'expert', SafetyMode::Expert ),
			'case and spaces'       => array( '  EXPERT ', SafetyMode::Expert ),
			'unknown falls back'    => array( 'root', SafetyMode::Read ),
			'empty falls back'      => array( '', SafetyMode::Read ),
			'non-string falls back' => array( 2, SafetyMode::Read ),
			'null falls back'       => array( null, SafetyMode::Read ),
		);
	}

	#[DataProvider( 'raw_values' )]
	public function test_parses_to_the_safest_mode_when_unsure( mixed $raw, SafetyMode $expected ): void {
		self::assertSame( $expected, SafetyMode::parse( $raw ) );
	}

	public function test_ranks_modes_from_safe_to_permissive(): void {
		self::assertSame( 0, SafetyMode::Read->rank() );
		self::assertSame( 1, SafetyMode::Assisted->rank() );
		self::assertSame( 2, SafetyMode::Expert->rank() );
	}

	public function test_caps_mode_to_the_site_ceiling(): void {
		self::assertSame( SafetyMode::Assisted, SafetyMode::Expert->cap_to( SafetyMode::Assisted ) );
		self::assertSame( SafetyMode::Read, SafetyMode::Assisted->cap_to( SafetyMode::Read ) );
		self::assertSame( SafetyMode::Assisted, SafetyMode::Assisted->cap_to( SafetyMode::Assisted ) );
		self::assertSame( SafetyMode::Read, SafetyMode::Read->cap_to( SafetyMode::Expert ) );
	}

	/**
	 * @return array<string, array{SafetyMode, StatementKind, bool}>
	 */
	public static function permissions(): array {
		$cases = array();
		foreach ( SafetyMode::cases() as $mode ) {
			foreach ( StatementKind::cases() as $kind ) {
				$allowed                                       = StatementKind::Read === $kind || SafetyMode::Expert === $mode;
				$cases[ "{$mode->value} runs {$kind->value}" ] = array( $mode, $kind, $allowed );
			}
		}
		return $cases;
	}

	#[DataProvider( 'permissions' )]
	public function test_free_sql_writes_need_expert_mode( SafetyMode $mode, StatementKind $kind, bool $allowed ): void {
		self::assertSame( $allowed, $mode->allows_statement( $kind ) );
	}

	public function test_row_edits_need_at_least_assisted_mode(): void {
		self::assertFalse( SafetyMode::Read->allows_row_edits() );
		self::assertTrue( SafetyMode::Assisted->allows_row_edits() );
		self::assertTrue( SafetyMode::Expert->allows_row_edits() );
	}
}
