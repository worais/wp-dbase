<?php
/**
 * Tests for the title guesser and reference resolver.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

namespace WPDBase\Tests\Unit\ObjectView;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WPDBase\ObjectView\ReferenceResolver;
use WPDBase\ObjectView\TitleGuesser;

#[CoversClass( TitleGuesser::class )]
#[CoversClass( ReferenceResolver::class )]
final class TitleAndReferenceTest extends TestCase {

	public function test_prefers_post_title(): void {
		self::assertSame(
			'Hello',
			( new TitleGuesser() )->guess(
				array(
					'name'       => 'slug',
					'post_title' => ' Hello ',
				)
			)
		);
	}

	public function test_skips_empty_candidates(): void {
		self::assertSame(
			'MARIA',
			( new TitleGuesser() )->guess(
				array(
					'post_title' => '  ',
					'name'       => null,
					'nome'       => 'MARIA',
				)
			)
		);
	}

	public function test_returns_null_without_title_column(): void {
		self::assertNull( ( new TitleGuesser() )->guess( array( 'id' => '1' ) ) );
	}

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function references(): array {
		return array(
			'thumbnail'                => array( '_thumbnail_id', 'wp_posts' ),
			'post author'              => array( 'post_author', 'wp_users' ),
			'post parent'              => array( 'post_parent', 'wp_posts' ),
			'user id'                  => array( 'user_id', 'wp_users' ),
			'plural table'             => array( 'contato_id', 'wp_contatos' ),
			'singular table'           => array( 'agenda_id', 'wp_agenda' ),
			'underscored key'          => array( '_contato_id', 'wp_contatos' ),
			'uppercase'                => array( 'CONTATO_ID', 'wp_contatos' ),
			'unknown table'            => array( 'banana_id', null ),
			'not an id'                => array( 'contato', null ),
			'known, missing'           => array( 'comment_id', null ),
			'bare _id'                 => array( '_id', null ),
			'must start with a letter' => array( '9contato_id', null ),
			'must end in _id'          => array( 'contato_id_old', null ),
		);
	}

	public function test_plural_table_wins_over_singular(): void {
		$resolver = new ReferenceResolver( 'wp_', array( 'wp_agenda', 'wp_agendas' ) );

		self::assertSame( 'wp_agendas', $resolver->resolve( 'agenda_id' ) );
	}
	#[DataProvider( 'references' )]
	public function test_resolves_reference( string $name, ?string $expected ): void {
		$resolver = new ReferenceResolver( 'wp_', array( 'wp_posts', 'wp_users', 'wp_contatos', 'wp_agenda' ) );

		self::assertSame( $expected, $resolver->resolve( $name ) );
	}
}
