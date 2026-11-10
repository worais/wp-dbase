<?php
/**
 * Plugin Name:       WP DBase
 * Plugin URI:        https://github.com/worais/wp-dbase
 * Description:       A database manager that speaks WordPress: Object View, SQL console with safety modes, schema and health insight — inside wp-admin.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Morais Junior
 * Author URI:        https://github.com/worais
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-dbase
 * Domain Path:       /languages
 *
 * @package WPDBase
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( defined( 'WPDBASE_FILE' ) ) {
	// Already loaded (e.g. by a must-use loader and as a regular plugin).
	return;
}

define( 'WPDBASE_FILE', __FILE__ );

// PSR-4 autoloader for WPDBase\ → src/ (no Composer needed at runtime).
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'WPDBase\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

\WPDBase\Plugin::boot( __FILE__ );
