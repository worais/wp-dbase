<?php
/**
 * Grid pages, the Object View and guided row edits.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Rest;

use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPDBase\Audit\AuditEntry;
use WPDBase\Audit\AuditLog;
use WPDBase\Database\DatabaseRegistry;
use WPDBase\Database\RowRepository;
use WPDBase\Database\SchemaRepository;
use WPDBase\ObjectView\ObjectAssembler;
use WPDBase\Security\AccessGate;

/**
 * `GET /rows`, `GET /objects/{id}`, `PATCH|DELETE /rows/{id}`.
 */
final class RowsController extends BaseController {

	/**
	 * Constructor.
	 *
	 * @param AccessGate       $gate      Access gate.
	 * @param DatabaseRegistry $registry  Database allow-list.
	 * @param SchemaRepository $schema    Schema reader.
	 * @param RowRepository    $rows      Row repository.
	 * @param ObjectAssembler  $assembler Object View builder.
	 * @param AuditLog         $audit     Audit log.
	 */
	public function __construct(
		AccessGate $gate,
		DatabaseRegistry $registry,
		SchemaRepository $schema,
		private readonly RowRepository $rows,
		private readonly ObjectAssembler $assembler,
		private readonly AuditLog $audit,
	) {
		parent::__construct( $gate, $registry, $schema );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$base = '/databases/' . self::DB . '/tables/' . self::TABLE;

		register_rest_route(
			self::NAMESPACE,
			$base . '/rows',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'page' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 500,
						'default' => 50,
					),
					'orderby'  => array(
						'type'    => 'string',
						'pattern' => '^[A-Za-z0-9_$\-]{1,64}$',
					),
					'order'    => array(
						'type'    => 'string',
						'enum'    => array( 'asc', 'desc' ),
						'default' => 'asc',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$base . '/objects/(?P<id>[^/]{1,191})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'object' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			$base . '/rows/(?P<id>[^/]{1,191})',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'changes' => array(
							'type'     => 'object',
							'required' => true,
						),
						'dry_run' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'confirm' => array(
							'type'    => 'string',
							'default' => '',
						),
						'dry_run' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * One grid page.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function page( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target = $this->table( $request );

		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$order_by = $request->get_param( 'orderby' );

		try {
			$page = $this->rows->page(
				$target[0],
				$target[1],
				(int) $request->get_param( 'page' ),
				(int) $request->get_param( 'per_page' ),
				is_string( $order_by ) && '' !== $order_by ? $order_by : null,
				'desc' === $request->get_param( 'order' )
			);
		} catch ( InvalidArgumentException $e ) {
			return $this->bad_request( __( 'Unknown column.', 'wp-dbase' ) );
		}

		return new WP_REST_Response( $page );
	}

	/**
	 * Object View payload.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function object( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target = $this->table( $request );

		if ( $target instanceof WP_Error ) {
			return $target;
		}

		try {
			$object = $this->assembler->assemble( $target[0], $target[1], (string) $request->get_param( 'id' ) );
		} catch ( InvalidArgumentException $e ) {
			return $this->bad_request( __( 'The Object View needs a table with a single-column primary key.', 'wp-dbase' ) );
		}

		if ( null === $object ) {
			return new WP_Error( 'wpdbase_not_found', __( 'Row not found.', 'wp-dbase' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $object );
	}

	/**
	 * Inline edit of one row (assisted mode or above).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target = $this->writable_table( $request );

		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$changes = array();
		foreach ( (array) $request->get_param( 'changes' ) as $column => $value ) {
			$changes[ (string) $column ] = null === $value ? null : (string) $value;
		}

		try {
			$sql = $this->rows->update_sql( $target[0], $target[1], (string) $request->get_param( 'id' ), $changes );
		} catch ( InvalidArgumentException $e ) {
			return $this->bad_request( __( 'These changes cannot be applied to this table.', 'wp-dbase' ) );
		}

		return $this->apply( $target[0], 'row-update', $sql, (bool) $request->get_param( 'dry_run' ) );
	}

	/**
	 * Delete one row (assisted mode or above, typed confirmation).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target = $this->writable_table( $request );

		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$id = (string) $request->get_param( 'id' );

		try {
			$sql = $this->rows->delete_sql( $target[0], $target[1], $id );
		} catch ( InvalidArgumentException $e ) {
			return $this->bad_request( __( 'Rows of this table cannot be deleted inline.', 'wp-dbase' ) );
		}

		$dry_run = (bool) $request->get_param( 'dry_run' );

		if ( ! $dry_run && $request->get_param( 'confirm' ) !== $target[1] ) {
			return new WP_Error( 'wpdbase_confirm', __( 'Type the table name to confirm.', 'wp-dbase' ), array( 'status' => 400 ) );
		}

		return $this->apply( $target[0], 'row-delete', $sql, $dry_run );
	}

	/**
	 * The target table, if the current mode allows row edits.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array{string, string}|WP_Error
	 */
	private function writable_table( WP_REST_Request $request ): array|WP_Error {
		if ( ! $this->gate->current_mode()->allows_row_edits() ) {
			return new WP_Error( 'wpdbase_read_only', __( 'Switch to Assisted or Expert mode to edit rows.', 'wp-dbase' ), array( 'status' => 403 ) );
		}

		return $this->table( $request );
	}

	/**
	 * Return the SQL (dry run) or run and audit it.
	 *
	 * @param string $database Database.
	 * @param string $kind     Audit kind.
	 * @param string $sql      Prepared statement.
	 * @param bool   $dry_run  Only return the SQL.
	 */
	private function apply( string $database, string $kind, string $sql, bool $dry_run ): WP_REST_Response|WP_Error {
		if ( $dry_run ) {
			return new WP_REST_Response(
				array(
					'dry_run' => true,
					'sql'     => $sql,
				)
			);
		}

		$start    = hrtime( true );
		$affected = $this->rows->execute( $sql );
		$error    = null === $affected ? $this->rows->last_error() : null;

		$this->audit->record(
			new AuditEntry(
				$database,
				$this->gate->current_mode()->value,
				$kind,
				$sql,
				$affected,
				(int) round( ( hrtime( true ) - $start ) / 1e6 ),
				$error
			)
		);

		if ( null !== $error ) {
			return new WP_Error( 'wpdbase_sql_error', $error, array( 'status' => 400 ) );
		}

		return new WP_REST_Response(
			array(
				'dry_run'  => false,
				'sql'      => $sql,
				'affected' => $affected,
			)
		);
	}
}
