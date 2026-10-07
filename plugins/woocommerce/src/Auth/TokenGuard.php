<?php

namespace LetAgents\Auth;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Permission check for every Let Agents REST route.
 *
 * Accepts the token as "X-LetAgents-Token: lat_..." (preferred: some hosts strip the Authorization
 * header) or "Authorization: Bearer lat_...". Wrong tokens are counted per IP address, and
 * after too many the address is refused for a while, so the token cannot be guessed.
 */
final class TokenGuard {

	public const HEADER = 'X-LetAgents-Token';

	private const MAX_FAILURES = 20;

	private const FAILURE_WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * @return true|WP_Error
	 */
	public static function check( WP_REST_Request $request ) {
		$failures_key = 'let_agents_auth_fail_' . md5( self::client_ip() );
		$failures     = (int) get_transient( $failures_key );

		if ( $failures >= self::MAX_FAILURES ) {
			return new WP_Error( 'let_agents_rate_limited', __( 'Too many failed attempts. Try again in a few minutes.', 'let-agents' ), array( 'status' => 429 ) );
		}

		$token = self::token_from( $request );

		if ( '' === $token ) {
			return new WP_Error( 'let_agents_missing_token', __( 'An access token is required.', 'let-agents' ), array( 'status' => 401 ) );
		}

		if ( ! AccessToken::verify( $token ) ) {
			set_transient( $failures_key, $failures + 1, self::FAILURE_WINDOW );

			return new WP_Error( 'let_agents_invalid_token', __( 'The access token is not valid.', 'let-agents' ), array( 'status' => 401 ) );
		}

		AccessToken::touch();

		return true;
	}

	private static function token_from( WP_REST_Request $request ): string {
		$header = (string) $request->get_header( self::HEADER );

		if ( '' !== $header ) {
			return trim( $header );
		}

		$authorization = (string) $request->get_header( 'authorization' );

		if ( '' === $authorization ) {
			// Apache with PHP-CGI hides the header from WordPress but keeps it here.
			$authorization = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		if ( preg_match( '/^Bearer\s+(\S+)$/i', trim( $authorization ), $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * REMOTE_ADDR only. Forwarded headers are set by the caller and cannot be trusted here.
	 */
	private static function client_ip(): string {
		return (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
}
