<?php

namespace LetAgents\Admin;

use LetAgents\Auth\AccessToken;
use LetAgents\Storefront\LetAgentsApi;

defined( 'ABSPATH' ) || exit;

/**
 * "Lets Search" in the wp-admin menu: the shop's Lets panel, inside WordPress.
 *
 * Each opening asks Let Agents, signed with the store's token, for a one-time sign-in address good
 * for a minute, and the frame opens it: the store's own Lets account is signed in, in this shop's
 * panel only. The token never reaches the browser, and the address works once. Only people who
 * manage the store in WordPress see the page.
 */
final class PanelPage {

	public const SLUG = 'lets-search';

	private const CAPABILITY = 'manage_woocommerce';

	/** The Lets mark: two halves of a circle, as the drawer's launcher draws it. */
	private const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iI2EwYTVhYSIgZD0iTTQgM2gxMmE2IDYgMCAwIDEtMTIgMHpNNCAxN2gxMmE2IDYgMCAwIDAtMTIgMHoiLz48L3N2Zz4=';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	public static function menu(): void {
		add_menu_page( 'Lets Search', 'Lets Search', self::capability(), self::SLUG, array( self::class, 'render' ), self::ICON, 56 );
	}

	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'let-agents' ) );
		}

		echo '<style>#wpcontent{padding-inline-start:0}#wpbody-content{padding-bottom:0}#wpfooter{display:none}.lets-panel{display:block;width:100%;height:calc(100vh - 32px);border:0;background:#f6f6f7}@media (max-width:782px){.lets-panel{height:calc(100vh - 46px)}}.lets-panel-note{max-width:640px;margin:48px auto;padding:0 20px;font-size:14px;line-height:1.6}.lets-panel-note h1{font-size:20px}</style>';

		if ( null === AccessToken::current() ) {
			self::note( __( 'Connect the store to Lets first', 'let-agents' ), __( 'Create an access token in the plugin settings and connect it in Lets. The panel opens here once the store is connected.', 'let-agents' ), true );
			return;
		}

		$user   = wp_get_current_user();
		$answer = LetAgentsApi::ask(
			'panel-login',
			array(
				'locale' => 0 === strpos( get_user_locale( $user ), 'he' ) ? 'he' : 'en',
				'name'   => (string) $user->display_name,
			)
		);

		if ( is_wp_error( $answer ) || empty( $answer['url'] ) || ! is_string( $answer['url'] ) ) {
			$why = is_wp_error( $answer ) ? $answer->get_error_message() : __( 'Lets did not answer as expected.', 'let-agents' );
			self::note( __( 'The panel could not open', 'let-agents' ), $why, true );
			return;
		}

		printf(
			'<iframe class="lets-panel" src="%s" title="%s" allow="clipboard-write" referrerpolicy="no-referrer"></iframe>',
			esc_url( $answer['url'] ),
			esc_attr__( 'Lets Search panel', 'let-agents' )
		);
	}

	private static function note( string $title, string $body, bool $settings ): void {
		echo '<div class="lets-panel-note"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $body ) . '</p>';
		if ( $settings ) {
			echo '<p><a class="button button-primary" href="' . esc_url( SettingsPage::url() ) . '">' . esc_html__( 'Plugin settings', 'let-agents' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Try again', 'let-agents' ) . '</a></p>';
		}
		echo '</div>';
	}

	private static function capability(): string {
		return class_exists( 'WooCommerce' ) ? self::CAPABILITY : 'manage_options';
	}
}
