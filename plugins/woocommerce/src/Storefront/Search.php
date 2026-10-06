<?php

namespace Rega\Storefront;

use Rega\Plugin;
use Rega\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Rega search in the store's own search box, on every page.
 *
 * The script comes from the Rega server and attaches to the search fields Rega was told about.
 * It downloads nothing until someone focuses a field. Suggestions are computed in the browser;
 * full results come from Rega, or, when the store keeps its own results page, that page is
 * ordered by Rega here, so what the box suggested and what the page lists agree.
 *
 *   off       nothing changes
 *   preview   only the store team sees it: managers logged in to WordPress, or a browser that
 *             opened a Rega preview link
 *   live      everyone
 *
 * If Rega does not answer, the store's own search runs as it always did.
 */
final class Search {

	public const HANDLE = 'rega-search';

	private const PREVIEW_COOKIE = 'rega_preview';

	private const CACHE_SECONDS = 600;

	private const TIMEOUT_SECONDS = 2;

	/** Marks the one query this plugin reordered, so WordPress's own LIKE search stays out of it. */
	private const FLAG = 'rega_search';

	public static function register(): void {
		add_action( 'init', array( self::class, 'remember_preview' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'pre_get_posts', array( self::class, 'order_results_page' ) );
		add_filter( 'posts_search', array( self::class, 'drop_like_search' ), 10, 2 );
	}

	/** Whether this visitor gets Rega search now. */
	public static function active(): bool {
		$mode = Settings::search_mode();

		if ( 'off' === $mode || null === SiteKeys::site() || is_admin() ) {
			return false;
		}

		return 'live' === $mode || self::is_team();
	}

	/** A browser that opened a preview link keeps seeing the preview for a month. */
	public static function remember_preview(): void {
		$key     = isset( $_GET['rega_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['rega_preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- a read-only preview switch.
		$preview = SiteKeys::preview();

		if ( '' !== $key && null !== $preview && hash_equals( $preview, $key ) && ! headers_sent() ) {
			setcookie( self::PREVIEW_COOKIE, $key, time() + MONTH_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE[ self::PREVIEW_COOKIE ] = $key;
		}
	}

	public static function enqueue(): void {
		if ( ! self::active() ) {
			return;
		}

		$api         = Settings::api_url();
		$woocommerce = Plugin::woocommerce_active();
		$context     = array(
			'site'      => SiteKeys::site(),
			'api'       => $api,
			'locale'    => substr( determine_locale(), 0, 2 ),
			'storeApi'  => $woocommerce ? rest_url( 'wc/store/v1/' ) : null,
			'nonce'     => $woocommerce ? wp_create_nonce( 'wc_store_api' ) : null,
			'cartUrl'   => $woocommerce ? wc_get_cart_url() : null,
			'searchUrl' => home_url( '/' ),
			'version'   => REGA_VERSION,
		);

		wp_enqueue_script(
			self::HANDLE,
			$api . '/search/rega-search.js',
			array(),
			null, // The server versions the file itself.
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script( self::HANDLE, 'window.RegaSearchContext = ' . wp_json_encode( $context ) . ';', 'before' );
	}

	/**
	 * The store's own search results page, in Rega's order: what the box suggested, first to last.
	 * Only the main search query, only when Rega answered. Nothing found stays nothing found, as
	 * in the box.
	 */
	public static function order_results_page( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! $query->is_search() || ! self::active() ) {
			return;
		}

		$term = trim( (string) $query->get( 's' ) );

		if ( '' === $term || mb_strlen( $term ) > 120 ) {
			return;
		}

		$found = self::ask( $term );

		if ( null === $found ) {
			return;
		}

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', array_merge( $found['products'], $found['content'] ) )
				)
			)
		);

		$query->set( 'post__in', array() === $ids ? array( 0 ) : $ids );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'post_type', 'any' );
		$query->set( self::FLAG, true );
	}

	/** WordPress would also demand every word as typed, which drops exactly the near matches. */
	public static function drop_like_search( string $search, \WP_Query $query ): string {
		return $query->get( self::FLAG ) ? '' : $search;
	}

	/**
	 * @return array{products: list<string>, content: list<string>}|null null when Rega did not answer
	 */
	private static function ask( string $term ): ?array {
		$key    = 'rega_search_' . md5( $term );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$answer = RegaApi::get( 'search', array( 'q' => $term ), self::TIMEOUT_SECONDS );

		if ( is_wp_error( $answer ) || empty( $answer['searched'] ) ) {
			return null;
		}

		$found = array(
			'products' => array_map( 'strval', (array) ( $answer['products'] ?? array() ) ),
			'content'  => array_map( 'strval', (array) ( $answer['content'] ?? array() ) ),
		);

		set_transient( $key, $found, self::CACHE_SECONDS );

		return $found;
	}

	private static function is_team(): bool {
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		$preview = SiteKeys::preview();
		$cookie  = isset( $_COOKIE[ self::PREVIEW_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::PREVIEW_COOKIE ] ) ) : '';

		return null !== $preview && '' !== $cookie && hash_equals( $preview, $cookie );
	}
}
