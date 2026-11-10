<?php
/**
 * Builds the Object View: a row plus everything that hangs off it.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\ObjectView;

use InvalidArgumentException;
use WPDBase\Database\Identifier;
use WPDBase\Database\Query;
use WPDBase\Database\RowRepository;
use WPDBase\Database\SchemaRepository;
use wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers come from information_schema and are quoted by Identifier; values are bound.

/**
 * Assembles a record from its table, its meta table, terms and related rows.
 *
 * Every SQL statement it runs is returned too ("view the SQL behind this panel").
 */
final class ObjectAssembler {

	private const META_LIMIT = 500;

	/**
	 * SQL run while assembling, in order.
	 *
	 * @var list<string>
	 */
	private array $sql = array();

	/**
	 * Constructor.
	 *
	 * @param wpdb              $wpdb      Site connection.
	 * @param SchemaRepository  $schema    Schema reader.
	 * @param RowRepository     $rows      Row reader.
	 * @param MetaTableDetector $detector  Meta pair detector.
	 * @param ValueTypeDetector $types     Value type detector.
	 * @param ValueTree         $tree      Serialized/JSON tree builder.
	 * @param TitleGuesser      $titles    Title guesser.
	 * @param string            $prefix    Table prefix of the databases' WordPress install.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly SchemaRepository $schema,
		private readonly RowRepository $rows,
		private readonly MetaTableDetector $detector,
		private readonly ValueTypeDetector $types,
		private readonly ValueTree $tree,
		private readonly TitleGuesser $titles,
		private readonly string $prefix,
	) {
	}

	/**
	 * Assemble one object.
	 *
	 * @param string $database Database (allow-listed).
	 * @param string $table    Table.
	 * @param string $id       Primary key value.
	 * @return array<string, mixed>|null Null when the row does not exist.
	 * @throws InvalidArgumentException On unknown table or missing single-column primary key.
	 */
	public function assemble( string $database, string $table, string $id ): ?array {
		$this->sql = array();
		$start     = hrtime( true );

		$found       = $this->rows->find( $database, $table, $id );
		$this->sql[] = $found['sql'];
		$row         = $found['row'];

		if ( null === $row ) {
			return null;
		}

		$tables   = $this->schema->table_names( $database );
		$resolver = new ReferenceResolver( $this->prefix, $tables );
		$pairs    = $this->detector->detect( $this->schema->column_map( $database ) );
		$pair     = $pairs[ $table ] ?? null;
		$primary  = $this->rows->single_primary_key( $database, $table );
		$metas    = null === $pair ? array() : $this->metas( $database, $pair, $id, $resolver );
		$public   = array_values( array_filter( $metas, static fn( array $meta ): bool => ! str_starts_with( $meta['key'], '_' ) ) );
		$internal = array_values( array_filter( $metas, static fn( array $meta ): bool => str_starts_with( $meta['key'], '_' ) ) );

		$fields = array();
		foreach ( $row as $column => $value ) {
			$fields[] = $this->describe_value( (string) $column, null === $value ? null : (string) $value, $column === $primary ? null : $resolver );
		}

		return array(
			'database'  => $database,
			'table'     => $table,
			'id'        => $id,
			'kind'      => $this->kind_of( $table ),
			'title'     => $this->titles->guess( $row ) ?? '#' . $id,
			'primary'   => $primary,
			'fields'    => $fields,
			'meta'      => null === $pair ? null : $pair->to_array(),
			'metas'     => $public,
			'internal'  => $internal,
			'blocks'    => $this->blocks( $database, $table, $id, $metas ),
			'related'   => $this->related( $database, $table, $id, $pairs ),
			'sql'       => $this->sql,
			'timing_ms' => (int) round( ( hrtime( true ) - $start ) / 1e6 ),
		);
	}

	/**
	 * Meta rows of the object.
	 *
	 * @param string            $database Database.
	 * @param MetaTable         $pair     Meta pair.
	 * @param string            $id       Object ID.
	 * @param ReferenceResolver $resolver Reference resolver.
	 * @return list<array<string, mixed>>
	 */
	private function metas( string $database, MetaTable $pair, string $id, ReferenceResolver $resolver ): array {
		$sql = Query::prepare(
			$this->wpdb,
			'SELECT ' . Identifier::quote( $pair->key_column ) . ' AS k, ' . Identifier::quote( $pair->value_column ) . ' AS v'
				. ' FROM ' . Identifier::qualified( $database, $pair->meta_table )
				. ' WHERE ' . Identifier::quote( $pair->foreign_key ) . ' = %s LIMIT %d',
			$id,
			self::META_LIMIT
		);

		$this->sql[] = $sql;
		$rows        = $this->wpdb->get_results( $sql, ARRAY_A );

		$metas = array();
		foreach ( is_array( $rows ) ? $rows : array() as $meta ) {
			$metas[] = $this->describe_value( (string) $meta['k'], null === $meta['v'] ? null : (string) $meta['v'], $resolver );
		}

		return $metas;
	}

