<?php
/**
 * Which databases can be opened, and connections to them.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Database;

use WPDBase\Config;
use wpdb;

/**
 * Resolves the allow-list of databases and hands out connections.
 *
 * Every connection uses the wp-config.php credentials: the plugin never accepts
 * a host, user or password from the browser.
 */
final class DatabaseRegistry {

	/**
	 * Schemas that are never listed.
	 */
	private const SYSTEM_SCHEMAS = array( 'information_schema', 'mysql', 'performance_schema', 'sys' );

	/**
	 * Cached allow-list.
	 *
	 * @var list<string>|null
	 */
	private ?array $names = null;

	/**
	 * Open connections by database name.
	 *
	 * @var array<string, wpdb>
	 */
	private array $connections = array();

	/**
	 * Constructor.
	 *
	 * @param Config $config Site configuration.
	 * @param wpdb   $wpdb   The site connection.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly wpdb $wpdb,
	) {
	}

	/**
	 * The site's own database name.
	 */
	public function own(): string {
		return (string) DB_NAME;
	}

	/**
	 * Table prefix of the site (assumed for every listed database).
	 */
	public function prefix(): string {
		return (string) $this->wpdb->prefix;
	}

	/**
	 * Every database the current request may open, the site's own one first.
	 *
	 * @return list<string>
	 */
	public function names(): array {
		if ( null !== $this->names ) {
			return $this->names;
		}

		$extra   = $this->config->extra_databases();
		$visible = '*' === $extra ? $this->visible_databases() : $extra;

		if ( '*' !== $extra ) {
			// An explicit list must still exist on the server.
			$visible = array_values( array_intersect( $extra, $this->visible_databases() ) );
		}

		sort( $visible );
		$names = array_values( array_unique( array_merge( array( $this->own() ), $visible ) ) );

		$this->names = array_values(
			array_filter( $names, static fn( string $name ): bool => Identifier::is_valid( $name ) )
		);

		return $this->names;
	}

	/**
	 * Whether a database is in the allow-list.
	 *
	 * @param string $name Database name.
	 */
	public function allows( string $name ): bool {
		return in_array( $name, $this->names(), true );
	}

	/**
	 * Connection to an allowed database, or null.
	 *
	 * The site's own database reuses the global connection; others get a
	 * dedicated `wpdb` instance, so `$wpdb` is never switched to another schema.
	 *
	 * @param string $name Database name.
	 */
	public function connection( string $name ): ?wpdb {
		if ( ! $this->allows( $name ) ) {
			return null;
		}

		if ( $name === $this->own() ) {
			return $this->wpdb;
		}

		if ( ! isset( $this->connections[ $name ] ) ) {
			$connection = $this->open( $name );

			if ( null === $connection ) {
				return null;
			}

			$this->connections[ $name ] = $connection;
		}

		return $this->connections[ $name ];
	}

	/**
	 * Open a dedicated connection and select the database.
	 *
	 * It first connects to the site's own database (known to work, so wpdb never
	 * dies on a connection error) and then switches with select(), which only
	 * reports failure when errors are hidden. The same class as `$wpdb` is used,
	 * so a `db.php` drop-in keeps applying.
	 *
	 * @param string $name Database name.
	 */
	private function open( string $name ): ?wpdb {
		$class      = get_class( $this->wpdb );
		$connection = new $class( (string) DB_USER, (string) DB_PASSWORD, $this->own(), (string) DB_HOST );

		$connection->show_errors( false );
		$connection->suppress_errors( true );
		$connection->select( $name );

		return $connection->ready ? $connection : null;
	}

	/**
	 * Databases the MySQL user can see, minus system schemas.
	 *
	 * @return list<string>
	 */
	private function visible_databases(): array {
		$rows = $this->wpdb->get_col( 'SHOW DATABASES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- listing schemas has no WP API.

		$names = array_map( 'strval', is_array( $rows ) ? $rows : array() );

		return array_values( array_diff( $names, self::SYSTEM_SCHEMAS ) );
	}
}
