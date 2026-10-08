<?php

namespace LetAgents\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * The one thing this plugin writes to the store: a pending payment order made from the cart, when a
 * shopper leaves their email before checkout and the shop has that turned on in Let Agents.
 *
 * The shopper's session remembers the order, so leaving the email again updates the same order
 * instead of adding another. Classic checkout picks the order up and finishes it (WooCommerce
 * resumes the order waiting for payment while the cart has not changed). Block checkout always makes
 * its own order, so once an order is placed, the one left behind goes to the trash.
 *
 * Never sends the shopper an email, never changes stock, never touches payment. Let Agents is told
 * in the background, so the shopper never waits for it.
 */
final class PendingOrder {

	public const ACTION = 'let_agents_report_cart';

	/** Session key: the order this shopper's email made. */
	public const SESSION_KEY = 'let_agents_pending_order';

	/** Marks an order made here, so nothing else is ever updated or trashed by mistake. */
	public const META = '_let_agents_capture';

	public const CONSENT_META = '_let_agents_consent';

	private const MAX_ITEMS = 200;

	public static function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'classic_checkout' ), 30, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'block_checkout' ), 30, 1 );
		add_action( self::ACTION, array( self::class, 'send' ), 10, 1 );
	}

	/**
	 * Makes or updates the shopper's pending order from the cart, and tells Let Agents.
	 * The cart must be loaded and not empty.
	 *
	 * @param list<array{q: string, at: string|null}> $searches
	 */
	public static function capture( string $email, string $consent_text, array $searches ): ?\WC_Order {
		$cart = WC()->cart;
		$cart->calculate_totals();

		$order = self::ours( WC()->session->get( self::SESSION_KEY ) );

		if ( null !== $order ) {
			$order->remove_order_items();
		} else {
			$order = wc_create_order(
				array(
					'created_via' => 'let-agents',
					'status'      => 'pending',
				)
			);

			if ( ! $order instanceof \WC_Order ) {
				return null;
			}
		}

		foreach ( $cart->get_cart() as $values ) {
			$product = $values['data'] ?? null;

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$variation = $product->is_type( 'variation' );
			$item      = new \WC_Order_Item_Product();

			// The cart's own line totals, as checkout writes them: discounts included, nothing recalculated.
			$item->set_props(
				array(
					'name'         => $product->get_name(),
					'tax_class'    => $product->get_tax_class(),
					'product_id'   => $variation ? $product->get_parent_id() : $product->get_id(),
					'variation_id' => $variation ? $product->get_id() : 0,
					'variation'    => (array) ( $values['variation'] ?? array() ),
					'quantity'     => $values['quantity'] ?? 1,
					'subtotal'     => $values['line_subtotal'] ?? 0,
					'total'        => $values['line_total'] ?? 0,
					'subtotal_tax' => $values['line_subtotal_tax'] ?? 0,
					'total_tax'    => $values['line_tax'] ?? 0,
					'taxes'        => $values['line_tax_data'] ?? array(),
				)
			);

			$order->add_item( $item );
		}

		$order->set_billing_email( $email );
		$order->set_currency( get_woocommerce_currency() );

		if ( is_user_logged_in() ) {
			$order->set_customer_id( get_current_user_id() );
		}

		$order->calculate_totals();
		// What classic checkout compares to resume this order rather than make another.
		$order->set_cart_hash( $cart->get_cart_hash() );
		$order->update_meta_data( self::META, 1 );
		$order->update_meta_data(
			self::CONSENT_META,
			array(
				'text' => $consent_text,
				'at'   => gmdate( 'c' ),
			)
		);
		$order->save();
		$order->add_order_note( __( 'The shopper left their email before checkout. Let Agents saved the cart as this order, waiting for payment.', 'let-agents' ) );

		WC()->session->set( 'order_awaiting_payment', $order->get_id() );
		WC()->session->set( self::SESSION_KEY, $order->get_id() );

		self::queue( $order, $consent_text, $searches );

		return $order;
	}

	/**
	 * @param int|string     $order_id
	 * @param array<mixed>   $posted_data
	 * @param \WC_Order|null $order
	 */
	public static function classic_checkout( $order_id, $posted_data = array(), $order = null ): void {
		self::settle( absint( $order_id ) );
	}

	/**
	 * @param \WC_Order|mixed $order
	 */
	public static function block_checkout( $order ): void {
		if ( $order instanceof \WC_Order ) {
			self::settle( $order->get_id() );
		}
	}

	/**
	 * An order was placed. If it is the one the email made, nothing is left to do. If it is another
	 * one (block checkout, or a cart that changed), the one the email made is a duplicate: to the trash.
	 */
	public static function settle( int $placed_id ): void {
		if ( ! WC()->session ) {
			return;
		}

		$pending_id = absint( WC()->session->get( self::SESSION_KEY ) );

		if ( 0 === $pending_id ) {
			return;
		}

		WC()->session->set( self::SESSION_KEY, null );

		$duplicate = self::duplicate( $pending_id, $placed_id, self::ours( $pending_id ) );

		if ( null === $duplicate ) {
			return;
		}

		$duplicate->delete( false );

		if ( absint( WC()->session->get( 'order_awaiting_payment' ) ) === $pending_id ) {
			WC()->session->set( 'order_awaiting_payment', null );
		}
	}

	/**
	 * The order to trash after $placed_id was placed, if any: ours, still waiting, and not the one placed.
	 */
	public static function duplicate( int $pending_id, int $placed_id, ?\WC_Order $pending ): ?\WC_Order {
		if ( 0 === $pending_id || $pending_id === $placed_id ) {
			return null;
		}

		return $pending;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function send( $payload ): void {
		if ( is_array( $payload ) ) {
			LetAgentsApi::post( 'carts', $payload );
		}
	}

	/**
	 * What Let Agents receives. Unlike a placed order, this carries the email: the shopper gave it to
	 * be contacted about this cart, and agreed to the text sent with it.
	 *
	 * @param list<array{q: string, at: string|null}> $searches
	 * @return array<string, mixed>
	 */
	public static function payload( \WC_Order $order, string $consent_text, array $searches, ?string $visitor ): array {
		$items = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || count( $items ) >= self::MAX_ITEMS ) {
				continue;
			}

			$items[] = array(
				'product_id'   => (string) $item->get_product_id(),
				'variation_id' => $item->get_variation_id() ? (string) $item->get_variation_id() : null,
				'quantity'     => max( 1, (int) $item->get_quantity() ),
				'total'        => self::decimal( $item->get_total() ),
			);
		}

		return array(
			'order_ref'    => OrderReporter::ref( $order->get_id() ),
			'order_id'     => $order->get_id(),
			'admin_url'    => $order->get_edit_order_url(),
			'email'        => $order->get_billing_email(),
			'consent'      => true,
			'consent_text' => $consent_text,
			'vid'          => $visitor,
			'items'        => $items,
			'total'        => self::decimal( $order->get_total() ),
			'currency'     => $order->get_currency(),
			'searches'     => $searches,
			'captured_at'  => gmdate( 'c' ),
		);
	}

	/**
	 * @param list<array{q: string, at: string|null}> $searches
	 */
	private static function queue( \WC_Order $order, string $consent_text, array $searches ): void {
		if ( null === SiteKeys::site() ) {
			return;
		}

		$payload = self::payload( $order, $consent_text, $searches, OrderReporter::visitor() );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION, array( $payload ), 'let-agents' );
			return;
		}

		LetAgentsApi::post( 'carts', $payload, false );
	}

	/**
	 * The order the email made, while it is still waiting for payment; null for any other order.
	 *
	 * @param mixed $order_id
	 */
	private static function ours( $order_id ): ?\WC_Order {
		$order_id = absint( $order_id );
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		return $order instanceof \WC_Order && $order->has_status( 'pending' ) && $order->get_meta( self::META ) ? $order : null;
	}

	/** Money as a decimal string, so no float rounding reaches Let Agents. */
	private static function decimal( float|string $amount ): string {
		return number_format( (float) $amount, 2, '.', '' );
	}
}
