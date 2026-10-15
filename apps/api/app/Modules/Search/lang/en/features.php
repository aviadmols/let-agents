<?php

return [
    'content_by_meaning' => [
        'label' => 'Guides and pages by meaning',
        'description' => 'A guide or a page shows in the results also when it is close in meaning, not only when it holds the words.',
    ],
    'results_page' => [
        'label' => 'The store\'s search page shows our results',
        'description' => 'When the store\'s search page opens (/search?q= on Shopify, ?s= on WordPress), our regular results open over it: with the tags, the phrases ("cheapest") and typo tolerance. Code only, no model.',
    ],
    'storefront' => [
        'label' => 'Let Agents search in the store\'s search box',
        'description' => 'Typo-tolerant suggestions while typing, and results grouped into products, guides and categories. The store also turns it on in the plugin.',
    ],
    'semantic' => [
        'label' => 'Search by meaning',
        'description' => 'Also finds what is not called by the typed words, like "something to join boards". Each new wording costs one small vector; a repeated one costs nothing.',
    ],
    'pictures' => [
        'label' => 'Search in pictures',
        'description' => 'Words like "striped shirt" also find products whose names do not say so, by their picture. Works only once the shop\\x27s pictures have vectors.',
    ],
    'photos' => [
        'label' => 'Search by photo',
        'description' => 'A camera button in the search box: a shopper uploads or takes a photo, and the store shows products that look alike. Shows only once the shop\'s pictures have vectors. Each photo costs one vector, and the photo is not kept.',
    ],
    'resolve_empty' => [
        'label' => 'Resolve searches that found nothing, with a model',
        'description' => 'At night, never live. A search that came back empty several times gets a vector and candidates from the catalogue; a model matches products and guides, and a model from another family checks. From the morning that search shows them. What was not found goes to the site suggestions.',
    ],
    'page_tags' => [
        'label' => 'Tag bank on pages',
        'description' => 'At night, for every product and guide: a model writes tags such as "materials for building a deck" or "deck screws", the search checks there is something to show, and a model from another family checks each tag against what it shows. Needed for the tag bank view of the on-page module.',
    ],
    'ai_share' => [
        'label' => 'Ask my own AI about this search',
        'description' => 'In the search results: a button that hands the search, the products with their details and the store\'s policies to the shopper\'s own ChatGPT, Claude or Perplexity, in a short link. The shopper takes advice there, and the purchase stays on the store. No model, no cost, and nothing about the shopper.',
    ],
    'agent_connector' => [
        'label' => 'The store as a connector for the shopper\'s AI (MCP)',
        'description' => 'An address anyone adds to their Claude or ChatGPT as a connector: the AI searches the store, reads product details and policies, and makes a link to the store\'s own cart. Buying and paying stay on the store. No model of ours, no cost.',
    ],
    'similar' => [
        'label' => 'Similar items in the results',
        'description' => 'A button on every product in the results. By picture where the pictures are scanned, and by the meaning of the product name and description otherwise. From vectors that already exist, with no model and no cost.',
    ],
    'mode_choice' => [
        'label' => 'Shoppers choose: regular or smart search',
        'description' => 'A switch in the search window. Regular: suggestions open under the site\'s search box. Smart: the side drawer, with a field for searches and questions. The choice is kept in the shopper\'s browser.',
    ],
    'photo_tags' => [
        'label' => 'Tags from a photo',
        'description' => 'In photo search, a model reads the photo and shows the shop\'s tags that fit it. A tag searches for itself.',
    ],
    'photo_limits' => [
        'label' => 'Limit photo searches',
        'description' => 'On: up to "photo searches per day" per shop and "per minute" per address. Off: no limit (the monthly spending cap still applies).',
    ],
    'photo_second_look' => [
        'label' => 'Second look at a photo',
        'description' => 'When the first reading of a photo leaves doubt, a stronger model looks again, with a short list of the categories that might fit. Only the doubtful photos cost more.',
    ],
    'photo_log' => [
        'label' => 'Photo search log',
        'description' => 'Keeps a reduced copy of every uploaded photo, what was seen in it and what was shown, on the "Photo searches" screen. The team marks right or wrong there, and that is the check set for photo search.',
    ],
    'photo_rank' => [
        'label' => 'Second ordering of photo search results',
        'description' => 'A model sees the shopper\'s photo beside the leading products\' pictures and orders them again: the truly closest first. One call per photo search, so it costs. Turn on only once the check shows it helps.',
    ],
    'size_alternatives' => [
        'label' => 'Size gone: similar items in that size',
        'description' => 'On a product page where a size is out of stock: the sizes that are gone are marked, and a tap opens similar items that have that size in stock. Stock is read from the store itself, live, with no model.',
    ],
];
