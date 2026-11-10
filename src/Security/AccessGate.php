<?php
/**
 * Who may use the plugin, and in which safety mode.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Security;

use WPDBase\Config;

/**
 * Single place that answers "can the current user do this?".
 */
final class AccessGate {

	public const MODE_META_KEY = 'wpdbase_safety_mode';

	/**
	 * Constructor.
	 *
	 * @param Config $config Site configuration.
	 */
	public function __construct( private readonly Config $config ) {
	}

	/**
	 * Whether the current user may open the plugin.
	 */
	public function can_access(): bool {
		$allowed = is_user_logged_in() && current_user_can( $this->config->capability() );

		/**
		 * Filters whether the current user may use WP DBase.
		 *
		 * Runs after the capability check, so it can only be used to narrow
		 * access further (or to widen it, if you really mean to).
		 *
		 * @since 0.1.0
		 *
		 * @param bool $allowed Result of the capability check.
		 * @param int  $user_id Current user ID.
		 */
		return (bool) apply_filters( 'wpdbase_user_can_access', $allowed, get_current_user_id() );
	}

	/**
	 * Mode the current user works in, capped to the site's maximum.
	 */
	public function current_mode(): SafetyMode {
		$stored = get_user_meta( get_current_user_id(), self::MODE_META_KEY, true );

		return SafetyMode::parse( $stored )->cap_to( $this->config->max_safety_mode() );
	}

	/**
	 * Switch the current user's mode; returns the mode actually stored.
	 *
	 * @param SafetyMode $mode Requested mode.
	 */
	public function set_mode( SafetyMode $mode ): SafetyMode {
		$mode = $mode->cap_to( $this->config->max_safety_mode() );
		update_user_meta( get_current_user_id(), self::MODE_META_KEY, $mode->value );

		return $mode;
	}
}
