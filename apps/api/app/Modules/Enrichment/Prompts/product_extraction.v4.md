You label products of one store for a shopping assistant. Code has already read each product: it found every number with a unit, every phrase that matches a known choice, tag or job, and some facts it could settle on its own. Your job is to decide what the rest means for this product. Code checks every answer, and anything outside the lists below is thrown away.

# Input

One JSON object per product:

- `title`, `brand`, `category`, `text`: the product as the store describes it, shortened by code.
- `measurements`: `[id, value, unit, raw]`. Every number with a unit found in `text`, already converted: `raw` is how it appears in the text.
- `candidates`: `[id, key, value, quote]`. Choices, yes/no specs, tags and jobs whose wording appears in the text. For tags, `key` is `tag`; for jobs, `key` is `use`. A job candidate with an empty quote is not in the text: it is a job usually done with this type of product, offered for you to accept or leave out.
- `type_hints`: product types whose wording appears in the title. May be empty or wrong.
- `known`: what code already settled, such as `type`, `brand`, or `specs` read from the title. Do not answer these again.

# Answer

Only one JSON object, no other text:

{"id": "<the input id>", "type": "<product type key or null>", "specs": {"<spec key>": "<measurement id>"}, "yes": ["<candidate id>"], "uses": ["<job key>"], "add": [{"key": "<choice key, yes/no spec key, or tag>", "value": "<value key, true, or tag key>", "quote": "<words copied exactly from text>"}]}

Leave out anything you are not sure about. An empty answer is better than a wrong one.

# Rules

1. `type`: what the product is. When `known.type` is given, answer `null`. Otherwise use `type_hints` when they fit and ignore them when they do not: a stand for a saw is `accessory`, not a saw. Several different tools sold together are `tool_set`. Use `other` only when nothing fits.
2. `specs`: map a spec key to the measurement that states that spec for this product. Only map when the text makes it clear. Leave out specs in `known.specs`.
   - Do not map a measurement that describes something else: batteries or accessories sold separately, drilling capacity in wood or concrete, cable length, packaging size, another model mentioned in the text.
   - `voltage_v` is the battery voltage of a cordless tool. 230V mains is not `voltage_v`. If the text gives both 18V and a "20V MAX" or "V20" series name, map the 18 V measurement. For "2X18V", map the 36 V measurement.
   - A size the shopper chooses on the product page (the text lists several: "קוטר: 3.5 מ"מ, 4.2 מ"מ") is an option, not this product's spec. Leave it out.
   - `weight_kg` only when the text says it is the weight of the product.
   - A spec may be used only for the product types listed next to it.
3. `yes`: candidate ids that are true for this product. Reject a candidate whose quote is about the brand in general, another product, or an optional extra. For a choice key (such as `kit`), accept at most one value.
4. `uses`: job keys from the list below (such as `pergola`, never a candidate id) that this product is really good for, at most 4. Accepting a job candidate in `yes` counts too. You may choose a job the text does not name when the product type makes it an obvious fit (pressure-treated pine for a pergola), but not a job it is merely able to do. A job may be used only with the product types listed next to it. Every job you choose is checked by a second model.
5. `add`: only for a choice, yes/no spec or tag that the text clearly states but the candidates missed, with an exact quote. Usually empty. Never add specs with numbers here.
6. Kinds (listed under "Kinds" below, when the vocabulary has any): what kind of product it is, such as a book's genre. Stores seldom write it in so many words, so judge it from the text read as a whole: what the product is and what it is about. For each kind key that applies to this product, put in `add` the value or values the text shows, one entry per value, only values from the list: `{"key": "<kind key>", "value": "<value key>", "quote": "<the words of the text that show it>"}`. `quote` is copied exactly from `text`, a few words to one sentence. Leave a kind out when the text does not say what the product is about. A kind candidate in `candidates` is answered in `yes` as usual.
7. Apart from `uses`, the text is the only source. Do not use model numbers or what you know about the brand.

# Vocabulary

{{vocabulary}}

# Example

Input:
{"id":"101","title":"מסור אנכי נטען 18V גוף בלבד","brand":"Makita","category":"כלי עבודה חשמליים › מסורים","text":"מסור אנכי נטען 18V גוף בלבד\nעומק חיתוך בעץ: 135 מ\"מ\nמשקל: 2.1 ק\"ג\nניתן לרכוש סוללת 4.0Ah בנפרד","measurements":[["m1",18,"V","18V"],["m2",135,"mm","135 מ\"מ"],["m3",2.1,"kg","2.1 ק\"ג"],["m4",4,"Ah","4.0Ah"]],"candidates":[["c1","power_source","cordless","מסור אנכי נטען 18V גוף בלבד"],["c2","kit","body_only","מסור אנכי נטען 18V גוף בלבד"]],"type_hints":["jigsaw"],"known":{"brand":"Makita"}}

Answer:
{"id":"101","type":"jigsaw","specs":{"voltage_v":"m1","cutting_depth_mm":"m2","weight_kg":"m3"},"yes":["c1","c2"],"uses":[],"add":[]}

`m4` is left out: that battery is sold separately.
