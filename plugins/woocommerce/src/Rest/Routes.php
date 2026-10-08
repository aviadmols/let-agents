<?php

namespace LetAgents\Rest;

use LetAgents\Auth\TokenGuard;
use LetAgents\Feed\ContentExporter;
use LetAgents\Feed\MetaKeyExplorer;
use LetAgents\Feed\ProductExporter;
use LetAgents\Feed\SiteStatus;
use LetAgents\Feed\TaxonomyExporter;
use LetAgents\Plugin;
use LetAgents\Settings;
use LetAgents\Support\Records;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The read-only API Let Agents uses. Every route needs the access token. There is no write route
 * here, and no route for customers or orders.
 *
 * The plugin's only write route, POST /let-agents/v1/cart/capture, lives in CartRoutes: it is called
 * by logged-out shoppers rather than by Let Agents, takes a page nonce instead of the token, and
 * writes one thing, the pending payment order a shopper asks for by leaving their email. Keeping it
 * apart means no route here can ever write.
 *
 *   GET /let-agents/v1/status
 *   GET /let-agents/v1/feed/manifest
 *   GET /let-agents/v1/feed/products?per_page=&after=&since=&status=
 *   GET /let-agents/v1/feed/products/{id}
 *   GET /let-agents/v1/feed/categories
 *   GET /let-agents/v1/feed/attributes
 *   GET /let-agents/v1/feed/content?type=&per_page=&after=&since=
 *   GET /let-agents/v1/meta-keys?post_type=
 */
final class Routes {

	public const NAMESPACE = 'let-agents/v1';

	public static function register(): void {
		$read  = WP_REST_Server::READABLE;
		$guard = array( TokenGuard::class, 'check' );

		register_rest_route( self::NAMESPACE, '/status', array(
			'methods'             => $read,
			'callback'            => array( self::class, 'status' ),
			'permission_callback' => $guard,
		) );

		register_rest_route( self::NAMESPACE, '/feed/manifest', array(
			'methods'             => $read,
			'callback'            => array( self::class, 'manifest' ),
			'permission_callback' => $guard,
		) );

		register_rest_route( self::NAMESPACE, '/feed/products', array(
			'methods'             => $read,
			'callback'            => array( self::class, 'products' ),
			'permission_callback' => $guard,
			'args'                => array_merge(
				Records::paging_args(),
				array(
					'status' => array(
						'type'    => 'string',
						'default' => 'publish',
						'enum'    => array_merge( ProductExporter::STATUSES, array( 'any' ) ),
					),
				)
			),
		) );

		register_rest_route( self::NAMESPACE, '/feed/products/(?P<id>\d+)', array(
			'methods'             => $read,
			'callback'            => array( self::class, 'product' ),
			'permission_callback' => $guard,
		) );

		register_rest_route( self::NAMESPACE, '/feed/categories', array(
			'methods'             => $read,
			'callback'            => array( self::class, 'categories' ),
			'permission_callback' => $guard,
		) );

		register_rest_route( self::NAMESPACE, '/feed/attributes', array(
			'methods'             => $read,
			'callback'            => array( self::class, 'attributes' ),
			'permission_callback' => $guard,
		) );

		register_rest_route( self::NAMESPACE, '/feed/content', array(
			'methods'             => $read,
			'callback'            => array( self::class, 'content' ),
			'permission_callback' => $guard,
			'args'                => array_merge(
				Records::paging_args(),
				array(
					'type' => array(
						'type'    => 'string',
						'default' => 'post',
					),
				)
			),
		) );

		register_rest_route( self::NAMESPACE, '/meta-keys', array(
			'methods'             => $read,
			'callback'            => array( self::class, 'meta_keys' ),
			'permission_callback' => $guard,
			'args'                => array(
				'post_type' => array(
					'type'    => 'string',
					'default' => 'product',
				),
			),
		) );
	}

