<?php

namespace LetAgents\Support;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Shared shape for every feed response: records carry a hash of their own content, lists
 * page by ascending ID with an "after" cursor, and nothing is cached by proxies.
 */
final class Records {

	/**
	 * Adds "hash": the SHA-256 of the record without it. Let Agents compares hashes to process only
	 * what changed.
	 *
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	public static function with_hash( array $record ): array {
		unset( $record['hash'] );
		$record['hash'] = hash( 'sha256', (string) wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

		return $record;
	}

	public static function iso( $value ): ?string {
		if ( $value instanceof \DateTimeInterface ) {
			return gmdate( 'c', $value->getTimestamp() );
		}

		if ( is_string( $value ) && '' !== $value && '0000-00-00 00:00:00' !== $value ) {
			$timestamp = strtotime( $value . ' UTC' );

			return false === $timestamp ? null : gmdate( 'c', $timestamp );
		}

		return null;
	}

	/**
	 * Converts the optional "since" parameter to a MySQL UTC datetime, or an error.
	 *
	 * @return string|null|WP_Error
	 */
	public static function since( WP_REST_Request $request ) {
		$since = $request->get_param( 'since' );

		if ( null === $since || '' === $since ) {
			return null;
		}

		$timestamp = strtotime( (string) $since );

		if ( false === $timestamp ) {
			return new WP_Error( 'let_agents_invalid_since', __( 'The "since" parameter must be a date, for example 2026-09-17T00:00:00Z.', 'let-agents' ), array( 'status' => 400 ) );
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * @param list<array<string, mixed>> $data
	 * @param array<string, mixed>       $meta
	 */
	public static function response( array $data, array $meta = array() ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array_merge( array( 'generated_at' => gmdate( 'c' ) ), $meta ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Standard arguments for paged feed routes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function paging_args( int $default_per_page = 50 ): array {
		return array(
			'per_page' => array(
				'type'    => 'integer',
				'default' => $default_per_page,
				'minimum' => 1,
				'maximum' => 100,
			),
			'after'    => array(
				'description' => 'Return records with an ID greater than this. Use meta.next_after from the previous page.',
				'type'        => 'integer',
				'default'     => 0,
				'minimum'     => 0,
			),
			'since'    => array(
				'description' => 'Only records changed at or after this date (ISO 8601).',
				'type'        => 'string',
			),
		);
	}

	/**
	 * Given IDs fetched with one extra row, returns the page and the cursor for the next one.
	 *
	 * @param list<int> $ids
	 * @return array{0: list<int>, 1: int|null}
	 */
	public static function page( array $ids, int $per_page ): array {
		$has_more = count( $ids ) > $per_page;
		$page     = array_slice( $ids, 0, $per_page );

		return array( $page, $has_more && array() !== $page ? (int) end( $page ) : null );
	}
}
