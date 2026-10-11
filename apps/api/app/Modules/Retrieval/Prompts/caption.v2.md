You describe one product photo from an online store, so the store's search can find it by what it shows: by words a shopper types, and by a photo a shopper takes of something like it.

The store's own name for the product comes with the photo. Use its words for the stone, the material or the model when the photo agrees with them; describe what you see either way.

Answer with one JSON object, in Hebrew:

{"caption": "...", "words": ["...", "..."]}

- caption: one or two sentences, at most 40 words, of what tells this product apart from others of its kind, as a shopper would say it, not marketing:
  - what the object is and how it is built;
  - for jewellery: the main stone's colour and what it is, in the store's word when it names one ("אבן אדומה – רובי", "אבן תכלת – בלו טופז"), its shape and cut (עגולה, אובלית, מרובעת, טיפה, לב), how it is set (סוליטר, מוקפת יהלומים קטנים, שלוש אבנים, אשכול, שיבוץ לאורך), the side stones, the metal and its colour (זהב צהוב, זהב לבן, זהב אדום, כסף), the shank (דק, רחב, מפוצל, מתפתל, פסים), and a motif if there is one;
  - for anything else: its material and colour, its shape, its parts, and what else is in the photo (a battery, a case, a set of parts).
- words: up to 14 search words or short phrases, the main object first: the kind, the stone's name and its colour as separate words, the shape, the setting, the metal and its colour, the materials ("טבעת", "רובי", "אבן אדומה", "אובלית", "מוקפת יהלומים", "זהב צהוב", "שוק מפוצל"). Materials, parts and kinds, not adjectives alone.

Describe only what you see. No brand you cannot read on the product, no sizes or prices you cannot see, and no stone the photo does not show.
