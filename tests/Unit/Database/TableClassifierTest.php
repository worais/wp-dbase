<?php
/**
 * Tests for the table classifier.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPDBase\Database\TableClassifier;
use WPDBase\Database\TableGroup;

#[CoversClass( TableClassifier::class )]
final class TableClassifierTest extends TestCase {

	/**
	 * @return array<string, array{string, TableGroup}>
	 */
	public static function tables(): array {
		return array(
			'posts'                                   => array( 'wp_posts', TableGroup::Core ),
			'term relationships'                      => array( 'wp_term_relationships', TableGroup::Core ),
			'usermeta'                                => array( 'wp_usermeta', TableGroup::Core ),
			'multisite blog table'                    => array( 'wp_12_options', TableGroup::Core ),
			'network table'                           => array( 'wp_sitemeta', TableGroup::Core ),
			'blog-prefixed network table is not core' => array( 'wp_2_sitemeta', TableGroup::Custom ),
			'woocommerce'                             => array( 'wp_wc_orders', TableGroup::Plugin ),
			'action scheduler'                        => array( 'wp_actionscheduler_actions', TableGroup::Plugin ),
			'custom'                                  => array( 'wp_contatos', TableGroup::Custom ),
			'custom meta'                             => array( 'wp_contatosmeta', TableGroup::Custom ),
			'core name, no prefix'                    => array( 'posts', TableGroup::Foreign ),
			'other prefix'                            => array( 'phpbb_users', TableGroup::Foreign ),
			'prefix is a substring'                   => array( 'xwp_posts', TableGroup::Foreign ),
			'core name with suffix'                   => array( 'wp_posts_backup', TableGroup::Custom ),
		);
	}

	#[DataProvider( 'tables' )]
	public function test_groups_table( string $table, TableGroup $expected ): void {
		self::assertSame( $expected, ( new TableClassifier( 'wp_' ) )->classify( $table ) );
	}

	public function test_names_the_plugin_owning_a_table(): void {
		$classifier = new TableClassifier( 'wp_' );

		self::assertSame( 'WooCommerce', $classifier->plugin_of( 'wp_wc_orders' ) );
		self::assertSame( 'Yoast SEO', $classifier->plugin_of( 'wp_yoast_indexable' ) );
		self::assertNull( $classifier->plugin_of( 'wp_contatos' ) );
		self::assertNull( $classifier->plugin_of( 'other_wc_orders' ) );
	}

	public function test_accepts_a_custom_plugin_list(): void {
		$classifier = new TableClassifier( 'wp_', array( 'acme_' => 'Acme' ) );

		self::assertSame( TableGroup::Plugin, $classifier->classify( 'wp_acme_log' ) );
		self::assertSame( TableGroup::Custom, $classifier->classify( 'wp_wc_orders' ) );
	}

	public function test_empty_prefix_still_recognises_core_tables(): void {
		$classifier = new TableClassifier( '' );

		self::assertSame( TableGroup::Core, $classifier->classify( 'posts' ) );
		self::assertSame( TableGroup::Custom, $classifier->classify( 'anything' ) );
	}
}
