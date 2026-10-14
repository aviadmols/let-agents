# Running agent tasks

How product knowledge gets built for a shop, from the store feed to superlatives. Every step is
recorded in Agent activity.

## Order

1. **Sync the catalog.** Operator panel, Catalog, "Sync catalog". Runs on the worker. Or
   `php artisan catalog:sync <shop-slug>`.
2. **Add a vocabulary** for a branch of the catalog. Vocabularies, "Add vocabulary", from a
   template (`power-tools`, `wood`, `care-and-cleaning`, `fasteners`, `hardware`, `hand-tools`,
   `adhesives`, `paints`) or a JSON file. A product type may name store categories, also outside
   the root (brushes filed away from paints); a product filed in two branches goes to the
   vocabulary whose category says what it is. A
   vocabulary lists product types (optionally the store categories each stands for), specs with
   units and ranges (optionally read from the title with a pattern), fixed choices, tags and jobs
   ("good for"), each with the patterns code matches first.
3. **Read products in code.** Vocabularies, "Read products in code". No model and no cost: brand,
   size families, a type the category stands for, sizes in titles, what a shopper must choose, the
   price unit. What code settles is saved as approved facts with origin "code" and sent to the
   model as `known`, so the model answers only the rest. Run it again after every catalog sync;
   unchanged products are skipped.
4. **Read products.** Agent tasks, "Create task file", task "Read products". Download the file.
5. **Run the file with a model.** Suggested: `claude-haiku-4-5`. The first line of the file holds
   the instructions (`system`). Every other line is one request. Produce one line per request:
   `{"custom_id": "...", "output": {...}}`.
6. **Upload the answers** on the task. Answers can come in several files. Code checks every answer
   before anything is saved.
7. **Check facts, tier 1.** Create a "Check facts" file, tier 1, for products and again for
   articles. Suggested model: `claude-haiku-4-5`.
8. **Check facts, tier 2.** Same, tier 2. Only what tier 1 was unsure about. Suggested model:
   `claude-sonnet-5`. What tier 2 is unsure about waits for a person in Product facts.
9. **Read articles** any time after the sync. Then check their facts as in steps 6 and 7.
10. **Compute superlatives.** Superlatives, "Compute superlatives".
11. **Match products to articles.** Products for articles, "Match products to articles". Linked products first, then in-stock products from the categories a checker approved. Store pages get none.
12. **Compute relations.** Product relations, "Save matching rules" (template `hardware-store` or
    a JSON file), then "Compute relations": the merchant's cross-sells, merchant links pointing to
    the product, the rules (a battery of the same brand and voltage for a body-only tool, oil for
    wood meant for outdoor jobs), other sizes of the same product, and alternatives of the same type
    at a similar price. Every relation keeps its reasons. Rules can also name store categories
    (`categories`), for branches without a vocabulary yet, such as shelf supports. A product
    several rules find shows the label of the first one, so specific rules (wall fixings for a
    shelf) go before general ones (fasteners for wood builds). The widget shows one product from
    each rule in turn.
13. **Write highlights.** Agent tasks, "Create task file", task "Product highlights", per
    vocabulary, after the reviews above. Suggested model: `claude-sonnet-5` (shoppers read these
    words). Up to four points per product from its own text, each resting on a quote; code rejects
    quotes not in the text and numbers, superlatives or prices the quote does not have. Then check
    them (step 7): the checker reads the same longer text. A point whose quote the store repeats on
    many products (`widget.common_highlight_products`) is shown last and never becomes the quote.
14. **Scores from behavior.** Nothing to run by hand: `analytics:scores` runs every night at 04:15
    (Asia/Jerusalem) for every connected shop, or `php artisan analytics:scores <shop-slug>`. It
    scores each widget section across the shop, on each page, and each product or article inside
    a section: opens + 2 clicks + 4 adds to cart + 8 purchases. A page's score leans on the
    shop-wide rate until the page has data (`analytics.score_prior_exposures`). The widget orders
    sections by score. A section with no score yet gets the best known score, so it gets seen.
    Inside a section, clicked items come first. After `analytics.drop_related_after_opens` openings
    on a page, products shown there and never clicked are dropped, and spare products take their
    place. Preview visits do not count. The run's output lists sections from best to worst.
    The same command counts, per product, how many times it was added to the cart (the store's
    own button, which the widget watches, and the widget's buttons) and how many orders held it
    in the last `analytics.popularity_window_days`. The widget shows the counts above the circles
    once they reach `widget.popularity_min_count`, and marks a product "popular in this store"
    when adds + 2 × orders is at least `analytics.popular_min_score` and it ranks in the top
    `analytics.popular_top_percent` of the live catalog. Flag: `widget.popularity`.
15. **The store team's say.** Operator panel, Widget, "Page in the widget"
    (`/operator/widget/page?shop=&type=&id=`; a preview visit on the store links there with
    "Why is this shown?"). It lists every section and item the widget would show on that page
    with the reason each is there: the rule or link that found a complement, the family, the
    guide's tie to the product, the score. The team pins an item or a whole section (shown first,
    never dropped by the scores), hides one (never shown on that page again), or adds a product
    the widget did not find. Nothing to recompute: pins and hides are read on every build.
