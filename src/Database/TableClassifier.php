<?php
/**
 * Sorts tables into core, plugin, custom and foreign groups.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Database;

/**
 * Pure classifier based on the table prefix and well-known table names.
 */
final class TableClassifier {

	/**
	 * Core tables of a single site (and of each multisite blog).
	 */
	private const CORE = array(
		'commentmeta',
		'comments',
		'links',
		'options',
		'postmeta',
		'posts',
		'termmeta',
		'terms',
		'term_relationships',
		'term_taxonomy',
		'usermeta',
		'users',
	);

	/**
	 * Network-wide multisite tables (only with the base prefix).
	 */
	private const CORE_NETWORK = array(
		'blogmeta',
		'blogs',
		'registration_log',
		'signups',
		'site',
		'sitemeta',
	);

	/**
	 * Table-name prefixes (after the site prefix) of popular plugins.
	 *
	 * The list is extensible through the `wpdbase_known_plugin_tables` filter.
	 */
	public const KNOWN_PLUGINS = array(
		'actionscheduler_' => 'Action Scheduler',
		'wc_'              => 'WooCommerce',
		'woocommerce_'     => 'WooCommerce',
		'yoast_'           => 'Yoast SEO',
		'wpforms_'         => 'WPForms',
		'gf_'              => 'Gravity Forms',
		'rg_'              => 'Gravity Forms',
		'redirection_'     => 'Redirection',
		'litespeed_'       => 'LiteSpeed Cache',
		'wpmailsmtp_'      => 'WP Mail SMTP',
		'e_'               => 'Elementor',
		'wpdbase_'         => 'WP DBase',
	);

	/**
	 * Constructor.
	 *
	 * @param string                $prefix  The site table prefix (`$wpdb->prefix`).
	 * @param array<string, string> $plugins Plugin table prefixes => plugin name.
	 */
	public function __construct(
		private readonly string $prefix,
		private readonly array $plugins = self::KNOWN_PLUGINS,
	) {
	}

	/**
	 * Group of a table.
	 *
	 * @param string $table Table name.
	 */
	public function classify( string $table ): TableGroup {
		if ( ! str_starts_with( $table, $this->prefix ) ) {
			return TableGroup::Foreign;
		}

		$bare = substr( $table, strlen( $this->prefix ) );

		// Multisite blog tables: wp_2_posts.
		$blog_bare = preg_replace( '/^\d+_/', '', $bare ) ?? $bare;

		if ( in_array( $bare, self::CORE_NETWORK, true ) || in_array( $blog_bare, self::CORE, true ) ) {
			return TableGroup::Core;
		}

		return null === $this->plugin_of( $table ) ? TableGroup::Custom : TableGroup::Plugin;
	}

	/**
	 * Name of the plugin a table belongs to, if recognised.
	 *
	 * @param string $table Table name.
	 */
	public function plugin_of( string $table ): ?string {
		if ( ! str_starts_with( $table, $this->prefix ) ) {
			return null;
		}

		$bare = substr( $table, strlen( $this->prefix ) );

		foreach ( $this->plugins as $table_prefix => $plugin ) {
			if ( str_starts_with( $bare, $table_prefix ) ) {
				return $plugin;
			}
		}

		return null;
	}
}
