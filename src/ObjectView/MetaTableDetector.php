<?php
/**
 * Finds meta tables by naming convention.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\ObjectView;

/**
 * Detects `<table>` + `<table>meta` pairs without any configuration.
 *
 * Works for core (`wp_posts` + `wp_postmeta`) and for custom tables that follow
 * the same idea with different column names (`wp_contatos` + `wp_contatosmeta`
 * with `_id`, `key`, `value`).
 */
final class MetaTableDetector {

	private const KEY_COLUMNS = array( 'meta_key', 'key', 'name' );

	private const VALUE_COLUMNS = array( 'meta_value', 'value' );

	/**
	 * Detect every pair in a schema.
	 *
	 * @param array<string, list<string>> $schema Table name => column names.
	 * @return array<string, MetaTable> Parent table name => pair.
	 */
	public function detect( array $schema ): array {
		$pairs = array();

		foreach ( $schema as $meta_table => $columns ) {
			$parent = $this->parent_name( $meta_table, $schema );

			if ( null === $parent ) {
				continue;
			}

			$pair = $this->describe( $parent, $meta_table, $columns );

			if ( null !== $pair ) {
				$pairs[ $parent ] = $pair;
			}
		}

		ksort( $pairs );

		return $pairs;
	}

	/**
	 * Name of the parent table a meta table belongs to, if it exists.
	 *
	 * Core meta tables use the singular (`postmeta` → `posts`); custom ones often
	 * use the table name as is (`contatosmeta` → `contatos`).
	 *
	 * @param string                      $meta_table Candidate meta table.
	 * @param array<string, list<string>> $schema     Whole schema.
	 */
	private function parent_name( string $meta_table, array $schema ): ?string {
		if ( 1 !== preg_match( '/(.+?)_?meta$/', $meta_table, $match ) ) {
			return null;
		}

		foreach ( array( $match[1], $match[1] . 's' ) as $candidate ) {
			if ( $candidate !== $meta_table && isset( $schema[ $candidate ] ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Build the pair if the meta table has a foreign key, a key and a value column.
	 *
	 * @param string       $parent_table Parent table.
	 * @param string       $meta_table   Meta table.
	 * @param list<string> $columns      Columns of the meta table.
	 */
	private function describe( string $parent_table, string $meta_table, array $columns ): ?MetaTable {
		$key   = $this->first_present( self::KEY_COLUMNS, $columns );
		$value = $this->first_present( self::VALUE_COLUMNS, $columns );
		$fk    = $this->first_present( $this->foreign_key_candidates( $parent_table ), $columns );

		if ( null === $key || null === $value || null === $fk ) {
			return null;
		}

		return new MetaTable( $parent_table, $meta_table, $fk, $key, $value );
	}

	/**
	 * Foreign-key column names to try, most specific first.
	 *
	 * For `wp_contatos`: `contato_id`, `contatos_id`, `_id`, `object_id`, `parent_id`.
	 *
	 * @param string $parent_table Parent table.
	 * @return list<string>
	 */
	private function foreign_key_candidates( string $parent_table ): array {
		$bare     = preg_replace( '/^[a-z0-9]+_(?:\d+_)?/i', '', $parent_table ) ?? $parent_table;
		$singular = str_ends_with( $bare, 's' ) ? substr( $bare, 0, -1 ) : $bare;

		// Duplicates (when the name has no trailing "s") are harmless: the first match wins.
		return array( $singular . '_id', $bare . '_id', '_id', 'object_id', 'parent_id' );
	}

	/**
	 * First candidate that is a column of the table.
	 *
	 * @param list<string> $candidates Names in order of preference.
	 * @param list<string> $columns    Existing columns.
	 */
	private function first_present( array $candidates, array $columns ): ?string {
		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				return $candidate;
			}
		}

		return null;
	}
}
