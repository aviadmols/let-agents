# UPSELL — shopping assistant platform

Multi-tenant widget for stores (WooCommerce first, Shopify later). The plan, in Hebrew, is
`docs/WORK-PLAN.md`; read the relevant section before building a phase.

## Layout

- `apps/api` — Laravel 13, Filament 5, Octane (FrankenPHP), PHPUnit 12, Pint.
- `packages/*` — language-neutral JSON Schemas shared by API, widget and plugin (npm workspaces).
- `docs/ADR` — decisions. `docs/runbooks` — how-tos, including the Railway service settings.
- Railway services are configured through the Railway API/dashboard, not files: Railway deprecated
  `railway.json` config-as-code, and its replacement (`.railway/railway.ts`) is applied only via
  `railway config apply`. A service cannot be managed by both. See `docs/runbooks/deploy-railway.md`.

## The kernel and modules (apps/api)

- `app/Core` is the kernel. It must never reference a module.
- Every feature is a module in `app/Modules/{Name}` with a `module.json` declaring `requires`,
  `features` (flags) and `settings` (typed, bounded caps). Create one with
  `php artisan make:module Name --requires=Tenancy`.
- A module may import another module only if it lists it in `requires`, and only from its
  `Contracts`, `Models`, `Enums` or `Events` namespaces. `tests/Architecture` enforces this.
- Filament screens go in `app/Modules/{Name}/Filament/{Operator|Merchant}/{Resources|Pages|Widgets}`
  and are discovered automatically. No panel provider edits.
- Module folders: `Database/Migrations`, `Database/Factories` (PSR-4 case matters on Linux),
  `lang/{he,en}`, `resources/views`, `routes/api.php` (prefixed `/api/v1`), `routes/web.php`, `Tests`.
- Shop-owned models use `App\Core\Tenancy\BelongsToTenant`. Queries without a shop in
  `TenantContext` throw. Cross-shop code uses `TenantContext::runUnscoped()` on purpose.
- Read flags and caps through `App\Core\Facades\Features` / `Settings`, never hardcode a cap.
- Every UI string goes through module translations in both `he` and `en`. `php artisan i18n:check`
  fails on a missing key and on a declared flag or setting without a label.
- Business logic lives in single-purpose `Actions`. Controllers, commands and Filament call them.

## Catalog and product knowledge (apps/api)

- `Catalog` reads the store feed through `Connections\Contracts\StoreFeed` (the Let Agents plugin today).
  Records are never deleted by a sync; `removed_at` marks what the store stopped publishing, and
  only after a complete read. Costs and internal notes are stripped before storing
  (`Catalog\Support\SensitiveFields`), even if an old plugin sends them.
- `Enrichment` builds facts about products and articles. Code first (`Scanning/`: text condenser,
  boilerplate, measurement scanner, vocabulary patterns), then a model picks IDs from what code
  found, then code checks every answer (`Tasks/`). Runbook: `docs/runbooks/agent-tasks.md`.
- Agent work goes through batches: a downloadable JSONL task file, answers uploaded back. Request
  IDs contain a hash of the input, so answers are reusable across batches with the same requests.
- Prompts are versioned files in `Enrichment/Prompts`. Never edit a released version; add a new
  one and raise `PromptLibrary::CURRENT`.
- Superlatives are computed in code only, from approved facts (`ComputeRankings`).
- Any call to a model with the keys saved in the panel asks `Ai\Contracts\SpendGuard::assertCanSpend()`
  first and records its cost with `RunContext::usage()`. The cap is `ai.monthly_spend_cap_usd`
  (default 50 USD a month, all shops). Answer files from agents outside the platform cost nothing.
- Shopper behaviour is kept for learning: events (`analytics.retention_days`), searches and clicks
  (`search.keep_days`) default to ten years, and a photo search keeps what it learned forever (only
  its full photo goes after `search.photo_keep_days`). What identifies a person does not stay: IP and
  session go after `*.visit_keep_days`, Try-On photos after `try_on.retention_days`, leads after a year.
  Don't shorten a behaviour retention or delete behaviour rows without the owner's word.
