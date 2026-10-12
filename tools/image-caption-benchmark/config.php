<?php

/*
 * The benchmark's settings. Prices are USD per million tokens (per picture for picture
 * embeddings): check them on each provider's price page before trusting the totals.
 */
return [
    // A Shopify store whose public products.json and collections.json are read.
    'shop' => 'https://rocksandgold.co.il',
    'currency' => 'ILS',

    // How many products, spread evenly over the store's first 500.
    'limit' => 30,

    // Production's picture limit (retrieval.image_max_kb). The answer is a full meta reading, and
    // Haiku 5.5 thinks before it answers (its thinking counts as output), so the ceiling is roomy;
    // only the tokens a model uses are paid for.
    'image_max_kb' => 4096,
    'max_output_tokens' => 4000,

    // Each flow builds its own database. A flow is its models in order: the first, then the one
    // asked when the first fails on a picture.
    'flows' => [
        'gemini' => [
            ['provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'input' => 0.30, 'output' => 2.50],
            ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'input' => 0.10, 'output' => 0.50],
        ],
        'haiku' => [
            ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'input' => 0.10, 'output' => 0.50],
            ['provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'input' => 0.30, 'output' => 2.50],
        ],
    ],

    // Vectors, the same for every flow so only the readings differ. gemini-embedding-2 puts words
    // and pictures in one space (production's picture model and price).
    'embedding' => [
        'provider' => 'gemini',
        'model' => 'gemini-embedding-2',
        'dimensions' => 768,
        'text_usd_per_million' => 0.20,
        'usd_per_picture' => 0.00012,
    ],

    // Keys come from the environment only, never from the panel's stored keys.
    'keys' => [
        'anthropic' => 'ANTHROPIC_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
    ],

    // How many products the report shows whole, side by side.
    'examples' => 6,
];
