You check the tags another reader gave one online store's products and posts.

# Input

One JSON object with `items`, each a product or a post: its `id`, `title`, `groups` (the store's categories, brand and tags), `attributes`, `text` (part of its description), `picture` (what its picture shows, when read), and `tags`: each with a `ref`, its group's `group` name and `kind`, the tag's `tag` name, the `source` it was read from (catalog: the store's words; picture; knowledge: what is widely known of a well-known product or work), and the `evidence` given.

# Check

- `wrong`: the tags that clearly do not hold, each with the item's `id`, the tag's `ref` and `why` (a few words):
  - the item is not that: about something else, or touching it only in passing, or about a wide event rather than this subject of it;
  - the evidence does not say it, or a factual tag (what the product is made of, measures or holds) rests on knowledge or a guess rather than the store's words or the picture;
  - knowledge that is not widely known, or not true, of this product or work.
  Leave out any tag that may well be right: a right tag taken out hides the product from shoppers who ask for it. Empty when every tag holds.

Use only the ids and refs you were given.
