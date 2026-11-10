<?php
/**
 * Tables, structure and health of a database.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPDBase\Database\DatabaseRegistry;
use WPDBase\Database\HealthRepository;
use WPDBase\Database\IndexAdvisor;
use WPDBase\Database\SchemaRepository;
use WPDBase\Database\TableClassifier;
use WPDBase\ObjectView\MetaTableDetector;
use WPDBase\Security\AccessGate;

/**
 * `GET /databases/{db}/overview`, `/tables`, `/tables/{table}/structure`.
 */
final class SchemaController extends BaseController {

	/**
	 * Constructor.
	 *
	 * @param AccessGate        $gate       Access gate.
	 * @param DatabaseRegistry  $registry   Database allow-list.
	 * @param SchemaRepository  $schema     Schema reader.
	 * @param HealthRepository  $health     Health checks.
	 * @param TableClassifier   $classifier Table classifier.
	 * @param IndexAdvisor      $advisor    Index advisor.
	 * @param MetaTableDetector $detector   Meta pair detector.
	 */
	public function __construct(
		AccessGate $gate,
		DatabaseRegistry $registry,
		SchemaRepository $schema,
		private readonly HealthRepository $health,
		private readonly TableClassifier $classifier,
		private readonly IndexAdvisor $advisor,
		private readonly MetaTableDetector $detector,
	) {
		parent::__construct( $gate, $registry, $schema );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$routes = array(
			'/databases/' . self::DB . '/overview' => 'overview',
			'/databases/' . self::DB . '/tables'   => 'tables',
			'/databases/' . self::DB . '/tables/' . self::TABLE . '/structure' => 'structure',
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);
		}
	}

	/**
	 * Health overview.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function overview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$database = $this->database( $request );

		if ( $database instanceof WP_Error ) {
			return $database;
		}

		return new WP_REST_Response( $this->health->overview( $database ) );
	}

	/**
	 * Table list with group, plugin and meta pair.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function tables( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$database = $this->database( $request );

		if ( $database instanceof WP_Error ) {
			return $database;
		}

		$pairs  = $this->detector->detect( $this->schema->column_map( $database ) );
		$metas  = array_flip( array_map( static fn( $pair ): string => $pair->meta_table, $pairs ) );
		$tables = array();

		foreach ( $this->schema->tables( $database ) as $table ) {
			$tables[] = $table + array(
				'group'   => $this->classifier->classify( $table['name'] )->value,
				'plugin'  => $this->classifier->plugin_of( $table['name'] ),
				'meta'    => isset( $pairs[ $table['name'] ] ) ? $pairs[ $table['name'] ]->meta_table : null,
				'is_meta' => isset( $metas[ $table['name'] ] ),
			);
		}

		return new WP_REST_Response(
			array(
				'database' => $database,
				'tables'   => $tables,
			)
		);
	}

	/**
	 * Columns, indexes (assessed) and DDL.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function structure( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target = $this->table( $request );

		if ( $target instanceof WP_Error ) {
			return $target;
		}

		[ $database, $table ] = $target;

		$indexes  = $this->schema->indexes( $database, $table );
		$assessed = $this->advisor->assess(
			array_map(
				static fn( array $index ): array => array(
					'name'    => $index['name'],
					'columns' => $index['columns'],
					'unique'  => $index['unique'],
				),
				$indexes
			)
		);

		foreach ( $assessed as $position => $index ) {
			$assessed[ $position ]['cardinality'] = $indexes[ $position ]['cardinality'];
		}

		return new WP_REST_Response(
			array(
				'database' => $database,
				'table'    => $table,
				'group'    => $this->classifier->classify( $table )->value,
				'columns'  => $this->schema->columns( $database, $table ),
				'indexes'  => $assessed,
				'create'   => $this->schema->create_statement( $database, $table ),
			)
		);
	}
}
