<?php
/**
 * Site-level configuration read from wp-config.php constants and filters.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase;

use WPDBase\Security\SafetyMode;

/**
 * Infrastructure decisions belong to whoever runs the server, so they are
 * constants (set in wp-config.php) refined by filters (set in code).
 *
 * | Constant                   | Default            | Meaning                                       |
 * |----------------------------|--------------------|-----------------------------------------------|
 * | WPDBASE_DATABASES          | (only DB_NAME)     | Extra databases: list of names, or '*' (all)  |
 * | WPDBASE_CAPABILITY         | 'manage_options'   | Capability required to open the plugin        |
 * | WPDBASE_MAX_SAFETY_MODE    | 'expert'           | Most permissive mode anyone may switch to     |
 * | WPDBASE_QUERY_TIMEOUT      | 20                 | Seconds before a console query is aborted     |
 * | WPDBASE_MAX_ROWS           | 1000               | Rows returned per console statement           |
 */
final class Config {

	public const DEFAULT_CAPABILITY = 'manage_options';

	public const DEFAULT_TIMEOUT = 20;

	public const DEFAULT_MAX_ROWS = 1000;

	/**
	 * Databases setting: `'*'` for every database the DB user can see, or a list of names.
	 *
	 * The site's own database (DB_NAME) is always available and is not part of this value.
	 *
	 * @return '*'|list<string>
	 */
	public function extra_databases(): string|array {
		$raw = defined( 'WPDBASE_DATABASES' ) ? constant( 'WPDBASE_DATABASES' ) : array();

		/**
		 * Filters which databases besides the site's own one can be opened.
		 *
		 * Return `'*'` for every database the MySQL user can see, or a list of names.
		 * The connection always uses the credentials of wp-config.php.
		 *
		 * @since 0.1.0
		 *
		 * @param string|string[] $raw Value of WPDBASE_DATABASES (empty array when undefined).
		 */
		$raw = apply_filters( 'wpdbase_databases', $raw );

		if ( '*' === $raw ) {
			return '*';
		}

		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$names = array_map( static fn( $name ): string => trim( (string) $name ), $raw );

		return array_values( array_unique( array_filter( $names, static fn( string $name ): bool => '' !== $name ) ) );
	}

	/**
	 * Capability required to use the plugin at all.
	 */
	public function capability(): string {
		$capability = defined( 'WPDBASE_CAPABILITY' ) ? (string) constant( 'WPDBASE_CAPABILITY' ) : self::DEFAULT_CAPABILITY;

		/**
		 * Filters the capability required to open WP DBase.
		 *
		 * @since 0.1.0
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'wpdbase_capability', $capability );
	}

	/**
	 * Most permissive safety mode users may switch to on this site.
	 */
	public function max_safety_mode(): SafetyMode {
		$raw = defined( 'WPDBASE_MAX_SAFETY_MODE' ) ? constant( 'WPDBASE_MAX_SAFETY_MODE' ) : SafetyMode::Expert->value;

		/**
		 * Filters the most permissive safety mode allowed on this site.
		 *
		 * @since 0.1.0
		 *
		 * @param string $mode One of 'read', 'assisted', 'expert'.
		 */
		return SafetyMode::parse( apply_filters( 'wpdbase_max_safety_mode', $raw ) );
	}

	/**
	 * Seconds a console statement may run.
	 */
	public function query_timeout(): int {
		$seconds = defined( 'WPDBASE_QUERY_TIMEOUT' ) ? (int) constant( 'WPDBASE_QUERY_TIMEOUT' ) : self::DEFAULT_TIMEOUT;

		return max( 1, $seconds );
	}

	/**
	 * Maximum rows returned for one console statement.
	 */
	public function max_rows(): int {
		$rows = defined( 'WPDBASE_MAX_ROWS' ) ? (int) constant( 'WPDBASE_MAX_ROWS' ) : self::DEFAULT_MAX_ROWS;

		return max( 1, $rows );
	}
}
