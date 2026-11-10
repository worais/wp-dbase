<?php
/**
 * Tests for the site configuration (constants + filters).
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\Adapters;

use Brain\Monkey;
use Brain\Monkey\Filters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WPDBase\Config;
use WPDBase\Security\SafetyMode;

#[CoversClass( Config::class )]
final class ConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_without_constants(): void {
		$config = new Config();

		self::assertSame( array(), $config->extra_databases() );
		self::assertSame( 'manage_options', $config->capability() );
		self::assertSame( SafetyMode::Expert, $config->max_safety_mode() );
		self::assertSame( 20, $config->query_timeout() );
		self::assertSame( 1000, $config->max_rows() );
	}

	/**
	 * @return array<string, array{mixed, string|list<string>}>
	 */
	public static function database_settings(): array {
		return array(
			'star'          => array( '*', '*' ),
			'list'          => array( array( 'a', 'b' ), array( 'a', 'b' ) ),
			'comma string'  => array( 'a, b ,,a', array( 'a', 'b' ) ),
			'blank entries' => array( array( ' ', '', 'c' ), array( 'c' ) ),
			'duplicates'    => array( array( 'x', 'x' ), array( 'x' ) ),
			'garbage'       => array( 42, array() ),
			'false'         => array( false, array() ),
		);
	}

	/**
	 * @param string|list<string> $expected
	 */
	#[DataProvider( 'database_settings' )]
	public function test_normalises_the_databases_filter( mixed $raw, string|array $expected ): void {
		Filters\expectApplied( 'wpdbase_databases' )->once()->with( array() )->andReturn( $raw );

		self::assertSame( $expected, ( new Config() )->extra_databases() );
	}

	public function test_capability_and_mode_are_filterable(): void {
		Filters\expectApplied( 'wpdbase_capability' )->once()->with( 'manage_options' )->andReturn( 'edit_posts' );
		Filters\expectApplied( 'wpdbase_max_safety_mode' )->once()->with( 'expert' )->andReturn( 'bogus' );

		$config = new Config();

		self::assertSame( 'edit_posts', $config->capability() );
		self::assertSame( SafetyMode::Read, $config->max_safety_mode(), 'An invalid mode falls back to the safest one.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_reads_constants(): void {
		define( 'WPDBASE_DATABASES', array( 'other' ) );
		define( 'WPDBASE_CAPABILITY', 'install_plugins' );
		define( 'WPDBASE_MAX_SAFETY_MODE', 'assisted' );
		define( 'WPDBASE_QUERY_TIMEOUT', 0 );
		define( 'WPDBASE_MAX_ROWS', 50 );

		$config = new Config();

		self::assertSame( array( 'other' ), $config->extra_databases() );
		self::assertSame( 'install_plugins', $config->capability() );
		self::assertSame( SafetyMode::Assisted, $config->max_safety_mode() );
		self::assertSame( 1, $config->query_timeout(), 'The timeout never drops below one second.' );
		self::assertSame( 50, $config->max_rows() );
	}
}
