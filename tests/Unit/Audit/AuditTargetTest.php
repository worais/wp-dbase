<?php
/**
 * Tests for the audit table resolution.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPDBase\Audit\AuditTarget;

#[CoversClass( AuditTarget::class )]
final class AuditTargetTest extends TestCase {

	/**
	 * @return array<string, array{mixed, string|null, string, bool, string}>
	 */
	public static function values(): array {
		return array(
			'default'           => array( 'wp_wpdbase_audit', null, 'wp_wpdbase_audit', false, '`wp_wpdbase_audit`' ),
			'other table'       => array( 'my_audit', null, 'my_audit', true, '`my_audit`' ),
			'other database'    => array( 'onsize.wp_wpdbase_audit', 'onsize', 'wp_wpdbase_audit', true, '`onsize`.`wp_wpdbase_audit`' ),
			'three parts'       => array( 'a.b.c', null, 'wp_wpdbase_audit', false, '`wp_wpdbase_audit`' ),
			'invalid database'  => array( 'on size.audit', null, 'wp_wpdbase_audit', false, '`wp_wpdbase_audit`' ),
			'invalid table'     => array( 'onsize.au`dit', null, 'wp_wpdbase_audit', false, '`wp_wpdbase_audit`' ),
			'empty table part'  => array( 'onsize.', null, 'wp_wpdbase_audit', false, '`wp_wpdbase_audit`' ),
			'empty string'      => array( '', null, 'wp_wpdbase_audit', false, '`wp_wpdbase_audit`' ),
			'not a string'      => array( array( 'x' ), null, 'wp_wpdbase_audit', false, '`wp_wpdbase_audit`' ),
			'injection attempt' => array( 'x; DROP TABLE y', null, 'wp_wpdbase_audit', false, '`wp_wpdbase_audit`' ),
		);
	}

	#[DataProvider( 'values' )]
	public function test_resolves_filtered_table( mixed $filtered, ?string $database, string $table, bool $external, string $sql ): void {
		$target = AuditTarget::resolve( $filtered, 'wp_wpdbase_audit' );

		self::assertSame( $database, $target->database );
		self::assertSame( $table, $target->table );
		self::assertSame( $external, $target->external );
		self::assertSame( $sql, $target->sql() );
	}
}
