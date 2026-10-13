<?php

namespace LetAgents\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * `[let_agents_wishlist]`: the wishlist's heart wherever the shop wants it, a header or a menu.
 *
 * It leaves an empty element behind; the wishlist script on the page fills it with a heart and
 * the number of products saved, and opens the list on a click. Nothing shows when the store has
 * no wishlist, so a shortcode left in a theme does no harm.
 */
final class WishlistHeart {

	/** The element the wishlist script looks for. */
	public const SLOT = 'data-let-agents-wishlist';

	public static function register(): void {
		add_shortcode( 'let_agents_wishlist', array( self::class, 'shortcode' ) );
	}

	/** @param array<string, string>|string $attributes */
	public static function shortcode( $attributes = array() ): string {
		if ( null === Widget::context() ) {
			return '';
		}

		return '<span ' . self::SLOT . '></span>';
	}
}
