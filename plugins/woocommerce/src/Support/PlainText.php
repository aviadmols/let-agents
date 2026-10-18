<?php

namespace LetAgents\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Turns stored HTML (classic editor, blocks, shortcodes) into clean text for analysis that keeps its
 * structure: paragraphs and line breaks as newlines, headings as "## ", list items as "- ", and a
 * table as rows of "| a | b |" with its header row first, so a row's values stay together. Markup,
 * scripts and entities do not survive. The service's HtmlText does the same for Shopify.
 */
final class PlainText {

	/** A table cell longer than this is cut: a table is for short values. */
	private const CELL = 200;

	public static function from( ?string $html, int $max_chars = 0 ): string {
		if ( null === $html || '' === $html ) {
			return '';
		}

		$text = strip_shortcodes( $html );
		$text = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#isu', ' ', $text );
		$text = (string) preg_replace_callback( '#<table\b[^>]*>(.*?)</table\s*>#isu', static fn ( array $m ): string => "\n" . self::table( $m[1] ) . "\n", $text );
		$text = (string) preg_replace_callback( '#<h([1-6])\b[^>]*>#iu', static fn ( array $m ): string => "\n" . str_repeat( '#', min( 3, max( 2, (int) $m[1] ) ) ) . ' ', $text );
		$text = (string) preg_replace( '#<li\b[^>]*>#iu', "\n- ", $text );
		// Breaks with attributes (<br data-start="12">) and accordion titles (<summary>) end a line too.
		$text = (string) preg_replace( '#<br\b[^>]*>|</(p|div|h[1-6]|tr|blockquote|summary|details|section|article|header|footer|figcaption|dt|dd|ul|ol)\s*>#iu', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = (string) preg_replace( "/ *\n */u", "\n", $text );
		// A list item or heading left empty by its markup is no line.
		$text = (string) preg_replace( "/^(?:-|#{2,3})[ \t]*$/mu", '', $text );
		$text = (string) preg_replace( "/\n{3,}/u", "\n\n", $text );
		$text = trim( $text );

		if ( $max_chars > 0 && mb_strlen( $text ) > $max_chars ) {
			$text = rtrim( mb_substr( $text, 0, $max_chars ) ) . '…';
		}

		return $text;
	}

	/** One table's rows as "| a | b |", the header row first and marked; an empty table is nothing. */
	private static function table( string $inner ): string {
		preg_match_all( '#<tr\b[^>]*>(.*?)</tr\s*>#isu', $inner, $rows );
		$lines  = array();
		$header = false;

		foreach ( $rows[1] as $i => $row ) {
			preg_match_all( '#<(t[hd])\b[^>]*>(.*?)</t[hd]\s*>#isu', $row, $cells, PREG_SET_ORDER );
			$values = array_map( static fn ( array $c ): string => self::cell( $c[2] ), $cells );

			if ( array() === $values || '' === implode( '', $values ) ) {
				continue;
			}

			$lines[] = '| ' . implode( ' | ', $values ) . ' |';

			// The first row is the header when it is all <th>: the line under it says so.
			if ( 0 === $i && count( $cells ) === count( array_filter( $cells, static fn ( array $c ): bool => 'th' === strtolower( $c[1] ) ) ) ) {
				$lines[] = '|' . str_repeat( ' --- |', count( $values ) );
				$header  = true;
			}
		}

		// A table that never says its header: its first row is read as one.
		if ( ! $header && count( $lines ) > 1 ) {
			array_splice( $lines, 1, 0, array( '|' . str_repeat( ' --- |', substr_count( $lines[0], ' | ' ) + 1 ) ) );
		}

		return implode( "\n", $lines );
	}

	private static function cell( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '#<br\b[^>]*>#iu', ' ', $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( '|', '/', trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) ) );

		return mb_strlen( $text ) > self::CELL ? mb_substr( $text, 0, self::CELL ) . '…' : $text;
	}

	/** Titles and labels: one line, decoded. */
	public static function line( ?string $value ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}
