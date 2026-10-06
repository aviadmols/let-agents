<?php

return [
    'title' => 'System map',
    'help' => 'How the shop\'s stores are built, how products are matched with each other, and how a product page is put together from them, stage by stage. Every number is counted from the tables when the page opens.',

    'tabs' => [
        'index' => 'Building the stores',
        'matching' => 'Matching',
        'page' => 'Retrieval and sorting on a product page',
    ],
    'tabs_help' => [
        'index' => 'Products, pages, posts and orders are cut into pieces and given vectors, so they can be found by meaning. Only text that changed is sent to the model again.',
        'matching' => 'Code finds candidates for each product, the model chooses complements and alternatives from them, code checks every choice, and what passes becomes a relation the page reads.',
        'page' => 'What the widget shows on one product\'s page, and how it changed at each stage: what was retrieved, what was dropped and what moved.',
    ],

    'index' => [
        'flow' => 'From the store to the index',
        'store' => 'In the store',
        'products' => 'Products',
        'pages' => 'Pages',
        'posts' => 'Posts and guides',
        'orders' => 'Orders: :live since install, :history from the past',
        'history_none' => 'Past orders have not arrived from the plugin yet (version 0.4.0 and up).',
        'history_running' => 'Past orders on their way: :received of :expected, from :oldest',
        'history_done' => 'Past orders arrived: :received of :expected, from :oldest',
        'history_refused' => ':n orders refused by the checks',
        'documents' => 'Documents',
        'source_line' => ':chunks pieces, about :average characters each',
        'pieces' => 'Pieces',
        'pieces_help' => 'Cut between paragraphs and sentences, with the title at the head of each piece.',
        'vectors' => 'Vectors',
        'pending' => ':n waiting for the next run',
        'ready' => 'Searchable by meaning',
        'ready_yes' => 'Ready',
        'ready_no' => 'Not yet',
        'ready_help' => 'Comparing vectors already stored costs nothing and calls no model.',
    ],

    'sources' => [
        'product' => 'Products',
        'content' => 'Pages and posts',
        'purchases' => 'What the orders say',
    ],

    'stopped' => [
        'unknown_provider' => 'The provider in the settings is unknown',
        'spend_cap' => 'Reached the monthly spending cap',
        'share_of_cap' => 'Reached matching\'s share of the cap',
        'no_key' => 'No connected key for the provider',
        'unsupported' => 'No driver for this provider',
        'provider_error' => 'The provider returned an error',
        'not_json' => 'The answer was not JSON',
        'off' => 'Off for this shop',
    ],

    'matching' => [
        'flow' => 'From evidence to relations on the page',
        'evidence' => 'Evidence',
        'evidence_orders' => 'Orders in the window',
        'evidence_vectors' => 'Products with a vector',
        'evidence_guides' => 'Guides naming two products or more',
        'candidates' => 'Candidate sources',
        'offered' => 'Offered to the model in the last run',
        'model' => 'The model chooses',
        'asked' => 'Products asked about in the last run. :unchanged unchanged, not asked again.',
        'check' => 'Code checks',
        'rejected' => 'Refused:',
        'relations' => 'Relations the page reads, by source',
        'no_relations' => 'No relations yet.',
        'coverage' => 'How much of the shop the model has seen',
    ],

    'candidate_sources' => [
        'bought_together' => 'Bought together',
        'similar' => 'Similar in meaning',
        'mentioned_together' => 'Named together in guides',
    ],

    'kinds' => [
        'complement' => 'Complement',
        'alternative' => 'Alternative',
        'family' => 'Other size',
    ],

    'because' => [
        'unknown' => 'A product code never offered',
        'both_kinds' => 'Already chosen as the other kind',
        'unavailable' => 'Out of stock',
        'not_similar' => 'Not similar enough to be an alternative',
        'over_limit' => 'Past the number per product',
    ],

    'relation_sources' => [
        'merchant' => 'The store\'s link',
        'merchant_reverse' => 'The store\'s link, the other way',
        'bought_together' => 'Bought together',
        'ai_match' => 'The model\'s choice',
        'category_affinity' => 'The store\'s habit',
        'family' => 'The same product in another size',
        'same_type' => 'The same type of product',
        'same_category' => 'The same category',
    ],

    'coverage' => [
        'answered' => ':n asked',
        'too_few' => ':n with fewer than two candidates',
        'failed' => ':n failed',
        'never' => ':n not asked yet',
    ],

    'request_status' => [
        'answered' => 'Answered',
        'too_few' => 'Fewer than two candidates',
        'failed' => 'Failed',
    ],

    'signals' => [
        'orders_together' => 'In :v orders together',
        'lift' => 'lift :v',
        'similarity' => 'similarity :v',
        'guides_together' => 'In :v guides together',
    ],

    'product' => [
        'search' => 'Find a product by name or ID',
        'none_found' => 'No product found.',
        'choose' => 'Choose a product to see its whole trail.',
        'chosen' => 'Product:',
        'trail' => 'The trail of :title',
        'ask_again' => 'Ask the model again',
        'not_asked' => 'The model has not been asked about this product yet.',
        'request' => ':status · :model · :at · $:cost',
        'invented' => ':n picks of products never offered were refused',
        'accepted' => 'Accepted',
        'relations' => 'What the page reads now',
        'columns' => [
            'product' => 'Candidate',
            'sources' => 'Found by',
            'evidence' => 'Evidence',
            'choice' => 'The model chose',
            'verdict' => 'Code decided',
            'why' => 'Why, says the model',
        ],
    ],

    'page' => [
        'flow' => 'Four stages, on every page load (no model)',
        'stages' => [
            'built' => ['title' => 'Retrieval', 'help' => 'From relations, facts and guides, with spare products.'],
            'allowed' => ['title' => 'What the shop allows', 'help' => 'A panel the shop switched off goes before anything else.'],
            'learned' => ['title' => 'What shoppers did', 'help' => 'What was clicked first, what was never clicked dropped, and the panels ordered by their scores.'],
            'curated' => ['title' => 'What the team decided', 'help' => 'Pinned goes first and is never dropped, hidden never shows. This is what a shopper sees.'],
        ],
        'disabled' => 'The widget is off on product pages for this shop.',
        'new' => 'New',
        'legend' => 'Green border: added at this stage. Red strike-through: dropped at this stage. Blue background: the model\'s choice. ▲▼ how many places a panel moved. Hover a product for its source and score.',
    ],

    'run' => [
        'title' => 'The last run',
        'never' => 'Has not run for this shop yet. It runs every night at 02:45, or with the command retrieval nightly.',
        'status' => [
            'succeeded' => 'Succeeded',
            'failed' => 'Failed',
            'running' => 'Running',
        ],
        'cost' => 'Cost $:cost',
        'took' => ':s seconds',
        'spend' => 'AI spending this month, all shops',
        'spent' => '$:spent of $:cap',
        'share' => 'Matching stops at :share% of the cap, so the shopper assistant keeps a budget.',
    ],
];
