<?php

namespace LetAgents\Storefront;

use LetAgents\Plugin;
use LetAgents\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lets Search on the store: one script from the Let Agents server, the drawer.
 *
 * It answers the store's search box and search icons, and on a product page or an article it
 * starts with "about this page" and learns from it. Since 0.9.0 it is the only script: the
 * product-page widget, the old search box and their display modes are gone, and the page the
 * widget used to be told about now travels in this context as `page`.
 *
 * When the store keeps its own results page, that page is ordered by Let Agents here, so what the
 * drawer suggested and what the page lists agree.
 *
 *   off       nothing changes
 *   preview   only the store team sees it: managers logged in to WordPress, or a browser that
 *             opened a Let Agents preview link. The drawer shows them a preview strip. Shoppers get
 *             it silent: nothing shown, their page views and the store's own adds still counted.
 *   live      everyone
 *
 * If Let Agents does not answer, the store's own search runs as it always did.
 */
final class Search {

	public const HANDLE = 'lets-search';

	private const PREVIEW_COOKIE = 'let_agents_preview';

	private const CACHE_SECONDS = 600;

	private const TIMEOUT_SECONDS = 2;

	/** Marks the one query this plugin reordered, so WordPress's own LIKE search stays out of it. */
	private const FLAG = 'let_agents_search';

	public static function register(): void {
		add_action( 'init', array( self::class, 'remember_preview' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'pre_get_posts', array( self::class, 'order_results_page' ) );
		add_filter( 'posts_search', array( self::class, 'drop_like_search' ), 10, 2 );
	}

	/** Whether this visitor gets Lets Search now. */
	public static function active(): bool {
		$mode = Settings::mode();

		if ( 'off' === $mode || null === SiteKeys::site() || is_admin() ) {
			return false;
		}

		return 'live' === $mode || self::is_team();
	}

	/** A browser that opened a preview link keeps seeing the preview for a month. */
	public static function remember_preview(): void {
		$key     = isset( $_GET['let_agents_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['let_agents_preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- a read-only preview switch.
		$preview = SiteKeys::preview();

		if ( '' !== $key && null !== $preview && hash_equals( $preview, $key ) && ! headers_sent() ) {
			setcookie( self::PREVIEW_COOKIE, $key, time() + MONTH_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE[ self::PREVIEW_COOKIE ] = $key;
		}
	}

	public static function enqueue(): void {
		$mode = Settings::mode();

		if ( 'off' === $mode || null === SiteKeys::site() || is_admin() ) {
			return;
		}

		// A shopper while the store is in preview gets the script silent: nothing is shown, but the page
		// view, the store's own add to cart and the search's attribution count, as the widget counted
		// them in preview before (the learning has its baseline before the drawer goes live).
		$silent = ! self::active();

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
			'version'   => LET_AGENTS_VERSION,
			// The signed-in account, signed: the wishlist is kept in it. Pages of signed-in users are not cached.
			'customer'  => is_user_logged_in() ? SiteKeys::customer( get_current_user_id() ) : null,
		);

		// The page being read, so the drawer starts with it and its views and learning are counted.
		$page = self::page();

		if ( null !== $page ) {
			$context['page'] = $page;
		}

		// In preview the team sees the drawer with a strip saying visitors do not see it yet.
		if ( $silent ) {
			$context['silent'] = true;
		} elseif ( 'preview' === $mode ) {
			$context['preview'] = true;
		}

		wp_enqueue_script(
			self::HANDLE,
			$api . '/search/lets-search.js',
			array(),
			null, // The server versions the file itself.
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script( self::HANDLE, 'window.LetAgentsSearchContext = ' . wp_json_encode( $context ) . ';', 'before' );
	}

	/**
	 * The product page, or an entry of a post type shared with Let Agents; null elsewhere.
	 *
	 * @return array{type: string, id: string}|null
	 */
	public static function page(): ?array {
		if ( Plugin::woocommerce_active() && function_exists( 'is_product' ) && is_product() ) {
			return array(
				'type' => 'product',
				'id'   => (string) get_queried_object_id(),
			);
		}

		$types = Settings::content_post_types();

		if ( array() !== $types && is_singular( $types ) ) {
			return array(
				'type' => 'content',
				'id'   => (string) get_queried_object_id(),
			);
		}

		return null;
	}

	/**
	 * The store's own search results page, in Let Agents' order: what the box suggested, first to last.
	 * Only the main search query, only when Let Agents answered. Nothing found stays nothing found, as
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
	 * @return array{products: list<string>, content: list<string>}|null null when Let Agents did not answer
	 */
	private static function ask( string $term ): ?array {
		$key    = 'let_agents_search_' . md5( $term );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$answer = LetAgentsApi::get( 'search', array( 'q' => $term ), self::TIMEOUT_SECONDS );

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
