=== Let Agents ===
Contributors: let-agents
Tags: woocommerce, shopping assistant, product recommendations
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects a WooCommerce store to Let Agents, the smart shopping assistant.

== Description ==

Let Agents reads the store's products, variations, categories, attributes and chosen content to build a shopping assistant that helps visitors decide and buy.

Let Agents gets read-only access through a token created by a store manager:

* Products with variations, attributes, custom fields, prices, stock and the upsells and cross-sells set in WooCommerce.
* Product categories with their full paths, and global and custom attributes.
* Published guides and articles from the post types you choose, with the products they mention.
* Site information: WordPress, WooCommerce, theme and active plugin versions.

Customer details are never shared: orders reach Let Agents only as totals, product IDs and quantities, at checkout and once from the last 24 months. The one exception is an email a shopper gives Let Agents themselves, below. Custom fields that look like costs, supplier details or internal notes are never shared either.

The plugin reads the store, and writes only one thing: a pending payment order a shopper asks for by leaving their email before checkout, when the shop turns that on. Nothing else in the store is ever changed, and the token gives Let Agents no write access.

= The email before checkout =

When the shop turns it on in Let Agents, the store asks the shopper for their email before checkout. When they leave it and agree, the plugin saves the cart as an order waiting for payment, with that email, so the shop can follow up. Leaving the email again updates the same order. If the shopper then checks out, the classic checkout finishes that same order; the block checkout makes its own, and the one left waiting goes to the trash. The shopper gets no email from the store, stock is not touched and nothing is charged.

= The widget on the store =

WooCommerce > Let Agents > Widget on the store:

* Off: nothing is added to the store.
* Preview (the default): only the store team sees the widget, when logged in to WordPress or after opening a preview link from Let Agents. Visitors are counted in the reports but see nothing.
* Live: every visitor sees it.

Where the widget shows (a CSS class or selector of the theme, before or after it) is set per store in Let Agents. The widget script is served by Let Agents, reads live prices and stock from the WooCommerce Store API, and adds to the cart through the same API.

= Reports =

WooCommerce > Let Agents reports shows page views, visitors, hot pages, hot display models, widget views and opens, add to cart from the widget, orders, and the orders and revenue that came through Let Agents.

= Privacy =

* The widget keeps an anonymous visitor ID in a first-party cookie (let_agents_vid) and localStorage, and sends Let Agents the page path, the product or article ID and what was done with the widget. No names, emails or query strings.
* When an order is placed, the plugin sends Let Agents a keyed hash of the order ID, the total and currency, product IDs with quantities and line totals, and the anonymous visitor ID. Never names, emails, phone numbers, addresses, payment details, notes or coupons. The request runs in the background and never slows checkout.
* When a shopper leaves their email before checkout, the plugin sends Let Agents that email with the text the shopper agreed to, the order ID and a link to it in the store admin, product IDs with quantities and line totals, the total and currency, what they searched for in the store, and the anonymous visitor ID. Nothing else about the shopper. It too runs in the background.
* Requests from the plugin to Let Agents are signed with a key derived from the access token.

== Installation ==

1. Plugins > Add New > Upload Plugin, choose the zip, install and activate.
2. WooCommerce > Let Agents > Create token.
3. Copy the token. It is shown only once.

== Changelog ==

= 0.7.0 =
* The email before checkout: when the shop turns it on in Let Agents, a shopper can leave their email before checkout and the cart is saved as an order waiting for payment, with that email, so the shop can follow up. Leaving it again updates the same order; checking out finishes it, or trashes it when the checkout made its own. This is the only thing the plugin writes to the store. The shopper gets no email, stock and payment are not touched.

= 0.6.0 =
* The plugin is now called Let Agents (it was Rega). Installing it carries over the access token, the settings and the past-orders progress, and switches the old plugin off, so the store stays connected. New tokens start with lat_; a token made before still works.

= 0.5.0 =
* Let Agents search in the store's own search box, on every page: suggestions while typing that forgive spelling mistakes, plural and singular, and a keyboard left in English; results grouped into products, guides and categories, with live prices and add to cart. When the store keeps its own results page, that page lists the same results in the same order. Off until turned on under WooCommerce > Let Agents; preview first shows it to the store team only. If Let Agents does not answer, the store's own search runs as before.

= 0.4.0 =
* Past orders: the store sends Let Agents its paid orders from the last 24 months once, in the background, so Let Agents can learn what sells together from before it was installed. The same order summary as at checkout: totals, product IDs and quantities, never the customer. WooCommerce > Let Agents shows how far it has got and can send them again.

= 0.3.0 =
* Pages are shared alongside posts by default, so what the store promises — returns, shipping, warranty — can be read and shown to shoppers. A shop that saved its own choice keeps it.

= 0.2.2 =
* Product text keeps its line breaks when the store's HTML uses breaks with attributes or accordions, so list items no longer run together.

= 0.2.1 =
* Reports name the new widget circles: other sizes, similar products, similar on sale, good for jobs, and comparison with a viewed product.

= 0.2.0 =
* The Let Agents widget on product pages and articles, with Off, Preview and Live modes. Store managers see it while in preview.
* WooCommerce > Let Agents reports: page views, hot pages, hot display models, add to cart, orders and revenue through Let Agents.
* Orders are reported to Let Agents without any customer details, in the background.

= 0.1.1 =
* Categories, tags and brands come in a fixed order, so a product that did not change keeps the same hash. Before, some sites returned them in a different order on each request and Let Agents saw hundreds of false changes.
* Custom fields that look like costs, supplier details, margins or internal notes are never shared, in English or Hebrew. The field list shows them as sensitive, without values. Add more with the let_agents_is_sensitive_meta_key filter.

= 0.1.0 =
* Access token, read-only catalog and content API, settings page in English and Hebrew.
