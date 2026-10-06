<?php

return [
    'analyst_provider' => [
        'label' => 'Provider of the model that proposes',
        'description' => 'anthropic by default. Must be from another family than the checker, or the review stops.',
    ],
    'analyst_model' => [
        'label' => 'The model that proposes',
        'description' => 'For example claude-sonnet-5-5. Runs once a day, only when there is something new.',
    ],
    'analyst_input_usd_per_million' => [
        'label' => 'Input price of the proposing model, per million tokens',
        'description' => 'From the provider\'s price list.',
    ],
    'analyst_output_usd_per_million' => [
        'label' => 'Output price of the proposing model, per million tokens',
        'description' => 'From the provider\'s price list. Thinking tokens count as output.',
    ],
    'analyst_max_output_tokens' => [
        'label' => 'Token cap for the proposing model\'s answer',
        'description' => 'Including thinking. Too low may leave the answer empty.',
    ],
    'auditor_provider' => [
        'label' => 'Provider of the model that checks',
        'description' => 'openai by default. Another family than the proposing model, so they do not err the same way.',
    ],
    'auditor_model' => [
        'label' => 'The model that checks',
        'description' => 'For example gpt-5.4-mini.',
    ],
    'auditor_input_usd_per_million' => [
        'label' => 'Input price of the checking model, per million tokens',
        'description' => 'From the provider\'s price list.',
    ],
    'auditor_output_usd_per_million' => [
        'label' => 'Output price of the checking model, per million tokens',
        'description' => 'From the provider\'s price list.',
    ],
    'auditor_max_output_tokens' => [
        'label' => 'Token cap for the checking model\'s answer',
        'description' => 'Including thinking.',
    ],
    'max_proposals' => [
        'label' => 'Suggestions a day at most',
        'description' => 'The strongest first.',
    ],
    'evidence_days' => [
        'label' => 'How many days back are reviewed',
        'description' => 'Searches and questions from this period.',
    ],
    'min_searches' => [
        'label' => 'A search with no results counts from',
        'description' => 'How many times a search must find nothing to be reviewed.',
    ],
    'digest_email' => [
        'label' => 'Address for the daily summary',
        'description' => 'The summary is sent here every morning. Empty: it is kept in the panel only.',
    ],
];
