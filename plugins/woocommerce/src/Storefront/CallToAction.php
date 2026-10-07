<?php

namespace LetAgents\Storefront;

use LetAgents\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Where the call to action sits inside the page.
 *
 * The widget puts itself where the store's placement setting says, which is right for something
 * that belongs beside the content. An offer belongs inside it — after the third paragraph, where
 * a reader has had enough of the piece to want more of it and not so much that they have gone.
 *
 * Two ways to place it, because shops differ. `[lets_cta]` for somebody who knows where they want
 * it, and a paragraph number for somebody who is not going to edit two hundred old posts. Both
 * leave the same empty element behind; the widget on the page fills it, so nothing here knows
 * anything about what the offer says.
 */
final class CallToAction {

	/** The element the widget looks for. Only the first on a page is filled. */
	public const SLOT = 'let-agents-cta';

	public static function register(): void {
		add_shortcode( 'lets_cta', array( self::class, 'shortcode' ) );
		// Late, so a paragraph added by another plugin is counted too.
		add_filter( 'the_content', array( self::class, 'place' ), 20 );
	}

	/**
	 * The slot, written where the author put the shortcode.
	 *
	 * @param array<string, string>|string $attributes
	 */
	public static function shortcode( $attributes = array() ): string {
		if ( null === Widget::context() ) {
			return '';
		}

		return self::slot();
	}

	/**
	 * The slot, dropped in after a paragraph, for a shop that is not editing its posts.
	 *
	 * Skipped when the author already placed one by hand: two offers on a page is no offer.
	 */
	public static function place( string $content ): string {
		$after = Settings::cta_after_paragraph();

		if ( $after < 1 || is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( null === Widget::context() || false !== strpos( $content, self::SLOT ) ) {
			return $content;
		}

		$paragraphs = explode( '</p>', $content );

		// A piece shorter than the chosen paragraph gets it at the end rather than not at all.
		$at = min( $after, max( 0, count( $paragraphs ) - 1 ) );

		if ( $at < 1 ) {
			return $content;
		}

		$head = implode( '</p>', array_slice( $paragraphs, 0, $at ) ) . '</p>';
		$tail = implode( '</p>', array_slice( $paragraphs, $at ) );

		return $head . self::slot() . $tail;
	}

	private static function slot(): string {
		return '<div class="' . esc_attr( self::SLOT ) . '" data-let-agents-cta="1"></div>';
	}
}
