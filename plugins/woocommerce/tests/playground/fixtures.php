<?php
/**
 * Test data shaped like the pilot store: Hebrew categories three levels deep, specs hidden in
 * titles and custom fields, a variable product on a global attribute, merchant-set upsells
 * and cross-sells, a draft, and a guide that mentions products three different ways.
 *
 * Runs inside WordPress Playground after WooCommerce and Rega are active. Writes the access
 * token and the created IDs to /rega-out/fixtures.json for the smoke test.
 */

require '/wordpress/wp-load.php';

if ( ! function_exists( 'wc_get_product' ) ) {
	fwrite( STDERR, "WooCommerce is not active\n" );
	exit( 1 );
}

$category = static function ( string $name, string $slug, int $parent = 0 ): int {
	$existing = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $existing ) {
		return (int) $existing->term_id;
	}

	$term = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug, 'parent' => $parent ) );

	return (int) $term['term_id'];
};

$tools = $category( 'כלי עבודה', 'tools' );
$power = $category( 'כלי עבודה חשמליים', 'power-tools', $tools );
$drills = $category( 'מקדחות', 'drills', $power );
$accessories = $category( 'אביזרים לכלי עבודה', 'tool-accessories' );

// A global attribute with terms, used for variations.
$attribute_id = wc_attribute_taxonomy_id_by_name( 'color' );
if ( ! $attribute_id ) {
	$attribute_id = wc_create_attribute( array( 'name' => 'צבע', 'slug' => 'color', 'type' => 'select' ) );
}
register_taxonomy( 'pa_color', array( 'product' ), array( 'hierarchical' => false ) );
$term_id = static function ( string $name, string $slug ): int {
	$existing = get_term_by( 'slug', $slug, 'pa_color' );

	return $existing ? (int) $existing->term_id : (int) wp_insert_term( $name, 'pa_color', array( 'slug' => $slug ) )['term_id'];
};
$red_id   = $term_id( 'אדום', 'red' );
$black_id = $term_id( 'שחור', 'black' );

// Accessory first, so the drill can point to it.
$bits = new WC_Product_Simple();
$bits->set_name( 'סט מקדחים לבטון 5 חלקים' );
$bits->set_sku( 'BITS-5' );
$bits->set_regular_price( '39.90' );
$bits->set_category_ids( array( $accessories ) );
$bits->set_short_description( '<p>סט מקדחים &amp; ביטים לבטון.</p>' );
$bits_id = $bits->save();

// Store the description exactly as a theme builder or importer might, script included, so the
// test proves the exporter strips it rather than relying on WordPress having filtered it.
kses_remove_filters();

$drill = new WC_Product_Simple();
$drill->set_name( 'מקדחה רוטטת 550W &quot;Pro&quot;' );
$drill->set_sku( 'DR-550' );
$drill->set_regular_price( '249' );
$drill->set_sale_price( '219' );
$drill->set_manage_stock( true );
$drill->set_stock_quantity( 7 );
$drill->set_weight( '1.8' );
$drill->set_category_ids( array( $drills ) );
$drill->set_description( "<!-- wp:paragraph --><p>מקדחה רוטטת בהספק <strong>550W</strong>.</p><!-- /wp:paragraph -->\n<script>alert(1)</script><ul><li>מהירות משתנה</li><li>ראש 13 מ&quot;מ</li></ul>[gallery ids=\"1\"]"
	. '<details><summary><span>שימושים:</span></summary><div><p data-start="1">קידוח בבטון<br data-start="2" data-end="3">קידוח בעץ</p></div></details>' );
$custom = new WC_Product_Attribute();
$custom->set_name( 'הספק' );
$custom->set_options( array( '550W' ) );
$custom->set_visible( true );
$custom->set_variation( false );
$drill->set_attributes( array( $custom ) );
$drill->set_cross_sell_ids( array( $bits_id ) );
$drill_id = $drill->save();
kses_init_filters();

if ( ! str_contains( (string) get_post_field( 'post_content', $drill_id, 'raw' ), '<script>' ) ) {
	fwrite( STDERR, "Fixture error: the drill description was filtered on save\n" );
	exit( 1 );
}

update_post_meta( $drill_id, 'power_watts', '550' );
update_post_meta( $drill_id, 'chuck_mm', '13' );
update_post_meta( $drill_id, '_internal_flag', 'secret-internal' );
// Real field names seen in a store: the cost price and an import note must never be shared.
update_post_meta( $drill_id, 'עלות ליחידה מהספק (לא לפרסום)', '4321.87' );
update_post_meta( $drill_id, 'supplier_cost', '4321.87' );
update_post_meta( $drill_id, 'הערת קליטה', 'internal import note' );

$pro = new WC_Product_Simple();
$pro->set_name( 'מקדחה רוטטת 850W' );
$pro->set_regular_price( '399' );
// Several categories and tags, given out of order: the feed must return them sorted.
$pro->set_category_ids( array( $drills, $accessories, $power ) );
$tag_ids = array();
foreach ( array( 'מקצועי', 'Bosch', 'אלחוטי' ) as $tag_name ) {
	$existing  = get_term_by( 'name', $tag_name, 'product_tag' );
	$tag_ids[] = $existing ? (int) $existing->term_id : (int) wp_insert_term( $tag_name, 'product_tag' )['term_id'];
}
$pro->set_tag_ids( $tag_ids );
$pro_id = $pro->save();

$drill = wc_get_product( $drill_id );
$drill->set_upsell_ids( array( $pro_id ) );
$drill->save();

