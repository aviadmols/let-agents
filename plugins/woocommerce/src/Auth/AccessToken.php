<?php

namespace LetAgents\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * The one token that lets Let Agents read this store.
 *
 * Only a SHA-256 hash is stored. The plaintext exists once, when it is created, and is shown
 * to the admin who created it. Creating a new token replaces the old one immediately.
 */
final class AccessToken {

	public const OPTION = 'let_agents_access_token';

	public const PREFIX = 'lat_';

	/** Tokens created before the plugin was renamed, carried over by Legacy::migrate(). */
	public const LEGACY_PREFIX = 'rgt_';

	/** Writing last_used_at on every request would turn every read into a database write. */
	private const TOUCH_INTERVAL = 300;

	/**
	 * @return string The plaintext token. Show it once; it cannot be recovered.
	 */
	public static function issue(): string {
		$plaintext = self::PREFIX . wp_generate_password( 48, false, false );

		update_option(
			self::OPTION,
			array(
				'hash'         => self::hash( $plaintext ),
				'prefix'       => substr( $plaintext, 0, 10 ),
				'created_at'   => gmdate( 'c' ),
				'created_by'   => get_current_user_id(),
				'last_used_at' => null,
			),
			false
		);

		return $plaintext;
	}

	public static function revoke(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @return array{hash: string, prefix: string, created_at: string, created_by: int, last_used_at: ?string}|null
	 */
	public static function current(): ?array {
		$stored = get_option( self::OPTION );

		return is_array( $stored ) && ! empty( $stored['hash'] ) ? $stored : null;
	}

	public static function verify( string $plaintext ): bool {
		$current = self::current();

		if ( null === $current || ! ( str_starts_with( $plaintext, self::PREFIX ) || str_starts_with( $plaintext, self::LEGACY_PREFIX ) ) ) {
			return false;
		}

		return hash_equals( $current['hash'], self::hash( $plaintext ) );
	}

	public static function touch(): void {
		$current = self::current();

		if ( null === $current ) {
			return;
		}

		$last = $current['last_used_at'] ? strtotime( $current['last_used_at'] ) : 0;

		if ( time() - (int) $last < self::TOUCH_INTERVAL ) {
			return;
		}

		$current['last_used_at'] = gmdate( 'c' );
		update_option( self::OPTION, $current, false );
	}

	private static function hash( string $plaintext ): string {
		return hash( 'sha256', $plaintext );
	}
}
