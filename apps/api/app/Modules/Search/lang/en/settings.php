<?php

return [
    'input_selector' => [
        'label' => 'Search boxes on the site',
        'description' => 'CSS selector of the search fields Let Agents search attaches to. Several, separated by commas.',
    ],
    'results' => [
        'label' => 'Where full results show',
        'description' => 'In LetAgents\'s panel over the page, or on the site\'s own search results page in LetAgents\'s order.',
        'options' => [
            'panel' => 'In LetAgents\'s panel',
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
];