$variable = new WC_Product_Variable();
$variable->set_name( 'מברגה נטענת 18V' );
$variable->set_category_ids( array( $power ) );
$color = new WC_Product_Attribute();
$color->set_id( (int) $attribute_id );
$color->set_name( 'pa_color' );
$color->set_options( array( $red_id, $black_id ) );
$color->set_visible( true );
$color->set_variation( true );
$variable->set_attributes( array( $color ) );
$variable_id = $variable->save();

$variation_ids = array();
foreach ( array( 'red' => '299', 'black' => '319' ) as $slug => $price ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable_id );
	$variation->set_attributes( array( 'pa_color' => $slug ) );
	$variation->set_regular_price( $price );
	$variation->set_sku( 'DRV-18-' . strtoupper( $slug ) );
	$variation_ids[ $slug ] = $variation->save();
}
WC_Product_Variable::sync( $variable_id );

$draft = new WC_Product_Simple();
$draft->set_name( 'מוצר טיוטה' );
$draft->set_status( 'draft' );
$draft->set_regular_price( '10' );
$draft_id = $draft->save();

$guide_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'איך בוחרים מקדחה',
		'post_content' => '<p>לפני שבוחרים מקדחה כדאי לבדוק הספק.</p><p>ראו <a href="' . esc_url( get_permalink( $drill_id ) ) . '">את המקדחה הרוטטת</a>.</p>[product id="' . $pro_id . '"]<!-- wp:woocommerce/handpicked-products {"products":[' . $bits_id . ']} /-->',
	)
);

$cta_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'איפה לשים את ההצעה',
		'post_content' => '<p>פסקה ראשונה.</p>[lets_cta]<p>פסקה שנייה.</p>',
	)
);

$long_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'כתבה ארוכה בלי שורטקוד',
		'post_content' => '<p>אחת.</p><p>שתיים.</p><p>שלוש.</p><p>ארבע.</p><p>חמש.</p>',
	)
);

$protected_id = wp_insert_post(
	array(
		'post_type'     => 'post',
		'post_status'   => 'publish',
		'post_title'    => 'Protected',
		'post_password' => 'x',
		'post_content'  => 'hidden',
	)
);

// What the shop promises lives on a page, not in a guide. Rega reads pages too.
$terms_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'תקנון האתר',
		'post_content' => '<p>ניתן להחזיר מוצר תוך 14 יום ולקבל החזר מלא.</p><p>משלוח חינם בהזמנה מעל 500 ש"ח.</p>',
	)
);

$token = \Rega\Auth\AccessToken::issue();

// Past orders: two paid ones inside the 24 months, one older, and one cancelled. Each carries
// customer details, which must never reach Rega. The request to Rega is caught here instead of
// sent, so the smoke test can read exactly what would have left the store.
// Completing an order would take its products off the shelf, and the feed test checks stock.
add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );

$order = static function ( array $lines, string $status, string $when ): int {
	$order = wc_create_order();
	foreach ( $lines as $product_id => $quantity ) {
		$order->add_product( wc_get_product( $product_id ), $quantity );
	}
	$order->set_billing_first_name( 'ישראל' );
	$order->set_billing_email( 'buyer@example.com' );
	$order->set_billing_phone( '0501234567' );
	$order->set_customer_note( 'להשאיר ליד הדלת' );
	$order->calculate_totals();
	$order->set_status( $status );
	$order->set_date_created( strtotime( $when ) );
	$order->save();

	return $order->get_id();
};

$history_orders = array(
	'recent'    => $order( array( $drill_id => 1, $bits_id => 2 ), 'completed', '-3 months' ),
	'older'     => $order( array( $pro_id => 1 ), 'processing', '-20 months' ),
	'too_old'   => $order( array( $drill_id => 1 ), 'completed', '-30 months' ),
	'cancelled' => $order( array( $bits_id => 1 ), 'cancelled', '-1 month' ),
);

$history_requests = array();
add_filter(
	'pre_http_request',
	static function ( $response, $args, $url ) use ( &$history_requests ) {
		if ( str_contains( $url, '/orders/history' ) ) {
			$history_requests[] = array( 'url' => $url, 'headers' => array_keys( $args['headers'] ), 'body' => json_decode( $args['body'], true ) );

			return array( 'headers' => array(), 'body' => '{"received":0,"stored":0}', 'response' => array( 'code' => 202, 'message' => 'Accepted' ), 'cookies' => array(), 'filename' => null );
		}

		return $response;
	},
	10,
	3
);

\Rega\Storefront\OrderHistory::start();
\Rega\Storefront\OrderHistory::send_page( 1, 0 );
$history_state = \Rega\Storefront\OrderHistory::state();

if ( ! is_dir( '/rega-out' ) ) {
	mkdir( '/rega-out' );
}

file_put_contents(
	'/rega-out/fixtures.json',
	json_encode(
		array(
			'token'       => $token,
			'categories'  => compact( 'tools', 'power', 'drills', 'accessories' ),
			'products'    => array(
				'bits'     => $bits_id,
				'drill'    => $drill_id,
				'pro'      => $pro_id,
				'variable' => $variable_id,
				'draft'    => $draft_id,
			),
			'variations'  => $variation_ids,
			'guide'       => $guide_id,
			'cta'         => $cta_id,
			'long'        => $long_id,
			'protected'   => $protected_id,
			'terms'       => $terms_id,
			'history'     => array(
				'orders'   => $history_orders,
				'requests' => $history_requests,
				'state'    => $history_state,
				'refs'     => array_map(
					static fn ( int $id ): string => hash_hmac( 'sha256', 'order|' . $id, (string) \Rega\Storefront\SiteKeys::preview() ),
					$history_orders
				),
			),
			'woocommerce' => WC()->version,
			'wordpress'   => get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
		),
		JSON_PRETTY_PRINT
	)
);

echo "fixtures ready\n";
