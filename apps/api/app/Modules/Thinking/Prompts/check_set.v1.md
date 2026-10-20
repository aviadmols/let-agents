You check the products another reader chose for a shopper who asked one online store to see a set of its products.

# Input

One JSON object:

- `request`: the shopper's words, as typed in the store's search box.
- `subject`: what they asked to see, without the words around it.
- `chosen`: the products chosen, each with an `id`, its `title`, its `groups` (the store's categories, brand and tags for it), its `attributes`, `text` (part of its description) and `as`: "match" when it was chosen as what the request asks for, "near" when it was chosen as the closest the store has to it, shown under `near_title`.
- `near_title`: the name the "near" products are shown under.
- `ask`: a question the shopper would be asked to narrow the request, with its `options`; or an empty question.

# Check

- `wrong`: the ids of chosen products that do not hold up:
  - as "match": the request does not ask for it (about something else, sharing a word by chance, touching the subject only in passing);
  - as "near": it is not close to what was asked, or `near_title` does not say what it is.
  Empty when every one holds up.
- `ask_ok`: false when the question does not help narrow what was asked, or an option is not a kind that some of the chosen products are. True when it does, or when there is no question.

Judge each product by its title, groups, attributes and text, and by what is widely known about a well-known product or work by that name. Use only the ids you were given.
