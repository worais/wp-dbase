<?php
/**
 * Tools → Database screen.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Admin;

use WPDBase\Config;
use WPDBase\Plugin;
use WPDBase\Rest\BaseController;
use WPDBase\Security\AccessGate;

/**
 * Registers the screen and enqueues the app. All data comes from the REST API.
 */
final class AdminPage {

	public const SLUG = 'wp-dbase';

	/**
	 * Constructor.
	 *
	 * @param AccessGate $gate   Access gate.
	 * @param Config     $config Site configuration.
	 * @param string     $file   Main plugin file (for asset URLs).
	 */
	public function __construct(
		private readonly AccessGate $gate,
		private readonly Config $config,
		private readonly string $file,
	) {
	}

	/**
	 * Add the Tools submenu, only for users who pass the gate.
	 */
	public function register(): void {
		if ( ! $this->gate->can_access() ) {
			return;
		}

		$hook = add_management_page(
			__( 'WP DBase', 'wp-dbase' ),
			__( 'Database', 'wp-dbase' ),
			$this->config->capability(),
			self::SLUG,
			array( $this, 'render' )
		);

		if ( false !== $hook ) {
			add_action(
				'admin_enqueue_scripts',
				function ( string $current ) use ( $hook ): void {
					if ( $current === $hook ) {
						$this->enqueue();
					}
				}
			);
		}
	}

	/**
	 * Enqueue the app and its configuration.
	 */
	public function enqueue(): void {
		$base = plugins_url( 'assets/', $this->file );

		wp_enqueue_style( 'wpdbase-app', $base . 'css/app.css', array(), $this->version( 'css/app.css' ) );
		wp_enqueue_script( 'wpdbase-app', $base . 'js/app.js', array( 'wp-api-fetch', 'wp-i18n' ), $this->version( 'js/app.js' ), true );
		wp_set_script_translations( 'wpdbase-app', 'wp-dbase', dirname( $this->file ) . '/languages' );

		wp_add_inline_script(
			'wpdbase-app',
			'window.wpdbase = ' . wp_json_encode(
				array(
					'namespace' => BaseController::NAMESPACE,
					'version'   => Plugin::VERSION,
					'user'      => wp_get_current_user()->user_login,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Asset version: plugin version plus file time, so a changed file is never served from cache.
	 *
	 * @param string $asset Path under assets/.
	 */
	private function version( string $asset ): string {
		$mtime = filemtime( dirname( $this->file ) . '/assets/' . $asset );

		return Plugin::VERSION . ( false === $mtime ? '' : '.' . $mtime );
	}

	/**
	 * Mount point; the app renders everything else.
	 */
	public function render(): void {
		if ( ! $this->gate->can_access() ) {
			wp_die( esc_html__( 'You are not allowed to use WP DBase.', 'wp-dbase' ), 403 );
		}

		echo '<div class="wrap wpdbase-wrap"><h1 class="screen-reader-text">' . esc_html__( 'WP DBase', 'wp-dbase' ) . '</h1>';
		echo '<div id="wpdbase-app" class="wpdbase" aria-live="polite"><p class="wpdbase-boot">' . esc_html__( 'Loading…', 'wp-dbase' ) . '</p></div>';
		echo '<noscript>' . esc_html__( 'WP DBase needs JavaScript.', 'wp-dbase' ) . '</noscript></div>';
	}
}
