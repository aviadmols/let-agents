<?php

namespace LetAgents;

use LetAgents\Admin\PanelPage;
use LetAgents\Admin\ReportsPage;
use LetAgents\Admin\SettingsPage;
use LetAgents\Rest\PostRoutes;
use LetAgents\Rest\Routes;
use LetAgents\Storefront\AgentDiscovery;
use LetAgents\Storefront\CallToAction;
use LetAgents\Storefront\OrderHistory;
use LetAgents\Storefront\OrderReporter;
use LetAgents\Storefront\PostSchema;
use LetAgents\Storefront\Search;
use LetAgents\Storefront\WishlistHeart;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress. Each part registers its own hooks; this class only
 * decides which parts run.
 */
final class Plugin {

	public static function boot(): void {
		Legacy::migrate();
		// 0.9.0: the widget's and the search's modes become the one mode of Lets Search.
		Settings::migrate();
		add_action( 'init', array( self::class, 'load_translations' ) );
		add_action( 'init', array( self::class, 'forget_cart_capture' ) );
		add_action( 'rest_api_init', array( Routes::class, 'register' ) );
		// The one write route: drafts of the posts the store team publishes in Let Agents.
		add_action( 'rest_api_init', array( PostRoutes::class, 'register' ) );

		// Lets Search, the one storefront script. The page it runs on travels in its context.
		Search::register();
		// [lets_cta] in old posts prints nothing now.
		CallToAction::register();
		WishlistHeart::register();
		OrderReporter::register();
		PostSchema::register();
		// An AI agent at the store's address finds its catalogue and MCP server without a link.
		AgentDiscovery::register();
		// Outside is_admin(): its pages are sent by Action Scheduler, which also runs from cron.
		OrderHistory::register();

		if ( is_admin() ) {
			SettingsPage::register();
			ReportsPage::register();
			// "Lets Search" in the menu: the shop's Lets panel inside WordPress, signed in by the token.
			PanelPage::register();
		}
	}

	/**
	 * The email before checkout is gone since 0.7.2: cart reports still queued from it are dropped
	 * once. Orders it saved stay in the store as they are.
	 */
	public static function forget_cart_capture(): void {
		if ( get_option( 'let_agents_cart_capture_removed' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( 'let_agents_report_cart' );
		update_option( 'let_agents_cart_capture_removed', gmdate( 'c' ), false );
	}

	public static function load_translations(): void {
		load_plugin_textdomain( 'let-agents', false, dirname( plugin_basename( LET_AGENTS_FILE ) ) . '/languages' );
	}

	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}
}
