<?php

namespace LetAgents\Storefront;

use LetAgents\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tells Let Agents an order was placed, so the reports can show purchases and which of them came
 * after using the widget.
 *
 * Sent: a keyed hash of the order ID, the total and currency, product IDs with quantities and
 * line totals, and the anonymous visitor ID the widget set in the let_agents_vid cookie.
 * Never sent: names, emails, phone numbers, addresses, payment details, notes or coupons.
 *
 * The request runs in the background (Action Scheduler), so checkout never waits for Let Agents.
 */
final class OrderReporter {

	public const ACTION = 'let_agents_report_order';

	private const MAX_ITEMS = 200;

	private const VISITOR_PATTERN = '/^anon-[A-Za-z0-9_-]{16,64}$/';

	public static function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'classic_checkout' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'block_checkout' ), 20, 1 );
		add_action( self::ACTION, array( self::class, 'send' ), 10, 1 );
	}

	/**
	 * @param int|string    $order_id
	 * @param array<mixed>  $posted_data
	 * @param \WC_Order|null $order
	 */
	public static function classic_checkout( $order_id, $posted_data = array(), $order = null ): void {
		self::queue( $order instanceof \WC_Order ? $order : wc_get_order( $order_id ) );
	}

	/**
	 * @param \WC_Order|mixed $order
	 */
	public static function block_checkout( $order ): void {
		self::queue( $order instanceof \WC_Order ? $order : null );
	}

	/**
	 * @param \WC_Order|false|null $order
	 */
	private static function queue( $order ): void {
		if ( ! $order instanceof \WC_Order || 'off' === Settings::widget_mode() || null === SiteKeys::site() ) {
			return;
		}

		$payload = self::payload( $order, self::visitor() );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION, array( $payload ), 'let-agents' );
			return;
		}

		LetAgentsApi::post( 'orders', $payload, false );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function send( $payload ): void {
		if ( is_array( $payload ) ) {
			LetAgentsApi::post( 'orders', $payload );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function payload( \WC_Order $order, ?string $visitor ): array {
		$items = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || count( $items ) >= self::MAX_ITEMS ) {
				continue;
			}

			$items[] = array(
				'product_id' => (string) $item->get_product_id(),
				'quantity'   => max( 1, (int) $item->get_quantity() ),
				'total'      => round( (float) $item->get_total(), 2 ),
			);
		}

		$created = $order->get_date_created();

		return array(
			'order_ref'  => self::ref( $order->get_id() ),
			'total'      => round( (float) $order->get_total(), 2 ),
			'currency'   => $order->get_currency(),
			'ordered_at' => $created ? $created->getTimestamp() : time(),
			'vid'        => null !== $visitor && preg_match( self::VISITOR_PATTERN, $visitor ) ? $visitor : null,
			'items'      => $items,
		);
	}

	/** The order as Let Agents knows it: a keyed hash, so the store's own order number never leaves it. */
	public static function ref( int $order_id ): string {
		return hash_hmac( 'sha256', 'order|' . $order_id, (string) SiteKeys::preview() );
	}

	/** The anonymous visitor ID the widget set, or null when there is none or it is not one. */
	public static function visitor(): ?string {
		$visitor = isset( $_COOKIE['let_agents_vid'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['let_agents_vid'] ) ) : '';

		return preg_match( self::VISITOR_PATTERN, $visitor ) ? $visitor : null;
	}
}
