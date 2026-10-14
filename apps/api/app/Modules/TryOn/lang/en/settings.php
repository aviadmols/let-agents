<?php

return [
    'monthly_cap_usd' => [
        'label' => 'Monthly Try-On budget for the shop',
        'description' => 'Every photo check and every try counts. When it is spent, Try-On waits for next month. Apart from the shop\'s AI budget.',
    ],
    'job_cap_usd' => [
        'label' => 'Most one try may cost',
        'description' => 'With its retries. A try that would cost more stops and is not shown.',
    ],
    'jobs_per_visitor_per_day' => [
        'label' => 'Tries per shopper per day',
        'description' => 'Counted per browser.',
    ],
    'retries' => [
        'label' => 'Tries again when the check refuses',
        'description' => 'With the checker\'s correction in the prompt.',
    ],
    'retention_days' => [
        'label' => 'Days photos and pictures are kept',
        'description' => 'Then deleted every night. The log keeps what happened and what it cost.',
    ],
    'similar_count' => [
        'label' => 'Similar products shown in the Try-On window',
        'description' => 'Of the same kind, ready to try on the same photo.',
    ],
    'pass_similarity' => [
        'label' => 'How alike the product must look to be shown (1 to 5)',
        'description' => 'The checker\'s score for the product in the picture.',
    ],
    'photo_max_kb' => [
        'label' => 'Largest photo a shopper may upload',
        'description' => 'It is made smaller before anything else.',
    ],
    'generator_provider' => [
        'label' => 'Provider that makes the pictures',
        'description' => 'Its key is saved under AI providers.',
    ],
    'model_apparel' => [
        'label' => 'Picture model for clothes',
        'description' => 'A Replicate model, such as google/nano-banana.',
    ],
    'model_jewelry' => [
        'label' => 'Picture model for jewelry',
        'description' => 'A Replicate model, such as google/nano-banana.',
    ],
    'model_furniture' => [
        'label' => 'Picture model for furniture',
        'description' => 'A Replicate model, such as google/nano-banana.',
    ],
    'generation_usd_per_image' => [
        'label' => 'Price of one picture',
        'description' => 'From the provider\'s price list for the chosen model. Verify it.',
    ],
    'gate_provider' => [
        'label' => 'Provider of the photo check',
        'description' => 'Looks at the shopper\'s photo and says what it shows. Code decides.',
    ],
    'gate_model' => [
        'label' => 'Model of the photo check',
        'description' => 'Looks at the shopper\'s photo and says what it shows. Code decides.',
    ],
    'gate_input_usd_per_million' => [
        'label' => 'Input price of the photo check, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'gate_output_usd_per_million' => [
        'label' => 'Output price of the photo check, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'writer_provider' => [
        'label' => 'Provider of the prompt writer',
        'description' => 'Makes the team\'s template exact for the product, the photo and the shopper\'s answers. A family other than the checker\'s.',
    ],
    'writer_model' => [
        'label' => 'Model of the prompt writer',
        'description' => 'Makes the team\'s template exact for the product, the photo and the shopper\'s answers. A family other than the checker\'s.',
    ],
    'writer_input_usd_per_million' => [
        'label' => 'Input price of the prompt writer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'writer_output_usd_per_million' => [
        'label' => 'Output price of the prompt writer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'checker_provider' => [
        'label' => 'Provider of the picture checker',
        'description' => 'Looks at the product, the photo and the result side by side. A family other than the writer\'s.',
    ],
    'checker_model' => [
        'label' => 'Model of the picture checker',
        'description' => 'Looks at the product, the photo and the result side by side. A family other than the writer\'s.',
    ],
    'checker_input_usd_per_million' => [
        'label' => 'Input price of the picture checker, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'checker_output_usd_per_million' => [
        'label' => 'Output price of the picture checker, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'anchor_selector' => [
        'label' => 'Where the try-on button appears on the product page',
        'description' => 'A CSS selector of an element on the product page, for example .product-form or #product-actions. Empty: next to the add-to-cart form. A store with the Let Agents block on its product page gets the button inside the block, unless a selector is set here.',
    ],
    'anchor_position' => [
        'label' => 'Relative to the element',
        'description' => 'Where the button goes relative to the selected element.',
        'options' => [
            'after' => 'After it',
            'before' => 'Before it',
            'start' => 'Inside, at the start',
            'end' => 'Inside, at the end',
        ],
    ],
];
