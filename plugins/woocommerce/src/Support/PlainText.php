<?php

namespace LetAgents\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Turns stored HTML (classic editor, blocks, shortcodes) into clean text for analysis.
 * Paragraph and line breaks survive as newlines; markup, scripts and entities do not.
 */
final class PlainText {

	public static function from( ?string $html, int $max_chars = 0 ): string {
		if ( null === $html || '' === $html ) {
			return '';
		}

		$text = strip_shortcodes( $html );
		$text = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text );
		// Breaks with attributes (<br data-start="12">) and accordion titles (<summary>) end a line too.
		$text = (string) preg_replace( '#<br\b[^>]*>|</(p|div|li|h[1-6]|tr|blockquote|summary|details|section|article|header|footer|figcaption|dt|dd|ul|ol|table)\s*>#i', "\n", $text );
		$text = (string) preg_replace( '#</(td|th)\s*>#i', ' ', $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = (string) preg_replace( "/ *\n */", "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		$text = trim( $text );

		if ( $max_chars > 0 && mb_strlen( $text ) > $max_chars ) {
			$text = rtrim( mb_substr( $text, 0, $max_chars ) ) . '…';
		}

		return $text;
	}

	/** Titles and labels: one line, decoded. */
	public static function line( ?string $value ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}