	/**
	 * Type, decoded tree and reference link for one value.
	 *
	 * @param string                 $key      Column or meta key.
	 * @param string|null            $value    Raw value.
	 * @param ReferenceResolver|null $resolver Resolver (null to skip reference detection).
	 * @return array{key: string, value: string|null, type: string, tree: list<array<string, mixed>>|null, ref: array{table: string, id: string}|null}
	 */
	private function describe_value( string $key, ?string $value, ?ReferenceResolver $resolver ): array {
		$type = $this->types->detect( $value );
		$tree = null;

		if ( ValueType::Serialized === $type && null !== $value ) {
			$decoded = $this->tree->unserialize( $value );
			$tree    = $decoded['ok'] ? $this->tree->build( $decoded['value'] ) : null;
		} elseif ( ValueType::Json === $type && null !== $value ) {
			$tree = $this->tree->build( json_decode( $value, true ) );
		}

		$ref = null;
		if ( null !== $resolver && ValueType::Integer === $type && null !== $value && '0' !== $value ) {
			$target = $resolver->resolve( $key );
			$ref    = null === $target ? null : array(
				'table' => $target,
				'id'    => $value,
			);
		}

		return array(
			'key'   => $key,
			'value' => $value,
			'type'  => $type->value,
			'tree'  => $tree,
			'ref'   => $ref,
		);
	}

	/**
	 * Object kind label from the table name.
	 *
	 * @param string $table Table.
	 */
	private function kind_of( string $table ): string {
		$bare = str_starts_with( $table, $this->prefix ) ? substr( $table, strlen( $this->prefix ) ) : $table;

		return match ( $bare ) {
			'posts'    => 'post',
			'users'    => 'user',
			'terms'    => 'term',
			'comments' => 'comment',
			default    => 'row',
		};
	}

	/**
	 * WordPress-aware blocks: roles, sessions, terms.
	 *
	 * @param string                     $database Database.
	 * @param string                     $table    Table.
	 * @param string                     $id       Object ID.
	 * @param list<array<string, mixed>> $metas    Decoded metas.
	 * @return list<array<string, mixed>>
	 */
	private function blocks( string $database, string $table, string $id, array $metas ): array {
		$blocks = array();
		$kind   = $this->kind_of( $table );

		if ( 'user' === $kind ) {
			foreach ( $metas as $meta ) {
				if ( $meta['key'] === $this->prefix . 'capabilities' && null !== $meta['value'] ) {
					$caps     = $this->tree->unserialize( $meta['value'] );
					$roles    = is_array( $caps['value'] ) ? array_keys( array_filter( $caps['value'] ) ) : array();
					$blocks[] = array(
						'type'   => 'roles',
						'source' => $meta['key'],
						'items'  => array_map( 'strval', $roles ),
					);
				}

				if ( 'session_tokens' === $meta['key'] && null !== $meta['value'] ) {
					$sessions = $this->tree->unserialize( $meta['value'] );
					$items    = array();
					foreach ( is_array( $sessions['value'] ) ? $sessions['value'] : array() as $session ) {
						if ( is_array( $session ) ) {
							$items[] = array(
								'ip'         => (string) ( $session['ip'] ?? '' ),
								'login'      => (int) ( $session['login'] ?? 0 ),
								'expiration' => (int) ( $session['expiration'] ?? 0 ),
								'ua'         => (string) ( $session['ua'] ?? '' ),
							);
						}
					}
					$blocks[] = array(
						'type'   => 'sessions',
						'source' => 'session_tokens',
						'items'  => $items,
					);
				}
			}//end foreach
		}//end if

		if ( 'post' === $kind ) {
			$terms = $this->post_terms( $database, $id );
			if ( null !== $terms ) {
				$blocks[] = array(
					'type'   => 'terms',
					'source' => $this->prefix . 'term_relationships',
					'items'  => $terms,
				);
			}
		}

		return $blocks;
	}

