<?php

return [
    'relations' => [
        'label' => 'Product relations computed at night',
        'description' => 'Complements, alternatives and other sizes are computed every night and kept. Off: the same sections come from the indexes (closeness in meaning, the same product type, the size family) and what the store set.',
    ],
    'read_text_in_code' => [
        'label' => 'Read attributes from the store\'s text in code',
        'description' => 'Code reads the vocabulary\'s choices, numbers and flags from the store\'s fields (type, tags, attributes), the title and the start of the description, in that order, and keeps where each fact was read and how sure its source is.',
    ],
    'schema_auto_advance' => [
        'label' => 'Go on to the full scan when the structure scores well',
        'description' => 'When every branch\'s vocabulary scores at least the pass score (80) and nothing hard failed, it is approved and the scan goes on without waiting. Off: the score is shown and a person approves. On by default.',
    ],
    'web_lookup' => [
        'label' => 'Let the team check a missing detail on the web',
        'description' => 'A button on the missing details screen asks a model that searches the web. A value is offered only when two independent sites, or the manufacturer, say it in a sentence a page really holds, and it is saved only when a person approves it.',
    ],
    'auto_approve' => [
        'label' => 'Approve checked facts automatically',
        'description' => 'When code and a model agree, or a reviewer model confirms, the fact is approved. When off, every fact waits for a person.',
    ],
    'weekly_audit' => [
        'label' => 'Weekly audit of article reading',
        'description' => 'Once a week a model checks a few articles code has read, proposes how to read better, and a second model reviews the proposal. Nothing changes without approval on the Scan improvements page.',
    ],
    'nightly' => [
        'label' => 'Nightly reading',
        'description' => 'Every night, after the sync, code reads what changed in the shop again: products, promises, articles, superlatives and relations. No model, no cost.',
    ],
    'auto_review' => [
        'label' => 'Nightly review of waiting details',
        'description' => 'Every night a model of another family checks the details the reader was unsure of: right is approved, wrong rejected, unsure goes to a person.',
    ],
    'search_summary' => [
        'label' => 'A short, clear description of every product, for search',
        'description' => 'In the nightly reading: one to three sentences on what the product is, what it is for and where it fits, only from its description and approved details, resting on quotes. A model of another family reviews it. It joins the search by meaning only; the description on the site does not change.',
    ],
    'auto_highlights' => [
        'label' => 'The key points of every product description',
        'description' => 'In the nightly reading and the first scan, after the details: up to four practical points from each description (what else is needed, where it fits, how to install, what is included), each resting on a quote, no praise and no numbers the text does not have. A model of another family reviews them.',
    ],
    'picture_suggestions' => [
        'label' => 'Suggestions from the picture for missing details',
        'description' => 'A button that suggests a value for a detail of looks (colour, stone, shape, pattern) from the product\'s picture. Only details a picture can show, and nothing is kept without approval.',
    ],
    'batch_reading' => [
        'label' => 'Read products in batches, at half price',
        'description' => 'Questions for the model go to the provider in one batch and are answered during the day, at half the price of asking one by one. The answers are collected every ten minutes and checked in code as always. Off: each question is asked on its own, at once.',
    ],
    'auto_publish_rules' => [
        'label' => 'Publish reading improvements automatically',
        'description' => 'A proposal two models agreed on, with a measured improvement, publishes itself — at most one version a week. An older version can always be put back on the Scan improvements screen.',
    ],
    'colour_names' => [
        'label' => 'Placing the store\'s colour names',
        'description' => 'Colour names code does not know and the store has no swatch for ("Ocean Mist") are placed once among the shades, so the products are found when filtering by colour. One model places them and one of another family checks, in one request a night and only when there are new names.',
    ],
];
