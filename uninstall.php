<?php
/**
 * Removes the plugin's own data: the audit table, its option and user preferences.
 *
 * @package WPDBase
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Audit/AuditLog.php';

global $wpdb;

( new \WPDBase\Audit\AuditLog( $wpdb ) )->uninstall();

delete_metadata( 'user', 0, 'wpdbase_safety_mode', '', true );
delete_metadata( 'user', 0, 'wpdbase_onboarded', '', true );
