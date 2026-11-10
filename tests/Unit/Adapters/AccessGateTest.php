<?php
/**
 * Tests for the access gate.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\Adapters;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WPDBase\Config;
use WPDBase\Security\AccessGate;
use WPDBase\Security\SafetyMode;

#[CoversClass( AccessGate::class )]
final class AccessGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_requires_login_and_capability(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );

		self::assertTrue( ( new AccessGate( new Config() ) )->can_access() );
	}

	public function test_denies_logged_out_users_without_checking_caps(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\expect( 'current_user_can' )->never();

		self::assertFalse( ( new AccessGate( new Config() ) )->can_access() );
	}

	public function test_filter_can_narrow_access_to_one_user(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Filters\expectApplied( 'wpdbase_user_can_access' )->once()->with( true, 7 )->andReturn( false );

		self::assertFalse( ( new AccessGate( new Config() ) )->can_access() );
	}

	public function test_current_mode_is_capped_by_the_site(): void {
		Functions\expect( 'get_user_meta' )->once()->with( 7, AccessGate::MODE_META_KEY, true )->andReturn( 'expert' );
		Filters\expectApplied( 'wpdbase_max_safety_mode' )->andReturn( 'assisted' );

		self::assertSame( SafetyMode::Assisted, ( new AccessGate( new Config() ) )->current_mode() );
	}

	public function test_unknown_stored_mode_reads_as_read_only(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		self::assertSame( SafetyMode::Read, ( new AccessGate( new Config() ) )->current_mode() );
	}

	public function test_set_mode_stores_the_capped_value(): void {
		Filters\expectApplied( 'wpdbase_max_safety_mode' )->andReturn( 'read' );
		Functions\expect( 'update_user_meta' )->once()->with( 7, AccessGate::MODE_META_KEY, 'read' );

		self::assertSame( SafetyMode::Read, ( new AccessGate( new Config() ) )->set_mode( SafetyMode::Expert ) );
	}
}
