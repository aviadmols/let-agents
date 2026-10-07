<?php

namespace LetAgents\Feed;

use LetAgents\Auth\AccessToken;
use LetAgents\Plugin;
use LetAgents\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What Let Agents needs to know about the site before reading it: versions, store settings,
 * active plugins (which ones hold specs, FAQs or guides), and how much there is to read.
 */
final class SiteStatus {

	/**
	 * @return array<string, mixed>
	 */
	public function status(): array {
		global $wp_version;

		$theme = wp_get_theme();

		return array(
			'plugin'      => array(
				'version' => LET_AGENTS_VERSION,
				'token'   => $this->token_summary(),
				'content_post_types' => Settings::content_post_types(),
			),
			'site'        => array(
				'name'             => get_bloginfo( 'name' ),
				'home_url'         => home_url( '/' ),
				'rest_url'         => rest_url( 'let-agents/v1/' ),
				'locale'           => get_locale(),
				'rtl'              => is_rtl(),
				'timezone'         => wp_timezone_string(),
				'wordpress'        => $wp_version,
				'php'              => PHP_VERSION,
				'multisite'        => is_multisite(),
				'pretty_permalinks' => '' !== (string) get_option( 'permalink_structure' ),
			),
			'woocommerce' => $this->woocommerce(),
			'theme'       => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			),
			'plugins'     => $this->active_plugins(),
			'counts'      => $this->counts(),
		);
	}

	/**
	 * A cheap signal of whether anything changed: counts and the latest modification time per
	 * entity, and one fingerprint over all of it.
	 *
	 * @return array<string, mixed>
	 */
	public function manifest(): array {
		global $wpdb;

		$entities = array();

		if ( Plugin::woocommerce_active() ) {
			$products = $wpdb->get_row( "SELECT COUNT(*) AS total, MAX(post_modified_gmt) AS modified, MAX(ID) AS max_id FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation') AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB

			$entities['products'] = array(
				'published'   => (int) ( wp_count_posts( 'product' )->publish ?? 0 ),
				'variations'  => (int) ( wp_count_posts( 'product_variation' )->publish ?? 0 ),
				'modified_at' => $products->modified ? gmdate( 'c', (int) strtotime( $products->modified . ' UTC' ) ) : null,
				'max_id'      => (int) $products->max_id,
			);

			$entities['categories'] = array( 'count' => (int) wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) );
			$entities['attributes'] = array( 'count' => count( wc_get_attribute_taxonomies() ) );
		}

		foreach ( Settings::content_post_types() as $type ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, MAX(post_modified_gmt) AS modified FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = ''", $type ) ); // phpcs:ignore WordPress.DB

			$entities[ 'content:' . $type ] = array(
				'published'   => (int) $row->total,
				'modified_at' => $row->modified ? gmdate( 'c', (int) strtotime( $row->modified . ' UTC' ) ) : null,
			);
		}

		return array(
			'entities'    => $entities,
			'fingerprint' => hash( 'sha256', (string) wp_json_encode( $entities ) ),
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function token_summary(): ?array {
		$token = AccessToken::current();

		return null === $token ? null : array(
			'prefix'       => $token['prefix'],
			'created_at'   => $token['created_at'],
			'last_used_at' => $token['last_used_at'],
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function woocommerce(): array {
		if ( ! Plugin::woocommerce_active() ) {
			return array( 'active' => false );
		}

		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		return array(
			'active'             => true,
			'version'            => WC()->version,
			'currency'           => get_woocommerce_currency(),
			'prices_include_tax' => wc_prices_include_tax(),
			'weight_unit'        => get_option( 'woocommerce_weight_unit' ),
			'dimension_unit'     => get_option( 'woocommerce_dimension_unit' ),
			'hpos'               => $hpos,
			'store_api'          => class_exists( \Automattic\WooCommerce\StoreApi\StoreApi::class ),
			'brands_taxonomy'    => taxonomy_exists( 'product_brand' ),
		);
	}

	/**
	 * @return list<array{name: string, version: string, file: string}>
	 */
	private function active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$active    = array();

		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $installed[ $file ] ) ) {
				$active[] = array(
					'name'    => $installed[ $file ]['Name'],
					'version' => $installed[ $file ]['Version'],
					'file'    => $file,
				);
			}
		}

		usort( $active, static fn ( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );

		return $active;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function counts(): array {
		$counts = array();

		if ( Plugin::woocommerce_active() ) {
			$counts['products']           = array_map( 'intval', (array) wp_count_posts( 'product' ) );
			$counts['variations']         = (int) ( wp_count_posts( 'product_variation' )->publish ?? 0 );
			$counts['product_categories'] = (int) wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
			$counts['product_tags']       = (int) wp_count_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
			$counts['global_attributes']  = count( wc_get_attribute_taxonomies() );
		}

		foreach ( array_keys( Settings::available_content_post_types() ) as $type ) {
			$counts['content'][ $type ] = (int) ( wp_count_posts( $type )->publish ?? 0 );
		}

		return $counts;
	}
}
