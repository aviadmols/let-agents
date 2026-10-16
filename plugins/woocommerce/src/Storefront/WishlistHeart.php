<?php

namespace LetAgents\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * `[let_agents_wishlist]`: a way in to the wishlist wherever the shop wants it, a header or a menu.
 *
 * A heart linking to #wishlist, marked data-let-agents-wishlist: Lets Search opens its saved tab on
 * a click on either, and on a page opened with #wishlist. Nothing shows while Lets Search does not
 * load for this visitor, so a shortcode left in a theme does no harm.
 */
final class WishlistHeart {

	/** The attribute Lets Search looks for. */
	public const SLOT = 'data-let-agents-wishlist';

	public static function register(): void {
		add_shortcode( 'let_agents_wishlist', array( self::class, 'shortcode' ) );
	}

	/** @param array<string, string>|string $attributes */
	public static function shortcode( $attributes = array() ): string {
		if ( ! Search::active() ) {
			return '';
		}

		$label = __( 'Wishlist', 'let-agents' );
		$heart = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.4 8 3.4 4.5 6.9 4.5c2 0 3.4 1.1 5.1 3 1.7-1.9 3.1-3 5.1-3 3.5 0 5.5 3.5 4.2 6.8-1.8 4.6-9.3 9.2-9.3 9.2z"/></svg>';

		return '<a href="#wishlist" class="let-agents-wishlist" ' . self::SLOT . ' aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '" style="display:inline-flex;align-items:center;color:inherit">' . $heart . '</a>';
	}
}
