# Index and matching (Retrieval)

How a shop's products, pages, posts and orders become an index by meaning, and how products are
matched with a model. Decisions: `docs/ADR/0007`. The operator panel's **System map** draws all of
it for the chosen shop.

## What runs, and when

Every night at 02:45 (Israel time), between the catalogue sync (02:30) and Enrichment (03:10):

```sh
php artisan retrieval nightly --all     # what the schedule runs: index, then match
php artisan retrieval index <shop>      # just the index
php artisan retrieval match <shop>      # just the matching
```

1. **Index** (`retrieval.build_index`). Every source tagged `retrieval.sources` is read in code
   and cut into pieces (`retrieval.chunk_chars`). A piece whose text is unchanged keeps its
   vector. The rest go to the embedding model, at most `retrieval.max_chunks_per_run` per run.
2. **Match** (`retrieval.match_products`). For each product (best sellers first, at most
   `retrieval.match_products_per_run`), every source tagged `retrieval.candidates` offers
   products. The model picks complements and alternatives by ref, and `MatchCheck` accepts or
   refuses each pick. A product whose text and evidence are unchanged is not asked again.
3. **Relations** (Enrichment, 03:10). Accepted picks become relations with source `ai_match`,
   next to the merchant's links, bought-together pairs and the rules.

Neither step fails because it ran out of something. A run that hit the spending cap, matching's
share of it (`retrieval.match_max_share_of_cap`), or found no key stops, says why
(`output.stopped`, shown on the map), and leaves the rest for the next night.

## Past orders

Plugin 0.4.0 and later sends the store's paid orders from the last 24 months once, by itself,
the first time an admin opens WordPress with the store connected and the widget not off.
WooCommerce > Rega shows how far it has got and can send them again. Rega already has an order
with the same hash, so sending again stores nothing twice. On the API side:
`POST /api/v1/plugin/{site}/orders/history`, and progress in `analytics_order_imports` (shown on
the map). History orders have `source = history`: they count for learning, not in the report of
what Rega did.

## Changing models

Everything is a setting (Configuration screen, module "Index and matching"):

| What | Settings |
|---|---|
| Vectors | `retrieval.embedding_provider`, `retrieval.embedding_model`, `retrieval.embedding_dimensions`, `retrieval.embedding_usd_per_million` |
| Matching | `retrieval.match_provider`, `retrieval.match_model`, `retrieval.match_reasoning_effort`, input and output prices, `retrieval.match_max_output_tokens` |

- A new **embedding model** rebuilds the index on the next run. Vectors of the old model are never
  compared with the new ones. Budget for one full re-embed.
- A new **matching model** re-asks every product, because the model is part of the request
  fingerprint.
- The provider needs a connected key under AI providers. `anthropic` works for matching today.

## Adding a provider, a source or a signal

- **Provider:** add a case to `Ai\Enums\AiProviderName`, a class implementing `Ai\Contracts\ChatDriver`
  and/or `Ai\Contracts\EmbeddingDriver`, and add it to `AiServiceProvider::CHAT_DRIVERS` or
  `EMBEDDING_DRIVERS`. Then set the provider's name in the settings above.
- **Index source:** a class implementing `Retrieval\Contracts\DocumentSource`, tagged
  `retrieval.sources` in its module's service provider. It appears as a lane on the map.
- **Candidate source:** a class implementing `Retrieval\Contracts\CandidateSource`, tagged
  `retrieval.candidates`. Its signals reach the model and the map as they are; add a label under
  `knowledge::map.signals` and `knowledge::map.candidate_sources`.
- **Prompt:** never edit `Retrieval/Prompts/match.v1.md`. Add `match.v2.md` and raise
  `MatchProducts::PROMPT_VERSION`; every product is asked again.

## Searching by meaning from code

`Retrieval\Contracts\SemanticSearch::similarTo($source, $sourceId, $sources, $limit)` compares
vectors already stored. It never calls a model and costs nothing. Example: the guides nearest a
product, `similarTo('product', $productId, ['content'], 3)`.

## When something looks wrong

- **Pending stays high:** check `output.embedding.stopped` on the last index run (`no_key`,
  `spend_cap`, `unknown_provider`), and whether `max_chunks_per_run` is lower than the catalogue.
- **A product has no model picks:** the map's Matching tab, choose the product. `too_few` means code
  found fewer than two candidates in stock; it needs orders, guides or vectors first.
- **A pick you disagree with:** "Ask the model again" on the product, or pin or hide it on
  "Page in the widget". Curation always wins over every relation source.
- **Postgres:** the migration runs `CREATE EXTENSION IF NOT EXISTS vector`; `php artisan system:check`
  tells whether the extension is available on the server.
