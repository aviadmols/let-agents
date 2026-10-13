<?php

namespace LetAgents\Storefront;

use LetAgents\Rest\PostRoutes;

defined( 'ABSPATH' ) || exit;

/**
 * On a post Let Agents wrote: its questions as FAQPage, which search engines read, and, on a site
 * with no SEO plugin, its SEO title and description, which Yoast or Rank Math print otherwise.
 * Read-only: it prints, and changes nothing.
 */
final class PostSchema {

	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'head' ), 5 );
		add_filter( 'pre_get_document_title', array( self::class, 'title' ), 20 );
	}

	public static function head(): void {
		$seo = self::seo();

		if ( null === $seo ) {
			return;
		}

		if ( ! self::seo_plugin() && '' !== ( $seo['description'] ?? '' ) ) {
			echo '<meta name="description" content="' . esc_attr( (string) $seo['description'] ) . '">' . "\n";
		}

		$faq = (array) ( $seo['faq'] ?? array() );

		if ( array() === $faq ) {
			return;
		}

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_map(
				static fn ( array $item ): array => array(
					'@type'          => 'Question',
					'name'           => (string) $item['q'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => (string) $item['a'],
					),
				),
				$faq
			),
		);

		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . '</script>' . "\n";
	}

	/** The SEO title, where no SEO plugin sets the page title. */
	public static function title( $title ) {
		$seo = self::seo();

		return null !== $seo && ! self::seo_plugin() && '' !== ( $seo['title'] ?? '' ) ? (string) $seo['title'] : $title;
	}

	/** @return array<string, mixed>|null */
	private static function seo(): ?array {
		if ( ! is_singular( 'post' ) ) {
			return null;
		}

		$seo = get_post_meta( (int) get_queried_object_id(), PostRoutes::SEO_META, true );

		return is_array( $seo ) ? $seo : null;
	}

	private static function seo_plugin(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}
}