	/**
	 * Terms attached to a post, grouped by taxonomy (null when the tables are missing).
	 *
	 * @param string $database Database.
	 * @param string $id       Post ID.
	 * @return list<array{taxonomy: string, name: string, term_id: string}>|null
	 */
	private function post_terms( string $database, string $id ): ?array {
		foreach ( array( 'term_relationships', 'term_taxonomy', 'terms' ) as $bare ) {
			if ( ! $this->schema->has_table( $database, $this->prefix . $bare ) ) {
				return null;
			}
		}

		$sql = Query::prepare(
			$this->wpdb,
			'SELECT tt.taxonomy, t.name, t.term_id FROM ' . Identifier::qualified( $database, $this->prefix . 'term_relationships' ) . ' tr'
				. ' JOIN ' . Identifier::qualified( $database, $this->prefix . 'term_taxonomy' ) . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
				. ' JOIN ' . Identifier::qualified( $database, $this->prefix . 'terms' ) . ' t ON t.term_id = tt.term_id'
				. ' WHERE tr.object_id = %s ORDER BY tt.taxonomy, t.name LIMIT 200',
			$id
		);

		$this->sql[] = $sql;
		$rows        = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			static fn( array $row ): array => array(
				'taxonomy' => (string) $row['taxonomy'],
				'name'     => (string) $row['name'],
				'term_id'  => (string) $row['term_id'],
			),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Rows elsewhere that point at this object through a meta key named after it.
	 *
	 * Example: `wp_agendameta` rows with `key = 'contato_id'` and `value = 6371`
	 * are appointments of contact 6371. Core relations (comments of a post,
	 * posts of an author) are included when the tables exist.
	 *
	 * @param string                   $database Database.
	 * @param string                   $table    Table.
	 * @param string                   $id       Object ID.
	 * @param array<string, MetaTable> $pairs    Detected meta pairs.
	 * @return list<array{table: string, label: string, count: int, where: string, sql: string}>
	 */
	private function related( string $database, string $table, string $id, array $pairs ): array {
		$related = array();
		$kind    = $this->kind_of( $table );

		$core = match ( $kind ) {
			'post'  => array(
				array( 'comments', 'comment_post_ID' ),
				array( 'posts', 'post_parent' ),
			),
			'user'  => array(
				array( 'posts', 'post_author' ),
			),
			default => array(),
		};

		foreach ( $core as [ $bare, $column ] ) {
			$target = $this->prefix . $bare;
			if ( $this->schema->has_table( $database, $target ) ) {
				$related[] = $this->count_where( $database, $target, $column, $id, $column . ' = ' . $id );
			}
		}

		$bare_table = str_starts_with( $table, $this->prefix ) ? substr( $table, strlen( $this->prefix ) ) : $table;
		$singular   = str_ends_with( $bare_table, 's' ) ? substr( $bare_table, 0, -1 ) : $bare_table;
		$reference  = $singular . '_id';

		if ( 'row' === $kind ) {
			foreach ( $pairs as $parent => $pair ) {
				if ( $parent === $table ) {
					continue;
				}

				$sql = Query::prepare(
					$this->wpdb,
					'SELECT COUNT(*) FROM ' . Identifier::qualified( $database, $pair->meta_table )
						. ' WHERE ' . Identifier::quote( $pair->key_column ) . ' = %s AND ' . Identifier::quote( $pair->value_column ) . ' = %s',
					$reference,
					$id
				);

				$this->sql[] = $sql;
				$count       = (int) $this->wpdb->get_var( $sql );

				if ( $count > 0 ) {
					$related[] = array(
						'table' => $pair->parent_table,
						'label' => $pair->meta_table . '.' . $reference,
						'count' => $count,
						'where' => $reference . ' = ' . $id,
						'sql'   => Query::prepare(
							$this->wpdb,
							'SELECT * FROM ' . Identifier::quote( $pair->meta_table )
								. ' WHERE ' . Identifier::quote( $pair->key_column ) . ' = %s AND ' . Identifier::quote( $pair->value_column ) . ' = %s LIMIT 100',
							$reference,
							$id
						),
					);
				}
			}//end foreach
		}//end if

		return array_values( array_filter( $related, static fn( array $item ): bool => $item['count'] > 0 ) );
	}

	/**
	 * Count rows of a table where a column equals the ID.
	 *
	 * @param string $database Database.
	 * @param string $table    Table.
	 * @param string $column   Column.
	 * @param string $id       Value.
	 * @param string $label    Display label.
	 * @return array{table: string, label: string, count: int, where: string, sql: string}
	 */
	private function count_where( string $database, string $table, string $column, string $id, string $label ): array {
		$sql = Query::prepare(
			$this->wpdb,
			'SELECT COUNT(*) FROM ' . Identifier::qualified( $database, $table ) . ' WHERE ' . Identifier::quote( $column ) . ' = %s',
			$id
		);

		$this->sql[] = $sql;

		return array(
			'table' => $table,
			'label' => $table . '.' . $column,
			'count' => (int) $this->wpdb->get_var( $sql ),
			'where' => $label,
			'sql'   => Query::prepare(
				$this->wpdb,
				'SELECT * FROM ' . Identifier::quote( $table ) . ' WHERE ' . Identifier::quote( $column ) . ' = %s LIMIT 100',
				$id
			),
		);
	}
}
