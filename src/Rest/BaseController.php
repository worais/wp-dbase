<?php
/**
 * Shared plumbing for the plugin's REST controllers.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Rest;

use WP_Error;
use WP_REST_Request;
use WPDBase\Database\DatabaseRegistry;
use WPDBase\Database\SchemaRepository;
use WPDBase\Security\AccessGate;

/**
 * Permission check and argument validation every route shares.
 */
abstract class BaseController {

	public const NAMESPACE = 'wp-dbase/v1';

	/**
	 * Route pattern for a database name.
	 */
	protected const DB = '(?P<database>[A-Za-z0-9_$\-]{1,64})';

	/**
	 * Route pattern for a table name.
	 */
	protected const TABLE = '(?P<table>[A-Za-z0-9_$\-]{1,64})';

	/**
	 * Constructor.
	 *
	 * @param AccessGate       $gate     Access gate.
	 * @param DatabaseRegistry $registry Database allow-list.
	 * @param SchemaRepository $schema   Schema reader.
	 */
	public function __construct(
		protected readonly AccessGate $gate,
		protected readonly DatabaseRegistry $registry,
		protected readonly SchemaRepository $schema,
	) {
	}

	/**
	 * Register the controller's routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * `permission_callback` of every route.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission(): bool|WP_Error {
		if ( $this->gate->can_access() ) {
			return true;
		}

		return new WP_Error(
			'wpdbase_forbidden',
			__( 'You are not allowed to use WP DBase.', 'wp-dbase' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * The allow-listed database named in the route, or an error.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return string|WP_Error
	 */
	protected function database( WP_REST_Request $request ): string|WP_Error {
		$database = (string) $request->get_param( 'database' );

		if ( ! $this->registry->allows( $database ) ) {
			return new WP_Error( 'wpdbase_unknown_database', __( 'This database is not available.', 'wp-dbase' ), array( 'status' => 404 ) );
		}

		return $database;
	}

	/**
	 * The database and an existing table named in the route, or an error.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array{string, string}|WP_Error
	 */
	protected function table( WP_REST_Request $request ): array|WP_Error {
		$database = $this->database( $request );

		if ( $database instanceof WP_Error ) {
			return $database;
		}

		$table = (string) $request->get_param( 'table' );

		if ( ! $this->schema->has_table( $database, $table ) ) {
			return new WP_Error( 'wpdbase_unknown_table', __( 'This table does not exist.', 'wp-dbase' ), array( 'status' => 404 ) );
		}

		return array( $database, $table );
	}

	/**
	 * Error response for an invalid argument detected deeper down.
	 *
	 * @param string $message Message (already translated).
	 */
	protected function bad_request( string $message ): WP_Error {
		return new WP_Error( 'wpdbase_bad_request', $message, array( 'status' => 400 ) );
	}
}
