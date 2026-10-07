<?php

namespace LetAgents;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin used to be called Rega. A store that had it keeps its connection: the token, the
 * settings and the progress of the past-orders upload are copied to their new names once, and
 * the old plugin is switched off so the store does not run both.
 *
 * Nothing is deleted. The old plugin can still be removed from the plugins screen as usual.
 */
final class Legacy {

	private const DONE = 'let_agents_legacy_migrated';

	private const OPTIONS = array(
		'rega_settings'       => 'let_agents_settings',
		'rega_access_token'   => 'let_agents_access_token',
		'rega_order_history'  => 'let_agents_order_history',
	);

	private const OLD_PLUGIN = 'rega/rega.php';

	public static function migrate(): void {
		if ( get_option( self::DONE ) ) {
			return;
		}

		foreach ( self::OPTIONS as $old => $new ) {
			$value = get_option( $old, null );

			if ( null !== $value && false === get_option( $new, false ) ) {
				update_option( $new, $value, false );
			}
		}

		update_option( self::DONE, gmdate( 'c' ), false );

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( self::OLD_PLUGIN ) ) {
			deactivate_plugins( self::OLD_PLUGIN, true );
		}
	}
}
