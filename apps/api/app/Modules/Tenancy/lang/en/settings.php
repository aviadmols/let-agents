<?php

return [
    'max_active_api_keys' => [
        'label' => 'Maximum active API keys',
        'description' => 'How many active keys a shop can hold at the same time.',
    ],
    'api_requests_per_minute' => [
        'label' => 'API requests per minute, per key',
        'description' => 'Beyond this the shop gets a rate_limited error until the minute ends.',
    ],
    'trial_days' => [
        'label' => 'Trial length',
        'description' => 'How many days a trial started for a shop lasts. After it the shop stays limited until the trial is ended.',
    ],
    'trial_max_products' => [
        'label' => 'Products on trial',
        'description' => 'How many products are read from a shop on trial. When the trial ends, the rest are read at the next sync.',
    ],
    'trial_questions_per_day' => [
        'label' => 'Questions a day on trial',
        'description' => 'How many questions a day the shop assistant answers on trial.',
    ],
];
