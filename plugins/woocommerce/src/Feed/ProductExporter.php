<?php

namespace LetAgents\Feed;

use LetAgents\Support\PlainText;
use LetAgents\Support\Records;
use LetAgents\Support\SensitiveFields;
use WC_Product;
use WC_Product_Attribute;

defined( 'ABSPATH' ) || exit;

/**
 * Reads products into the feed shape Let Agents ingests.
 *
 * Attributes are exported raw, as the store has them. Mapping them to normalized keys with
 * units ("550W" to power_w = 550) happens in Let Agents, where the mapping can be reviewed.
 * Price and stock are included for filtering and calculation only; Let Agents shows visitors live
 * values, never these.
 */
final class ProductExporter {

	public const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	private const MAX_VARIATIONS = 100;

	private const MAX_META_VALUE_CHARS = 500;

	/** Meta written by WordPress and WooCommerce internals that says nothing about the product. */
	private const IGNORED_PUBLIC_META = array( 'total_sales' );

	/** @var array<int, list<string>> term_id => names from root to term */
	private array $category_paths = array();

	/**
	 * Product IDs for one page, in ascending ID order, plus one extra to detect a next page.
	 *
	 * A product counts as changed when the product or any of its variations changed, because
	 * saving a variation does not always touch the parent.
	 *
	 * @param list<string> $statuses
	 * @return list<int>
	 */
	public function ids( array $statuses, int $after, int $limit, ?string $since ): array {
		global $wpdb;

		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params              = array_merge( $statuses, array( $after ) );
		$changed             = '';

		if ( null !== $since ) {
			$changed  = " AND ( p.post_modified_gmt >= %s OR p.ID IN ( SELECT v.post_parent FROM {$wpdb->posts} v WHERE v.post_type = 'product_variation' AND v.post_modified_gmt >= %s ) )";
			$params[] = $since;
			$params[] = $since;
		}

		$params[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are built above.
		$sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status IN ( {$status_placeholders} ) AND p.ID > %d{$changed} ORDER BY p.ID ASC LIMIT %d";

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @param list<int> $ids
	 * @return list<array<string, mixed>>
	 */
	public function export_many( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		_prime_post_caches( $ids, true, true );

		$records = array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( $product instanceof WC_Product ) {
				$records[] = $this->export( $product );
			}
		}

		return $records;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function export( WC_Product $product ): array {
		$id         = $product->get_id();
		$categories = $this->categories( $product->get_category_ids() );

		$record = array(
			'external_id'       => (string) $id,
			'platform'          => 'woocommerce',
			'type'              => $product->get_type(),
			'status'            => $product->get_status(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'title'             => PlainText::line( $product->get_name() ),
			'slug'              => $product->get_slug(),
			'url'               => get_permalink( $id ),
			'sku'               => (string) $product->get_sku(),
			'gtin'              => method_exists( $product, 'get_global_unique_id' ) ? (string) $product->get_global_unique_id() : '',
			'short_description' => PlainText::from( $product->get_short_description() ),
			'description'       => PlainText::from( $product->get_description() ),
			'images'            => $this->images( $product ),
			'category_path'     => $this->primary_category_path( $id, $categories ),
			'categories'        => $categories,
			'tags'              => $this->term_names( $id, 'product_tag' ),
			'brands'            => taxonomy_exists( 'product_brand' ) ? $this->term_names( $id, 'product_brand' ) : array(),
			'attributes'        => $this->attributes( $product ),
			'meta'              => $this->public_meta( $id ),
			'relations'         => $this->relations( $product ),
			'price'             => $this->price( $product ),
			'stock'             => $this->stock( $product ),
			'purchasable'       => $product->is_purchasable(),
			'dimensions'        => array(
				'weight'         => (string) $product->get_weight(),
				'length'         => (string) $product->get_length(),
				'width'          => (string) $product->get_width(),
				'height'         => (string) $product->get_height(),
				'weight_unit'    => (string) get_option( 'woocommerce_weight_unit' ),
				'dimension_unit' => (string) get_option( 'woocommerce_dimension_unit' ),
			),
			'rating'            => array(
				'average' => (float) $product->get_average_rating(),
				'count'   => (int) $product->get_review_count(),
			),
			'variations'        => array(),
			'created_at'        => Records::iso( $product->get_date_created() ),
			'updated_at'        => Records::iso( $product->get_date_modified() ),
		);

		if ( $product->is_type( 'variable' ) ) {
			[ $record['variations'], $record['variations_truncated'] ] = $this->variations( $product );
		}

		return Records::with_hash( $record );
	}

	/**
	 * @return list<array{url: string, alt: string}>
	 */
	private function images( WC_Product $product ): array {
		$images = array();

		foreach ( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) as $attachment_id ) {
			$url = wp_get_attachment_url( (int) $attachment_id );

			if ( $url ) {
				$images[] = array(
					'url' => $url,
					'alt' => PlainText::line( (string) get_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', true ) ),
				);
			}
		}

		return $images;
	}

	/**
	 * @param list<int> $category_ids
	 * @return list<array{id: string, name: string, slug: string, path: list<string>}>
	 */
	private function categories( array $category_ids ): array {
		$categories = array();

		// WordPress does not promise an order, and a term-ordering plugin can change it between
		// requests. Sorted, an unchanged product always has the same hash.
		$category_ids = array_map( 'intval', $category_ids );
		sort( $category_ids, SORT_NUMERIC );

		foreach ( $category_ids as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );

			if ( $term && ! is_wp_error( $term ) ) {
				$categories[] = array(
					'id'   => (string) $term->term_id,
					'name' => PlainText::line( $term->name ),
					'slug' => $term->slug,
					'path' => $this->category_path( (int) $term->term_id ),
				);
			}
		}

		return $categories;
	}

	/**
	 * @return list<string>
	 */
	public function category_path( int $term_id ): array {
		if ( isset( $this->category_paths[ $term_id ] ) ) {
			return $this->category_paths[ $term_id ];
		}

		$path = array();

		foreach ( array_reverse( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( (int) $ancestor_id, 'product_cat' );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				$path[] = PlainText::line( $ancestor->name );
			}
		}

		$term = get_term( $term_id, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			$path[] = PlainText::line( $term->name );
		}

		return $this->category_paths[ $term_id ] = $path;
	}

	/**
	 * The primary category chosen in Yoast or Rank Math when there is one, otherwise the
	 * deepest category the product is in.
	 *
	 * @param list<array{id: string, path: list<string>}> $categories
	 * @return list<string>
	 */
	private function primary_category_path( int $product_id, array $categories ): array {
		foreach ( array( '_yoast_wpseo_primary_product_cat', 'rank_math_primary_product_cat' ) as $meta_key ) {
			$primary = (string) get_post_meta( $product_id, $meta_key, true );

			foreach ( $categories as $category ) {
				if ( '' !== $primary && $category['id'] === $primary ) {
					return $category['path'];
				}
			}
		}

		$deepest = array();

		foreach ( $categories as $category ) {
			if ( count( $category['path'] ) > count( $deepest ) ) {
				$deepest = $category['path'];
			}
		}

		return $deepest;
	}

	/**
	 * @return list<string>
	 */
	private function term_names( int $product_id, string $taxonomy ): array {
		$terms = get_the_terms( $product_id, $taxonomy );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$names = array_map( static fn ( $term ): string => PlainText::line( $term->name ), $terms );
		sort( $names, SORT_STRING );

		return array_values( $names );
	}

	/**
	 * @return list<array{name: string, key: string, taxonomy: bool, values: list<string>, used_for_variations: bool, visible: bool}>
	 */
	private function attributes( WC_Product $product ): array {
		$attributes = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}

			$key = $attribute->get_name();

			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $key, array( 'fields' => 'names' ) )
				: $attribute->get_options();

			$attributes[] = array(
				'name'                => PlainText::line( wc_attribute_label( $key, $product ) ),
				'key'                 => $key,
				'taxonomy'            => $attribute->is_taxonomy(),
				'values'              => array_values( array_map( array( PlainText::class, 'line' ), array_map( 'strval', (array) $values ) ) ),
				'used_for_variations' => $attribute->get_variation(),
				'visible'             => $attribute->get_visible(),
			);
		}

