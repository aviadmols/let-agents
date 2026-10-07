<?php

return [
    'steps_per_minute' => [
        'label' => 'Steps a minute from one site',
        'description' => 'A rate limit on the lead form, so nobody fills it with a script.',
    ],
    'retention_days' => [
        'label' => 'How long a lead is kept',
        'description' => 'After this a lead is deleted. A single lead can be deleted at any time too.',
    ],
    'writer_model' => [
        'label' => 'The model that writes the offers',
        'description' => 'The model that phrases the invitation on each page. Must be a different model from the reviewer.',
    ],
    'reviewer_model' => [
        'label' => 'The model that reviews and scores',
        'description' => 'A second model that scores each line and says why. It does not write, and it is measured against what shoppers actually did.',
    ],
    'reviewer_bar' => [
        'label' => 'The score below which a line is not shown',
        'description' => 'A line under this is refused. If it was close, the writer gets the reviewer\'s rewrite and one more attempt.',
    ],
    'model_tokens' => [
        'label' => 'Longest answer either model may write',
        'description' => 'How many tokens each of the two models may spend.',
    ],
    'pages_written_per_night' => [
        'label' => 'Pages a model writes each night',
        'description' => 'How many pages go to the models nightly. 0 turns model writing off and leaves the versions code writes.',
    ],
    'writer_provider' => [
        'label' => 'Provider of the model that writes offers to readers',
        'description' => 'openai by default.',
    ],
    'writer_input_usd_per_million' => [
        'label' => 'Input price of the writer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'writer_output_usd_per_million' => [
        'label' => 'Output price of the writer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'reviewer_provider' => [
        'label' => 'Provider of the model that reviews the offers',
        'description' => 'Best from another family than the writer.',
    ],
    'reviewer_input_usd_per_million' => [
        'label' => 'Input price of the reviewer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'reviewer_output_usd_per_million' => [
        'label' => 'Output price of the reviewer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
];