- The learning and checking agents' work is kept forever: runs and model calls of every agent that
  is not `"when": "live"` in its module.json (or has `"keep": true`) are never pruned
  (`Runs\Support\LearningAgents`), and are read in one place: "מה הסוכנים למדו" (`LearningJournal`).
  A new learning agent is kept by being declared there.
- Every call through `ChatModel`/`VisionModel`/`WebSearchModel`/`ImageModel` is written to `run_calls`
  (`Runs\Contracts\CallJournal`) with its prompt, what was sent and what came back, under the run open at
  the time (`RunStack`). Pictures appear by type and size only. Kept `runs.call_log_days` (30); the
  operator opens it from the pipeline (`ProcessLog`). A driver called directly bypasses it: don't.
- `ReadProductsInCode` runs before any model: brand (`BrandResolver`), size families, a type the
  category stands for, title sizes. Its facts have origin `code`; model readings never supersede
  them and receive them as `known`. `ComputeProductRelations` builds complements, families and
  alternatives from approved facts, merchant links and a shop's versioned rules
  (`resources/relations`), each with reasons. The Scan log page shows one product's whole trail.
- Hebrew text: never `trim($s, '•…')` with multibyte characters (PHP trims bytes and cuts letters);
  use a `/u` regex. Hebrew final letters (ן ם ך ף ץ) differ from their regular forms in patterns.

## Planned (docs only so far): strict first scan, Site rename

- ADR 0011 / `docs/strict-scan.md`: one vocabulary per branch leaf with a buyer's checklist, a score
  (code metrics + pilot extraction + cross-family review) and a gate before the full scan; merchant
  screen for missing info; "ask the web" needs two independent verbatim sources and a person's approval.
  `enrichment.schema_auto_advance` stays off until the score is calibrated.
