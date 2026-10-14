<?php

return [
    'retention_days' => [
        'label' => 'Keep activity history for',
        'description' => 'What the agents did and answered. Older is deleted automatically every night. Default: a year.',
    ],
    'call_log_days' => [
        'label' => 'Keep model prompts and replies for',
        'description' => 'What was sent to each model and what came back, opened from the pipeline. Deleted automatically after this many days.',
    ],
];
