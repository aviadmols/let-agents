<?php

namespace LetAgents\Storefront;

defined( 'ABSPATH' ) || exit;

/**
 * `[lets_cta]`, kept only so posts that carry it do not print the raw tag.
 *
 * Its slot was filled by the product-page widget, which is gone since 0.9.0 together with the
 * offer dropped after paragraph N. The shortcode now prints nothing.
 */
final class CallToAction {

	public static function register(): void {
		add_shortcode( 'lets_cta', array( self::class, 'shortcode' ) );
	}

	/** @param array<string, string>|string $attributes */
	public static function shortcode( $attributes = array() ): string {
		return '';
	}
}
