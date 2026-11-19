# After the Shopify App Store approval

Held while the Shopify app is in review, because each touches the app's listing, its install or open path, billing,
or the theme extension (a new app version), and a rejection lengthens the wait to resubmit. Do them after approval,
each tested the reviewer's way (incognito, a fresh store, a reinstall, a long gap). Kept since 19.11.

## The brand
- [ ] The app's name in Shopify and the theme block's texts: "Lets" → "Let’s" (`extensions/shopify/**`: `shopify.app*.toml`,
      `extensions/let-agents-search/shopify.extension.toml`, `locales/*.schema.json`, `blocks/app-embed.liquid`), then
      release a new app version. The rest of the platform (app texts, emails, drawer, the WordPress plugin) is already "Let’s".

## Speed of the merchant panel (Shopify LCP 2,988 ms, "Built for Shopify" needs under 2.5 s)
- [ ] Safe first: cache the Improvements badge (`Today::fixes()`), memoise Onboarding `progress()`/`learned()`, shorten or
      defer `Search/Support/StorefrontLock.php` (a 4 s storefront fetch while rendering), non-blocking fonts, lazy Home widgets.
- [ ] Then the open path: `ShopifyAppController::onward()` calls `ManageSubscription::refresh()` (a sync Admin GraphQL call)
      before redirecting; skip a recent refresh, and one redirect instead of two (`/welcome` from `ShopOverview::mount`).
- [ ] Measure LCP before and after.

## Session and install
- [ ] ID-token authentication on every embedded request, with no cookie-only fallback (Aviad's written requirements,
      17.11): two stores open in one browser share the framed session cookie and the panel answers 404. The likely full
      fix serves the embedded panel from the app's own address (App Bridge adds the token only there).
- [ ] Deletion webhooks answer 200 at once and delete in the background (a 6 s timeout was seen).
- [ ] The sidebar says "Choose a plan" during a trial even with an approved plan.

## Billing and credits
- [ ] The AI credits work kept out of every commit so far (`Modules/Ai` credit files, `ShopifyAppController.php`,
      `Shopify/routes/web.php`, `Shopify/module.json`, `ShopifyServiceProvider.php`, `ShopifyAppTest.php`, the credit hunk of
      `Admin/resources/css/merchant.css`): finish, test and release.

## Data a store's shoppers give
- [ ] Search alerts: prefill a logged-in customer's email on Shopify, which needs Shopify's protected customer data
      approval (left out for now).

## From the strict mock review of 19.11 (held: each touches the app's scopes, billing, extension or version)
- [ ] `read_customers`: drop the scope if nothing needs it, or write the two lines that say what does (the privacy
      policy says Let’s reads no names, emails, phones or addresses from Shopify).
- [ ] A new free trial after uninstall and reinstall: check the shop domain's trial history before granting trialDays.
- [ ] App Bridge NavMenu instead of the panel's own menu inside the iframe (static navigation is supported until 12/2026).
- [ ] One name everywhere: the theme block ("Let's search"), the app ("Let's: AI Search & Answers"), the drawer's title.
- [ ] The Web Pixel's data access is "Always on": follow the shopper's consent there too.
- [ ] The store's own /search page (direct visits, links from Google) still shows Shopify's results, not the drawer's.
- [ ] Load only a small part of the drawer at first and the rest when a shopper opens it (the loader now caches the
      whole file for a year; a split is the next step for the first visit's weight).
- [ ] Measure Lighthouse with and without the embed on a clean store (Shopify allows a drop of at most 10 points).

## Done before approval, for the record
- 19.11: the email settings of the background worker (team emails for leads and call-back requests ran on the `log`
  mailer and were never sent) — see the status in the conversation of 19.11 before relying on it.
- 19.11 (mock review): the drawer behind a small loader, cached a year; no Google font; Shopify's Customer Privacy API
  before any analytics; "Help & support" in the account menu; the app proxy's unknown addresses answer "Not found.";
  gift cards only for gift searches; a new store's opening screen shows its products; the greeting by the viewer's
  clock; the WhatsApp example by language; the delete text explains the Shopify uninstall (48 hours).
