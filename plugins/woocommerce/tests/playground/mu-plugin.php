<?php
/**
 * Test only: fixtures.php copies this into mu-plugins inside Playground. Never shipped: bin/build.php
 * packs src/ and languages/ only.
 *
 * Nothing leaves Playground for the Let Agents API: every request to it is caught and kept, so the smoke
 * test can read what would have been sent. GET /let-agents-test/v1/carts shows the orders the email
 * capture made and those requests.
 */

defined( 'ABSPATH' ) || exit;

const LET_AGENTS_TEST_REQUESTS = 'let_agents_test_requests';

// The email capture's report is sent at once instead of queued, as Action Scheduler would send it,
// so the test sees the signed request right after the capture, exactly once.
add_filter(
	'pre_as_enqueue_async_action',
	static function ( $pre, $hook, $args ) {
		if ( class_exists( \LetAgents\Storefront\PendingOrder::class ) && \LetAgents\Storefront\PendingOrder::ACTION === $hook ) {
			do_action( $hook, ...$args );

			return 0;
		}

		return $pre;
	},
	10,
	3
);

// Playground runs on SQLite, which has no DELETE with a JOIN. WooCommerce 11 removes the items of an
// order it updates that way (the classic checkout resuming an order, or the email capture updating
// one), so here the same delete is written with a subquery. MySQL needs none of this.
add_filter(
	'query',
	static function ( $query ) {
		if ( is_string( $query ) && preg_match( '/^\s*DELETE itemmeta FROM (\S+) as itemmeta INNER JOIN (\S+) as items WHERE itemmeta\.order_item_id = items\.order_item_id (?:AND|and) (.+)$/s', $query, $m ) ) {
			return "DELETE FROM {$m[1]} WHERE order_item_id IN (SELECT items.order_item_id FROM {$m[2]} as items WHERE {$m[3]})";
		}

		return $query;
	}
);

add_filter(
	'pre_http_request',
	static function ( $response, $args, $url ) {
		if ( ! class_exists( \LetAgents\Settings::class ) || ! str_starts_with( $url, \LetAgents\Settings::api_url() ) ) {
			return $response;
		}

		$requests   = (array) get_option( LET_AGENTS_TEST_REQUESTS, array() );
		$requests[] = array(
			'url'     => $url,
			'headers' => array_keys( (array) ( $args['headers'] ?? array() ) ),
			'body'    => json_decode( (string) ( $args['body'] ?? '' ), true ),
		);
		update_option( LET_AGENTS_TEST_REQUESTS, $requests, false );

		return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => 202, 'message' => 'Accepted' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'let-agents-test/v1',
			'/carts',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function (): array {
					$orders = array();
					$all    = wc_get_orders(
						array(
							'limit'  => -1,
							'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ),
						)
					);

					foreach ( $all as $order ) {
						$orders[] = array(
							'id'          => $order->get_id(),
							'status'      => $order->get_status(),
							'created_via' => $order->get_created_via(),
							'email'       => $order->get_billing_email(),
							'capture'     => (bool) $order->get_meta( \LetAgents\Storefront\PendingOrder::META ),
							'consent'     => $order->get_meta( \LetAgents\Storefront\PendingOrder::CONSENT_META ),
							'total'       => $order->get_total(),
							'items'       => array_values(
								array_map(
									static fn ( $item ): array => array( $item->get_product_id(), $item->get_variation_id(), $item->get_quantity() ),
									$order->get_items()
								)
							),
							'notes'       => array_map( static fn ( $note ): string => $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ),
						);
					}

					return array(
						'orders'   => $orders,
						'requests' => array_values( (array) get_option( LET_AGENTS_TEST_REQUESTS, array() ) ),
					);
				},
			)
		);
	}
);