16. **Shopper questions.** Operator panel, Assistant, "Shopper questions": every question asked
    on the shop's product pages, by product, unanswered first. The team's answer becomes the saved
    answer for that product and question (source `team`); it is never asked of a model again and
    survives prompt changes. An answer can also be hidden.

The WhatsApp strip (feature `widget.whatsapp`, with the number, hours and message in Widget
settings) needs no scan; it is on or off per shop.

Each shop picks how the widget looks (`widget.layout`): **circles** — the key sentence, a row of
circles each marked with how much it holds ("+4"), one panel under them — or **assistant** — the
page loads with one closed line ("I have 5 things that will help you decide"), which opens into a
conversation: the same circles as suggestions, each answered from the bank already in hand after a
short pause, a field for a free question at the bottom, the WhatsApp strip inside when the shop has
it. Nothing about the scan changes; the assistant calls no model except for a typed question.

The "products you viewed" circle (feature `shoppers.recent_products`, on) needs no scan either: it
counts the page views already collected. Leaving a phone or an email (feature `shoppers.signup`,
off) adds a short form under it; every sign-up appears in the operator panel under "Sign-ups" with
the products that person looked at. Turn it on only with consent wording the shop stands behind
(`shoppers.signup_consent`). A shopper who signs up is remembered for
`shoppers.recent_days_identified` instead of `shoppers.recent_days`, and once they type back a code
their browsing follows them to their other browsers. Sending a code needs a channel that works:
set `MAIL_MAILER` and the rest of the mail environment for email; phones wait for an SMS provider.

## The first scan's structure (ADR 0011)

A new shop's scan reads what the store says about itself first (`knowledge`, `posts`), then divides the
catalog (`structure`), then reads the products with what that produced. `structure` is
`php artisan enrichment structure <shop> [--force]`: the catalog is divided into branches in code, each
branch gets a vocabulary written by one family and reviewed by the other (it reads the titles of the
store's guides), code scores it on a pilot, and the gate lets it on or leaves it for a person on
"Vocabulary discovery" (score, parts, hard failures, cost). Nothing is read with a vocabulary until it is
approved; with `enrichment.schema_auto_advance` off (the default) every branch waits for a person.

## The log

Scan and check log shows one product's whole story: the code reading, every fact with origin,
status, reason and checker verdict, every request sent to a model with the answer and the
problems code found in it, and the relations built. Its quality section counts the most common
answer problems across the shop, which is where to look before changing a vocabulary or a prompt.

The same steps from the command line:

```sh
php artisan enrichment vocabulary <shop> --template=wood --author="..."
php artisan enrichment code <shop>
php artisan enrichment tasks <shop> product_extraction --out=tasks.jsonl
php artisan enrichment results <batch-id> answers.jsonl --model=claude-haiku-4-5
php artisan enrichment tasks <shop> fact_review --tier=1 --subject=product --out=review.jsonl
php artisan enrichment rankings <shop>
php artisan enrichment rules <shop> --template=hardware-store
php artisan enrichment relations <shop>
```

## What code does before a model sees anything

- Removes "click here" lines, repeated lines, and paragraphs pasted into many products
  (brand boilerplate, found per batch).
- Finds every number with a unit, in Hebrew and English spellings, converted to one unit per
  dimension. `"½7` is 190.5 mm, `2X18V` is offered as 36 V and as 18 V.
- Finds the wording of choices and tags from the vocabulary patterns, and product type hints in
  the title.

The model answers with IDs from those lists. It never types a number or a quote for a spec.

## What gets approved without review

A claim is approved when code and the model agree and nothing else could have been meant:

- the product type is the single type hint code found in the title;
- a measurement whose unit can mean only one spec for that product type, with one value in the
  text, and not in a sentence about something optional ("ניתן לרכוש בנפרד");
- a choice or yes/no spec whose wording code found, when the model accepts one value.

Everything else goes to review: tags always, measurements that could be several specs, types that
differ from the hints, anything the model added on its own. With `enrichment.auto_approve` off for
a shop, nothing is approved without a person.

## Reusing answers

A request ID is `<task>-<subject>-<hash of what was asked>`. The same product text, vocabulary
and prompt give the same ID in any environment. Answers made for one batch can be uploaded to
another batch with the same requests; only matching IDs are read.

## Changing a prompt

Prompts live in `apps/api/app/Modules/Enrichment/Prompts/<task>.v<version>.md`. Never edit a
released version. Add `.v2.md`, raise the version in `PromptLibrary::CURRENT`, and run the pilot
branch again. Every batch stores the exact instructions it sent.
