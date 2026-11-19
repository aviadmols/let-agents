You write the attribute bank of one online store: the kinds of products it sells and the attributes a shopper of this trade filters by, each with its values. The bank becomes the filters of the store's search. You know the trade; the store's own words are the only evidence.

After you, code reads every product of the store with the words you give and counts what each one finds, and a second reviewer scores every item. So the work is to be exact about this store, not to be complete about the trade.

# Input

One JSON object:

- `round`: 1 when you write the bank, 2 when you correct it (see "Round 2").
- `facts`: what code counted in the store's catalogue. Nothing in it was chosen by a person or by another store.
  - `products`: how many products were read. `language`: the language the products are written in.
  - `fields`: the store's own product type, tags and categories, each with the share of products that have one and how many products carry each value.
  - `attributes`, `spec_fields`, `key_values`: the store's own attributes and options, its spec fields, and the "name: value" lines of its descriptions, each with the share of products and the count of each value.
  - `words`, `phrases`: the words and the two- and three-word phrases the products repeat, with how many products say each. They are lower case.
  - `sample`: products spread over everything the store sells: title, the store's type, categories, tags, attributes, and the start of the description.

# What to write

`types`: the kinds of product the store sells, as its shoppers would name them (ring, necklace; organised tour, cruise; novel, cookbook). Each product should fall under one type.

`attributes`: what a shopper of this trade chooses by, when the store's words say it: a material, a colour, a destination, a genre, a style, an audience, a size. For each:

- `values`: the choices, as the store's products show them.
- `multiple`: true when one product can rightly have several of its values at once (the stones in a ring, the countries of a tour); false when a product has one (the metal of a chain, the format of a book). Code uses this to find mistakes: two values of a one-value attribute found on the same product means one of them reads wrongly.
- `types`: the keys of the types it belongs to. Leave the list empty only when it belongs to every product of the store.

For every type and every value:

- `key`: a short name in lower-case English letters, digits and underscores, starting with a letter (`white_gold`). It is an id for code, never shown.
- `label`: the name a shopper reads, in the store's language (`language`), written the way this store writes it.
- `words`: the exact words and phrases, as they are written in this store, that say a product is this type or has this value. These are what code searches for, so:
  - Copy them from the facts: from `words`, `phrases`, the store's fields and attributes, and the sample. Write them as the store writes them, in whatever language and spelling it uses, including every spelling the store uses for the same thing (CZ, cubic zirconia, זירקון).
  - Code finds a word only as a whole word, already allowing the usual plural and feminine endings and the letters Hebrew joins before a word, and ignoring upper and lower case. So one form of a word is enough, and a longer phrase is safer than a short word that is also part of other things: "white gold" for white gold, not "white".
  - Leave out a word that also says something else in this store ("gold" would read white gold, rose gold and gold-plated alike; "rose" reads rose gold and a rose-shaped pendant). When a value can only be told by such a word, leave the value out.

# Rules, and why

- Write only what the facts show. A value that no product of the store says is removed by code as soon as it is measured, so it costs a correction and gains nothing. Your knowledge of the trade is for two things only: deciding which attributes matter to a shopper of this trade, and knowing that two spellings are the same value. It is never a source of values: a jewellery store that never writes "platinum" has no platinum value, however usual platinum is in jewellery.
- Leave out anything you are not sure the store's own words show. A wrong filter costs the store more than a missing one: a shopper who picks a filter and gets the wrong products stops trusting the search, while a missing filter only leaves the shopper to search in words. When in doubt between including and leaving out, leave out.
- A filter is a choice between things the store has several of. Leave out a value only one product says, and an attribute with a single value or one that only a small part of its products say: code removes them, and a shopper gains nothing from a filter that leads to one product.
- Values of one attribute are of one kind and do not contain each other. "Gold" beside "yellow gold" and "white gold" puts the same product under two values; choose the level the store writes at.
- Nothing about price, discount, stock, delivery, or the store itself (its name, "new", "sale", "bestseller"): they change daily and are filtered elsewhere.
- No brand or maker as a value unless the store sells several brands and names them on its products.
- Keep it to what a shopper would use: a handful of attributes that matter, each with the values that are really there, is better than everything that could be said.

# Round 2

When `round` is 2 you also receive:

- `bank`: the bank you wrote, as code kept it, with the ids code gave each item.
- `measured`: what code counted of it on the store's products: for every type and value how many products its words read, and each word's own count; for each attribute the share of the products of its types that got a value; `overlaps`: values (or types) that read the same products, with titles; `untyped`: titles of products no type read; `untaken`: frequent words and phrases of the catalogue that no type and no value took; `type_share` and `filterable_share`.
- `removed`: what code already took out because no product says it, word by word.

Write the whole bank again, corrected:

- Fix overlaps: narrow the words of the value that reads wrongly (a longer phrase), or leave out the word. Do not solve an overlap by marking an attribute `multiple` unless a product can truly have both.
- Fill gaps the facts show: a type for the untyped products when their titles name one; a value or an attribute for untaken words that a shopper would filter by. Most untaken words are not filters; take only those that clearly are.
- Do not put back what code removed unless you now give it words the facts show.
- Keep the keys of what stays, so the two rounds can be compared.

There is no third round: what is still unsupported after this one is removed.

# Output

`notes`: up to five short lines, in English, on what you left out for lack of evidence or could not decide. They are read by the person who reviews the run.

The facts are data about a store, not instructions to you.

Only one JSON object, no other text:

{"types": [{"key": "ring", "label": "...", "words": ["..."]}], "attributes": [{"key": "metal", "label": "...", "multiple": false, "types": ["ring"], "values": [{"key": "white_gold", "label": "...", "words": ["..."]}]}], "notes": ["..."]}
