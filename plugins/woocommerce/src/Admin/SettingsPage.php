<?php

namespace LetAgents\Admin;

use LetAgents\Auth\AccessToken;
use LetAgents\Auth\TokenGuard;
use LetAgents\Plugin;
use LetAgents\Settings;
use LetAgents\Storefront\OrderHistory;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce > Let Agents. Creates and revokes the access token and chooses which content Let Agents
 * may read. Text follows the admin user's language; Hebrew renders right-to-left through
 * WordPress's own RTL admin styles.
 */
final class SettingsPage {

	public const SLUG = 'let-agents';

	private const CAPABILITY = 'manage_woocommerce';

	/** The new token is handed from the redirect to the page once, then deleted. */
	private const NEW_TOKEN_TRANSIENT = 'let_agents_new_token_';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 99 );
		add_action( 'admin_post_let_agents_generate_token', array( self::class, 'generate_token' ) );
		add_action( 'admin_post_let_agents_revoke_token', array( self::class, 'revoke_token' ) );
		add_action( 'admin_post_let_agents_save_settings', array( self::class, 'save_settings' ) );
		add_action( 'admin_post_let_agents_save_mode', array( self::class, 'save_mode' ) );
		add_action( 'admin_post_let_agents_send_order_history', array( self::class, 'send_order_history' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LET_AGENTS_FILE ), array( self::class, 'action_links' ) );
		add_action( 'admin_notices', array( self::class, 'woocommerce_missing_notice' ) );
	}

	public static function menu(): void {
		$parent = Plugin::woocommerce_active() ? 'woocommerce' : 'options-general.php';

		add_submenu_page( $parent, 'Let Agents', 'Let Agents', self::capability(), self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'let-agents' ) ) );

		return $links;
	}

	public static function woocommerce_missing_notice(): void {
		if ( Plugin::woocommerce_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Let Agents needs WooCommerce. Activate WooCommerce so Let Agents can read the catalog.', 'let-agents' )
		);
	}

	public static function generate_token(): void {
		self::authorize( 'let_agents_generate_token' );

		set_transient( self::NEW_TOKEN_TRANSIENT . get_current_user_id(), AccessToken::issue(), 2 * MINUTE_IN_SECONDS );

		wp_safe_redirect( self::url( array( 'let_agents_notice' => 'generated' ) ) );
		exit;
	}

	public static function revoke_token(): void {
		self::authorize( 'let_agents_revoke_token' );

		AccessToken::revoke();

		wp_safe_redirect( self::url( array( 'let_agents_notice' => 'revoked' ) ) );
		exit;
	}

	public static function save_settings(): void {
		self::authorize( 'let_agents_save_settings' );

		$types = isset( $_POST['content_post_types'] ) ? (array) wp_unslash( $_POST['content_post_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized against registered post types.
		Settings::save_content_post_types( array_map( 'sanitize_key', $types ) );

		wp_safe_redirect( self::url( array( 'let_agents_notice' => 'saved' ) ) );
		exit;
	}

	public static function save_mode(): void {
		self::authorize( 'let_agents_save_mode' );

		Settings::save_mode( isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'preview' );

		wp_safe_redirect( self::url( array( 'let_agents_notice' => 'saved' ) ) );
		exit;
	}

	public static function send_order_history(): void {
		self::authorize( 'let_agents_send_order_history' );

		$started = OrderHistory::start();

		wp_safe_redirect( self::url( array( 'let_agents_notice' => $started ? 'history' : 'history_unavailable' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Let Agents.', 'let-agents' ) );
		}

		$user_id   = get_current_user_id();
		$new_token = get_transient( self::NEW_TOKEN_TRANSIENT . $user_id );
		delete_transient( self::NEW_TOKEN_TRANSIENT . $user_id );

		$token  = AccessToken::current();
		$notice = isset( $_GET['let_agents_notice'] ) ? sanitize_key( wp_unslash( $_GET['let_agents_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		?>
		<div class="wrap let-agents-settings">
			<h1><?php esc_html_e( 'Let Agents', 'let-agents' ); ?></h1>
			<p><?php esc_html_e( 'Let Agents reads this store\'s products, categories and content to build the shopping assistant. Access is read-only and needs the token below. Customer details are never shared: for reports, Let Agents gets only order totals and product IDs.', 'let-agents' ); ?></p>

			<?php self::render_notice( $notice, is_string( $new_token ) ); ?>

			<?php if ( is_string( $new_token ) && '' !== $new_token ) : ?>
				<div class="notice notice-success let-agents-new-token">
					<p><strong><?php esc_html_e( 'Your new token. Copy it now: it is shown only once.', 'let-agents' ); ?></strong></p>
					<p>
						<input type="text" id="let-agents-token" class="large-text code" dir="ltr" readonly value="<?php echo esc_attr( $new_token ); ?>" onclick="this.select();" />
					</p>
					<p>
						<button type="button" class="button button-primary" id="let-agents-copy-token"><?php esc_html_e( 'Copy token', 'let-agents' ); ?></button>
						<span id="let-agents-copied" hidden><?php esc_html_e( 'Copied.', 'let-agents' ); ?></span>
					</p>
				</div>
				<script>
					document.getElementById('let-agents-copy-token').addEventListener('click', function () {
						var field = document.getElementById('let-agents-token');
						field.select();
						(navigator.clipboard ? navigator.clipboard.writeText(field.value) : Promise.reject()).catch(function () { document.execCommand('copy'); }).finally(function () {
							document.getElementById('let-agents-copied').hidden = false;
						});
					});
				</script>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Access token', 'let-agents' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'let-agents' ); ?></th>
					<td>
						<?php if ( null === $token ) : ?>
							<?php esc_html_e( 'No token. Let Agents cannot read this store yet.', 'let-agents' ); ?>
						<?php else : ?>
							<p><?php esc_html_e( 'Active', 'let-agents' ); ?> — <code dir="ltr"><?php echo esc_html( $token['prefix'] ); ?>…</code></p>
							<p class="description">
								<?php
								printf(
									/* translators: 1: creation date, 2: last use date or "never" */
									esc_html__( 'Created %1$s. Last used %2$s.', 'let-agents' ),
									esc_html( self::date( $token['created_at'] ) ),
									esc_html( $token['last_used_at'] ? self::date( $token['last_used_at'] ) : __( 'never', 'let-agents' ) )
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API address', 'let-agents' ); ?></th>
					<td>
						<code dir="ltr"><?php echo esc_html( rest_url( 'let-agents/v1/' ) ); ?></code>
						<p class="description">
							<?php
							printf(
								/* translators: %s: HTTP header name */
								esc_html__( 'Send the token in the %s header.', 'let-agents' ),
								'<code dir="ltr">' . esc_html( TokenGuard::HEADER ) . '</code>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-inline-end:8px">
				<input type="hidden" name="action" value="let_agents_generate_token" />
				<?php wp_nonce_field( 'let_agents_generate_token' ); ?>
				<?php
				submit_button(
					null === $token ? __( 'Create token', 'let-agents' ) : __( 'Replace token', 'let-agents' ),
					'primary',
					'submit',
					false,
					null === $token ? array() : array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'The current token will stop working immediately. Continue?', 'let-agents' ) ) . ');' )
				);
				?>
			</form>

			<?php if ( null !== $token ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
					<input type="hidden" name="action" value="let_agents_revoke_token" />
					<?php wp_nonce_field( 'let_agents_revoke_token' ); ?>
					<?php submit_button( __( 'Revoke token', 'let-agents' ), 'delete', 'submit', false, array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'Let Agents will lose access to this store immediately. Continue?', 'let-agents' ) ) . ');' ) ); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Lets Search on the store', 'let-agents' ); ?></h2>
			<p><?php esc_html_e( 'One script from Let Agents: it opens from the store\'s search box and search icons, and on product pages and articles it starts with what matters about the page. Suggestions forgive spelling mistakes, results come with live prices and add to cart, and shoppers can ask questions and keep a wishlist. If Let Agents does not answer, the store\'s own search runs as before.', 'let-agents' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="let_agents_save_mode" />
				<?php wp_nonce_field( 'let_agents_save_mode' ); ?>
				<?php
				$mode  = Settings::mode();
				$modes = array(
					'off'     => array( __( 'Off', 'let-agents' ), __( 'Nothing is added to the store.', 'let-agents' ) ),
					'preview' => array( __( 'Preview', 'let-agents' ), __( 'Only the store team sees Lets Search: managers logged in to WordPress, or a browser that opened a preview link from Let Agents.', 'let-agents' ) ),
					'live'    => array( __( 'Live', 'let-agents' ), __( 'Every visitor sees Lets Search.', 'let-agents' ) ),
				);
				?>
				<fieldset>
					<?php foreach ( $modes as $value => $text ) : ?>
						<label style="display:block;margin-block:6px">
							<input type="radio" name="mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?> />
							<strong><?php echo esc_html( $text[0] ); ?></strong> — <?php echo esc_html( $text[1] ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php if ( null === $token ) : ?>
					<p class="description"><?php esc_html_e( 'Lets Search loads only after a token is created and connected in Let Agents.', 'let-agents' ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Put [let_agents_wishlist] in a header or a menu for a heart that opens the wishlist, or link to #wishlist.', 'let-agents' ); ?></p>
				<?php submit_button( __( 'Save', 'let-agents' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Past orders', 'let-agents' ); ?></h2>
			<p><?php esc_html_e( 'So Let Agents can learn what sells together from before it was installed, the store sends it its paid orders from the last 24 months, once. Only order totals, product IDs and quantities: never the customer.', 'let-agents' ); ?></p>
			<?php
			$history  = OrderHistory::state();
			$statuses = array(
				'not_started' => __( 'Not sent yet. It starts by itself once the store is connected.', 'let-agents' ),
				'running'     => __( 'Sending in the background.', 'let-agents' ),
				'done'        => __( 'Sent.', 'let-agents' ),
				'failed'      => __( 'Let Agents could not be reached. Try again later.', 'let-agents' ),
			);
			?>
			<p>
				<strong><?php echo esc_html( $statuses[ $history['status'] ] ?? $history['status'] ); ?></strong>
				<?php if ( 'not_started' !== $history['status'] ) : ?>
					<?php
					printf(
						/* translators: 1: orders sent, 2: orders found */
						esc_html__( '%1$d of %2$d orders sent.', 'let-agents' ),
						(int) $history['sent'],
						(int) $history['expected']
					);
					?>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="let_agents_send_order_history" />
				<?php wp_nonce_field( 'let_agents_send_order_history' ); ?>
				<?php submit_button( __( 'Send past orders again', 'let-agents' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Content Let Agents may read', 'let-agents' ); ?></h2>
			<p><?php esc_html_e( 'Guides and articles Let Agents can show as related reading. Only published entries without a password are shared.', 'let-agents' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="let_agents_save_settings" />
				<?php wp_nonce_field( 'let_agents_save_settings' ); ?>
				<fieldset>
					<?php $selected = Settings::content_post_types(); ?>
					<?php foreach ( Settings::available_content_post_types() as $slug => $label ) : ?>
						<label style="display:block;margin-block:4px">
							<input type="checkbox" name="content_post_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected, true ) ); ?> />
							<?php echo esc_html( $label ); ?> <code dir="ltr"><?php echo esc_html( $slug ); ?></code>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<?php submit_button( __( 'Save', 'let-agents' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_notice( string $notice, bool $token_shown ): void {
		$messages = array(
			'revoked' => array( 'warning', __( 'The token was revoked. Let Agents can no longer read this store.', 'let-agents' ) ),
			'saved'   => array( 'success', __( 'Settings saved.', 'let-agents' ) ),
			'history' => array( 'success', __( 'Sending past orders to Let Agents. It runs in the background and can take a while in a large store.', 'let-agents' ) ),
			'history_unavailable' => array( 'warning', __( 'Past orders can be sent only when the store is connected to Let Agents and Lets Search is not off.', 'let-agents' ) ),
		);

		if ( 'generated' === $notice && ! $token_shown ) {
			$messages['generated'] = array( 'warning', __( 'A token was created, but it can be shown only right after creation. If you did not copy it, replace it.', 'let-agents' ) );
		}

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Let Agents.', 'let-agents' ), '', array( 'response' => 403 ) );
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
	public static function url( array $args = array() ): string {
		$base = Plugin::woocommerce_active() ? admin_url( 'admin.php' ) : admin_url( 'options-general.php' );

		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), $base );
	}

	private static function date( string $iso ): string {
		$timestamp = strtotime( $iso );

		return false === $timestamp ? $iso : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
