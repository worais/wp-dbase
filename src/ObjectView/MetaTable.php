<?php
/**
 * A detected "<table> + <table>meta" pair.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\ObjectView;

/**
 * Describes how a meta table hangs off its parent table.
 */
final class MetaTable {

	/**
	 * Constructor.
	 *
	 * @param string $parent_table Parent table, e.g. `wp_posts`.
	 * @param string $meta_table   Meta table, e.g. `wp_postmeta`.
	 * @param string $foreign_key  Column of the meta table pointing at the parent, e.g. `post_id`.
	 * @param string $key_column   Column holding the meta key.
	 * @param string $value_column Column holding the meta value.
	 */
	public function __construct(
		public readonly string $parent_table,
		public readonly string $meta_table,
		public readonly string $foreign_key,
		public readonly string $key_column,
		public readonly string $value_column,
	) {
	}

	/**
	 * Array form for the REST API.
	 *
	 * @return array{parent: string, meta: string, foreign_key: string, key: string, value: string}
	 */
	public function to_array(): array {
		return array(
			'parent'      => $this->parent_table,
			'meta'        => $this->meta_table,
			'foreign_key' => $this->foreign_key,
			'key'         => $this->key_column,
			'value'       => $this->value_column,
		);
	}
}
