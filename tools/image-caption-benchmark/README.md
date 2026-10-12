# Image caption benchmark

A disposable tool, not a feature. It builds one product database per **flow** (a picture-reading
model and its fallback) from the same products, compares their structured meta objects and their
cost, and searches every database with the same query.

Nothing here is registered with the app: no provider, command, route, setting, migration, cron or
queue. It reads the store's public `products.json`, never the production database, and writes only
to `results/` (ignored by Git). It runs only when you run it.

## Run

Keys come from the environment only:

```powershell
$env:ANTHROPIC_API_KEY = "..."
$env:GEMINI_API_KEY = "..."
php tools/image-caption-benchmark/build.php --limit=30
php tools/image-caption-benchmark/query.php "טבעת זהב לבן עד 2000"
php tools/image-caption-benchmark/query.php "טבעת דומה" --image=C:\photos\ring.jpg
```

- `build.php [--limit=30] [--flows=gemini,haiku] [--shop=https://...]` checks every key before any
  call, then writes `results/<run>/`: one `<flow>.sqlite` per flow, `report.html`, `metrics.csv`
  and `review.csv` (a sheet for scoring each reading by hand).
- `query.php "words" [--image=photo.jpg] [--top=8] [--run=<folder>]` searches every database of the
  latest run (or `--run`) and writes `results/<run>/queries/*.html` with the answers side by side.
  The query is embedded once, so both databases get the same vectors. Without `GEMINI_API_KEY` it
  searches by spelling and filters only.

Flows, models, prices, the store and the sample size are in `config.php`. The prompt is `prompt.md`.

## What a flow writes

Each product's meta object (`identity`, `text`, `commerce`, `variants`, `physical`, `attributes`,
`image_analysis`, `search`, `vectors`, `provenance`):

- The catalogue is the source of truth. Code reads the title, description, tags and options
  (karat, weight in grams, measurements in cm, stones, metal, colour) through `src/Vocabulary.php`.
- The picture fills only what the catalogue left empty: `search.filterable_fields.<field>_source`
  says `catalog` or `image`.
- `provenance.sources` names the source of every field: `catalog`, `variant`, `catalog_text`,
  `image`, `generated` or `unavailable` (metafields, inventory, barcode and brand are not public).
- Vectors (`gemini-embedding-2`, words and pictures in one space): the catalogue text and the
  picture are shared by every flow, so only `combined` (catalogue + the flow's reading) differs.

## What the report measures

Agreement with the catalogue where it states the type, colour, material or stone; conflicts with it
(an invented material, stone or kind); details it states that the reading missed; prices, sizes,
karats or codes read from a picture; schema repairs; search words the picture adds; time, tokens and
cost per step, per product and per 1,000 products. Where the catalogue is silent a reading is
"unverified": score it by hand in `review.csv`.

## Remove

Delete this folder. Nothing else in the project refers to it.
