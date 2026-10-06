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

- `Catalog` reads the store feed through `Connections\Contracts\StoreFeed` (the Rega plugin today).
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
  (default 6 USD a month, all shops). Answer files from agents outside the platform cost nothing.
- `ReadProductsInCode` runs before any model: brand (`BrandResolver`), size families, a type the
  category stands for, title sizes. Its facts have origin `code`; model readings never supersede
  them and receive them as `known`. `ComputeProductRelations` builds complements, families and
  alternatives from approved facts, merchant links and a shop's versioned rules
  (`resources/relations`), each with reasons. The Scan log page shows one product's whole trail.
- Hebrew text: never `trim($s, '•…')` with multibyte characters (PHP trims bytes and cuts letters);
  use a `/u` regex. Hebrew final letters (ן ם ך ף ץ) differ from their regular forms in patterns.

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

## Storefront widget and analytics (apps/api)

- `Widget` serves `/api/v1/widget/rega.js` (source: `Widget/resources/widget/rega.js`, plain ES5-ish JS,
  no build step) and `/api/v1/widget/{site}/page`, built in code from approved facts only. Placement
  is a per-shop CSS selector setting. Shopper-facing sentences are templates in `widget::bank`.
  Two layouts from one bank (`widget.layout`): `circles` (the row of circles, one panel) and
  `chat` (one closed teaser line that opens into a conversation whose suggestions are the same
  sections; a chosen section's rendered body is moved into an assistant bubble after a short
  "thinking" pause — no model call, only a typed question reaches the Assistant). Both use the soft
  look: gradient hairlines from `--rega-glow-1/2/3`, the store's `--rega-accent` only on buttons.
- `Analytics` stores beacons validated against `packages/event-spec` (copied to
  `resources/event-spec` in the image) and plugin order summaries; `BuildShopReport` is the one
  report for the plugin, the operator panel and later the merchant panel.
- `analytics:scores` (nightly) writes `analytics_scores`; `BuildPageBank::learned()` applies them:
  section order, clicked items first, never-clicked products dropped after enough openings. Sections
  carry spare products for that, so trim to `widget.max_products` only there.
- `ComputePopularity` (same nightly command) writes `analytics_popularity`: adds to the cart and
  orders per product. rega.js reports the store's own add-to-cart button as `source: page`. The
  widget's popularity line reads the table (`BuildPageBank::popularity()`), never the events.
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
  bank, which is cached and shared: `POST widget/{site}/recent` serves it and the widget adds the
  circle itself, like `compare`.
- Highlights (`product_highlights`, facts of kind `highlight`) are written from longer text
  (`enrichment.max_highlight_text_chars`); a reviewer of highlights must read that same text.
- A route under `api/*` gets `Access-Control-Allow-Origin: *` from Laravel's CORS config; origin
  checks for the widget are done in the controllers with `StoreConnection::allowsOrigin`.

## WooCommerce plugin (plugins/woocommerce)

- Plain WordPress PHP, no Composer runtime deps, minimum PHP 8.1 (CI lints on 8.1: no readonly
  classes, no typed class constants). Namespace `Rega\`, text domain `rega`, REST `rega/v1`.
- Read-only toward the store: no write routes, nothing about customers or users. ADR 0005.
  Outgoing only: order summaries without customer data and signed report requests (ADR 0006).
- Keys the plugin and the API share are derived from the token hash, never stored twice:
  `RegaStorefrontSiteKeys` must stay identical to `ConnectionsSupportSiteKeys`.
- Translations: `languages/rega-he_IL.l10n.php` (WP 6.5+ PHP format). `php bin/i18n.php check`.
- Integration tests boot real WordPress + WooCommerce in Playground:
  `node tests/playground/run.mjs` (about 3-5 minutes; `REGA_KEEP=1` leaves the site running,
  login admin / password). Zip: `php bin/build.php` -> `dist/rega-<version>.zip`.
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
