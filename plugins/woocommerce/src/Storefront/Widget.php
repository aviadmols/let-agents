<?php

namespace LetAgents\Storefront;

use LetAgents\Plugin;
use LetAgents\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the Let Agents widget on product pages and on the content types shared with Let Agents.
 *
 * The script comes from the Let Agents server, so it improves without plugin updates. Where it shows
 * on the page (a CSS selector, before or after) is set per store in Let Agents.
 *
 *   off       nothing is loaded
 *   preview   the widget shows only to the store team: managers logged in to WordPress, or
 *             a browser that opened a link with ?let_agents_preview=<key>. Other visitors count as
 *             page views only.
 *   live      everyone sees it
 */
final class Widget {

	public const HANDLE = 'let-agents-widget';

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue(): void {
		$context = self::context();

		if ( null === $context ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			$context['script'],
			array(),
			null, // The server versions the file itself.
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script( self::HANDLE, 'window.LetAgentsContext = ' . wp_json_encode( $context ) . ';', 'before' );
	}

	/**
	 * What the widget needs to know about the page, or null when it should not load.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function context(): ?array {
		$mode = Settings::widget_mode();
		$site = SiteKeys::site();

		if ( 'off' === $mode || null === $site || is_admin() ) {
			return null;
		}

		$page = self::page();

		if ( null === $page ) {
			return null;
		}

		$api        = Settings::api_url();
		$is_team    = current_user_can( 'manage_woocommerce' );
		$woocommerce = Plugin::woocommerce_active();

		return array(
			'site'     => $site,
			'api'      => $api,
			'script'   => $api . '/widget/let-agents.js',
			'mode'     => $mode,
			// Only for the logged-in team; everyone else needs a preview link.
			'preview'  => 'preview' === $mode && $is_team ? SiteKeys::preview() : null,
			'page'     => $page,
			'locale'   => substr( determine_locale(), 0, 2 ),
			'storeApi' => $woocommerce ? rest_url( 'wc/store/v1/' ) : null,
			'nonce'    => $woocommerce ? wp_create_nonce( 'wc_store_api' ) : null,
			'cartUrl'  => $woocommerce ? wc_get_cart_url() : null,
			'version'  => LET_AGENTS_VERSION,
		);
	}

	/**
	 * @return array{type: string, id: string}|null
	 */
	private static function page(): ?array {
		if ( Plugin::woocommerce_active() && function_exists( 'is_product' ) && is_product() ) {
			return array(
				'type' => 'product',
				'id'   => (string) get_queried_object_id(),
			);
		}

		$types = Settings::content_post_types();

		if ( array() !== $types && is_singular( $types ) ) {
			return array(
				'type' => 'content',
				'id'   => (string) get_queried_object_id(),
			);
		}

		return null;
	}
}
