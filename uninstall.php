<?php
/**
 * Removes the plugin's own data: the audit table, its option and user preferences.
 *
 * An audit table moved elsewhere with the `wpdbase_audit_table` filter is left alone.
 *
 * @package WPDBase
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Database/Identifier.php';
require_once __DIR__ . '/src/Database/Query.php';
require_once __DIR__ . '/src/Audit/AuditTarget.php';
require_once __DIR__ . '/src/Audit/AuditLog.php';

global $wpdb;

( new \WPDBase\Audit\AuditLog( $wpdb ) )->uninstall();

delete_metadata( 'user', 0, 'wpdbase_safety_mode', '', true );
delete_metadata( 'user', 0, 'wpdbase_onboarded', '', true );
