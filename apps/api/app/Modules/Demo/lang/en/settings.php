<?php

return [
    'max_products' => ['label' => 'Products in a demo', 'description' => 'At most this many products are read for each demo.'],
    'max_content' => ['label' => 'Pages and posts in a demo', 'description' => 'At most this many pages and posts are read for each demo (the terms and policies are always read).'],
    'keep_days' => ['label' => 'Days a demo lasts', 'description' => 'After this, the demo is deleted with everything read from the site.'],
    'per_day' => ['label' => 'Demos a day', 'description' => 'How many demo requests are taken a day, all sites together.'],
    'per_email_per_day' => ['label' => 'Demos a day from one email', 'description' => 'How many demo requests are taken from one email in 24 hours.'],
    'budget_usd' => ['label' => 'Cost cap per demo', 'description' => 'The most spent on model calls for each demo, the scan and the questions together. Once reached, the demo keeps searching without the AI.'],
    'photos' => ['label' => 'Photo searches in a demo', 'description' => 'How many searches by photo each demo may make.'],
    'prepared_questions' => ['label' => 'Questions answered ahead in a demo', 'description' => 'How many questions about the store (shipping, returns...) are answered ahead from its terms and pages, to show in "What people ask here".'],
    'vocabulary_branches' => ['label' => 'Product groups given a vocabulary in a demo', 'description' => 'How many groups of the store\'s products get a vocabulary of their own (types, details to filter by), written and checked by models. The most costly part of a demo\'s scan: about 10 cents a group.'],
    'vocabulary_pass_score' => ['label' => 'Pass score of a demo\'s vocabulary', 'description' => 'The score from which a demo\'s vocabulary is used without a person approving it, so the demo shows its tags and filters. A real store keeps its own bar.'],
];
