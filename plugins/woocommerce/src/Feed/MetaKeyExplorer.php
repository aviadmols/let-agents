<?php

namespace LetAgents\Feed;

use LetAgents\Support\PlainText;
use LetAgents\Support\SensitiveFields;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the custom field keys used on products, with how often and a few example values.
 * This is what the attribute mapping screen is built from: specs often live in ACF or
 * plugin fields rather than in WooCommerce attributes.
 */
final class MetaKeyExplorer {

	private const MAX_KEYS = 300;

	private const SAMPLED_KEYS = 120;

	private const SAMPLES_PER_KEY = 3;

	/** WordPress and WooCommerce bookkeeping, never a product fact. */
	private const IGNORED_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wc_review_count',
		'_wc_rating_count',
		'_wc_average_rating',
		'_product_version',
		'_transient_wc_product_children',
	);

	/**
	 * @return list<array{key: string, private: bool, sensitive: bool, uses: int, samples: list<string>}>
	 */
	public function explore( string $post_type ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT pm.meta_key, COUNT(*) AS uses FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s GROUP BY pm.meta_key ORDER BY uses DESC, pm.meta_key ASC LIMIT %d",
				$post_type,
				self::MAX_KEYS
			)
		);

		$keys    = array();
		$sampled = 0;

		foreach ( $rows as $row ) {
			$key = (string) $row->meta_key;

			if ( in_array( $key, self::IGNORED_KEYS, true ) ) {
				continue;
			}

			$samples   = array();
			$sensitive = SensitiveFields::is_sensitive( $key );

			// A sensitive field is listed so the store knows it exists, but its values never are.
			if ( ! $sensitive && $sampled < self::SAMPLED_KEYS ) {
				++$sampled;
				$samples = $this->samples( $post_type, $key );
			}

			$keys[] = array(
				'key'       => $key,
				'private'   => str_starts_with( $key, '_' ),
				'sensitive' => $sensitive,
				'uses'      => (int) $row->uses,
				'samples'   => $samples,
			);
		}

		return $keys;
	}

	/**
	 * @return list<string>
	 */
	private function samples( string $post_type, string $key ): array {
		global $wpdb;

		$values = $wpdb->get_col( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value <> '' LIMIT %d",
				$post_type,
				$key,
				self::SAMPLES_PER_KEY
			)
		);

		return array_values(
			array_map(
				static fn ( $value ): string => is_serialized( (string) $value ) ? '[structured value]' : PlainText::from( (string) $value, 120 ),
				$values
			)
		);
	}
}