- Try-On was removed on 2026-10-16 (the owner's word): its storefront script, routes and screens are
  gone; `/api/v1/try-on/let-agents-tryon.js` answers a tiny retired script for old stores. Its models,
  migrations and rows stay, and `try-on:prune` still deletes expired photos nightly (privacy).
- ADR 0013: the tenant becomes `Site` (`site_id`). Until that commit lands, code still says `Shop`.

- Try-On marks are code-only facts (`FactKind::TryOn`, key `class`): `none` is written for known-not-eligible, nothing for undecided
  (a vocabulary that says nothing about try-on). Vocabulary attributes carry `importance` (default `optional`) and `why`;
  `RequiredGaps` lists what a branch asks for. Yes/no attributes are not counted (code stores only the yes): write an
  "included / body only" choice instead.
- First scan order: catalog, knowledge, posts, **structure**, products, pages, search, tags. `structure` (`BuildStructure`)
  divides the catalog (`BranchPlan`, code), writes a vocabulary per branch (`ProposeSchema`, prompts v3, reads guide titles
  from `KnowledgeEvidence`), scores it (`ScoreSchemaProposal`: pilot in code, never a model) and gates it
  (`AdvanceSchemaProposal`, flag `enrichment.schema_auto_advance` off by default). A `required`/`recommended` attribute
  is kept even when no product says it: that is the gap to ask the store about.
- `php` CLI defaults to 128 MB here and `composer check` runs out of memory in the full suite; run
  `php -d memory_limit=2G vendor/bin/phpunit` when it does.

## A content site: WordPress with no WooCommerce (apps/api, Demo + Connections)

- One reader, `Connections\Contracts\ContentSiteFeed`, implemented by `Demo\Support\WordPressContentFeed` (over
  `WordPressSite`), for a demo (platform `wordpress` from `PublicSite::read`) and for a plugin-connected site whose
  status says `woocommerce.active` false (`PluginStoreFeed` hands it over; a store with WooCommerce never reaches it).
- `WordPressSite`: content types from the REST API, only those its sitemap lists, never `page` (internal pages) or a
  builder's own. Three pages of each are read with `HtmlText`; the lines most pages repeat are the template and go; a
  type whose pages state a price, a way to ask or book, or a length (or Product/Event/TouristTrip/Course data) is offered
  and becomes products (`purchasable` false, its page's words when the API body is empty, the price only from its
  first lines, one item once by address); one with article data becomes posts. Code only.
- Its search (`Demo\Support\ContentSite::settle`, once: the demo's preparation, or the first plugin status with no
  WooCommerce): `search.show_cart` off, `search.card_action` read_more, `search.guides_after` 4 (the guides after the
  second row), `search.store_currency` on (the shop's currency, read from its prices), `search.content_by_meaning` on.
  Every one defaults to what stores always had; the drawer reads them from the index config.

## Index and matching (apps/api, ADR 0007)

- `Retrieval` builds a per-shop index by meaning (`retrieval_chunks`, pgvector on Postgres, JSON
  text compared in PHP on SQLite) from every `DocumentSource` tagged `retrieval.sources`: products,
  pages and posts, and per-product purchase documents. Text is built in code, price and stock left
  out; only changed pieces are embedded. A vector keeps its model name and is never compared with
  another model's. `SemanticSearch::similarTo()` compares stored vectors, no model call.
- `MatchProducts`: every `CandidateSource` tagged `retrieval.candidates` offers in-stock products
  (bought together with lift, similar vectors, named together in guides), the model picks by ref,
  `MatchCheck` accepts or refuses. Accepted picks reach `ComputeProductRelations` as source
  `ai_match`. The request fingerprint bands the evidence so a new order does not re-ask the shop.
  Matching stops at `retrieval.match_max_share_of_cap` of the monthly AI cap.
- Providers are drivers: `Ai\Contracts\ChatDriver` / `EmbeddingDriver`, listed in
  `AiServiceProvider`. Callers name a provider and model from settings, never in code.
- Past orders: plugin 0.4.0 sends 24 months once (`orders/history`); they are stored with
  `source = history`, no attribution, and `BuildShopReport` leaves them out.
- The operator page "System map" (Knowledge) draws the index, the matching and one product page's
  stages (`BuildPageBank` explain `trace`, read through `Widget\Contracts\ExplainsPages`).
  Runbook: `docs/runbooks/retrieval.md`.

## Search, pictures and the daily review (apps/api, ADR 0008)

- `Search` owns the store's search box. `Support/HebrewSearch.php` and `resources/search/hebrew-search-engine.js`
  are one algorithm in two languages; change both or neither, `HebrewSearchTest` runs them on one
  fixture and requires identical ids in identical order. The storefront runs one script, the Lets Search
  drawer (`resources/search/lets-search.js`, built from `lets-search/` and the engine with
  `npm run build:lets-search`; `LetsSearchBuildTest` fails when it is stale). Its code is one function
  split by subject into `lets-search/drawer/NN-*.js` (filters, search, cart…), joined in number order and
  sharing one scope; edit the part, never the built file. It is served at
  `/api/v1/search/lets-search.js` and at the old `/search/let-agents-search.js` address. The index (`search_indexes`) is rebuilt at
  02:40 and on demand; synonyms are applied at build time, never at query time.
- A picture's words are the product's words in the search for words, less what is only drawn, printed or written on
  it (`BuildSearchIndex::shown`: the describer's `depicted` list since caption v8, and before it a phrase that opens
  with "איור", "לוגו", "הדפס של"…): a T-shirt with a drawing of a shoe is no shoe. What is drawn stays in the index
  as `dw` and counts for a photo search and for similar items (`SearchCatalog::photoText`), where it is what the photo
  shows and part of what sets the product apart. Similar items stay of the product's own kind (`ProductKinds::sameKind`).
  A print asked for (`Support/Patterns`) orders by how much of each product its picture says the print covers (caption
  v8's `pattern`), in the search and in similar items; a picture described before v8 keeps its place.
- `SearchCatalog` merges spelling (exact matches first), meaning (`SemanticSearch::nearText`) and
  pictures (`picturesNearText`) by reciprocal rank. `CountSearch` counts once per tab; query text
  only, nothing about the shopper. Day columns: compare with `whereDate` (SQLite stores a time).
- `QueryVectors` embeds a new wording once per shop and model and adds its cost to one run per shop
  per day (`retrieval.query_embedder`); the exact total lives in the run's output, the column is
  rounded from it.
- Pictures: `ImageEmbedder` / `ImageEmbeddingDriver` in Ai; `GeminiEmbeddings` (plain REST, key in
  the `x-goog-api-key` header). `BuildImageIndex` embeds each product's main picture once per
  address. `LookAlike` is a candidate source; `MatchCheck::likeness()` counts looks as similarity.
  A fingerprint only gains the looks band when the signal exists, so old ones stay stable.
- `Improvement` runs at 04:30: `Evidence` (code, fixed-length lists, an item is sent once unless it
  doubled), analyst proposes, code gates, an auditor from another `AiProviderName::family()` checks.
  Same family stops the review before any call. `Digest` is written in code.
- Scripted edits from Git Bash: `node -e` and `awk` mangle `\` in PHP namespaces and `\x27`.
  Use a small PHP script with `str_replace`, or the editor.
## Storefront widget and analytics (apps/api)

- Since 2026-10-16 the storefront runs ONE script, the Lets Search drawer (see Search above); the old
  widget, search box, shortlist script and their display modes (layouts, placement, panel/page/drawer
  results) are gone. Old script addresses stay for stores on an older plugin: `/widget/let-agents.js`
  and `/search/let-agents-search.js` serve the drawer, `/shortlist/let-agents-shortlist.js` a tiny
  retired script. The drawer keeps every contract of the old scripts (events, visitor id cookie, ask
  bodies, `la_q`/`la_f`/`la_order`/`la_list` links, `window.LetAgentsSearch`); change one only with its
  server side. In preview mode shoppers get it `silent` (counted, nothing shown).
- `Widget` serves `/api/v1/widget/{site}/page`, built in code from approved facts only: on a product
  page the drawer shows it as "about this product", in the bank's (learned) section order, and sends
  its beacons. Shopper-facing sentences are templates in `widget::bank`.
- `Analytics` stores beacons validated against `packages/event-spec` (copied to
  `resources/event-spec` in the image) and plugin order summaries; `BuildShopReport` is the one
  report for the plugin, the operator panel and later the merchant panel.
- `analytics:scores` (nightly) writes `analytics_scores`; `BuildPageBank::learned()` applies them:
  section order, clicked items first, never-clicked products dropped after enough openings. Sections
  carry spare products for that, so trim to `widget.max_products` only there.
- `ComputePopularity` (same nightly command) writes `analytics_popularity`: adds to the cart and
  orders per product. The drawer reports the store's own add-to-cart button as `source: page`. The
  bank's popularity line reads the table (`BuildPageBank::popularity()`), never the events.
- `Assistant` answers shoppers' questions (`/widget/{site}/ask`, `/widget/{site}/questions`): saved
  answers first, contact details and daily limits in code, a small model checks the question is
  about the product, the writer answers from store information first and general knowledge second
  (never "according to the page"), then code and a small model verify the answer is about this
  product and quotes no number the store did not give. Models go through `Ai\Contracts\ChatModel`
  (panel keys) and `SpendGuard`; tests bind a fake ChatModel. Answers with an older
  `AnswerQuestion::PROMPT_VERSION` are asked again, except the team's own (source `team`), which the
  operator writes in "Shopper questions".
- The store team's say: `WidgetCuration` rows (pin or hide, per page, an item or a whole section)
  are applied in `BuildPageBank::curated()` after `learned()`. A pin is shown first and never
  dropped by the scores; a hidden section is never re-created by a pinned item. The operator page
  "Page in the widget" builds the bank with `explain: true`, so every item carries `why` notes.
- `Shoppers` owns the people behind the anonymous ids. A shopper who leaves a phone or an email
  gets a `ShopperIdentity` (contact encrypted, looked up by a hash salted per shop) and a
  `ShopperVisitor` per browser. Browsing follows them between browsers only between links they
  proved with a code, so typing someone else's phone shows nothing of theirs. No code is created
  for a channel that cannot deliver it (`Support\Channels`): email needs a real mailer, phones wait
  for an SMS provider. The Widget reads history through `Shoppers\Contracts\VisitHistory`, never
  the tables. The "products you viewed" circle is one visitor's own, so it can never be in the page
  bank, which is cached and shared: `POST widget/{site}/recent` serves it and the drawer adds it
  itself, like the comparison with the product viewed before.
- Highlights (`product_highlights`, facts of kind `highlight`) are written from longer text
  (`enrichment.max_highlight_text_chars`); a reviewer of highlights must read that same text.
- A route under `api/*` gets `Access-Control-Allow-Origin: *` from Laravel's CORS config; origin
  checks for the widget are done in the controllers with `StoreConnection::allowsOrigin`.

## WooCommerce plugin (plugins/woocommerce)

- Plain WordPress PHP, no Composer runtime deps, minimum PHP 8.1 (CI lints on 8.1: no readonly
  classes, no typed class constants). Namespace `LetAgents\`, text domain `let-agents`, REST `let-agents/v1`.
- Read-only toward the store: no write routes, nothing about customers or users. ADR 0005.
  Outgoing only: order summaries without customer data and signed report requests (ADR 0006).
- Keys the plugin and the API share are derived from the token hash, never stored twice:
  `LetAgentsStorefrontSiteKeys` must stay identical to `ConnectionsSupportSiteKeys`.
- Translations: `languages/let-agents-he_IL.l10n.php` (WP 6.5+ PHP format). `php bin/i18n.php check`.
- Integration tests boot real WordPress + WooCommerce in Playground:
  `node tests/playground/run.mjs` (about 3-5 minutes; `LET_AGENTS_KEEP=1` leaves the site running,
  login admin / password). Zip: `php bin/build.php` -> `dist/let-agents-<version>.zip`.
- From Git Bash, Playground VFS paths need `MSYS_NO_PATHCONV=1`; `run.mjs` avoids the issue.

## Commands (run from apps/api)

```sh
composer check                 # pint --test, i18n:check, phpunit
php artisan module:list
php artisan admin:operator you@example.com
```

On this Windows machine PHP comes from Herd. From Git Bash use
`/c/Users/user/.config/herd/bin/php84/php.exe` directly; `php` resolves only in PowerShell.

## Gotchas

- `AuthenticateShopKey` implements `AuthenticatesRequests` so Laravel runs it before
  `ThrottleRequests`; otherwise the per-shop rate limit silently becomes per-IP.
- A Livewire page must never have a public property and a public method of the same name.
  The server side works and a `->call()` test passes, but in the browser `$wire.<name>` resolves
  to the property, so `wire:click="<name>"` calls `null()` and the button does nothing, silently.
- Postgres refuses `FOR UPDATE` with aggregates: lock the parent row, then count.
- Tests run on SQLite locally and on Postgres + pgvector in CI. Tests that create their own
  tables must drop them in tearDown.
- AI rules from the plan: OpenAI's top model writes visitor-facing text; Claude models do
  analysis (Haiku for bulk, in Batch). No LLM call on page load, ever.
