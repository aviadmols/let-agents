<?php
/**
 * Plugin Name:       Let Agents
 * Plugin URI:        https://github.com/aviadmols/let-agents
 * Description:       Connects this WooCommerce store to Let Agents, the smart shopping assistant. Gives Let Agents read-only access to the catalog and content, shows the Let Agents widget on product pages and articles, adds Let Agents search to the store's search box, can save a shopper's cart as an order waiting for payment when they leave their email before checkout, and adds a reports page.
 * Version:           0.7.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Let Agents
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       let-agents
 * Domain Path:       /languages
 * WC requires at least: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'LET_AGENTS_VERSION', '0.7.0' );
define( 'LET_AGENTS_FILE', __FILE__ );
define( 'LET_AGENTS_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class ): void {
		if ( strncmp( $class, 'LetAgents\\', 10 ) !== 0 ) {
			return;
		}

		$path = LET_AGENTS_DIR . '/src/' . str_replace( '\\', '/', substr( $class, 10 ) ) . '.php';

		if ( is_file( $path ) ) {
			require $path;
		}
	}
);

// Let Agents reads and writes orders only through the WC_Order API (it writes one kind: the pending payment order a shopper asks for
// by leaving their email), so it is compatible with HPOS and block checkout.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LET_AGENTS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', LET_AGENTS_FILE, true );
		}
	}
);

// A store that ran the plugin under its old name keeps its token and settings.
register_activation_hook( __FILE__, array( \LetAgents\Legacy::class, 'migrate' ) );

add_action( 'plugins_loaded', array( \LetAgents\Plugin::class, 'boot' ) );
