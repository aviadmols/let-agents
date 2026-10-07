<?php

return [
    'max_requests_per_task_file' => [
        'label' => 'Maximum requests in one task file',
        'description' => 'Larger jobs are split: create another file when the first is done.',
    ],
    'max_highlight_text_chars' => [
        'label' => 'Product text for writing highlights',
        'description' => 'Usage, installation and care advice usually comes at the end of a description, so writing highlights gets longer text. Headings are kept only with what they introduce.',
    ],
    'max_agent_text_chars' => [
        'label' => 'Product text sent to a model',
        'description' => 'Code shortens each product to this many characters, keeping lines with numbers and specs first. Measurements are found before shortening is applied to prose.',
    ],
    'max_products_per_article' => [
        'label' => 'Products shown next to an article',
        'description' => 'The most products chosen for one article or guide. Linked products come first, then products from the categories a checker approved.',
    ],
    'min_set_size' => [
        'label' => 'Smallest set for a superlative',
        'description' => 'No "lightest" or "cheapest" among fewer comparable products in stock than this.',
    ],
    'max_upload_kilobytes' => [
        'label' => 'Largest results file',
        'description' => 'Uploads above this size are refused.',
    ],
    'model_answer_tokens' => [
        'label' => 'Longest answer a model may write',
        'description' => 'How many tokens a model may spend on one product.',
    ],
    'nightly_model_requests' => [
        'label' => 'Products a model reads each night',
        'description' => 'How many products are sent to a model nightly. 0 turns model reading off. The monthly spend cap still wins.',
    ],
    'copurchase_window_days' => [
        'label' => 'How far back orders are read',
        'description' => 'The window what-is-bought-with-what is learned from. Longer gives more data; shorter follows the season.',
    ],
    'copurchase_min_orders' => [
        'label' => 'Orders needed before two products are linked',
        'description' => 'Below this it is a coincidence rather than a pattern, and no relation is made.',
    ],
    'reader_provider' => [
        'label' => 'Provider of the nightly product and article reader',
        'description' => 'openai by default.',
    ],
    'reader_model' => [
        'label' => 'The model that reads products and articles at night',
        'description' => 'Reads facts, key points and article matches.',
    ],
    'reader_input_usd_per_million' => [
        'label' => 'Input price of the reader, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'reader_output_usd_per_million' => [
        'label' => 'Output price of the reader, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'audit_writer_provider' => [
        'label' => 'Provider of the model that proposes reading rules',
        'description' => 'openai by default.',
    ],
    'audit_writer_model' => [
        'label' => 'The model that proposes article reading rules',
        'description' => 'Proposes what code missed when reading articles.',
    ],
    'audit_writer_input_usd_per_million' => [
        'label' => 'Input price of the proposer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'audit_writer_output_usd_per_million' => [
        'label' => 'Output price of the proposer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'audit_checker_provider' => [
        'label' => 'Provider of the model that checks article reading',
        'description' => 'Best from another family than the proposer.',
    ],
    'audit_checker_model' => [
        'label' => 'The model that checks article reading',
        'description' => 'Checks a sample of articles and the proposed rules.',
    ],
    'audit_checker_input_usd_per_million' => [
        'label' => 'Input price of the checker, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'audit_checker_output_usd_per_million' => [
        'label' => 'Output price of the checker, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
];
