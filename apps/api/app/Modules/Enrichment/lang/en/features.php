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
    'batch_reading' => [
        'label' => 'Read products in batches, at half price',
        'description' => 'Questions for the model go to the provider in one batch and are answered during the day, at half the price of asking one by one. The answers are collected every ten minutes and checked in code as always. Off: each question is asked on its own, at once.',
    ],
    'auto_publish_rules' => [
        'label' => 'Publish reading improvements automatically',
        'description' => 'A proposal two models agreed on, with a measured improvement, publishes itself — at most one version a week. An older version can always be put back on the Scan improvements screen.',
    ],
];
