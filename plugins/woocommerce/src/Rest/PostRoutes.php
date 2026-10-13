<?php

namespace LetAgents\Rest;

use LetAgents\Auth\TokenGuard;
use LetAgents\Storefront\SiteKeys;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's one write route: POST /let-agents/v1/posts. Let Agents sends a post the store team
 * published in its panel, and the plugin keeps it as a draft of the site's blog for the owner to
 * publish: its text, excerpt and tags, its picture as the featured image, and its SEO title,
 * description and focus keyword in Yoast or Rank Math when one of them is active. The questions
 * and answers are kept for PostSchema, which prints them as FAQPage.
 *
 * Only Let Agents can write: the access token, and a signature over the request with the same key
 * (SiteKeys::verify). Sent again with the id it got, the same draft is updated; once the owner
 * published it, it is theirs and left as it is. Nothing else in the store is ever written.
 */
final class PostRoutes {

	public const ROUTE = '/posts';

	/** Where the post Let Agents sent is kept on the draft. */
	public const META = '_let_agents_post';

	/** Its SEO title, description, focus keyword and questions, for PostSchema. */
	public const SEO_META = '_let_agents_seo';

	public static function register(): void {
		register_rest_route(
			Routes::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'save' ),
				'permission_callback' => array( self::class, 'allowed' ),
			)
		);
	}

	/** @return true|WP_Error */
	public static function allowed( WP_REST_Request $request ) {
		$token = TokenGuard::check( $request );

		if ( true !== $token ) {
			return $token;
		}

		$signed = SiteKeys::verify(
			(string) $request->get_header( 'X-LetAgents-Timestamp' ),
			'POST',
			'/' . Routes::NAMESPACE . self::ROUTE,
			(string) $request->get_body(),
			(string) $request->get_header( 'X-LetAgents-Signature' )
		);

		return $signed ? true : new WP_Error( 'let_agents_bad_signature', 'The request is not signed by Let Agents.', array( 'status' => 401 ) );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function save( WP_REST_Request $request ) {
		$data = json_decode( (string) $request->get_body(), true );
		$post = is_array( $data['post'] ?? null ) ? $data['post'] : null;

		if ( null === $post || '' === trim( (string) ( $post['title'] ?? '' ) ) || '' === trim( (string) ( $post['html'] ?? '' ) ) ) {
			return new WP_Error( 'let_agents_bad_post', 'A post needs a title and a body.', array( 'status' => 400 ) );
		}

		$ours     = sanitize_text_field( (string) ( $post['post_id'] ?? '' ) );
		$existing = self::existing( absint( $data['id'] ?? 0 ), $ours );

		// Published by the owner: theirs, and left as it is.
		if ( null !== $existing && ! in_array( $existing->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			return self::answer( $existing->ID );
		}

		$fields = array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => sanitize_text_field( (string) $post['title'] ),
			'post_content' => wp_kses_post( (string) $post['html'] ),
			'post_excerpt' => sanitize_textarea_field( (string) ( $post['excerpt'] ?? '' ) ),
		);

		if ( null !== $existing ) {
			$fields['ID'] = $existing->ID;
			$id           = wp_update_post( wp_slash( $fields ), true );
		} else {
			$fields['post_author'] = self::author();
			$id                    = wp_insert_post( wp_slash( $fields ), true );
		}

		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'let_agents_post_failed', $id->get_error_message(), array( 'status' => 500 ) );
		}

		$id = (int) $id;
		update_post_meta( $id, self::META, $ours );
		wp_set_post_tags( $id, array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $post['tags'] ?? array() ) ) ) );
		self::seo( $id, $post );
		self::picture( $id, $post['image'] ?? null );

		return self::answer( $id );
	}

	/** The draft made before for this post: by the id Let Agents kept, only if it is ours. */
	private static function existing( int $id, string $ours ): ?\WP_Post {
		if ( $id <= 0 || '' === $ours ) {
			return null;
		}

		$post = get_post( $id );

		return $post instanceof \WP_Post && 'post' === $post->post_type && 'trash' !== $post->post_status && get_post_meta( $id, self::META, true ) === $ours ? $post : null;
	}

	/** The first administrator: a post with no author shows none on most themes. */
	private static function author(): int {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'fields'  => 'ID',
			)
		);

		return (int) ( $admins[0] ?? 0 );
	}

	/** @param array<string, mixed> $post */
	private static function seo( int $id, array $post ): void {
		$title       = sanitize_text_field( (string) ( $post['seo_title'] ?? '' ) );
		$description = sanitize_textarea_field( (string) ( $post['meta_description'] ?? '' ) );
		$keyword     = sanitize_text_field( (string) ( $post['focus_keyword'] ?? '' ) );
		$faq         = array();

		foreach ( (array) ( $post['faq'] ?? array() ) as $item ) {
			$q = sanitize_text_field( (string) ( $item['q'] ?? '' ) );
			$a = sanitize_textarea_field( (string) ( $item['a'] ?? '' ) );

			if ( '' !== $q && '' !== $a ) {
				$faq[] = array( 'q' => $q, 'a' => $a );
			}
		}

		update_post_meta(
			$id,
			self::SEO_META,
			array(
				'title'       => $title,
				'description' => $description,
				'keyword'     => $keyword,
				'faq'         => $faq,
			)
		);

		// The SEO plugin the site uses reads its own fields: they are filled in, as an editor would.
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $id, '_yoast_wpseo_title', $title );
			update_post_meta( $id, '_yoast_wpseo_metadesc', $description );
			update_post_meta( $id, '_yoast_wpseo_focuskw', $keyword );
		}

		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			update_post_meta( $id, 'rank_math_title', $title );
			update_post_meta( $id, 'rank_math_description', $description );
			update_post_meta( $id, 'rank_math_focus_keyword', $keyword );
		}
	}

	/** The post's picture as its featured image, downloaded once into the media library. */
	private static function picture( int $id, $image ): void {
		if ( ! is_array( $image ) || has_post_thumbnail( $id ) ) {
			return;
		}

		$url = esc_url_raw( (string) ( $image['url'] ?? '' ) );

		if ( '' === $url ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$alt        = sanitize_text_field( (string) ( $image['alt'] ?? '' ) );
		$attachment = media_sideload_image( $url, $id, $alt, 'id' );

		// A picture that cannot be fetched leaves the post without one; the post itself is kept.
		if ( is_wp_error( $attachment ) ) {
			return;
		}

		update_post_meta( (int) $attachment, '_wp_attachment_image_alt', $alt );
		set_post_thumbnail( $id, (int) $attachment );
	}

	private static function answer( int $id ): WP_REST_Response {
		$post = get_post( $id );

		return new WP_REST_Response(
			array(
				'id'       => (string) $id,
				'status'   => $post ? $post->post_status : 'draft',
				'url'      => $post && 'publish' === $post->post_status ? get_permalink( $id ) : get_preview_post_link( $id ),
				'edit_url' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			),
			200
		);
	}
}
