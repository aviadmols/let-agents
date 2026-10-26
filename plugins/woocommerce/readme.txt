=== Let Agents ===
Contributors: let-agents
Tags: woocommerce, shopping assistant, product recommendations
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.14.0
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

Customer details are never shared: orders reach Let Agents only as totals, product IDs and quantities, at checkout and once from the last 24 months. Custom fields that look like costs, supplier details or internal notes are never shared either.

The plugin reads the store, and writes only one thing: a draft post on the site's blog, when the store team publishes a guide in Let Agents. The owner publishes it from WordPress; once published it is never changed again. Nothing else in the store is ever changed, and the write needs both the token and a signature only Let Agents can make.

= Lets Search on the store =

WooCommerce > Let Agents > Lets Search on the store:

* Off: nothing is added to the store.
* Preview (the default): only the store team sees Lets Search, when logged in to WordPress or after opening a preview link from Let Agents. It shows them a preview strip.
* Live: every visitor sees it.

Lets Search is one script served by Let Agents ({api}/search/lets-search.js). It opens from the store's search box and search icons, answers the store's results page, and on product pages and on the post types shared with Let Agents it starts with that page. It reads live prices and stock from the WooCommerce Store API and adds to the cart through the same API. [let_agents_wishlist] prints a heart that opens the wishlist; any link to #wishlist does the same.

= Reports =

WooCommerce > Let Agents reports shows page views, visitors, hot pages, hot display models, widget views and opens, add to cart from the widget, orders, and the orders and revenue that came through Let Agents.

= Privacy =

* Lets Search keeps an anonymous visitor ID in a first-party cookie (let_agents_vid) and localStorage, and sends Let Agents the page path, the product or article ID and what was done with Lets Search. No names, emails or query strings.
* When an order is placed, the plugin sends Let Agents a keyed hash of the order ID, the total and currency, product IDs with quantities and line totals, and the anonymous visitor ID. Never names, emails, phone numbers, addresses, payment details, notes or coupons. The request runs in the background and never slows checkout.
* Requests from the plugin to Let Agents are signed with a key derived from the access token.

== Installation ==

1. Plugins > Add New > Upload Plugin, choose the zip, install and activate.
2. WooCommerce > Let Agents > Create token.
3. Copy the token. It is shown only once.

Or the other way round: in Lets, Settings > Connection and plan > Create a connection token, and paste it in WooCommerce > Let Agents > Have a token from Lets?

== Changelog ==

= 0.14.0 =
* Each product says how many were sold, as WooCommerce counts it: the assistant recommends what the shop's customers buy most.

= 0.13.0 =
* A connection token made in Lets (Settings > Connection and plan) can be pasted in the plugin's settings. The plugin keeps only its hash, as with a token made here.

= 0.12.0 =
* Product and post text keeps its structure: headings, list items and tables (each row with its header) reach Lets as such, so a table of sizes or uses is read row by row and its values never mix.

= 0.11.0 =
* "Lets Search" in the WordPress admin menu: the store's Lets panel opens inside WordPress, signed in by the store's token. Each opening uses a one-time address that works for a minute; the token never reaches the browser. Only people who manage the store see it.

= 0.10.0 =
* AI agents find the store without a link: while Lets Search is live, the store answers /llms.txt (what it sells, its catalogue for AI, its MCP server, its policies and categories) and /.well-known/mcp/server-card.json (and /.well-known/mcp.json) with its MCP server described, and every page links them in its head. Served from Let Agents and kept for six hours. An llms.txt of the store's own (written by an SEO plugin) is never replaced. Off with the let_agents_agent_discovery filter.

= 0.9.1 =
* A colour attribute's terms carry the colour a swatch plugin or the theme keeps for them (a hex code in the term's meta), so Let Agents knows a colour by its swatch where its name says nothing ("Ocean Mist").

= 0.9.0 =
* One script on the store: Lets Search, the drawer, loaded from {api}/search/lets-search.js. The product-page widget, the old search box script and their display modes are removed; everything they did is in the drawer, and page views, searches, add to cart and orders are counted as before.
* One mode instead of two. A store that had the widget or the search live is live; else, if either was in preview, it is in preview; else off. A store that had the search off and the widget on still gets the drawer, so product pages keep their help and learning.
* The page being read (a product, or an entry of a post type shared with Let Agents) is sent with the search context.
* [lets_cta] and the offer after paragraph N are removed: the shortcode prints nothing, so old posts do not show it. [let_agents_wishlist] now prints a heart linking to #wishlist, which opens the drawer's saved tab.

= 0.8.1 =
* [let_agents_wishlist]: the wishlist heart, with the number of products saved, wherever the shop places it (a header, a menu). A click opens the list.

= 0.8.0 =
* Guides to the site's blog: when the store team publishes a guide in Let Agents, it arrives as a draft post, with links and pictures of the store's products, its excerpt and tags, a featured image, its SEO title, description and focus keyword in Yoast or Rank Math, and its questions as FAQ structured data. The owner publishes it from WordPress. Sent again, the draft is updated; a published post is left as it is.

= 0.7.2 =
* The email before checkout and the abandoned carts are removed: the plugin no longer asks for an email, saves no pending payment orders and has no write route. It only reads the store again. Orders it saved before stay in the store as they are.
* The wishlist of a signed-in shopper: when the shop turns the wishlist on in Let Agents, the page tells Let Agents which store account is signed in, signed with the store's key, so the list is kept in that account and waits on any device. Only the user ID is sent, never a name or an email.

= 0.7.1 =
* Orders are reported to Let Agents while the search is on, not only while the widget is: the search analytics count the purchases of products added to the cart from the search. As before, an order carries no customer data: a hashed order number, totals, product IDs and quantities, and the anonymous visitor ID.

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
