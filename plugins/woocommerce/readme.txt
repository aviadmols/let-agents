=== Rega ===
Contributors: rega
Tags: woocommerce, shopping assistant, product recommendations
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects a WooCommerce store to Rega, the smart shopping assistant.

== Description ==

Rega reads the store's products, variations, categories, attributes and chosen content to build a shopping assistant that helps visitors decide and buy.

Rega gets read-only access through a token created by a store manager:

* Products with variations, attributes, custom fields, prices, stock and the upsells and cross-sells set in WooCommerce.
* Product categories with their full paths, and global and custom attributes.
* Published guides and articles from the post types you choose, with the products they mention.
* Site information: WordPress, WooCommerce, theme and active plugin versions.

Customer details are never shared: orders reach Rega only as totals, product IDs and quantities, at checkout and once from the last 24 months. Custom fields that look like costs, supplier details or internal notes are never shared either. There is no write access.

= The widget on the store =

WooCommerce > Rega > Widget on the store:

* Off: nothing is added to the store.
* Preview (the default): only the store team sees the widget, when logged in to WordPress or after opening a preview link from Rega. Visitors are counted in the reports but see nothing.
* Live: every visitor sees it.

Where the widget shows (a CSS class or selector of the theme, before or after it) is set per store in Rega. The widget script is served by Rega, reads live prices and stock from the WooCommerce Store API, and adds to the cart through the same API.

= Reports =

WooCommerce > Rega reports shows page views, visitors, hot pages, hot display models, widget views and opens, add to cart from the widget, orders, and the orders and revenue that came through Rega.

= Privacy =

* The widget keeps an anonymous visitor ID in a first-party cookie (rega_vid) and localStorage, and sends Rega the page path, the product or article ID and what was done with the widget. No names, emails or query strings.
* When an order is placed, the plugin sends Rega a keyed hash of the order ID, the total and currency, product IDs with quantities and line totals, and the anonymous visitor ID. Never names, emails, phone numbers, addresses, payment details, notes or coupons. The request runs in the background and never slows checkout.
* Requests from the plugin to Rega are signed with a key derived from the access token.

== Installation ==

1. Plugins > Add New > Upload Plugin, choose the zip, install and activate.
2. WooCommerce > Rega > Create token.
3. Copy the token. It is shown only once.

== Changelog ==

= 0.4.0 =
* Past orders: the store sends Rega its paid orders from the last 24 months once, in the background, so Rega can learn what sells together from before it was installed. The same order summary as at checkout: totals, product IDs and quantities, never the customer. WooCommerce > Rega shows how far it has got and can send them again.

= 0.3.0 =
* Pages are shared alongside posts by default, so what the store promises — returns, shipping, warranty — can be read and shown to shoppers. A shop that saved its own choice keeps it.

= 0.2.2 =
* Product text keeps its line breaks when the store's HTML uses breaks with attributes or accordions, so list items no longer run together.

= 0.2.1 =
* Reports name the new widget circles: other sizes, similar products, similar on sale, good for jobs, and comparison with a viewed product.

= 0.2.0 =
* The Rega widget on product pages and articles, with Off, Preview and Live modes. Store managers see it while in preview.
* WooCommerce > Rega reports: page views, hot pages, hot display models, add to cart, orders and revenue through Rega.
* Orders are reported to Rega without any customer details, in the background.

= 0.1.1 =
* Categories, tags and brands come in a fixed order, so a product that did not change keeps the same hash. Before, some sites returned them in a different order on each request and Rega saw hundreds of false changes.
* Custom fields that look like costs, supplier details, margins or internal notes are never shared, in English or Hebrew. The field list shows them as sensitive, without values. Add more with the rega_is_sensitive_meta_key filter.

= 0.1.0 =
* Access token, read-only catalog and content API, settings page in English and Hebrew.
