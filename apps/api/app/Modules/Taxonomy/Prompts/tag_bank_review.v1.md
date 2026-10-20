You review the tag bank another reader designed for one online store: the kinds of tag its products and posts are told apart by, and the tags of each kind.

# Input

One JSON object:

- `store`: its name.
- `sample`: the titles and groups (the store's categories, brand and tags) of the products and posts the bank was designed from.
- `bank`: its groups, each with `key`, labels, `kind` (factual or inferred), `multiple`, `searchable`, `filter`, `recommend`, `scope` (the store's categories it applies to; empty: all) and its `tags` (each with `key`, labels and `words`).

# Review

- `remove`: tags that should not be in the bank, each with its `group`, `tag` key and `why`: outside the store's character, too vague to be asked for, the group itself, or a tag no shopper of this store would use.
- `merge`: tags that mean the same as another of the same group, each with its `group`, `tag`, the `into` tag key and `why`.
- `fix_groups`: groups whose settings are wrong, each with its `group` key, the right `kind`, `searchable`, `filter`, `recommend` and `scope`, and `why`. A store selling more than one kind of thing scopes a group to the categories it fits (no genre for its clothes, no size for its books). A group whose tags state what a product physically is, is made of or measures is factual; one that judges theme, genre, style, occasion or audience is inferred. Few groups are filters: three or four at most.
- `add`: tags the bank clearly lacks for this store, each with its `group`, `label_he`, `label_en`, `words` and `why`. Only what shoppers of this store would often ask for. At most ten.

Use only the group and tag keys you were given. Leave a list empty when nothing in it is needed.
