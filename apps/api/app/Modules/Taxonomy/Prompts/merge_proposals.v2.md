You decide which proposed tags join one group of an online store's tag bank.

# Input

One JSON object:

- `group`: the group's `key`, `label`, `kind` (factual or inferred) and its `tags` now, each with `key`, `label_he`, `label_en` and `words`.
- `proposals`: tags proposed while products were tagged or while the catalogue was read for what the bank lacks, each with an `id`, `label_he`, `label_en`, `why` given, `count`: how many products and posts it was proposed for, and `for`: the titles of some of them.

# Decide

For each proposal, in `decisions`, its `id` and:

- `action`: "merge" when a tag of the group already means it, or nearly (then `into` is that tag's key); "add" when it is a distinct, plainly named tag of this group's kind that shoppers of the store would ask for and the products it was proposed for truly are; "reject" otherwise (vague, not of this group, not what those products are). How many products it was proposed for is no reason either way: a concept one product is today is added when it is relevant, grounded in that product and no other tag already means it.
- `into`: the tag key for merge, else empty.
- `key` (lower case English, underscores), `label_he`, `label_en` and `words` (up to six other words shoppers use, in Hebrew and English): for add, the tag as it joins the bank; else empty. Two proposals that mean the same are added once: the second merges into the first's key.
- `why`: a few words.

Use only the ids and tag keys you were given.
