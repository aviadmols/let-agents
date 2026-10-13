<?php

namespace LetAgents\Storefront;

use LetAgents\Auth\AccessToken;

defined( 'ABSPATH' ) || exit;

/**
 * Keys derived from the stored token hash, computed the same way on the Let Agents server
 * (App\Modules\Connections\Support\SiteKeys). No extra secret to copy or keep in sync:
 * replacing the token replaces all of them.
 *
 *   site key      public, in storefront pages: tells the Let Agents API which store this is
 *   preview key   private: shows the widget to the store team while the store is in preview
 *   signature     private: signs what the plugin sends to Let Agents (orders, report requests)
 *   customer      private: names the signed-in store account in a page, for the wishlist
 */
final class SiteKeys {

	public static function site(): ?string {
		$hash = self::token_hash();

		return null === $hash ? null : substr( hash( 'sha256', 'let-agents-site|' . $hash ), 0, 24 );
	}

	public static function preview(): ?string {
		$hash = self::token_hash();

		return null === $hash ? null : substr( hash_hmac( 'sha256', 'let-agents-preview', $hash ), 0, 32 );
	}

	/**
	 * Headers for a request to the Let Agents API. $path is the URL path with its query string.
	 *
	 * @return array<string, string>
	 */
	public static function signed_headers( string $method, string $path, string $body ): array {
		$hash = self::token_hash();

		if ( null === $hash ) {
			return array();
		}

		$timestamp = (string) time();

		return array(
			'X-LetAgents-Timestamp' => $timestamp,
			'X-LetAgents-Signature' => hash_hmac( 'sha256', $timestamp . "\n" . strtoupper( $method ) . "\n" . $path . "\n" . $body, $hash ),
		);
	}

	/**
	 * The signed-in store account, as the page tells Let Agents: "<user id>.<time>.<hmac>". Only
	 * Let Agents can check it, so a shopper cannot claim another account's wishlist.
	 */
	public static function customer( int $user_id ): ?string {
		$hash = self::token_hash();

		if ( null === $hash || $user_id <= 0 ) {
			return null;
		}

		$timestamp = (string) time();

		return $user_id . '.' . $timestamp . '.' . hash_hmac( 'sha256', 'let-agents-customer|' . $user_id . '|' . $timestamp, $hash );
	}

	/**
	 * Whether a request from Let Agents was signed with this store's key, within five minutes: the
	 * same signature the plugin puts on what it sends (signed_headers), the other way round. $route
	 * is the REST route ("/let-agents/v1/posts").
	 */
	public static function verify( string $timestamp, string $method, string $route, string $body, string $signature ): bool {
		$hash = self::token_hash();

		if ( null === $hash || ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 || '' === $signature ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $timestamp . "\n" . strtoupper( $method ) . "\n" . $route . "\n" . $body, $hash ), $signature );
	}

	private static function token_hash(): ?string {
		$token = AccessToken::current();

		return null === $token ? null : $token['hash'];
	}
}
