<?php

namespace LetAgents\Feed;

use LetAgents\Support\PlainText;
use LetAgents\Support\Records;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Guides, articles and pages: published, not password protected, from the post types the
 * merchant allowed in the settings.
 *
 * Each record lists the products it mentions, found from links to product pages, product
 * shortcodes and the hand-picked products block. Let Agents uses that to connect a guide to the
 * products it explains.
 */
final class ContentExporter {

	private const MAX_LINKS_TO_RESOLVE = 50;

	/**
	 * @return list<int>
	 */
	public function ids( string $post_type, int $after, int $limit, ?string $since ): array {
		global $wpdb;

		$params  = array( $post_type, $after );
		$changed = '';

		if ( null !== $since ) {
			$changed  = ' AND post_modified_gmt >= %s';
			$params[] = $since;
		}

		$params[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = '' AND ID > %d{$changed} ORDER BY ID ASC LIMIT %d";

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
			$post = get_post( $id );

			if ( $post instanceof WP_Post ) {
				$records[] = $this->export( $post );
			}
		}

		return $records;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function export( WP_Post $post ): array {
		$text  = PlainText::from( $post->post_content );
		$terms = array();

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}

			$assigned = get_the_terms( $post, $taxonomy->name );

			if ( is_array( $assigned ) && array() !== $assigned ) {
				// Sorted so the hash changes only when the terms do, not their order.
				$names = array_map( static fn ( $term ): string => PlainText::line( $term->name ), $assigned );
				sort( $names, SORT_STRING );
				$terms[ $taxonomy->name ] = array_values( $names );
			}
		}

		return Records::with_hash(
			array(
				'external_id' => (string) $post->ID,
				'type'        => $post->post_type,
				'title'       => PlainText::line( get_the_title( $post ) ),
				'slug'        => $post->post_name,
				'url'         => get_permalink( $post ),
				'excerpt'     => '' !== trim( $post->post_excerpt ) ? PlainText::from( $post->post_excerpt ) : PlainText::from( $text, 300 ),
				'text'        => $text,
				'image'       => (string) get_the_post_thumbnail_url( $post, 'large' ),
				'terms'       => $terms,
				'product_ids' => $this->mentioned_products( $post->post_content ),
				'created_at'  => Records::iso( $post->post_date_gmt ),
				'updated_at'  => Records::iso( $post->post_modified_gmt ),
			)
		);
	}

	/**
	 * @return list<string>
	 */
	private function mentioned_products( string $content ): array {
		$ids = array();

		// [product id="12"], [add_to_cart id="12"], [products ids="12,13"]
		if ( preg_match_all( '/\[(?:product|add_to_cart|product_page)\s[^\]]*\bid=["\']?(\d+)/i', $content, $matches ) ) {
			$ids = array_merge( $ids, $matches[1] );
		}

		if ( preg_match_all( '/\[products\s[^\]]*\bids=["\']?([\d,\s]+)/i', $content, $matches ) ) {
			foreach ( $matches[1] as $list ) {
				$ids = array_merge( $ids, preg_split( '/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) );
			}
		}

		// <!-- wp:woocommerce/handpicked-products {"products":[12,13]} -->
		if ( preg_match_all( '/<!-- wp:woocommerce\/handpicked-products (\{.*?\}) (?:\/)?-->/s', $content, $matches ) ) {
			foreach ( $matches[1] as $json ) {
				$attributes = json_decode( $json, true );
				$ids        = array_merge( $ids, array_map( 'strval', (array) ( $attributes['products'] ?? array() ) ) );
			}
		}

		// Links to product pages on this site.
		if ( preg_match_all( '/href=["\']([^"\']+)["\']/i', $content, $matches ) ) {
			$home     = wp_parse_url( home_url(), PHP_URL_HOST );
			$resolved = 0;

			foreach ( array_unique( $matches[1] ) as $url ) {
				if ( $resolved >= self::MAX_LINKS_TO_RESOLVE ) {
					break;
				}

				$host = wp_parse_url( $url, PHP_URL_HOST );

				if ( null !== $host && $host !== $home ) {
					continue;
				}

				++$resolved;
				$post_id = url_to_postid( $url );

				if ( $post_id && 'product' === get_post_type( $post_id ) ) {
					$ids[] = (string) $post_id;
				}
			}
		}

		$ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ), static fn ( string $id ): bool => ctype_digit( $id ) && 'product' === get_post_type( (int) $id ) ) ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}
}
