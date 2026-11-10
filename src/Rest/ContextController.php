<?php
/**
 * Bootstrap data for the app: databases, mode, server.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPDBase\Config;
use WPDBase\Database\DatabaseRegistry;
use WPDBase\Database\SchemaRepository;
use WPDBase\Plugin;
use WPDBase\Security\AccessGate;
use WPDBase\Security\SafetyMode;

/**
 * `GET /context` and `PUT /safety-mode`.
 */
final class ContextController extends BaseController {

	/**
	 * Constructor.
	 *
	 * @param AccessGate       $gate     Access gate.
	 * @param DatabaseRegistry $registry Database allow-list.
	 * @param SchemaRepository $schema   Schema reader.
	 * @param Config           $config   Site configuration.
	 */
	public function __construct(
		AccessGate $gate,
		DatabaseRegistry $registry,
		SchemaRepository $schema,
		private readonly Config $config,
	) {
		parent::__construct( $gate, $registry, $schema );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/context',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'context' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/safety-mode',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'set_mode' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'mode' => array(
						'type'     => 'string',
						'enum'     => array_map( static fn( SafetyMode $mode ): string => $mode->value, SafetyMode::cases() ),
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Context payload.
	 */
	public function context(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'version'    => Plugin::VERSION,
				'databases'  => $this->registry->names(),
				'own'        => $this->registry->own(),
				'server'     => $this->schema->server_label(),
				'mode'       => $this->gate->current_mode()->value,
				'max_mode'   => $this->config->max_safety_mode()->value,
				'max_rows'   => $this->config->max_rows(),
				'capability' => $this->config->capability(),
				'prefix'     => $this->registry->prefix(),
				'timeout'    => $this->config->query_timeout(),
				'onboarded'  => (bool) get_user_meta( get_current_user_id(), 'wpdbase_onboarded', true ),
			)
		);
	}

	/**
	 * Switch safety mode.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public function set_mode( WP_REST_Request $request ): WP_REST_Response {
		$mode = $this->gate->set_mode( SafetyMode::parse( $request->get_param( 'mode' ) ) );
		update_user_meta( get_current_user_id(), 'wpdbase_onboarded', 1 );

		return new WP_REST_Response( array( 'mode' => $mode->value ) );
	}
}
