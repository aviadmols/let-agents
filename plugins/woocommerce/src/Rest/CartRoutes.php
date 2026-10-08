<?php

namespace LetAgents\Rest;

use LetAgents\Plugin;
use LetAgents\Storefront\PendingOrder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The only route that writes to the store, kept apart from the read-only API in Routes. It is called
 * by shoppers, who are logged out, so it takes no access token: the page's REST nonce instead, and a
 * limit per address.
 *
 *   POST /let-agents/v1/cart/capture   {email, consent: true, consent_text, searches: [{q, at}]}
 *
 *   200 {ok: true}                      the cart is saved as an order waiting for payment
 *   403                                 no nonce, or one that expired
 *   409 {error: "empty_cart"}           nothing in the cart, or WooCommerce is not active
 *   422 {error: "invalid_email"}        the email is not one
 *   422 {error: "no_consent"}           the shopper did not agree
 *   429 {error: "rate_limited"}         more than ten tries from this address in an hour
 *
 * The answer never says which order was made: the shopper's session knows, nobody else needs to.
 */
final class CartRoutes {

	public const ROUTE = '/cart/capture';

	private const MAX_PER_HOUR = 10;

	private const MAX_SEARCHES = 20;

	private const MAX_QUERY = 120;

	private const MAX_CONSENT_TEXT = 1000;

	public static function register(): void {
		register_rest_route( Routes::NAMESPACE, self::ROUTE, array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'capture' ),
			'permission_callback' => array( self::class, 'check_nonce' ),
		) );
	}

	/** The route as rest_url() and ?rest_route= take it: "let-agents/v1/cart/capture". */
	public static function path(): string {
		return Routes::NAMESPACE . self::ROUTE;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function check_nonce( WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'let_agents_invalid_nonce', __( 'This page has expired. Reload it and try again.', 'let-agents' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public static function capture( WP_REST_Request $request ): WP_REST_Response {
		// Every try counts, so a script cannot fill the store with orders or test emails one by one.
		$tries_key = 'let_agents_capture_' . md5( self::client_ip() );
		$tries     = (int) get_transient( $tries_key );

		if ( $tries >= self::MAX_PER_HOUR ) {
			return self::answer( array( 'error' => 'rate_limited' ), 429 );
		}

		set_transient( $tries_key, $tries + 1, HOUR_IN_SECONDS );

		$body  = (array) $request->get_json_params();
		$error = self::invalid( $body );

		if ( null !== $error ) {
			return self::answer( array( 'error' => $error ), 422 );
		}

		if ( ! self::cart_loaded() || WC()->cart->is_empty() ) {
			return self::answer( array( 'error' => 'empty_cart' ), 409 );
		}

		$order = PendingOrder::capture(
			sanitize_email( (string) $body['email'] ),
			self::consent_text( $body['consent_text'] ?? '' ),
			self::searches( $body['searches'] ?? array() )
		);

		if ( null === $order ) {
			return self::answer( array( 'error' => 'not_saved' ), 500 );
		}

		return self::answer( array( 'ok' => true ), 200 );
	}

	/**
	 * Why the request cannot be taken, or null when it can.
	 *
	 * @param array<mixed> $body
	 */
	public static function invalid( array $body ): ?string {
		$email = isset( $body['email'] ) && is_string( $body['email'] ) ? sanitize_email( $body['email'] ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return 'invalid_email';
		}

		return true === ( $body['consent'] ?? null ) ? null : 'no_consent';
	}

	/**
	 * What the shopper searched for before, as sent by the page: at most twenty, each cut to 120
	 * characters, with a time only when it is one.
	 *
	 * @param mixed $raw
	 * @return list<array{q: string, at: string|null}>
	 */
	public static function searches( $raw ): array {
		$searches = array();

		foreach ( is_array( $raw ) ? $raw : array() as $search ) {
			if ( count( $searches ) >= self::MAX_SEARCHES ) {
				break;
			}

			$query = is_array( $search ) && is_string( $search['q'] ?? null ) ? sanitize_text_field( $search['q'] ) : '';
			$query = mb_substr( $query, 0, self::MAX_QUERY );

			if ( '' === $query ) {
				continue;
			}

			$at = is_array( $search ) && is_string( $search['at'] ?? null ) ? strtotime( $search['at'] ) : false;

			$searches[] = array(
				'q'  => $query,
				'at' => false === $at ? null : gmdate( 'c', $at ),
			);
		}

		return $searches;
	}

	/**
	 * @param mixed $raw
	 */
	private static function consent_text( $raw ): string {
		return is_string( $raw ) ? mb_substr( sanitize_text_field( $raw ), 0, self::MAX_CONSENT_TEXT ) : '';
	}

	/**
	 * REST requests do not load the cart; this loads it from the shopper's session cookie, as the
	 * WooCommerce Store API does.
	 */
	private static function cart_loaded(): bool {
		if ( ! Plugin::woocommerce_active() || ! function_exists( 'wc_load_cart' ) ) {
			return false;
		}

		if ( ! did_action( 'woocommerce_load_cart_from_session' ) ) {
			wc_load_cart();
		}

		return null !== WC()->cart && null !== WC()->session;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function answer( array $data, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * REMOTE_ADDR only. Forwarded headers are set by the caller and cannot be trusted here.
	 */
	private static function client_ip(): string {
		return (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
}
