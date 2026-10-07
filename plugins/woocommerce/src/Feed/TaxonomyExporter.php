<?php

namespace LetAgents\Feed;

use LetAgents\Support\PlainText;
use LetAgents\Support\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Categories and attributes: the vocabulary Let Agents builds comparison sets and mappings from.
 */
final class TaxonomyExporter {

	private const MAX_TERMS_PER_ATTRIBUTE = 200;

	/** How many products' attribute lists to scan when looking for custom attributes. */
	private const CUSTOM_ATTRIBUTE_SCAN_LIMIT = 5000;

	public function __construct( private ProductExporter $products ) {}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$records = array();

		foreach ( $terms as $term ) {
			$image_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
			$link     = get_term_link( $term );

			$records[] = Records::with_hash(
				array(
					'external_id' => (string) $term->term_id,
					'name'        => PlainText::line( $term->name ),
					'slug'        => $term->slug,
					'parent_id'   => $term->parent ? (string) $term->parent : null,
					'path'        => $this->products->category_path( (int) $term->term_id ),
					'description' => PlainText::from( $term->description ),
					'product_count' => (int) $term->count,
					'url'         => is_wp_error( $link ) ? '' : $link,
					'image'       => $image_id ? (string) wp_get_attachment_url( $image_id ) : '',
				)
			);
		}

		return $records;
	}

	/**
	 * Global attributes (taxonomies with terms) and the custom attributes typed on products.
	 *
	 * @return array{global: list<array<string, mixed>>, custom: list<array{name: string, product_count: int, sample_values: list<string>}>}
	 */
	public function attributes(): array {
		$global = array();

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			$terms    = taxonomy_exists( $taxonomy )
				? get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'number'     => self::MAX_TERMS_PER_ATTRIBUTE,
					)
				)
				: array();
			$terms    = is_array( $terms ) ? $terms : array();

			$global[] = array(
				'external_id' => (string) $attribute->attribute_id,
				'name'        => PlainText::line( $attribute->attribute_label ),
				'key'         => $taxonomy,
				'type'        => $attribute->attribute_type,
				'term_count'  => taxonomy_exists( $taxonomy ) ? (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) : 0,
				'terms'       => array_values(
					array_map(
						static fn ( $term ): array => array(
							'name'          => PlainText::line( $term->name ),
							'slug'          => $term->slug,
							'product_count' => (int) $term->count,
						),
						$terms
					)
				),
			);
		}

		return array(
			'global' => $global,
			'custom' => $this->custom_attributes(),
		);
	}

	/**
	 * Custom attributes are stored per product in the _product_attributes meta, so the only
	 * way to list them is to read those rows.
	 *
	 * @return list<array{name: string, product_count: int, sample_values: list<string>}>
	 */
	private function custom_attributes(): array {
		global $wpdb;

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_product_attributes' AND p.post_type = 'product' ORDER BY p.ID DESC LIMIT %d",
				self::CUSTOM_ATTRIBUTE_SCAN_LIMIT
			)
		);

		$found = array();

		foreach ( $rows as $row ) {
			$attributes = maybe_unserialize( $row );

			if ( ! is_array( $attributes ) ) {
				continue;
			}

			foreach ( $attributes as $attribute ) {
				if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) || empty( $attribute['name'] ) ) {
					continue;
				}

				$name = PlainText::line( (string) $attribute['name'] );

				$found[ $name ] ??= array( 'name' => $name, 'product_count' => 0, 'sample_values' => array() );
				++$found[ $name ]['product_count'];

				if ( count( $found[ $name ]['sample_values'] ) < 10 ) {
					foreach ( array_map( 'trim', explode( '|', (string) ( $attribute['value'] ?? '' ) ) ) as $value ) {
						if ( '' !== $value && ! in_array( $value, $found[ $name ]['sample_values'], true ) && count( $found[ $name ]['sample_values'] ) < 10 ) {
							$found[ $name ]['sample_values'][] = PlainText::line( $value );
						}
					}
				}
			}
		}

		usort( $found, static fn ( array $a, array $b ): int => $b['product_count'] <=> $a['product_count'] );

		return array_values( $found );
	}
}
