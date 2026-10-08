<?php

return [
    'wait_minutes' => [
        'label' => 'When a cart counts as abandoned',
        'description' => 'In minutes. Only then is the report written.',
    ],
    'keep_days' => [
        'label' => 'How long carts are kept',
        'description' => 'In days. Then the cart and its email are deleted.',
    ],
    'reports_per_run' => [
        'label' => 'Reports per run',
        'description' => 'At most this many carts in each hourly run.',
    ],
    'popup_title' => [
        'label' => 'Popup title',
        'description' => 'Empty: the default title.',
    ],
    'popup_text' => [
        'label' => 'Popup text',
        'description' => 'Empty: the default text.',
    ],
    'popup_button' => [
        'label' => 'Continue button',
        'description' => 'Empty: the default button.',
    ],
    'popup_skip' => [
        'label' => 'Skip link',
        'description' => 'Empty: the default link.',
    ],
    'popup_consent' => [
        'label' => 'Consent wording',
        'description' => 'What the shopper agrees to by leaving an email. Empty: the default wording.',
    ],
    'writer_provider' => [
        'label' => 'Report writer provider',
        'description' => 'anthropic or openai.',
    ],
    'writer_model' => [
        'label' => 'Report writer model',
        'description' => 'A strong model; the input is short.',
    ],
    'writer_input_usd_per_million' => [
        'label' => 'Writer input price (USD per million)',
        'description' => 'For the cost.',
    ],
    'writer_output_usd_per_million' => [
        'label' => 'Writer output price (USD per million)',
        'description' => 'For the cost.',
    ],
    'writer_max_output_tokens' => [
        'label' => 'Writer longest answer',
        'description' => 'In tokens.',
    ],
    'checker_provider' => [
        'label' => 'Checker provider',
        'description' => 'Of another family than the writer.',
    ],
    'checker_model' => [
        'label' => 'Checker model',
        'description' => 'A small, cheap model.',
    ],
    'checker_input_usd_per_million' => [
        'label' => 'Checker input price (USD per million)',
        'description' => 'For the cost.',
    ],
    'checker_output_usd_per_million' => [
        'label' => 'Checker output price (USD per million)',
        'description' => 'For the cost.',
    ],
    'checker_max_output_tokens' => [
        'label' => 'Checker longest answer',
        'description' => 'In tokens.',
    ],
];
