<?php
/**
 * The SQL console and the audit trail.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPDBase\Audit\AuditLog;
use WPDBase\Database\DatabaseRegistry;
use WPDBase\Database\SchemaRepository;
use WPDBase\Security\AccessGate;
use WPDBase\Sql\QueryRunner;

/**
 * `POST /databases/{db}/query` (with `plan_only` for the pre-flight) and `GET /audit`.
 */
final class QueryController extends BaseController {

	/**
	 * Constructor.
	 *
	 * @param AccessGate       $gate     Access gate.
	 * @param DatabaseRegistry $registry Database allow-list.
	 * @param SchemaRepository $schema   Schema reader.
	 * @param QueryRunner      $runner   Console runner.
	 * @param AuditLog         $audit    Audit log.
	 */
	public function __construct(
		AccessGate $gate,
		DatabaseRegistry $registry,
		SchemaRepository $schema,
		private readonly QueryRunner $runner,
		private readonly AuditLog $audit,
	) {
		parent::__construct( $gate, $registry, $schema );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/databases/' . self::DB . '/query',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'query' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'sql'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'plan_only' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'confirm'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'audit' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
				),
			)
		);
	}

	/**
	 * Plan or run a console script.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function query( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$database = $this->database( $request );

		if ( $database instanceof WP_Error ) {
			return $database;
		}

		$sql  = (string) $request->get_param( 'sql' );
		$mode = $this->gate->current_mode();

		if ( (bool) $request->get_param( 'plan_only' ) ) {
			return new WP_REST_Response( $this->runner->plan( $sql, $mode ) );
		}

		$connection = $this->registry->connection( $database );

		if ( null === $connection ) {
			return new WP_Error( 'wpdbase_connection', __( 'Could not connect to this database.', 'wp-dbase' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			$this->runner->run( $connection, $database, $sql, $mode, (string) $request->get_param( 'confirm' ) )
		);
	}

	/**
	 * Audit trail page.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function audit( WP_REST_Request $request ): WP_REST_Response {
		$per_page = 50;
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		return new WP_REST_Response(
			array(
				'page'    => $page,
				'entries' => $this->audit->recent( $per_page, ( $page - 1 ) * $per_page ),
			)
		);
	}
}
