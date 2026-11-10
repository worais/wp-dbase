<?php
/**
 * Guesses which table a "*_id" value points at.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\ObjectView;

/**
 * Pure naming-convention resolver used to turn IDs into links.
 */
final class ReferenceResolver {

	/**
	 * Keys and columns whose target is fixed by WordPress itself.
	 */
	private const KNOWN = array(
		'_thumbnail_id'        => 'posts',
		'_menu_item_object_id' => 'posts',
		'post_author'          => 'users',
		'post_parent'          => 'posts',
		'comment_post_ID'      => 'posts',
		'user_id'              => 'users',
		'post_id'              => 'posts',
		'comment_id'           => 'comments',
		'comment_parent'       => 'comments',
		'term_id'              => 'terms',
	);

	/**
	 * Constructor.
	 *
	 * @param string       $prefix Site table prefix.
	 * @param list<string> $tables Tables that exist in the database.
	 */
	public function __construct(
		private readonly string $prefix,
		private readonly array $tables,
	) {
	}

	/**
	 * Table a key/column with an integer value refers to, or null.
	 *
	 * `contato_id` resolves to `wp_contatos` (or `wp_contato`) when that table exists.
	 *
	 * @param string $name Meta key or column name.
	 */
	public function resolve( string $name ): ?string {
		if ( isset( self::KNOWN[ $name ] ) ) {
			return $this->existing( $this->prefix . self::KNOWN[ $name ] );
		}

		if ( 1 !== preg_match( '/^_?([a-z][a-z0-9_]*?)_id$/i', $name, $match ) ) {
			return null;
		}

		$base = strtolower( $match[1] );

		return $this->existing( $this->prefix . $base . 's' ) ?? $this->existing( $this->prefix . $base );
	}

	/**
	 * The table name if it exists.
	 *
	 * @param string $table Candidate.
	 */
	private function existing( string $table ): ?string {
		return in_array( $table, $this->tables, true ) ? $table : null;
	}
}
