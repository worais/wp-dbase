<?php
/**
 * PHPUnit bootstrap: Composer autoload only — unit tests never load WordPress.
 *
 * @package WPDBase\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}
