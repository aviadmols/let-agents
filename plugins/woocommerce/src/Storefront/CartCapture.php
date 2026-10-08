<?php

namespace LetAgents\Storefront;

use LetAgents\Plugin;
use LetAgents\Rest\CartRoutes;
use LetAgents\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the Let Agents cart script on every page of the store, except the thank-you page.
 *
 * Before checkout the script can ask the shopper for their email and save the cart as an order
 * waiting for payment (CartRoutes, PendingOrder). Whether it asks at all is decided per store in
 * Let Agents, so the script comes from the Let Agents server and does nothing when the shop has
 * not turned it on.
 */
final class CartCapture {

	public const HANDLE = 'let-agents-cart';

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
			$context['api'] . '/cart/let-agents-cart.js',
			array(),
			null, // The server versions the file itself.
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script( self::HANDLE, 'window.LetAgentsCartContext = ' . wp_json_encode( $context ) . ';', 'before' );
	}

	/**
	 * What the script needs, or null when it should not load.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function context(): ?array {
		$site = SiteKeys::site();

		if ( null === $site || is_admin() || ! Plugin::woocommerce_active() || is_wc_endpoint_url( 'order-received' ) ) {
			return null;
		}

		return array(
			'site'            => $site,
			'api'             => Settings::api_url(),
			'capture'         => rest_url( CartRoutes::path() ),
			'captureFallback' => home_url( '/?rest_route=/' . CartRoutes::path() ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'checkoutUrl'     => wc_get_checkout_url(),
			'cartCount'       => WC()->cart ? WC()->cart->get_cart_contents_count() : 0,
			'locale'          => get_locale(),
		);
	}
}
