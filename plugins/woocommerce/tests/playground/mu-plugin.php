<?php
/**
 * Test only: fixtures.php copies this into mu-plugins inside Playground. Never shipped: bin/build.php
 * packs src/ and languages/ only.
 *
 * Nothing leaves Playground for the Let Agents API: every request to it is caught and kept, so the smoke
 * test can read what would have been sent.
 */

defined( 'ABSPATH' ) || exit;

const LET_AGENTS_TEST_REQUESTS = 'let_agents_test_requests';

// Playground runs on SQLite, which has no DELETE with a JOIN. WooCommerce 11 removes the items of an
// order it updates that way (the classic checkout resuming an order), so here the same delete is written with a subquery. MySQL needs none of this.
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
