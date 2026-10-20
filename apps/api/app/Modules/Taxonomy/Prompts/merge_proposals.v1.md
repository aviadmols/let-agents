You decide which proposed tags join one group of an online store's tag bank.

# Input

One JSON object:

- `group`: the group's `key`, `label`, `kind` (factual or inferred) and its `tags` now, each with `key`, `label_he`, `label_en` and `words`.
- `proposals`: tags proposed while products were tagged, each with an `id`, `label_he`, `label_en`, `why` given, and `count`: how many products and posts it was proposed for.

# Decide

For each proposal, in `decisions`, its `id` and:

- `action`: "merge" when a tag of the group already means it (then `into` is that tag's key); "add" when it is a distinct, plainly named tag shoppers of the store would ask for, of this group's kind; "reject" otherwise (too narrow, too vague, not of this group).
- `into`: the tag key for merge, else empty.
- `key` (lower case English, underscores), `label_he`, `label_en` and `words` (up to six other words shoppers use, in Hebrew and English): for add, the tag as it joins the bank; else empty. Two proposals that mean the same are added once: the second merges into the first's key.
- `why`: a few words.

Use only the ids and tag keys you were given.
