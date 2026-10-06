<?php

namespace Rega;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings stored in one option. Every read goes through a sanitizer, so a value
 * edited in the database directly can never widen what the API exposes.
 */
final class Settings {

	public const OPTION = 'rega_settings';

	public const MODES = array( 'off', 'preview', 'live' );

	/** The Rega server. A site can point elsewhere with the REGA_API_URL constant in wp-config.php. */
	public const DEFAULT_API_URL = 'https://api-production-dfb8a.up.railway.app/api/v1';

	/** Whether the storefront widget loads, and for whom. New installs start in preview. */
	public static function widget_mode(): string {
		$stored = get_option( self::OPTION, array() );
		$mode   = is_array( $stored ) && isset( $stored['widget_mode'] ) ? (string) $stored['widget_mode'] : 'preview';

		return in_array( $mode, self::MODES, true ) ? $mode : 'preview';
	}

	public static function save_widget_mode( string $mode ): void {
		$stored                = get_option( self::OPTION, array() );
		$stored                = is_array( $stored ) ? $stored : array();
		$stored['widget_mode'] = in_array( $mode, self::MODES, true ) ? $mode : 'preview';

		update_option( self::OPTION, $stored, false );
	}

	/** Whether Rega search runs in the store's search box, and for whom. Off until the store turns it on. */
	public static function search_mode(): string {
		$stored = get_option( self::OPTION, array() );
		$mode   = is_array( $stored ) && isset( $stored['search_mode'] ) ? (string) $stored['search_mode'] : 'off';

		return in_array( $mode, self::MODES, true ) ? $mode : 'off';
	}

	public static function save_search_mode( string $mode ): void {
		$stored                = get_option( self::OPTION, array() );
		$stored                = is_array( $stored ) ? $stored : array();
		$stored['search_mode'] = in_array( $mode, self::MODES, true ) ? $mode : 'off';

		update_option( self::OPTION, $stored, false );
	}

	/** Base URL of the Rega API, without a trailing slash. HTTPS only, except on this machine. */
	public static function api_url(): string {
		$url = defined( 'REGA_API_URL' ) ? (string) constant( 'REGA_API_URL' ) : self::DEFAULT_API_URL;
		$url = untrailingslashit( esc_url_raw( $url ) );

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' === $scheme || ( 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) ) {
			return $url;
		}

		return self::DEFAULT_API_URL;
	}

	/**
	 * Post types whose published entries Rega may read as guides and articles.
	 *
	 * Posts and pages by default: the guides a shopper reads are usually posts, and what the shop
	 * promises — returns, shipping, warranty — is usually a page. A shop that wants neither turns
	 * them off in the settings; a saved choice, even an empty one, is always kept.
	 *
	 * @return list<string>
	 */
	/** Which paragraph the offer is dropped after, or 0 to place it only by shortcode. */
	public static function cta_after_paragraph(): int {
		$stored = get_option( self::OPTION, array() );
		$after  = is_array( $stored ) && isset( $stored['cta_after_paragraph'] ) ? (int) $stored['cta_after_paragraph'] : 0;

		return max( 0, min( 20, $after ) );
	}

	public static function save_cta_after_paragraph( int $after ): void {
		$stored                        = get_option( self::OPTION, array() );
		$stored                        = is_array( $stored ) ? $stored : array();
		$stored['cta_after_paragraph'] = max( 0, min( 20, $after ) );

		update_option( self::OPTION, $stored );
	}

	public static function content_post_types(): array {
		$stored = get_option( self::OPTION, array() );
		$types  = is_array( $stored ) && isset( $stored['content_post_types'] ) ? (array) $stored['content_post_types'] : array( 'post', 'page' );

		return self::sanitize_post_types( $types );
	}

	/**
	 * @param array<mixed> $types
	 */
	public static function save_content_post_types( array $types ): void {
		$stored                       = get_option( self::OPTION, array() );
		$stored                       = is_array( $stored ) ? $stored : array();
		$stored['content_post_types'] = self::sanitize_post_types( $types );

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Public post types a guide can live in. Products are exposed by their own endpoint, and
	 * attachments are not content.
	 *
	 * @return array<string, string> slug => label
	 */
	public static function available_content_post_types(): array {
		$available = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'product', 'attachment' ), true ) ) {
				continue;
			}

			$available[ $type->name ] = $type->labels->name;
		}

		return $available;
	}

	/**
	 * @param array<mixed> $types
	 * @return list<string>
	 */
	private static function sanitize_post_types( array $types ): array {
		$allowed = array_keys( self::available_content_post_types() );

		return array_values( array_unique( array_filter( array_map( 'strval', $types ), static fn ( string $type ): bool => in_array( $type, $allowed, true ) ) ) );
	}
}
