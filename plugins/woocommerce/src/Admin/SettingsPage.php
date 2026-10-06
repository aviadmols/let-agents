<?php

namespace Rega\Admin;

use Rega\Auth\AccessToken;
use Rega\Auth\TokenGuard;
use Rega\Plugin;
use Rega\Settings;
use Rega\Storefront\OrderHistory;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce > Rega. Creates and revokes the access token and chooses which content Rega
 * may read. Text follows the admin user's language; Hebrew renders right-to-left through
 * WordPress's own RTL admin styles.
 */
final class SettingsPage {

	public const SLUG = 'rega';

	private const CAPABILITY = 'manage_woocommerce';

	/** The new token is handed from the redirect to the page once, then deleted. */
	private const NEW_TOKEN_TRANSIENT = 'rega_new_token_';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 99 );
		add_action( 'admin_post_rega_generate_token', array( self::class, 'generate_token' ) );
		add_action( 'admin_post_rega_revoke_token', array( self::class, 'revoke_token' ) );
		add_action( 'admin_post_rega_save_settings', array( self::class, 'save_settings' ) );
		add_action( 'admin_post_rega_save_widget', array( self::class, 'save_widget' ) );
		add_action( 'admin_post_rega_save_search', array( self::class, 'save_search' ) );
		add_action( 'admin_post_rega_send_order_history', array( self::class, 'send_order_history' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( REGA_FILE ), array( self::class, 'action_links' ) );
		add_action( 'admin_notices', array( self::class, 'woocommerce_missing_notice' ) );
	}

	public static function menu(): void {
		$parent = Plugin::woocommerce_active() ? 'woocommerce' : 'options-general.php';

		add_submenu_page( $parent, 'Rega', 'Rega', self::capability(), self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'rega' ) ) );

		return $links;
	}

	public static function woocommerce_missing_notice(): void {
		if ( Plugin::woocommerce_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Rega needs WooCommerce. Activate WooCommerce so Rega can read the catalog.', 'rega' )
		);
	}

	public static function generate_token(): void {
		self::authorize( 'rega_generate_token' );

		set_transient( self::NEW_TOKEN_TRANSIENT . get_current_user_id(), AccessToken::issue(), 2 * MINUTE_IN_SECONDS );

		wp_safe_redirect( self::url( array( 'rega_notice' => 'generated' ) ) );
		exit;
	}

	public static function revoke_token(): void {
		self::authorize( 'rega_revoke_token' );

		AccessToken::revoke();

		wp_safe_redirect( self::url( array( 'rega_notice' => 'revoked' ) ) );
		exit;
	}

	public static function save_settings(): void {
		self::authorize( 'rega_save_settings' );

		$types = isset( $_POST['content_post_types'] ) ? (array) wp_unslash( $_POST['content_post_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized against registered post types.
		Settings::save_content_post_types( array_map( 'sanitize_key', $types ) );
		Settings::save_cta_after_paragraph( isset( $_POST['cta_after_paragraph'] ) ? (int) $_POST['cta_after_paragraph'] : 0 );

		wp_safe_redirect( self::url( array( 'rega_notice' => 'saved' ) ) );
		exit;
	}

	public static function save_widget(): void {
		self::authorize( 'rega_save_widget' );

		Settings::save_widget_mode( isset( $_POST['widget_mode'] ) ? sanitize_key( wp_unslash( $_POST['widget_mode'] ) ) : 'preview' );

		wp_safe_redirect( self::url( array( 'rega_notice' => 'saved' ) ) );
		exit;
	}

	public static function save_search(): void {
		self::authorize( 'rega_save_search' );

		Settings::save_search_mode( isset( $_POST['search_mode'] ) ? sanitize_key( wp_unslash( $_POST['search_mode'] ) ) : 'off' );

		wp_safe_redirect( self::url( array( 'rega_notice' => 'saved' ) ) );
		exit;
	}

	public static function send_order_history(): void {
		self::authorize( 'rega_send_order_history' );

		$started = OrderHistory::start();

		wp_safe_redirect( self::url( array( 'rega_notice' => $started ? 'history' : 'history_unavailable' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Rega.', 'rega' ) );
		}

		$user_id   = get_current_user_id();
		$new_token = get_transient( self::NEW_TOKEN_TRANSIENT . $user_id );
		delete_transient( self::NEW_TOKEN_TRANSIENT . $user_id );

		$token  = AccessToken::current();
		$notice = isset( $_GET['rega_notice'] ) ? sanitize_key( wp_unslash( $_GET['rega_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		?>
		<div class="wrap rega-settings">
			<h1><?php esc_html_e( 'Rega', 'rega' ); ?></h1>
			<p><?php esc_html_e( 'Rega reads this store\'s products, categories and content to build the shopping assistant. Access is read-only and needs the token below. Customer details are never shared: for reports, Rega gets only order totals and product IDs.', 'rega' ); ?></p>

			<?php self::render_notice( $notice, is_string( $new_token ) ); ?>

			<?php if ( is_string( $new_token ) && '' !== $new_token ) : ?>
				<div class="notice notice-success rega-new-token">
					<p><strong><?php esc_html_e( 'Your new token. Copy it now: it is shown only once.', 'rega' ); ?></strong></p>
					<p>
						<input type="text" id="rega-token" class="large-text code" dir="ltr" readonly value="<?php echo esc_attr( $new_token ); ?>" onclick="this.select();" />
					</p>
					<p>
						<button type="button" class="button button-primary" id="rega-copy-token"><?php esc_html_e( 'Copy token', 'rega' ); ?></button>
						<span id="rega-copied" hidden><?php esc_html_e( 'Copied.', 'rega' ); ?></span>
					</p>
				</div>
				<script>
					document.getElementById('rega-copy-token').addEventListener('click', function () {
						var field = document.getElementById('rega-token');
						field.select();
						(navigator.clipboard ? navigator.clipboard.writeText(field.value) : Promise.reject()).catch(function () { document.execCommand('copy'); }).finally(function () {
							document.getElementById('rega-copied').hidden = false;
						});
					});
				</script>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Access token', 'rega' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'rega' ); ?></th>
					<td>
						<?php if ( null === $token ) : ?>
							<?php esc_html_e( 'No token. Rega cannot read this store yet.', 'rega' ); ?>
						<?php else : ?>
							<p><?php esc_html_e( 'Active', 'rega' ); ?> — <code dir="ltr"><?php echo esc_html( $token['prefix'] ); ?>…</code></p>
							<p class="description">
								<?php
								printf(
									/* translators: 1: creation date, 2: last use date or "never" */
									esc_html__( 'Created %1$s. Last used %2$s.', 'rega' ),
									esc_html( self::date( $token['created_at'] ) ),
									esc_html( $token['last_used_at'] ? self::date( $token['last_used_at'] ) : __( 'never', 'rega' ) )
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API address', 'rega' ); ?></th>
					<td>
						<code dir="ltr"><?php echo esc_html( rest_url( 'rega/v1/' ) ); ?></code>
						<p class="description">
							<?php
							printf(
								/* translators: %s: HTTP header name */
								esc_html__( 'Send the token in the %s header.', 'rega' ),
								'<code dir="ltr">' . esc_html( TokenGuard::HEADER ) . '</code>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-inline-end:8px">
				<input type="hidden" name="action" value="rega_generate_token" />
				<?php wp_nonce_field( 'rega_generate_token' ); ?>
				<?php
				submit_button(
					null === $token ? __( 'Create token', 'rega' ) : __( 'Replace token', 'rega' ),
					'primary',
					'submit',
					false,
					null === $token ? array() : array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'The current token will stop working immediately. Continue?', 'rega' ) ) . ');' )
				);
				?>
			</form>

			<?php if ( null !== $token ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
					<input type="hidden" name="action" value="rega_revoke_token" />
					<?php wp_nonce_field( 'rega_revoke_token' ); ?>
					<?php submit_button( __( 'Revoke token', 'rega' ), 'delete', 'submit', false, array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'Rega will lose access to this store immediately. Continue?', 'rega' ) ) . ');' ) ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Widget on the store', 'rega' ); ?></h2>
			<p><?php esc_html_e( 'Where the widget shows on product pages and articles is set in Rega, by a CSS class or selector of your theme.', 'rega' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rega_save_widget" />
				<?php wp_nonce_field( 'rega_save_widget' ); ?>
				<?php
				$mode  = Settings::widget_mode();
				$modes = array(
					'off'     => array( __( 'Off', 'rega' ), __( 'Nothing is added to the store.', 'rega' ) ),
					'preview' => array( __( 'Preview', 'rega' ), __( 'Only the store team sees the widget: managers logged in to WordPress, or a browser that opened a preview link from Rega. Visitors are counted in the reports but see nothing.', 'rega' ) ),
					'live'    => array( __( 'Live', 'rega' ), __( 'Every visitor sees the widget.', 'rega' ) ),
				);
				?>
				<fieldset>
					<?php foreach ( $modes as $value => $text ) : ?>
						<label style="display:block;margin-block:6px">
							<input type="radio" name="widget_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?> />
							<strong><?php echo esc_html( $text[0] ); ?></strong> — <?php echo esc_html( $text[1] ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php if ( null === $token ) : ?>
					<p class="description"><?php esc_html_e( 'The widget loads only after a token is created and connected in Rega.', 'rega' ); ?></p>
				<?php endif; ?>
				<?php submit_button( __( 'Save', 'rega' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Search on the store', 'rega' ); ?></h2>
			<p><?php esc_html_e( 'Rega search in the store\'s own search box: suggestions while typing that forgive spelling mistakes, and results grouped into products, guides and categories. Which search fields it attaches to is set in Rega. If Rega does not answer, the store\'s own search runs as before.', 'rega' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rega_save_search" />
				<?php wp_nonce_field( 'rega_save_search' ); ?>
				<?php
				$search_mode  = Settings::search_mode();
				$search_modes = array(
					'off'     => array( __( 'Off', 'rega' ), __( 'The store\'s search stays as it is.', 'rega' ) ),
					'preview' => array( __( 'Preview', 'rega' ), __( 'Only the store team sees Rega search: managers logged in to WordPress, or a browser that opened a preview link from Rega.', 'rega' ) ),
					'live'    => array( __( 'Live', 'rega' ), __( 'Every visitor searches with Rega.', 'rega' ) ),
				);
				?>
				<fieldset>
					<?php foreach ( $search_modes as $value => $text ) : ?>
						<label style="display:block;margin-block:6px">
							<input type="radio" name="search_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $search_mode, $value ); ?> />
							<strong><?php echo esc_html( $text[0] ); ?></strong> — <?php echo esc_html( $text[1] ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php submit_button( __( 'Save', 'rega' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Past orders', 'rega' ); ?></h2>
			<p><?php esc_html_e( 'So Rega can learn what sells together from before it was installed, the store sends it its paid orders from the last 24 months, once. Only order totals, product IDs and quantities: never the customer.', 'rega' ); ?></p>
			<?php
			$history  = OrderHistory::state();
			$statuses = array(
				'not_started' => __( 'Not sent yet. It starts by itself once the store is connected.', 'rega' ),
				'running'     => __( 'Sending in the background.', 'rega' ),
				'done'        => __( 'Sent.', 'rega' ),
				'failed'      => __( 'Rega could not be reached. Try again later.', 'rega' ),
			);
			?>
			<p>
				<strong><?php echo esc_html( $statuses[ $history['status'] ] ?? $history['status'] ); ?></strong>
				<?php if ( 'not_started' !== $history['status'] ) : ?>
					<?php
					printf(
						/* translators: 1: orders sent, 2: orders found */
						esc_html__( '%1$d of %2$d orders sent.', 'rega' ),
						(int) $history['sent'],
						(int) $history['expected']
					);
					?>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rega_send_order_history" />
				<?php wp_nonce_field( 'rega_send_order_history' ); ?>
				<?php submit_button( __( 'Send past orders again', 'rega' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Content Rega may read', 'rega' ); ?></h2>
			<p><?php esc_html_e( 'Guides and articles Rega can show as related reading. Only published entries without a password are shared.', 'rega' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rega_save_settings" />
				<?php wp_nonce_field( 'rega_save_settings' ); ?>
				<fieldset>
					<?php $selected = Settings::content_post_types(); ?>
					<?php foreach ( Settings::available_content_post_types() as $slug => $label ) : ?>
						<label style="display:block;margin-block:4px">
							<input type="checkbox" name="content_post_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected, true ) ); ?> />
							<?php echo esc_html( $label ); ?> <code dir="ltr"><?php echo esc_html( $slug ); ?></code>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<h3><?php esc_html_e( 'Where the offer sits inside a post', 'rega' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Rega can drop its offer after a paragraph, so you do not have to edit posts. Put [lets_cta] in a post to place it by hand instead — a post that has one is left alone.', 'rega' ); ?></p>
				<p>
					<label>
						<?php esc_html_e( 'After paragraph', 'rega' ); ?>
						<input type="number" name="cta_after_paragraph" min="0" max="20" value="<?php echo esc_attr( (string) Settings::cta_after_paragraph() ); ?>" style="width:5em" />
					</label>
					<span class="description"><?php esc_html_e( '0 places it only where you write the shortcode.', 'rega' ); ?></span>
				</p>
				<?php submit_button( __( 'Save', 'rega' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_notice( string $notice, bool $token_shown ): void {
		$messages = array(
			'revoked' => array( 'warning', __( 'The token was revoked. Rega can no longer read this store.', 'rega' ) ),
			'saved'   => array( 'success', __( 'Settings saved.', 'rega' ) ),
			'history' => array( 'success', __( 'Sending past orders to Rega. It runs in the background and can take a while in a large store.', 'rega' ) ),
			'history_unavailable' => array( 'warning', __( 'Past orders can be sent only when the store is connected to Rega and the widget is not off.', 'rega' ) ),
		);

		if ( 'generated' === $notice && ! $token_shown ) {
			$messages['generated'] = array( 'warning', __( 'A token was created, but it can be shown only right after creation. If you did not copy it, replace it.', 'rega' ) );
		}

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Rega.', 'rega' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce_action );
	}

	/** Shop managers without WooCommerce installed would have no page at all; fall back to options. */
	private static function capability(): string {
		return Plugin::woocommerce_active() ? self::CAPABILITY : 'manage_options';
	}

	/**
	 * @param array<string, string> $args
	 */
	private static function url( array $args = array() ): string {
		$base = Plugin::woocommerce_active() ? admin_url( 'admin.php' ) : admin_url( 'options-general.php' );

		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), $base );
	}

	private static function date( string $iso ): string {
		$timestamp = strtotime( $iso );

		return false === $timestamp ? $iso : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
