<?php
/**
 * Plugin Name: Ayurveda Store Core
 * Description: Store-specific onboarding and provider coordination for Ashtanga Ayurveda.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * Text Domain: ayurveda-store-core
 */

defined( 'ABSPATH' ) || exit;

define( 'ASHTANGA_STORE_CORE_VERSION', '0.1.0' );
define( 'ASHTANGA_STORE_CORE_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Ashtanga\\Store\\Core\\';
		$base_dir = __DIR__ . '/src/';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WooCommerce' ) ) {
			( new Ashtanga\Store\Core\Plugin() )->boot();
		}
	},
	20
);