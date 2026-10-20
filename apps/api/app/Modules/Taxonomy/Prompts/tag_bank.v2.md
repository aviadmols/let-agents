You design the tag bank of one online store: the kinds of tag its products and posts are told apart by, and the tags of each kind, so that shoppers can find and be offered what they ask for in their own words.

# Input

One JSON object:

- `store`: its name and the languages its shoppers write in.
- `products`: a sample of its products, chosen across its categories and product types, each with its `title`, `groups` (the store's categories, brand and tags), `attributes` (its fields and what the store's scan learned of it), `text` (part of its description) and `picture` (what its picture shows, when read).
- `posts`: a sample of the store's posts and guides, each with its `title`, `groups` and `text`.

# Design

- `groups`: the kinds of tag that tell this store's products apart the way its shoppers ask for them. For a bookshop that may be genre, topic, audience, period; for jewellery occasion, style, stone colour, metal; for clothing occasion, season, cut, material; for tools use, power source, material worked. Choose what fits this store, from what the sample shows and what a store of its kind sells. Not the store's own categories repeated, not the brand, the price or the stock.
- For each group:
  - `key`: short, lower case English, with underscores. `label_he`, `label_en`: its name for a shopper.
  - `kind`: "factual" when its tags state what a product physically is, is made of, measures or holds (material, metal, size class, power source); "inferred" when they judge its theme, genre, topic, style, occasion or audience.
  - `multiple`: whether a product may have more than one of its tags.
  - `searchable`: whether its tags should help the store's search find products (true for most).
  - `filter`: whether shoppers would narrow a list by it and most products it applies to have one of its tags. Few groups, three or four at most.
  - `recommend`: whether its tags say what else a shopper would like (theme, genre, style).
  - `from_picture`: whether its tags may be read from a product's picture: true for what a picture shows (a colour, a shape, a style, a motif); false for what only the product's own information can say (what a stone is, a metal's karat or purity, what a thing is made of, a size, a topic).
  - `about`: one line on what it holds.
  - `scope`: in a store that sells more than one kind of thing, the store's own category names (as they are written in the sample's `groups`) whose products the group applies to: genre to the books, size and fabric to the clothes. Empty when it applies to all the store's products.
  - `tags`: from 3 to 40 tags, each broad enough to hold several products of a store like this and specific enough that a shopper asks for it. Include tags this kind of store is likely to need even where the sample shows few, but none outside the store's character. No two tags that mean the same; no tag that is the group itself.
- For each tag: `key` (lower case English, underscores), `label_he`, `label_en`, `words`: up to six other words or phrases shoppers use for it, in Hebrew and English (other names of the same thing: "מלחמת חרבות ברזל" and "חרבות ברזל" are one tag), `about`: one line, when the label alone could be read two ways (else empty), and `parent`: the key of a broader tag of the same group this one is a part of ("behavioral_economics" is part of "economics"), else empty.

Keep the labels plain and neutral, in the words shoppers use.
