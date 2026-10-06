<?php
/**
 * Plugin Name:       Rega
 * Plugin URI:        https://github.com/aviadmols/Rega
 * Description:       Connects this WooCommerce store to Rega, the smart shopping assistant. Gives Rega read-only access to the catalog and content, shows the Rega widget on product pages and articles, and adds a reports page.
 * Version:           0.4.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Rega
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rega
 * Domain Path:       /languages
 * WC requires at least: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'REGA_VERSION', '0.4.0' );
define( 'REGA_FILE', __FILE__ );
define( 'REGA_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class ): void {
		if ( strncmp( $class, 'Rega\\', 5 ) !== 0 ) {
			return;
		}

		$path = REGA_DIR . '/src/' . str_replace( '\\', '/', substr( $class, 5 ) ) . '.php';

		if ( is_file( $path ) ) {
			require $path;
		}
	}
);

// Rega reads order totals only through the WC_Order API and never writes orders, so it is compatible with HPOS and block checkout.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', REGA_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', REGA_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( \Rega\Plugin::class, 'boot' ) );
