<?php
/**
 * Composition root.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase;

use WPDBase\Admin\AdminPage;
use WPDBase\Audit\AuditLog;
use WPDBase\Database\DatabaseRegistry;
use WPDBase\Database\HealthRepository;
use WPDBase\Database\IndexAdvisor;
use WPDBase\Database\RowRepository;
use WPDBase\Database\SchemaRepository;
use WPDBase\Database\TableClassifier;
use WPDBase\ObjectView\MetaTableDetector;
use WPDBase\ObjectView\ObjectAssembler;
use WPDBase\ObjectView\TitleGuesser;
use WPDBase\ObjectView\ValueTree;
use WPDBase\ObjectView\ValueTypeDetector;
use WPDBase\Rest\BaseController;
use WPDBase\Rest\ContextController;
use WPDBase\Rest\QueryController;
use WPDBase\Rest\RowsController;
use WPDBase\Rest\SchemaController;
use WPDBase\Security\AccessGate;
use WPDBase\Sql\QueryRunner;
use WPDBase\Sql\StatementClassifier;
use WPDBase\Sql\StatementSplitter;
use wpdb;

/**
 * Builds the object graph and wires WordPress hooks. Nothing else registers hooks.
 */
final class Plugin {

	public const VERSION = '0.1.0';

	/**
	 * Singleton guard so a must-use loader and a regular activation never boot twice.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Boot the plugin.
	 *
	 * @param string $file Absolute path of the main plugin file.
	 */
	public static function boot( string $file ): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'admin_menu', static fn() => self::admin_page( $file )->register() );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}


	/**
	 * Register every REST controller.
	 */
	public static function register_routes(): void {
		foreach ( self::controllers() as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * The admin page service.
	 *
	 * @param string $file Main plugin file.
	 */
	private static function admin_page( string $file ): AdminPage {
		$config = new Config();

		return new AdminPage( new AccessGate( $config ), $config, $file );
	}

	/**
	 * Build the REST controllers and their dependencies.
	 *
	 * @return list<BaseController>
	 */
	private static function controllers(): array {
		global $wpdb;
		assert( $wpdb instanceof wpdb );

		$prefix     = (string) $wpdb->prefix;
		$config     = new Config();
		$gate       = new AccessGate( $config );
		$registry   = new DatabaseRegistry( $config, $wpdb );
		$schema     = new SchemaRepository( $wpdb );
		$rows       = new RowRepository( $wpdb, $schema );
		$audit      = new AuditLog( $wpdb );
		$detector   = new MetaTableDetector();
		$classifier = new TableClassifier( $prefix, self::known_plugin_tables() );

		$assembler = new ObjectAssembler(
			$wpdb,
			$schema,
			$rows,
			$detector,
			new ValueTypeDetector(),
			new ValueTree(),
			new TitleGuesser(),
			$prefix
		);

		$runner = new QueryRunner(
			new StatementSplitter(),
			new StatementClassifier(),
			$audit,
			$config->query_timeout(),
			$config->max_rows(),
			str_contains( strtolower( (string) $wpdb->db_server_info() ), 'mariadb' )
		);

		return array(
			new ContextController( $gate, $registry, $schema, $config ),
			new SchemaController( $gate, $registry, $schema, new HealthRepository( $wpdb, $schema, $classifier, $prefix ), $classifier, new IndexAdvisor(), $detector ),
			new RowsController( $gate, $registry, $schema, $rows, $assembler, $audit ),
			new QueryController( $gate, $registry, $schema, $runner, $audit ),
		);
	}

	/**
	 * Plugin table prefixes, extensible by filter.
	 *
	 * @return array<string, string>
	 */
	private static function known_plugin_tables(): array {
		/**
		 * Filters the table prefixes (after the site prefix) recognised as plugin tables.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string> $plugins Table prefix => plugin name, e.g. `'wc_' => 'WooCommerce'`.
		 */
		$plugins = apply_filters( 'wpdbase_known_plugin_tables', TableClassifier::KNOWN_PLUGINS );

		return is_array( $plugins ) ? array_map( 'strval', $plugins ) : TableClassifier::KNOWN_PLUGINS;
	}
}
