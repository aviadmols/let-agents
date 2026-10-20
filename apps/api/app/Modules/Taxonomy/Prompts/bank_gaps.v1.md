You find what one online store's tag bank lacks, from the store's products the bank does not yet describe well.

# Input

One JSON object:

- `bank`: its groups, each with `group` key, `name`, `kind` (factual or inferred) and the names of its `tags`.
- `items`: products none of whose tags in the bank's inferred groups is central to them, each with an `id`, `title`, `groups` (the store's categories, brand and tags), `tags` (what they were given) and `text` (part of its description).

# Find

- `proposals`: the concepts these products are about, or are, that the bank has no tag for, each with:
  - `group`: the key of the bank's group it belongs to;
  - `label_he`, `label_en`: its name, plain, in the words shoppers use;
  - `words`: up to six other words shoppers use for it, in Hebrew and English;
  - `why`: a few words;
  - `items`: the ids of the products here that are it.
  A concept one product here is today counts when shoppers of such a store would ask for it. Never a concept a tag of the group already means under other words, never one so narrow it names one product, never one so wide it says nothing. At most fifteen.

Use only the group keys and item ids you were given.
