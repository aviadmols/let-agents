<?php

return [
    'embedding_provider' => [
        'label' => 'Vector model provider',
        'description' => 'openai today. A new provider is a driver in the AI module and its name here.',
    ],
    'embedding_model' => [
        'label' => 'Vector model',
        'description' => 'For example text-embedding-3-small. Changing the model rebuilds the whole index, because vectors of different models cannot be compared.',
    ],
    'embedding_dimensions' => [
        'label' => 'Dimensions',
        'description' => '0 keeps the model\'s default. Models that support it can return a shorter vector, cheaper to store.',
    ],
    'embedding_usd_per_million' => [
        'label' => 'Vector model price, per million tokens',
        'description' => 'From the provider\'s price list. Used to estimate before each call and to record the cost.',
    ],
    'chunk_chars' => [
        'label' => 'Piece length',
        'description' => 'At most this many characters in one piece, the title at its head included. Changing it cuts everything again.',
    ],
    'max_chunks_per_run' => [
        'label' => 'New pieces per run',
        'description' => 'At most this many pieces are sent to the model in one run. The rest wait for the next run.',
    ],
    'purchase_window_days' => [
        'label' => 'How far back orders count',
        'description' => 'For matching and for the purchase documents. Includes the past orders the plugin sent.',
    ],
    'match_provider' => [
        'label' => 'Matching model provider',
        'description' => 'openai or anthropic, by the drivers in the AI module and the key saved in the panel.',
    ],
    'match_model' => [
        'label' => 'Matching model',
        'description' => 'The provider\'s model ID, for example gpt-5.4-mini.',
    ],
    'match_reasoning_effort' => [
        'label' => 'How much the model thinks before choosing',
        'description' => 'More thinking makes more careful choices, but slower and dearer ones. Not every provider supports it.',
        'options' => [
            'model_default' => 'The model\'s default',
            'minimal' => 'Minimal',
            'low' => 'Low',
            'medium' => 'Medium',
        ],
    ],
    'match_input_usd_per_million' => [
        'label' => 'Matching model input price, per million tokens',
        'description' => 'From the provider\'s price list. Better to estimate high.',
    ],
    'match_output_usd_per_million' => [
        'label' => 'Matching model output price, per million tokens',
        'description' => 'From the provider\'s price list. Reasoning tokens count as output.',
    ],
    'match_max_output_tokens' => [
        'label' => 'Token cap per answer',
        'description' => 'Reasoning tokens included. Too low can leave the answer empty.',
    ],
    'match_products_per_run' => [
        'label' => 'Products per matching run',
        'description' => 'Best sellers first. A product unchanged since last time is not asked again and does not count.',
    ],
    'match_candidates' => [
        'label' => 'How many candidates the model sees',
        'description' => 'From all sources together, in turns, so no source crowds out the others.',
    ],
    'match_max_share_of_cap' => [
        'label' => 'Share of the monthly AI cap for matching',
        'description' => 'Matching stops when the month\'s spending reaches this share of the cap, so the shopper assistant always has budget left. 0.5 is half.',
    ],
    'complements_per_product' => [
        'label' => 'Complements per product',
        'description' => 'At most this many complement choices are accepted for a product.',
    ],
    'alternatives_per_product' => [
        'label' => 'Alternatives per product',
        'description' => 'At most this many alternative choices are accepted for a product. 0 turns model alternatives off.',
    ],
    'min_alternative_similarity' => [
        'label' => 'Least similarity for an alternative',
        'description' => 'An alternative the model chose is refused when its text is too far in meaning, from 0 to 1. A complement need not be similar.',
    ],
    'image_provider' => [
        'label' => 'Picture vector provider',
        'description' => 'gemini today. A provider that puts pictures and words in one space is a driver in the AI module.',
    ],
    'image_model' => [
        'label' => 'Picture vector model',
        'description' => 'For example gemini-embedding-2. Changing it rebuilds every picture vector.',
    ],
    'image_dimensions' => [
        'label' => 'Picture vector dimensions',
        'description' => '768 is enough for visual likeness and cheap to store. 0 keeps the model\\x27s default.',
    ],
    'image_usd_per_image' => [
        'label' => 'Price per picture',
        'description' => 'From the provider\\x27s price list. Used to estimate before each call and to record the cost.',
    ],
    'image_text_usd_per_million' => [
        'label' => 'Price of words for picture search, per million tokens',
        'description' => 'From the provider\\x27s price list.',
    ],
    'max_images_per_run' => [
        'label' => 'New pictures in one run',
        'description' => 'The rest wait for the next night.',
    ],
    'image_max_kb' => [
        'label' => 'Largest picture',
        'description' => 'A bigger picture is skipped and marked.',
    ],
    'min_look_alike' => [
        'label' => 'Minimum visual likeness',
        'description' => 'Below this a product is not offered as looking alike.',
    ],
    'images_per_part' => [
        'label' => 'Pictures per scan part',
        'description' => 'A big scan runs in small parts: a deploy or a crash loses one part at most, and the scan goes on by itself.',
    ],
    'caption_provider' => [
        'label' => 'Picture description provider',
        'description' => 'anthropic or openai.',
    ],
    'caption_model' => [
        'label' => 'Picture description model',
        'description' => 'A model that sees pictures.',
    ],
    'caption_input_usd_per_million' => [
        'label' => 'Description input price (USD per million)',
        'description' => 'For the cost.',
    ],
    'caption_output_usd_per_million' => [
        'label' => 'Description output price (USD per million)',
        'description' => 'For the cost.',
    ],
    'caption_max_output_tokens' => [
        'label' => 'Longest description',
        'description' => 'In tokens.',
    ],
    'content_weight' => [
        'label' => 'Content against looks',
        'description' => '0: looks only. 1: content only. 0.5: half and half.',
    ],
];
