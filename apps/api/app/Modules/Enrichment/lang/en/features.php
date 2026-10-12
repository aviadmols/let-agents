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
    'auto_publish_rules' => [
        'label' => 'Publish reading improvements automatically',
        'description' => 'A proposal two models agreed on, with a measured improvement, publishes itself — at most one version a week. An older version can always be put back on the Scan improvements screen.',
    ],
];
