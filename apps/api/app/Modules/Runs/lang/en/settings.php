<?php

return [
    'retention_days' => [
        'label' => 'Keep activity history for',
        'description' => 'Older activity is deleted automatically every night.',
    ],
    'call_log_days' => [
        'label' => 'Keep model prompts and replies for',
        'description' => 'What was sent to each model and what came back, opened from the pipeline. Deleted automatically after this many days.',
    ],
];
