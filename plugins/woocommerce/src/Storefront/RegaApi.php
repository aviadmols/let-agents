<?php

namespace Rega\Storefront;

use Rega\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Signed calls from this store to the Rega API. Nothing here reads a request from Rega:
 * these are outgoing only.
 */
final class RegaApi {

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function post( string $route, array $payload, bool $blocking = true ): bool {
		$site = SiteKeys::site();

		if ( null === $site ) {
			return false;
		}

		$url  = Settings::api_url() . '/plugin/' . $site . '/' . ltrim( $route, '/' );
		$body = (string) wp_json_encode( $payload );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => $blocking ? 10 : 1,
				'blocking' => $blocking,
				'headers'  => array( 'Content-Type' => 'application/json' ) + SiteKeys::signed_headers( 'POST', self::path( $url ), $body ),
				'body'     => $body,
			)
		);

		if ( ! $blocking ) {
			return true;
		}

		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 300;
	}

	/**
	 * @param array<string, scalar> $query
	 * @param int                   $timeout seconds; the storefront waits two at most, the admin fifteen
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( string $route, array $query = array(), int $timeout = 15 ) {
		$site = SiteKeys::site();

		if ( null === $site ) {
			return new \WP_Error( 'rega_no_token', __( 'Create an access token first.', 'rega' ) );
		}

		$url      = add_query_arg( $query, Settings::api_url() . '/plugin/' . $site . '/' . ltrim( $route, '/' ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Accept' => 'application/json' ) + SiteKeys::signed_headers( 'GET', self::path( $url ), '' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 404 === $status ) {
			return new \WP_Error( 'rega_unknown_site', __( 'Rega does not know this store yet. Connect it in Rega with the current token.', 'rega' ) );
		}

		if ( 401 === $status ) {
			return new \WP_Error( 'rega_bad_signature', __( 'Rega refused the request. Check that the token connected in Rega is the current one and that the site clock is correct.', 'rega' ) );
		}

		if ( 200 !== $status || ! is_array( $data ) ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'rega_api_status', sprintf( __( 'Rega answered with status %d.', 'rega' ), $status ) );
		}

		return $data;
	}

	/** The part of the URL the signature covers: path and query, as the server receives them. */
	private static function path( string $url ): string {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = wp_parse_url( $url, PHP_URL_QUERY );

		return $path . ( is_string( $query ) && '' !== $query ? '?' . $query : '' );
	}
}