	public static function status(): WP_REST_Response {
		$response = new WP_REST_Response( array( 'data' => ( new SiteStatus() )->status() ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	public static function manifest(): WP_REST_Response {
		$response = new WP_REST_Response( array( 'data' => ( new SiteStatus() )->manifest() ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function products( WP_REST_Request $request ) {
		if ( $error = self::require_woocommerce() ) {
			return $error;
		}

		$since = Records::since( $request );
		if ( is_wp_error( $since ) ) {
			return $since;
		}

		$per_page = (int) $request->get_param( 'per_page' );
		$after    = (int) $request->get_param( 'after' );
		$status   = (string) $request->get_param( 'status' );
		$statuses = 'any' === $status ? ProductExporter::STATUSES : array( $status );

		$exporter          = new ProductExporter();
		[ $ids, $next ]    = Records::page( $exporter->ids( $statuses, $after, $per_page + 1, $since ), $per_page );

		return Records::response(
			$exporter->export_many( $ids ),
			array(
				'count'      => count( $ids ),
				'per_page'   => $per_page,
				'after'      => $after,
				'next_after' => $next,
				'since'      => $since ? gmdate( 'c', (int) strtotime( $since . ' UTC' ) ) : null,
				'status'     => $status,
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function product( WP_REST_Request $request ) {
		if ( $error = self::require_woocommerce() ) {
			return $error;
		}

		$product = wc_get_product( (int) $request->get_param( 'id' ) );

		if ( ! $product || $product->is_type( 'variation' ) ) {
			return new WP_Error( 'let_agents_not_found', __( 'Product not found.', 'let-agents' ), array( 'status' => 404 ) );
		}

		$response = new WP_REST_Response( array( 'data' => ( new ProductExporter() )->export( $product ) ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function categories() {
		if ( $error = self::require_woocommerce() ) {
			return $error;
		}

		$records = ( new TaxonomyExporter( new ProductExporter() ) )->categories();

		return Records::response( $records, array( 'count' => count( $records ) ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function attributes() {
		if ( $error = self::require_woocommerce() ) {
			return $error;
		}

		$response = new WP_REST_Response(
			array(
				'data' => ( new TaxonomyExporter( new ProductExporter() ) )->attributes(),
				'meta' => array( 'generated_at' => gmdate( 'c' ) ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function content( WP_REST_Request $request ) {
		$type    = (string) $request->get_param( 'type' );
		$allowed = Settings::content_post_types();

		if ( ! in_array( $type, $allowed, true ) ) {
			return new WP_Error(
				'let_agents_content_type_not_allowed',
				/* translators: %s: comma-separated list of post types */
				sprintf( __( 'This content type is not shared with Let Agents. Allowed types: %s. Change this in WooCommerce > Let Agents.', 'let-agents' ), implode( ', ', $allowed ) ?: '-' ),
				array( 'status' => 400 )
			);
		}

		$since = Records::since( $request );
		if ( is_wp_error( $since ) ) {
			return $since;
		}

		$per_page       = (int) $request->get_param( 'per_page' );
		$after          = (int) $request->get_param( 'after' );
		$exporter       = new ContentExporter();
		[ $ids, $next ] = Records::page( $exporter->ids( $type, $after, $per_page + 1, $since ), $per_page );

		return Records::response(
			$exporter->export_many( $ids ),
			array(
				'type'       => $type,
				'count'      => count( $ids ),
				'per_page'   => $per_page,
				'after'      => $after,
				'next_after' => $next,
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function meta_keys( WP_REST_Request $request ) {
		$post_type = (string) $request->get_param( 'post_type' );
		$allowed   = array_merge( array( 'product', 'product_variation' ), Settings::content_post_types() );

		if ( ! in_array( $post_type, $allowed, true ) ) {
			return new WP_Error(
				'let_agents_post_type_not_allowed',
				/* translators: %s: comma-separated list of post types */
				sprintf( __( 'Custom fields can be listed only for: %s.', 'let-agents' ), implode( ', ', $allowed ) ),
				array( 'status' => 400 )
			);
		}

		$keys = ( new MetaKeyExplorer() )->explore( $post_type );

		return Records::response( $keys, array( 'post_type' => $post_type, 'count' => count( $keys ) ) );
	}

	private static function require_woocommerce(): ?WP_Error {
		return Plugin::woocommerce_active()
			? null
			: new WP_Error( 'let_agents_woocommerce_inactive', __( 'WooCommerce is not active on this site.', 'let-agents' ), array( 'status' => 503 ) );
	}
}
