<?php
/**
 * Removes everything Let Agents stored in this site when the plugin is deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'let_agents_access_token' );
delete_option( 'let_agents_settings' );

global $wpdb;

// Failed-attempt counters and one-time token hand-offs are short-lived transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_let\\_agents\\_%' OR option_name LIKE '\\_transient\\_timeout\\_let\\_agents\\_%'" ); // phpcs:ignore WordPress.DB
