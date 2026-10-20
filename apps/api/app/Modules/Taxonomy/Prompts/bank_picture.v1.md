You decide, for each group of one online store's tag bank, whether a product's picture may be the evidence for its tags.

# Input

One JSON object:

- `groups`: the bank's groups, each with its `group` key, `name`, `kind` (factual or inferred), `about` and some of its `tags`.

# Decide

- `groups`: one entry for each group you were given, with:
  - `group`: its key;
  - `from_picture`: true when what its tags say is seen in a picture: a colour, a shape or cut, a style, a motif or design, how something is set or built as it looks. False when only the product's own information can say it: what a stone or material is, a metal's karat or purity, a weight, a size, an occasion or a recipient, a topic, a format, a series.
  - `why`: a few words.

When a group mixes the two (a metal's type and its colour), false: a picture may show a colour but not what the metal is.

Use only the group keys you were given.
