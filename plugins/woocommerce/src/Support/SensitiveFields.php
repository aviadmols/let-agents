<?php

namespace LetAgents\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Custom fields that must never leave the store: costs, supplier details, margins and internal
 * notes. Stores name these freely ("עלות ליחידה מהספק (לא לפרסום)", "supplier_cost"), so the
 * check looks for the words, in English and Hebrew, anywhere in the key.
 *
 * Excluding a harmless field by mistake costs little. Sharing a cost price does not, so the
 * list leans wide. Sites can add their own keys with the "let_agents_is_sensitive_meta_key" filter.
 */
final class SensitiveFields {

	private const PATTERNS = array(
		'cost',
		'supplier',
		'vendor',
		'wholesale',
		'margin',
		'profit',
		'purchase',
		'buy_price',
		'internal',
		'private',
		'secret',
		'note',
		'comment',
		'עלות',
		'ספק',
		'סיטונא',
		'רווח',
		'קנייה',
		'קניה',
		'פנימי',
		'הערה',
		'הערת',
		'לא לפרסום',
		'חסוי',
	);

	public static function is_sensitive( string $key ): bool {
		$normalized = mb_strtolower( $key );
		$sensitive  = false;

		foreach ( self::PATTERNS as $pattern ) {
			if ( str_contains( $normalized, $pattern ) ) {
				$sensitive = true;
				break;
			}
		}

		/**
		 * Whether a custom field key is kept out of everything Let Agents reads.
		 *
		 * @param bool   $sensitive
		 * @param string $key
		 */
		return (bool) apply_filters( 'let_agents_is_sensitive_meta_key', $sensitive, $key );
	}
}
