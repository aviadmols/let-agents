<?php

namespace Rega;

use Rega\Admin\ReportsPage;
use Rega\Admin\SettingsPage;
use Rega\Rest\Routes;
use Rega\Storefront\CallToAction;
use Rega\Storefront\OrderHistory;
use Rega\Storefront\OrderReporter;
use Rega\Storefront\Search;
use Rega\Storefront\Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress. Each part registers its own hooks; this class only
 * decides which parts run.
 */
final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( self::class, 'load_translations' ) );
		add_action( 'rest_api_init', array( Routes::class, 'register' ) );

		Widget::register();
		Search::register();
		CallToAction::register();
		OrderReporter::register();
		// Outside is_admin(): its pages are sent by Action Scheduler, which also runs from cron.
		OrderHistory::register();

		if ( is_admin() ) {
			SettingsPage::register();
			ReportsPage::register();
		}
	}

	public static function load_translations(): void {
		load_plugin_textdomain( 'rega', false, dirname( plugin_basename( REGA_FILE ) ) . '/languages' );
	}

	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}
}
