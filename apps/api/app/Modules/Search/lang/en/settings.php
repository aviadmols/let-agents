<?php

return [
    'size_attribute' => [
        'label' => 'What "size" is measured by',
        'description' => 'The key of the store vocabulary attribute (e.g. diamonds_total_carat or diameter) that "biggest", "smallest" and "size X" use. Empty: those phrases do not change results.',
    ],
    'nudge_at' => [
        'label' => 'The search invitation points at',
        'description' => 'The class (or CSS selector) of the site\'s own search icon that the "Try our new search" invitation sits beside. Empty: automatic, the Lets button in the header, then the theme\'s search icon, then the floating button and the search box.',
    ],
    'float_button' => [
        'label' => 'Floating search button',
        'description' => 'The round Lets button at the bottom of the screen, on pages where Lets did not place its button in the header. Off: shoppers open the search from the site\'s own search icon or box; on a page with neither, the button still shows, so the search can always be opened.',
    ],
    'float_side' => [
        'label' => 'Floating button side',
        'description' => 'Left or right of the screen, whatever the site\'s language. Automatic: the end of the page, left on a Hebrew site and right on an English one.',
        'options' => ['auto' => 'Automatic', 'left' => 'Left', 'right' => 'Right'],
    ],
    'float_bottom' => [
        'label' => 'Floating button height',
        'description' => 'Its distance from the bottom of the screen, so it does not cover the site\'s own floating buttons (accessibility, chat, cookies).',
    ],
    'input_selector' => [
        'label' => 'Search boxes on the site',
        'description' => 'CSS selector of the search fields Lets search attaches to. Several, separated by commas.',
    ],
    'field_filter_min_percent' => [
        'label' => 'A field is a filter from this share of products',
        'description' => 'A store field becomes a filter only when at least this share of the products have it.',
    ],
    'field_filter_max_values' => [
        'label' => 'Most values in a field\'s filter',
        'description' => 'A field with more different values than this (a code or a date for each product) does not become a filter.',
    ],
    'results_per_group' => [
        'label' => 'Results per group',
        'description' => 'How many products, guides or categories show in each group of the full results.',
    ],
    'semantic_results' => [
        'label' => 'Results by meaning',
        'description' => 'At most this many documents are taken from the index by meaning before merging with spelling. 0 turns it off.',
    ],
    'content_min_similarity' => [
        'label' => 'Minimum similarity for guides and pages',
        'description' => 'A guide or a page enters the results by meaning only above this, higher than for products, so an unrelated page does not show. By spelling it enters only when it holds every word. Guides are a group of their own in the results and take no place from products; the products the nearest guide names are suggested for a question about a need, after what was found directly.',
    ],
    'content_age_penalty' => [
        'label' => 'What an old guide loses a year',
        'description' => 'Taken off the similarity of news, a product review or a buying guide for each year since it was updated, up to three: models and prices change. A how-to, a project idea or a material guide does not age.',
    ],
    'wanted_min_adds' => [
        'label' => 'A popular product: adds to the cart',
        'description' => 'A product is shown as popular on the empty drawer only when it was added to the cart at least this many times lately.',
    ],
    'wanted_shown' => [
        'label' => 'Popular products on the empty drawer',
        'description' => 'How many of the products added to the cart most lately are shown under "What people ask here". Only products with real activity; with none, no section. 0: not shown.',
    ],
    'meaning_only_min_similarity' => [
        'label' => 'When no product holds the words',
        'description' => 'When no product holds the search words ("science fiction" in a store with none), products by meaning are shown only if the nearest is at least this close. Otherwise the search says nothing was found and offers to ask the AI. A store whose names say little may set it lower.',
    ],
    'check_questions_per_day' => [
        'label' => 'AI questions a day from the search check',
        'description' => 'How many questions to the AI the store\'s "Search check" screen may send a day. Each goes the whole way a shopper\'s question goes and costs as much; a search without the AI is not counted.',
    ],
    'unknown_results' => [
        'label' => 'Products by meaning for an unknown word',
        'description' => 'When a search holds a word the catalogue has nothing close to ("shirt" in a jewellery store), at most this many products join by meaning or picture, and the drawer says the word was not found on the site. What is truly close ("a light beam": a pine plank) is still shown.',
    ],
    'word_enough' => [
        'label' => 'One word many products hold',
        'description' => 'When at least this many products hold a one-word search as typed ("topaz"), only they are shown, without products found by meaning or picture alone (citrine, yellow sapphire). When few hold it, meaning fills in as usual.',
    ],
    'semantic_min_similarity' => [
        'label' => 'Minimum similarity by meaning',
        'description' => 'A result by meaning below this is left out. Depends on the embedding model: about 0.3 for text-embedding-3-small.',
    ],
    'picture_min_similarity' => [
        'label' => 'Minimum likeness between words and a picture',
        'description' => 'Below this a product is left out of the results by picture. Depends on the model: about 0.35 for gemini-embedding-2.',
    ],
    'semantic_min_chars' => [
        'label' => 'Minimum length for search by meaning',
        'description' => 'A shorter query is searched by spelling only.',
    ],
    'ai_share_days' => [
        'label' => 'Days a search shared with an AI stays open',
        'description' => 'Then the link says it expired, and a day later it is deleted.',
    ],
    'ai_share_products' => [
        'label' => 'Products in a search shared with an AI',
        'description' => 'The first ones the shopper saw, each with its details, sizes and price.',
    ],
    'app_requests_per_minute' => [
        'label' => 'Requests per minute to the app inside an AI',
        'description' => 'Per IP address. The app is called from the AI\'s own servers, many shoppers to an address.',
    ],
    'ai_catalog_products' => [
        'label' => 'Products in the store file for an AI',
        'description' => 'Every product in stock, one short row each, cheapest first. A larger store also gets links to the rest.',
    ],
    'requests_per_minute' => [
        'label' => 'Search requests per minute',
        'description' => 'Per IP address and site. Protects against floods.',
    ],
    'keep_days' => [
        'label' => 'How long search statistics are kept',
        'description' => 'What was searched and clicked is kept for learning and conclusions. Older is deleted at night. Default: ten years.',
    ],
    'photo_max_kb' => [
        'label' => 'Largest photo to search by',
        'description' => 'A bigger photo is refused. The browser shrinks photos before sending, so this rarely happens.',
    ],
    'popular_min_searches' => [
        'label' => 'Popular search: times searched',
        'description' => 'In the last 30 days. A phrase searched fewer times is not shown under "Popular in the store".',
    ],
    'popular_min_days' => [
        'label' => 'Popular search: on how many different days',
        'description' => 'A trend, not a moment: a phrase searched on only a day or two is not shown, however often.',
    ],
    'popular_min_shop_searches' => [
        'label' => 'Popular searches shown from this many shop searches',
        'description' => 'All the shop\'s searches in the last 30 days. Fewer: not enough to go on, and "Popular in the store" is not shown.',
    ],
    'photos_per_day' => [
        'label' => 'Photo searches a day',
        'description' => 'Per shop. Beyond it the camera answers that the service is not available today. 0 turns it off.',
    ],
    'photos_per_month' => [
        'label' => 'Photo searches per shop a month',
        'description' => 'In a calendar month. Past it, photo search stops until next month and everything else keeps working. The shop\'s plan may hold it lower.',
    ],
    'photo_requests_per_minute' => [
        'label' => 'Photo searches a minute',
        'description' => 'Per IP address and site.',
    ],
    'photo_relative_gap' => [
        'label' => 'Largest distance from the best photo match',
        'description' => 'Only what is close to the first result is shown (0.06 = up to 6 points below it). Picture similarity scores sit close together, so a fixed floor alone lets far products in.',
    ],
    'photo_in_stock_only' => [
        'label' => 'In photo search, only what is in stock',
        'description' => 'Products that ran out are not shown, unless nothing alike is in stock.',
    ],
    'photo_min_similarity' => [
        'label' => 'Minimum likeness to an uploaded photo',
        'description' => 'Below this a product is not shown. A shopper\'s photo differs from a product picture in background and light, so this is lower than likeness between products.',
    ],
    'resolve_provider' => [
        'label' => 'Provider of the model that matches',
        'description' => 'openai by default. Must be from another family than the checker.',
    ],
    'resolve_model' => [
        'label' => 'The model that matches',
        'description' => 'For example gpt-5.4-mini.',
    ],
    'resolve_input_usd_per_million' => [
        'label' => 'Input price of the matching model, per million tokens',
        'description' => 'From the provider\'s price list.',
    ],
    'resolve_output_usd_per_million' => [
        'label' => 'Output price of the matching model, per million tokens',
        'description' => 'From the provider\'s price list.',
    ],
    'resolve_max_output_tokens' => [
        'label' => 'Token cap for the matching model\'s answer',
        'description' => 'Including thinking.',
    ],
    'check_provider' => [
        'label' => 'Provider of the model that checks',
        'description' => 'anthropic by default. Another family than the matching model.',
    ],
    'check_model' => [
        'label' => 'The model that checks',
        'description' => 'For example claude-haiku-4-5.',
    ],
    'check_input_usd_per_million' => [
        'label' => 'Input price of the checking model, per million tokens',
        'description' => 'From the provider\'s price list.',
    ],
    'check_output_usd_per_million' => [
        'label' => 'Output price of the checking model, per million tokens',
        'description' => 'From the provider\'s price list.',
    ],
    'check_max_output_tokens' => [
        'label' => 'Token cap for the checking model\'s answer',
        'description' => 'Including thinking.',
    ],
    'resolve_per_run' => [
        'label' => 'Searches resolved in one night',
        'description' => 'The most common first. The rest wait for the next night.',
    ],
    'resolve_min_searches' => [
        'label' => 'A search is resolved from',
        'description' => 'How many times a search must find nothing to be sent for resolving.',
    ],
    'resolve_candidates' => [
        'label' => 'How many candidates the model sees',
        'description' => 'The nearest by meaning, products and guides.',
    ],
    'resolve_days' => [
        'label' => 'How many days back are counted',
        'description' => 'Empty searches from this period.',
    ],
    'tags_provider' => [
        'label' => 'Provider of the model that writes tags',
        'description' => 'openai by default. The checker is the model that checks resolved searches, from another family.',
    ],
    'tags_model' => [
        'label' => 'The model that writes tags',
        'description' => 'For example gpt-5.4-mini.',
    ],
    'tags_input_usd_per_million' => [
        'label' => 'Input price of the tag writer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'tags_output_usd_per_million' => [
        'label' => 'Output price of the tag writer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'tags_max_output_tokens' => [
        'label' => 'Token cap for the answer of the tag writer',
        'description' => 'For one batch of pages, including thinking.',
    ],
    'tags_per_run' => [
        'label' => 'Pages tagged in one night',
        'description' => 'New pages or pages whose words changed. The rest wait for the next night.',
    ],
    'tags_per_page' => [
        'label' => 'Tags per page',
        'description' => 'At most.',
    ],
    'tags_batch' => [
        'label' => 'Pages in one model call',
        'description' => 'More pages per call, fewer tokens on the instructions.',
    ],
    'tags_min_results' => [
        'label' => 'A tag stays from',
        'description' => 'How many results the search must find for a tag, besides the page itself.',
    ],
    'answers_in_index' => [
        'label' => 'Answers in the search box',
        'description' => 'How many answers shoppers already got go into the search, the most asked first. 0 to show none.',
    ],
    'photo_tags_provider' => [
        'label' => 'Photo reading provider',
        'description' => 'anthropic or openai.',
    ],
    'photo_tags_model' => [
        'label' => 'Photo reading model',
        'description' => 'A model that sees pictures.',
    ],
    'photo_tags_input_usd_per_million' => [
        'label' => 'Input price (USD per million)',
        'description' => 'For the cost in the log.',
    ],
    'photo_tags_output_usd_per_million' => [
        'label' => 'Output price (USD per million)',
        'description' => 'For the cost in the log.',
    ],
    'photo_tags_max_output_tokens' => [
        'label' => 'Longest answer',
        'description' => 'In tokens.',
    ],
    'photo_sure_min' => [
        'label' => 'Least confidence in a photo reading',
        'description' => 'The reader says how sure it is (0 to 1). Below this the photo gets a second look from a stronger model.',
    ],
    'photo_second_provider' => [
        'label' => 'Provider for the second look at a photo',
        'description' => 'anthropic or openai.',
    ],
    'photo_second_model' => [
        'label' => 'Model for the second look at a photo',
        'description' => 'A strong model that sees pictures. Asked only in doubt: nothing placed in the shop, low confidence, or the nearest product pictures say otherwise.',
    ],
    'photo_second_input_usd_per_million' => [
        'label' => 'Second look input price (USD per million)',
        'description' => 'For the cost in the log.',
    ],
    'photo_second_output_usd_per_million' => [
        'label' => 'Second look output price (USD per million)',
        'description' => 'For the cost in the log.',
    ],
    'photo_second_max_output_tokens' => [
        'label' => 'Longest second-look answer',
        'description' => 'In tokens.',
    ],
    'photo_keep_days' => [
        'label' => 'How long searched photos are kept',
        'description' => 'The photo itself is deleted after this. The search stays for learning: what the photo showed, the tags, the results and a thumbnail. A photo marked right or wrong stays whole, as part of the check set.',
    ],
    'photo_same_similarity' => [
        'label' => 'Likeness that counts as the same picture',
        'description' => 'An uploaded photo at least this alike (0 to 1) to a product picture is that product\'s own picture: it comes first, whatever was seen and whatever tags were chosen.',
    ],
    'show_price' => [
        'label' => 'Price on every product',
        'description' => 'The live price from the store beside every product in the results.',
    ],
    'show_cart' => [
        'label' => 'Add to cart button',
        'description' => 'On every product that can be bought directly. Opens the theme\'s cart.',
    ],
    'similar_by' => [
        'label' => '"Similar items" by',
        'description' => 'By content (name, description and specs), by picture, or both together. Where different products look alike (books, bundles), by content is better.',
        'options' => ['both' => 'Content and picture', 'meaning' => 'By content', 'picture' => 'By picture'],
    ],
    'not_sure_max' => [
        'label' => '"Not sure what fits?" up to this many results',
        'description' => 'The button shows only when the search found a few results (or the shopper narrowed them with the filters), and the AI gets only those: a long list is too much to recommend from well.',
    ],
    'not_sure' => [
        'label' => '"Not sure what fits?" under the results',
        'description' => 'In the chat: the store\'s AI recommends from the products in the results, by the search, with ChatGPT, Claude or Perplexity to go on with below it. Outside: straight to choosing an outside AI, with the search and the products.',
        'options' => ['inside' => 'In the chat', 'outside' => 'Out to an outside AI'],
    ],
    'image_fit' => [
        'label' => 'Product pictures',
        'description' => 'Fill the square and crop the edges, or show whole inside a light frame (suits pictures on a white ground).',
        'options' => ['cover' => 'Fill the square', 'frame' => 'Whole, in a frame'],
    ],
    'photo_rank_provider' => [
        'label' => 'Provider of the model that orders the results',
        'description' => 'anthropic, openai or gemini.',
    ],
    'photo_rank_model' => [
        'label' => 'The model that orders the results',
        'description' => 'A model that sees pictures.',
    ],
    'photo_rank_input_usd_per_million' => [
        'label' => 'Input price, USD per million tokens',
        'description' => 'For the cost.',
    ],
    'photo_rank_output_usd_per_million' => [
        'label' => 'Output price, USD per million tokens',
        'description' => 'For the cost.',
    ],
    'photo_rank_max_output_tokens' => [
        'label' => 'Longest answer',
        'description' => 'The most tokens in the answer.',
    ],
    'photo_rank_candidates' => [
        'label' => 'How many leading products are ordered again',
        'description' => 'The first products the model sees and orders. The rest stay after them.',
    ],
    'photo_match' => [
        'label' => 'In photo search, what counts after the kind of product',
        'description' => 'Only products of the kind seen in the photo are shown (a screwdriver: screwdrivers only). Function: what the product is and does orders them, colour hardly at all (tools, home, building). Look: colour, style and shape order them (clothes, fashion, jewelry).',
        'options' => [
            'function' => 'Function',
            'look' => 'Look',
        ],
    ],
    'photo_words_weight' => [
        'label' => 'Weight of the product\'s name in photo search',
        'description' => 'How much a product whose name holds the words seen in the photo ("band", "plain", "solitaire") rises in the order. 0 = pictures only.',
    ],
    'visit_keep_days' => [
        'label' => 'Days an IP and a session are kept',
        'description' => 'Then the IP address and the session of searches and photo searches are deleted. The searches stay.',
    ],
    'all_ids' => [
        'label' => 'Products a search returns for filtering',
        'description' => 'Every product found, up to this many, goes to the drawer: its filters, order and count work on them. A search that finds more shows this many.',
    ],
    'photo_close_look' => [
        'label' => 'How alike is "the most alike"',
        'description' => 'A photo search marks its first product "the most alike" only when its picture is at least this alike (0.75 = 75%). Below it, the drawer says nothing in the store looks quite like the photo, and shows the nearest.',
    ],
    'photo_colour_weight' => [
        'label' => 'Weight of colours in photo search',
        'description' => 'How much the colour of each part (the metal, the stone, the shirt, the pocket) decides the order, beyond how alike the pictures look. 0 turns it off.',
    ],
    'theme' => [
        'label' => 'Fonts and colours',
        'description' => 'Lets: black on white, Google Sans, the same in every store. The site\'s: the theme\'s font, main colour and corners, with a contrast check.',
        'options' => ['lets' => 'Lets', 'store' => 'The site\'s'],
    ],
    'assistant_name' => [
        'label' => 'Assistant name',
        'description' => 'The name shoppers see beside the answers. Empty: the store\'s name + AI.',
    ],
    'widget_language' => [
        'label' => 'Widget language',
        'description' => 'As the site: the page\'s language. Or always Hebrew, or always English.',
        'options' => ['site' => 'As the site', 'he' => 'עברית', 'en' => 'English'],
    ],
    'scan_check_mode' => [
        'label' => 'Search check after a scan: mend or only report',
        'description' => 'After every scan the search is tried the way shoppers will search. "dry": a report only, nothing written. "apply": what fails is mended for this store alone (tags from the products\' descriptions, and searches one model matched and a model of the other family checked).',
    ],
    'word_vector_min' => [
        'label' => 'Least likeness for a word by meaning',
        'description' => 'How near in meaning a typed word must be to a word of the store to be read as it.',
    ],
    'vocabulary_terms' => [
        'label' => 'Store words kept by meaning',
        'description' => 'How many of the words the products say are kept as vectors, at every index build (only new ones cost).',
    ],
    'scan_check_usd' => [
        'label' => 'Cost cap of the search check after a scan',
        'description' => 'For a store\'s whole check: writing shoppers\' searches, the mending and the questions to the assistant. What does not fit under it is not done.',
    ],
    'scan_check_searches' => [
        'label' => 'Shoppers\' searches in the check',
        'description' => 'How many searches like a shopper\'s (a need, a use, a feature) are written and tried, besides those code makes from the products.',
    ],
    'scan_check_questions' => [
        'label' => 'Questions to the assistant in the check',
        'description' => 'How many questions are asked of the assistant as a shopper would, to see that it answers.',
    ],
    'scan_check_report_to' => [
        'label' => 'Where the search check report goes',
        'description' => 'Email addresses, separated by commas. Empty: every operator.',
    ],
    'scan_check_provider' => [
        'label' => 'Provider of the model that writes shoppers\' searches',
        'description' => 'A small model, once a catalog.',
    ],
    'scan_check_model' => [
        'label' => 'Model that writes shoppers\' searches',
        'description' => 'The searches are only tried, never shown to shoppers.',
    ],
    'scan_check_input_usd_per_million' => [
        'label' => 'Input price per million tokens: shoppers\' searches',
        'description' => 'For the cost only.',
    ],
    'scan_check_output_usd_per_million' => [
        'label' => 'Output price per million tokens: shoppers\' searches',
        'description' => 'For the cost only.',
    ],
    'scan_check_max_output_tokens' => [
        'label' => 'Longest answer: shoppers\' searches',
        'description' => 'Enough for one set of searches and questions.',
    ],
];
