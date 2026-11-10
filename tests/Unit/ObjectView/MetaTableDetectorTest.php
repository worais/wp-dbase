<?php
/**
 * Tests for meta table detection.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\ObjectView;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WPDBase\ObjectView\MetaTable;
use WPDBase\ObjectView\MetaTableDetector;

#[CoversClass( MetaTableDetector::class )]
#[CoversClass( MetaTable::class )]
final class MetaTableDetectorTest extends TestCase {

	/**
	 * @return array<string, list<string>>
	 */
	private static function schema(): array {
		return array(
			'wp_posts'        => array( 'ID', 'post_title' ),
			'wp_postmeta'     => array( 'meta_id', 'post_id', 'meta_key', 'meta_value' ),
			'wp_users'        => array( 'ID', 'user_login' ),
			'wp_usermeta'     => array( 'umeta_id', 'user_id', 'meta_key', 'meta_value' ),
			'wp_contatos'     => array( 'id', 'nome' ),
			'wp_contatosmeta' => array( 'id', '_id', 'key', 'value' ),
			'wp_agenda'       => array( 'id', 'nome' ),
			'wp_agendameta'   => array( 'id', '_id', 'key', 'value' ),
			'wp_orphanmeta'   => array( 'id', 'orphan_id', 'meta_key', 'meta_value' ),
			'wp_items'        => array( 'id' ),
			'wp_items_meta'   => array( 'id', 'object_id', 'name', 'value' ),
			'wp_broken'       => array( 'id' ),
			'wp_brokenmeta'   => array( 'id', 'broken_id', 'meta_key' ),
			'meta'            => array( 'id', 'key', 'value' ),
		);
	}

	public function test_detects_core_and_custom_pairs(): void {
		$pairs = ( new MetaTableDetector() )->detect( self::schema() );

		self::assertSame( array( 'wp_agenda', 'wp_contatos', 'wp_items', 'wp_posts', 'wp_users' ), array_keys( $pairs ) );

		self::assertSame(
			array(
				'parent'      => 'wp_posts',
				'meta'        => 'wp_postmeta',
				'foreign_key' => 'post_id',
				'key'         => 'meta_key',
				'value'       => 'meta_value',
			),
			$pairs['wp_posts']->to_array()
		);

		self::assertSame( 'user_id', $pairs['wp_users']->foreign_key );

		self::assertSame(
			array(
				'parent'      => 'wp_contatos',
				'meta'        => 'wp_contatosmeta',
				'foreign_key' => '_id',
				'key'         => 'key',
				'value'       => 'value',
			),
			$pairs['wp_contatos']->to_array()
		);

		self::assertSame( 'wp_items_meta', $pairs['wp_items']->meta_table );
		self::assertSame( 'object_id', $pairs['wp_items']->foreign_key );
		self::assertSame( 'name', $pairs['wp_items']->key_column );
	}

	public function test_prefers_the_most_specific_foreign_key(): void {
		$pairs = ( new MetaTableDetector() )->detect(
			array(
				'wp_posts'    => array( 'ID' ),
				'wp_postmeta' => array( 'object_id', 'post_id', 'meta_key', 'meta_value' ),
			)
		);

		self::assertSame( 'post_id', $pairs['wp_posts']->foreign_key );
	}

	public function test_ignores_meta_without_parent_or_columns(): void {
		$pairs = ( new MetaTableDetector() )->detect( self::schema() );

		self::assertArrayNotHasKey( 'wp_orphan', $pairs );
		self::assertArrayNotHasKey( 'wp_broken', $pairs );
	}

	public function test_requires_a_foreign_key_column(): void {
		$pairs = ( new MetaTableDetector() )->detect(
			array(
				'wp_things'     => array( 'id' ),
				'wp_thingsmeta' => array( 'id', 'meta_key', 'meta_value' ),
			)
		);

		self::assertSame( array(), $pairs );
	}

	public function test_requires_a_value_column(): void {
		$pairs = ( new MetaTableDetector() )->detect(
			array(
				'wp_things'     => array( 'id' ),
				'wp_thingsmeta' => array( 'id', 'thing_id', 'meta_key' ),
			)
		);

		self::assertSame( array(), $pairs );
	}

	public function test_handles_multisite_blog_prefix(): void {
		$pairs = ( new MetaTableDetector() )->detect(
			array(
				'wp_2_posts'    => array( 'ID' ),
				'wp_2_postmeta' => array( 'meta_id', 'post_id', 'meta_key', 'meta_value' ),
			)
		);

		self::assertSame( 'post_id', $pairs['wp_2_posts']->foreign_key );
	}

	public function test_meta_must_be_a_suffix(): void {
		$pairs = ( new MetaTableDetector() )->detect(
			array(
				'wp_x'          => array( 'id' ),
				'wp_x_metadata' => array( 'id', 'x_id', 'meta_key', 'meta_value' ),
			)
		);

		self::assertSame( array(), $pairs );
	}

	public function test_accepts_plural_foreign_key(): void {
		$pairs = ( new MetaTableDetector() )->detect(
			array(
				'wp_news'     => array( 'id' ),
				'wp_newsmeta' => array( 'id', 'news_id', 'meta_key', 'meta_value' ),
			)
		);

		self::assertSame( 'news_id', $pairs['wp_news']->foreign_key );
	}
	public function test_table_without_meta_suffix_is_not_a_meta_table(): void {
		self::assertSame( array(), ( new MetaTableDetector() )->detect( array( 'wp_meta' => array( 'id', 'key', 'value' ) ) ) );
	}
}
