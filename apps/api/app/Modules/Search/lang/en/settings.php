<?php

return [
    'input_selector' => [
        'label' => 'Search boxes on the site',
        'description' => 'CSS selector of the search fields Rega search attaches to. Several, separated by commas.',
    ],
    'results' => [
        'label' => 'Where full results show',
        'description' => 'In Rega\'s panel over the page, or on the site\'s own search results page in Rega\'s order.',
        'options' => [
            'panel' => 'In Rega\'s panel',
            'page' => 'On the site\'s search page',
        ],
    ],
    'suggestions' => [
        'label' => 'Products in suggestions',
        'description' => 'How many products show under the box while typing.',
    ],
    'results_per_group' => [
        'label' => 'Results per group',
        'description' => 'How many products, guides or categories show in each group of the full results.',
    ],
    'semantic_results' => [
        'label' => 'Results by meaning',
        'description' => 'At most this many documents are taken from the index by meaning before merging with spelling. 0 turns it off.',
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
    'requests_per_minute' => [
        'label' => 'Search requests per minute',
        'description' => 'Per IP address and site. Protects against floods.',
    ],
    'keep_days' => [
        'label' => 'How long search statistics are kept',
        'description' => 'Search and click counts older than this are deleted at night.',
    ],
    'photo_max_kb' => [
        'label' => 'Largest photo to search by',
        'description' => 'A bigger photo is refused. The browser shrinks photos before sending, so this rarely happens.',
    ],
    'photos_per_day' => [
        'label' => 'Photo searches a day',
        'description' => 'Per shop. Beyond it the camera answers that the service is not available today. 0 turns it off.',
    ],
    'photo_requests_per_minute' => [
        'label' => 'Photo searches a minute',
        'description' => 'Per IP address and site.',
    ],
    'photo_min_similarity' => [
        'label' => 'Minimum likeness to an uploaded photo',
        'description' => 'Below this a product is not shown. A shopper\'s photo differs from a product picture in background and light, so this is lower than likeness between products.',
    ],
];
