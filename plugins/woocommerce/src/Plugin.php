<?php

namespace LetAgents;

use LetAgents\Admin\ReportsPage;
use LetAgents\Admin\SettingsPage;
use LetAgents\Rest\CartRoutes;
use LetAgents\Rest\Routes;
use LetAgents\Storefront\CallToAction;
use LetAgents\Storefront\CartCapture;
use LetAgents\Storefront\OrderHistory;
use LetAgents\Storefront\OrderReporter;
use LetAgents\Storefront\PendingOrder;
use LetAgents\Storefront\Search;
use LetAgents\Storefront\Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress. Each part registers its own hooks; this class only
 * decides which parts run.
 */
final class Plugin {

	public static function boot(): void {
		Legacy::migrate();
		add_action( 'init', array( self::class, 'load_translations' ) );
		add_action( 'rest_api_init', array( Routes::class, 'register' ) );
		add_action( 'rest_api_init', array( CartRoutes::class, 'register' ) );

		Widget::register();
		Search::register();
		CallToAction::register();
		CartCapture::register();
		OrderReporter::register();
		PendingOrder::register();
		// Outside is_admin(): its pages are sent by Action Scheduler, which also runs from cron.
		OrderHistory::register();

		if ( is_admin() ) {
			SettingsPage::register();
			ReportsPage::register();
		}
	}

	public static function load_translations(): void {
		load_plugin_textdomain( 'let-agents', false, dirname( plugin_basename( LET_AGENTS_FILE ) ) . '/languages' );
	}

	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}
}
