<?php

namespace LetAgents\Admin;

use LetAgents\Plugin;
use LetAgents\Storefront\LetAgentsApi;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce > Let Agents reports. What visitors did on the store and with the widget, as Let Agents
 * counted it: page views, hot pages, hot display models, add to cart from the widget, orders
 * and the part of them that came through Let Agents. Counts only, no visitor-level data.
 */
final class ReportsPage {

	public const SLUG = 'let-agents-reports';

	private const PERIODS = array( 7, 30, 90 );

	/** Reports are cached briefly so reloading the page does not call Let Agents every time. */
	private const CACHE_SECONDS = 600;

	private const TRANSIENT = 'let_agents_report_';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 100 );
		add_action( 'admin_post_let_agents_refresh_report', array( self::class, 'refresh' ) );
	}

	public static function menu(): void {
		$parent = Plugin::woocommerce_active() ? 'woocommerce' : 'options-general.php';

		add_submenu_page( $parent, __( 'Let Agents reports', 'let-agents' ), __( 'Let Agents reports', 'let-agents' ), self::capability(), self::SLUG, array( self::class, 'render' ) );
	}

	public static function refresh(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Let Agents.', 'let-agents' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'let_agents_refresh_report' );

		$days = self::days( isset( $_POST['days'] ) ? (int) $_POST['days'] : 30 );
		delete_transient( self::TRANSIENT . $days );

		wp_safe_redirect( self::url( $days ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Let Agents.', 'let-agents' ) );
		}

		$days   = self::days( isset( $_GET['days'] ) ? (int) $_GET['days'] : 30 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$report = self::report( $days );
		?>
		<div class="wrap let-agents-reports">
			<style>
				.let-agents-reports .let-agents-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 16px}
				.let-agents-reports .let-agents-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:20px}
				.let-agents-reports .let-agents-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px}
				.let-agents-reports .let-agents-card .label{color:#50575e;font-size:12px}
				.let-agents-reports .let-agents-card .value{font-size:24px;font-weight:600;margin-top:4px;direction:ltr;unicode-bidi:isolate}
				.let-agents-reports .let-agents-card .hint{color:#50575e;font-size:12px;margin-top:2px}
				.let-agents-reports .let-agents-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;margin-bottom:20px}
				.let-agents-reports .let-agents-panel h2{margin:4px 0 12px;font-size:15px}
				.let-agents-reports .let-agents-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:20px}
				.let-agents-reports table.widefat td,.let-agents-reports table.widefat th{vertical-align:middle}
				.let-agents-reports .num{text-align:center;direction:ltr}
				.let-agents-reports .let-agents-legend{display:flex;gap:16px;font-size:12px;color:#50575e;margin-top:6px}
				.let-agents-reports .let-agents-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-inline-end:4px;vertical-align:middle}
			</style>

			<h1><?php esc_html_e( 'Let Agents reports', 'let-agents' ); ?></h1>

			<div class="let-agents-toolbar">
				<?php foreach ( self::PERIODS as $period ) : ?>
					<a class="button <?php echo $period === $days ? 'button-primary' : ''; ?>" href="<?php echo esc_url( self::url( $period ) ); ?>">
						<?php
						/* translators: %d: number of days */
						echo esc_html( sprintf( __( 'Last %d days', 'let-agents' ), $period ) );
						?>
					</a>
				<?php endforeach; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="let_agents_refresh_report" />
					<input type="hidden" name="days" value="<?php echo esc_attr( (string) $days ); ?>" />
					<?php wp_nonce_field( 'let_agents_refresh_report' ); ?>
					<button type="submit" class="button-link"><?php esc_html_e( 'Refresh', 'let-agents' ); ?></button>
				</form>
			</div>

			<?php if ( is_wp_error( $report ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $report->get_error_message() ); ?></p></div>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<?php
			$totals   = $report['totals'];
			$currency = (string) ( $report['currency'] ?? '' );
			$cards    = array(
				array( __( 'Page views', 'let-agents' ), self::number( $totals['page_views'] ), __( 'Product pages and articles', 'let-agents' ) ),
				array( __( 'Visitors', 'let-agents' ), self::number( $totals['visitors'] ), '' ),
				array( __( 'Widget seen', 'let-agents' ), self::number( $totals['impressions'] ), '' ),
				array( __( 'Widget opened', 'let-agents' ), self::number( $totals['opens'] ), null === $totals['open_rate'] ? '' : self::percent( $totals['open_rate'] ) ),
				array( __( 'Added to cart from Let Agents', 'let-agents' ), self::number( $totals['widget_add_to_cart'] ), '' ),
				array( __( 'Orders', 'let-agents' ), self::number( $totals['orders'] ), self::money( $totals['revenue'], $currency ) ),
				array( __( 'Orders after using Let Agents', 'let-agents' ), self::number( $totals['assisted_orders'] ), '' ),
				array( __( 'Revenue from products added via Let Agents', 'let-agents' ), self::money( $totals['attributed_revenue'], $currency ), '' ),
			);
			?>

			<div class="let-agents-cards">
				<?php foreach ( $cards as $card ) : ?>
					<div class="let-agents-card">
						<div class="label"><?php echo esc_html( $card[0] ); ?></div>
						<div class="value"><?php echo esc_html( $card[1] ); ?></div>
						<?php if ( '' !== $card[2] ) : ?>
							<div class="hint"><?php echo esc_html( $card[2] ); ?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( (int) $totals['preview_events'] > 0 ) : ?>
				<p class="description">
					<?php
					/* translators: %s: number of events */
					echo esc_html( sprintf( __( 'Includes %s events from preview visits by the store team.', 'let-agents' ), self::number( $totals['preview_events'] ) ) );
					?>
				</p>
			<?php endif; ?>

			<div class="let-agents-panel">
				<h2><?php esc_html_e( 'Day by day', 'let-agents' ); ?></h2>
				<?php self::chart( (array) $report['daily'] ); ?>
			</div>

			<div class="let-agents-grid">
				<div class="let-agents-panel">
					<h2><?php esc_html_e( 'Hot pages', 'let-agents' ); ?></h2>
					<?php
					self::table(
						array( __( 'Page', 'let-agents' ), __( 'Views', 'let-agents' ), __( 'Widget seen', 'let-agents' ), __( 'Opened', 'let-agents' ), __( 'Added to cart', 'let-agents' ) ),
						array_map(
							static fn ( array $page ): array => array( array( $page['title'], $page['url'] ?? null ), $page['views'], $page['impressions'], $page['opens'], $page['add_to_cart'] ),
							(array) $report['hot_pages']
						)
					);
					?>
				</div>

				<div class="let-agents-panel">
					<h2><?php esc_html_e( 'Hot display models', 'let-agents' ); ?></h2>
					<?php
					self::table(
						array( __( 'Display model', 'let-agents' ), __( 'Seen', 'let-agents' ), __( 'Opened', 'let-agents' ), __( 'Clicks', 'let-agents' ), __( 'Added to cart', 'let-agents' ) ),
						array_map(
							static fn ( array $model ): array => array( self::model_label( (string) $model['model'] ), $model['impressions'], $model['opens'], $model['clicks'], $model['add_to_cart'] ),
							(array) $report['hot_models']
						)
					);
					?>
				</div>

				<div class="let-agents-panel">
					<h2><?php esc_html_e( 'Products added to cart from Let Agents', 'let-agents' ); ?></h2>
					<?php
					self::table(
						array( __( 'Product', 'let-agents' ), __( 'Added to cart', 'let-agents' ), __( 'Bought', 'let-agents' ) ),
						array_map(
							static fn ( array $product ): array => array( array( $product['title'], get_permalink( (int) $product['id'] ) ?: null ), $product['add_to_cart'], $product['purchased'] ),
							(array) $report['top_products']
						)
					);
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function report( int $days ) {
		$cached = get_transient( self::TRANSIENT . $days );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = LetAgentsApi::get( 'reports', array( 'days' => $days ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['data']['totals'] ) || ! is_array( $response['data'] ) ) {
			return new \WP_Error( 'let_agents_report_shape', __( 'Let Agents sent a report this version of the plugin cannot read. Update the plugin.', 'let-agents' ) );
		}

		set_transient( self::TRANSIENT . $days, $response['data'], self::CACHE_SECONDS );

		return $response['data'];
	}

	/**
	 * Page views as bars, widget opens as darker bars inside them, orders as dots.
	 *
	 * @param list<array<string, mixed>> $days
	 */
	private static function chart( array $days ): void {
		if ( array() === $days ) {
			echo '<p>' . esc_html__( 'No data yet.', 'let-agents' ) . '</p>';
			return;
		}

		$width  = 900;
		$height = 160;
		$max    = max( 1, max( array_map( static fn ( array $d ): int => (int) $d['page_views'], $days ) ) );
		$max_o  = max( 1, max( array_map( static fn ( array $d ): int => (int) $d['orders'], $days ) ) );
		$step   = $width / count( $days );
		$bar    = max( 2, $step * 0.7 );

		echo '<svg viewBox="0 0 ' . esc_attr( (string) $width ) . ' ' . esc_attr( (string) ( $height + 20 ) ) . '" style="width:100%;height:auto;direction:ltr" role="img" aria-label="' . esc_attr__( 'Day by day', 'let-agents' ) . '">';

		foreach ( $days as $i => $day ) {
			$x      = $i * $step + ( $step - $bar ) / 2;
			$views  = (int) $day['page_views'];
			$opens  = (int) $day['opens'];
			$orders = (int) $day['orders'];
			$h      = $height * $views / $max;
			$ho     = $height * min( $opens, $views ) / $max;
			/* translators: 1: date, 2: page views, 3: widget opens, 4: added to cart, 5: orders */
			$title = sprintf( __( '%1$s: %2$d views, %3$d opens, %4$d added to cart, %5$d orders', 'let-agents' ), $day['date'], $views, $opens, (int) $day['add_to_cart'], $orders );

			echo '<g><title>' . esc_html( $title ) . '</title>';
			printf( '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#c3c4c7" rx="2"/>', $x, $height - $h, $bar, $h );
			printf( '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#2271b1" rx="2"/>', $x, $height - $ho, $bar, $ho );
			if ( $orders > 0 ) {
				printf( '<circle cx="%.1f" cy="%.1f" r="4" fill="#00a32a"/>', $x + $bar / 2, $height - ( $height - 8 ) * $orders / $max_o - 4 );
			}
			echo '</g>';
		}

		printf( '<text x="0" y="%d" font-size="11" fill="#50575e">%s</text>', (int) ( $height + 16 ), esc_html( (string) $days[0]['date'] ) );
		printf( '<text x="%d" y="%d" font-size="11" fill="#50575e" text-anchor="end">%s</text>', (int) $width, (int) ( $height + 16 ), esc_html( (string) end( $days )['date'] ) );
		echo '</svg>';
		?>
		<div class="let-agents-legend">
			<span><i style="background:#c3c4c7"></i><?php esc_html_e( 'Page views', 'let-agents' ); ?></span>
			<span><i style="background:#2271b1"></i><?php esc_html_e( 'Widget opened', 'let-agents' ); ?></span>
			<span><i style="background:#00a32a;border-radius:50%"></i><?php esc_html_e( 'Orders', 'let-agents' ); ?></span>
		</div>
		<?php
	}

	/**
	 * @param list<string>       $headings
	 * @param list<list<mixed>>  $rows first cell is text, or [text, url]
	 */
	private static function table( array $headings, array $rows ): void {
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No data yet.', 'let-agents' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $headings as $i => $heading ) {
			echo '<th' . ( $i > 0 ? ' class="num"' : '' ) . '>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $i => $cell ) {
				if ( 0 === $i ) {
					[ $text, $url ] = is_array( $cell ) ? $cell : array( $cell, null );
					echo '<td>' . ( $url ? '<a href="' . esc_url( (string) $url ) . '" target="_blank" rel="noopener">' . esc_html( (string) $text ) . '</a>' : esc_html( (string) $text ) ) . '</td>';
					continue;
				}
				echo '<td class="num">' . esc_html( self::number( $cell ) ) . '</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function model_label( string $model ): string {
		$labels = array(
			'position'         => __( 'Superlatives', 'let-agents' ),
			'specs'            => __( 'Specs in brief', 'let-agents' ),
			'complement'       => __( 'Goes well with it', 'let-agents' ),
			'guide_card'       => __( 'Guides', 'let-agents' ),
			'article_products' => __( 'Products for an article', 'let-agents' ),
			'family'           => __( 'Other sizes', 'let-agents' ),
			'alternative'      => __( 'Similar products', 'let-agents' ),
			'on_sale'          => __( 'Similar on sale', 'let-agents' ),
			'good_for'         => __( 'Good for jobs', 'let-agents' ),
			'explainer'        => __( 'Good to know', 'let-agents' ),
			'compare'          => __( 'Comparison with a viewed product', 'let-agents' ),
		);

		return $labels[ $model ] ?? $model;
	}

	/**
	 * @param mixed $value
	 */
	private static function number( $value ): string {
		return number_format_i18n( (float) $value );
	}

	/**
	 * @param mixed $value
	 */
	private static function percent( $value ): string {
		return number_format_i18n( (float) $value * 100, 1 ) . '%';
	}

	/**
	 * @param mixed $value
	 */
	private static function money( $value, string $currency ): string {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( (float) $value, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
		}

		return number_format_i18n( (float) $value, 2 ) . ' ' . $currency;
	}

	private static function days( int $days ): int {
		return in_array( $days, self::PERIODS, true ) ? $days : 30;
	}

	private static function url( int $days ): string {
		$base = Plugin::woocommerce_active() ? admin_url( 'admin.php' ) : admin_url( 'options-general.php' );

		return add_query_arg(
			array(
				'page' => self::SLUG,
				'days' => $days,
			),
			$base
		);
	}

	private static function capability(): string {
		return Plugin::woocommerce_active() ? 'manage_woocommerce' : 'manage_options';
	}
}
