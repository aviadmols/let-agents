> **Historical plan.** This folder is the original plan, brought in from the let-agents-plan repository. The system is built in this repository; the rules in force are in the root CLAUDE.md and docs/ADR. Kept for the reasoning behind the design.

# Let Agents — search, discovery and Q&A for Shopify and WordPress sites

Status: **design only, no application code yet.** Read `docs/INDEX.md` first, then `docs/01-flows.md`.
The Hebrew docs are the plan; this file is the contract for anyone writing code here.

## Layout (planned, ADR 0001)

- `apps/api` — Laravel 13, Filament 5, Octane (FrankenPHP), PHPUnit 12, Pint. Kernel and the
  `Tenancy`, `Runs`, `Ai`, `Admin` modules are copied from Rega (`../UPSELL`), renamed `upsell` → `letagents`.
- `packages/event-spec` — JSON Schema of analytics events, shared by API, widget and plugins.
- `plugins/wordpress` — WordPress/WooCommerce plugin (plain PHP 8.1+, no Composer at runtime, read-only toward the site).
- `extensions/shopify` — Shopify app: Theme App Extension (app embed) + app proxy.
- `docs/ADR` — decisions. `docs/runbooks` — how-tos, including Railway service settings
  (Railway is configured in its dashboard/API, not in files: `railway.json` is deprecated).

## Kernel and modules (apps/api)

- `app/Core` is the kernel and never references a module.
- Every feature is a module in `app/Modules/{Name}` with `module.json` (`requires`, `features`, `settings`
  with typed bounded caps). `php artisan make:module Name --requires=Tenancy`.
- A module imports another only if listed in `requires`, and only from `Contracts`, `Models`, `Enums`, `Events`.
  `tests/Architecture` enforces it.
- Module folders: `Database/Migrations`, `lang/{he,en}`, `resources/views`, `routes/api.php` (`/api/v1`),
  `routes/web.php`, `Filament/{Operator|Merchant}`, `Prompts`, `Tests`. Business logic in single-purpose `Actions`.
- Site-owned models use `BelongsToTenant`. A query without a site in `TenantContext` throws.
- Flags and caps are read through `Features` / `Settings`, never hardcoded. Every UI string exists in `he` and `en`;
  `php artisan i18n:check` fails otherwise.
- Merchant screens are written for an end client: what changed and what waits for them. No model names, no
  costs, no mechanics. Those appear only on operator screens (Models & budget, Agent log, System map).

## Non-negotiable rules (ADR 0002, 0003, 0004)

1. **No model call on page load or on a keystroke.** `/w/*` routes serve cached JSON built at night; only `/w/{site}/ask`
   may reach a model, and only after the saved-answer lookup. A test proves it.
2. **Code before model.** Counts, filtering, candidates, lexical search, ranking, caching, citation checks,
   the daily digest: code. A model receives condensed input with ids and answers in JSON against a schema.
3. **Two families, crossed.** What one family writes (OpenAI by default) the other audits (Claude by default),
   and vice versa. `family(writer) != family(auditor)` is checked in code before the call; equal families fail the task.
4. **Never ask twice.** Every model request carries a fingerprint (content hash + candidates + evidence bands +
   prompt version). Unchanged input is skipped.
5. **Every call is accounted.** `SpendGuard::assertCanSpend()` before; tokens (input, output, cache read/write),
   batch flag, cost and duration in `ai_ledger` after; one Hebrew summary line per step in the `Run`.
6. **Everything is a registry.** Platforms, document sources, candidate sources, chat/embedding drivers, widget
   layouts, proposal kinds, notify channels. A new model or provider is a settings row or one driver class.
7. **Vectors carry their model name** and are never compared across models. Price and stock never enter the
   embedded text.
8. **Prompts are versioned files** (`Prompts/{role}.v{n}.md`); a released version is never edited. Structured
   output always (`output_config.format` on Anthropic, strict `json_schema` on OpenAI). Keep prompts neutral.
9. **Batch for everything not urgent**; stable prefix first for prompt caching; verify `cache_read_input_tokens > 0`.

## Models (defaults are settings, see docs/03-models-and-budget.md)

OpenAI: `text-embedding-3-small`, `gpt-5.4-nano` (scope checks, samples), `gpt-5.4-mini` (picker, answerer, proposal auditor).
Anthropic: `claude-haiku-4-5` (vision reader, tag auditor, answer verifier; Batch), `claude-sonnet-5-5` (escalation, daily analyst),
`claude-opus-5-5` (arbiter only). Official SDKs (`anthropic-ai/sdk`, `openai-php/client`), no OpenRouter.
Claude 4.6+ models: `thinking: {type: "adaptive"}` + low `effort` for bulk roles; no prefill, no `budget_tokens`.

## Local environment

PHP 8.4 and Composer come from Herd: `php`/`composer` resolve in PowerShell; from Git Bash use
`/c/Users/user/.config/herd/bin/php84/php.exe`. Node 24. No Docker: Postgres + pgvector tests run in CI only.
Hebrew text: never `trim()` with multibyte bullets (use a `/u` regex); Hebrew final letters differ in patterns;
anything hashed into a fingerprint must be sorted explicitly (Postgres order differs from SQLite).

## Commands (once apps/api exists)

```sh
composer check        # pint --test, i18n:check, phpunit
php artisan module:list
php artisan admin:operator you@example.com
```
