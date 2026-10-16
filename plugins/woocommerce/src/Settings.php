<?php

namespace LetAgents;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings stored in one option. Every read goes through a sanitizer, so a value
 * edited in the database directly can never widen what the API exposes.
 */
final class Settings {

	public const OPTION = 'let_agents_settings';

	public const MODES = array( 'off', 'preview', 'live' );

	/** The Let Agents server. A site can point elsewhere with the LET_AGENTS_API_URL constant in wp-config.php. */
	public const DEFAULT_API_URL = 'https://api-production-dfb8a.up.railway.app/api/v1';

	/** Keys only the scripts removed in 0.9.0 read: dropped from the saved option when it is migrated. */
	private const LEGACY_KEYS = array( 'widget_mode', 'search_mode', 'cta_after_paragraph' );

	/**
	 * Whether Lets Search loads on the store, and for whom. New installs start in preview.
	 *
	 * Until 0.9.0 the product-page widget and the search had a mode each; a store that never saved
	 * the new one is read from those (see legacy_mode()), so it keeps working before migrate() ran.
	 */
	public static function mode(): string {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( ! isset( $stored['mode'] ) ) {
			return self::legacy_mode( $stored );
		}

		$mode = (string) $stored['mode'];

		return in_array( $mode, self::MODES, true ) ? $mode : 'preview';
	}

	public static function save_mode( string $mode ): void {
		$stored         = get_option( self::OPTION, array() );
		$stored         = is_array( $stored ) ? $stored : array();
		$stored['mode'] = in_array( $mode, self::MODES, true ) ? $mode : 'preview';

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * One mode instead of two, written once: live if the widget or the search was live, else
	 * preview if either was in preview, else off. Unsaved modes count as their old defaults
	 * (widget preview, search off), which is what the store was running.
	 */
	public static function migrate(): void {
		$stored = get_option( self::OPTION, false );

		if ( ! is_array( $stored ) || isset( $stored['mode'] ) ) {
			return;
		}

		$stored['mode'] = self::legacy_mode( $stored );

		foreach ( self::LEGACY_KEYS as $key ) {
			unset( $stored[ $key ] );
		}

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	private static function legacy_mode( array $stored ): string {
		$widget = isset( $stored['widget_mode'] ) ? (string) $stored['widget_mode'] : 'preview';
		$search = isset( $stored['search_mode'] ) ? (string) $stored['search_mode'] : 'off';
		$widget = in_array( $widget, self::MODES, true ) ? $widget : 'preview';
		$search = in_array( $search, self::MODES, true ) ? $search : 'off';

		if ( 'live' === $widget || 'live' === $search ) {
			return 'live';
		}

		return 'preview' === $widget || 'preview' === $search ? 'preview' : 'off';
	}

	/** Base URL of the Let Agents API, without a trailing slash. HTTPS only, except on this machine. */
	public static function api_url(): string {
		$url = defined( 'LET_AGENTS_API_URL' ) ? (string) constant( 'LET_AGENTS_API_URL' ) : self::DEFAULT_API_URL;
		$url = untrailingslashit( esc_url_raw( $url ) );

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' === $scheme || ( 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) ) {
			return $url;
		}

		return self::DEFAULT_API_URL;
	}

	/**
	 * Post types whose published entries Let Agents may read as guides and articles.
	 *
	 * Posts and pages by default: the guides a shopper reads are usually posts, and what the shop
	 * promises — returns, shipping, warranty — is usually a page. A shop that wants neither turns
	 * them off in the settings; a saved choice, even an empty one, is always kept.
	 *
	 * @return list<string>
	 */
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