		return $attributes;
	}

	/**
	 * Custom fields whose keys do not start with "_" (ACF values, store-specific specs).
	 * Private keys are plugin internals. Arrays and serialized values are skipped, and so are
	 * costs, supplier details and internal notes (see SensitiveFields).
	 *
	 * @return array<string, string>
	 */
	private function public_meta( int $product_id ): array {
		$meta = array();

		foreach ( (array) get_post_meta( $product_id ) as $key => $values ) {
			$key = (string) $key;

			if ( '' === $key || '_' === $key[0] || in_array( $key, self::IGNORED_PUBLIC_META, true ) || SensitiveFields::is_sensitive( $key ) ) {
				continue;
			}

			$value = $values[0] ?? null;

			if ( ! is_scalar( $value ) || is_serialized( (string) $value ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' !== $value ) {
				$meta[ $key ] = mb_substr( $value, 0, self::MAX_META_VALUE_CHARS );
			}
		}

		ksort( $meta );

		return $meta;
	}

	/**
	 * Relations the merchant declared in WooCommerce. Let Agents treats these as the most trusted
	 * source for complementary and alternative products.
	 *
	 * @return list<array{type: string, target: string, source: string}>
	 */
	private function relations( WC_Product $product ): array {
		$relations = array();

		foreach ( $product->get_upsell_ids() as $id ) {
			$relations[] = array( 'type' => 'upsell', 'target' => (string) $id, 'source' => 'merchant' );
		}

		foreach ( $product->get_cross_sell_ids() as $id ) {
			$relations[] = array( 'type' => 'cross_sell', 'target' => (string) $id, 'source' => 'merchant' );
		}

		if ( $product->is_type( 'grouped' ) ) {
			foreach ( $product->get_children() as $id ) {
				$relations[] = array( 'type' => 'grouped_item', 'target' => (string) $id, 'source' => 'merchant' );
			}
		}

		return $relations;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function price( WC_Product $product ): array {
		$price = array(
			'currency'      => get_woocommerce_currency(),
			'price'         => self::decimal( $product->get_price() ),
			'regular_price' => self::decimal( $product->get_regular_price() ),
			'sale_price'    => self::decimal( $product->get_sale_price() ),
			'on_sale'       => $product->is_on_sale(),
			'sale_from'     => Records::iso( $product->get_date_on_sale_from() ),
			'sale_to'       => Records::iso( $product->get_date_on_sale_to() ),
		);

		if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
			$price['min_price'] = self::decimal( $product->get_variation_price( 'min' ) );
			$price['max_price'] = self::decimal( $product->get_variation_price( 'max' ) );
		}

		return $price;
	}

	/**
	 * One format for every price: "299", "39.9", or "" when unset. WooCommerce returns stored
	 * prices as typed ("249") but computed ones padded ("299.00").
	 */
	private static function decimal( $value ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}

		return (string) wc_format_decimal( $value, false, true );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stock( WC_Product $product ): array {
		return array(
			'status'            => $product->get_stock_status(),
			'in_stock'          => $product->is_in_stock(),
			'managed'           => $product->managing_stock(),
			'quantity'          => $product->managing_stock() ? $product->get_stock_quantity() : null,
			'backorders'        => $product->get_backorders(),
			'sold_individually' => $product->is_sold_individually(),
		);
	}

	/**
	 * @return array{0: list<array<string, mixed>>, 1: bool}
	 */
	private function variations( WC_Product $product ): array {
		$children  = $product->get_children();
		$truncated = count( $children ) > self::MAX_VARIATIONS;
		$children  = array_slice( $children, 0, self::MAX_VARIATIONS );

		if ( array() !== $children ) {
			_prime_post_caches( $children, false, true );
		}

		$variations = array();

		foreach ( $children as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation instanceof WC_Product ) {
				continue;
			}

			$attributes = array();

			foreach ( $variation->get_attributes() as $taxonomy => $value ) {
				$label = $value;

				if ( '' !== $value && taxonomy_exists( (string) $taxonomy ) ) {
					$term  = get_term_by( 'slug', (string) $value, (string) $taxonomy );
					$label = $term ? $term->name : $value;
				}

				$attributes[] = array(
					'key'   => (string) $taxonomy,
					'name'  => PlainText::line( wc_attribute_label( (string) $taxonomy, $product ) ),
					// An empty value means "any": the visitor picks it.
					'value' => PlainText::line( (string) $label ),
				);
			}

			$image_id = $variation->get_image_id();

			$variations[] = array(
				'external_id' => (string) $variation->get_id(),
				'status'      => $variation->get_status(),
				'sku'         => (string) $variation->get_sku(),
				'attributes'  => $attributes,
				'price'       => array(
					'price'         => self::decimal( $variation->get_price() ),
					'regular_price' => self::decimal( $variation->get_regular_price() ),
					'sale_price'    => self::decimal( $variation->get_sale_price() ),
					'on_sale'       => $variation->is_on_sale(),
				),
				'stock'       => $this->stock( $variation ),
				'purchasable' => $variation->is_purchasable(),
				'image'       => $image_id ? (string) wp_get_attachment_url( (int) $image_id ) : '',
				'updated_at'  => Records::iso( $variation->get_date_modified() ),
			);
		}

		return array( $variations, $truncated );
	}
}
