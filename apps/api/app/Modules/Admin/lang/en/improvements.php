<?php

// Improvements (the designer's redesign 11.2026). Friendly: never "critical". Shoppers' words are data.
return [
    'nav' => 'Improvements',
    'title' => 'Improvements',
    'lede' => 'Small fixes that help shoppers find what they’re looking for. Approve what makes sense, the rest waits.',
    'tally' => [
        'label' => 'Since you started',
        'approved' => '{1} fix you approved since you started|[0,*] fixes you approved since you started',
        'found' => 'searches that now find something',
        'sales' => 'in sales from fixed searches',
    ],
    'tabs' => ['waiting' => 'Waiting for you', 'auto' => 'Fixed by Lets', 'done' => 'Approved'],
    'kinds' => [
        'label' => 'Kind',
        'all' => 'All',
        'nothing' => 'Found nothing',
        'noclick' => 'Didn’t click',
        'question' => 'Questions',
        'info' => 'Product details',
        'idea' => 'Ideas',
    ],
    'one_tap' => 'One tap',
    'units' => [
        'searches' => '{1} search|[0,*] searches',
        'times' => '{1} time|[0,*] times',
        'products' => '{1} product|[0,*] products',
    ],
    'sugg' => [
        'ours' => 'Our suggestion',
        'they_see' => 'and from now on, this is what they’ll see:',
        'more' => '{1} +1 more|[2,*] +:n more',
        'as_one' => 'Our suggestion: read these as one word',
        'draft' => 'Draft answer · check that it’s right',
        'where_shown' => 'Will show in the assistant’s answers, and where shoppers ask it.',
        'photos' => 'What we read in the photos',
        'more_products' => '{1} And 1 more product.|[2,*] And :n more products.',
    ],
    'typed' => ['typed' => 'What exactly they typed', 'asked' => 'How they asked'],
    'not_relevant' => 'Not relevant',
    'dismissed' => 'Done. We won’t suggest it again.',
    'waiting' => [
        'none' => 'All caught up',
        'none_body' => 'When shoppers search for something that isn’t found, or ask something that has no answer, it shows up here with a suggestion.',
    ],
    'auto' => [
        'sub' => 'Clear typos and obvious words of the same meaning are fixed on their own. You can undo any of them with one tap.',
        'kinds' => ['typo' => 'Typo', 'same' => 'Same meaning'],
        'found_since' => 'found it since',
        'undo' => 'Undo',
        'earlier' => '{1} And 1 earlier fix|[2,*] And :n earlier fixes',
        'none' => 'Nothing fixed on its own yet',
        'none_body' => 'Clear typos and words of the same meaning that shoppers use will be fixed here, each one with an undo.',
    ],
    'done' => [
        'sub' => 'What you approved, and what it brought in since.',
        'found' => '{1} 1 found|[0,*] :n found',
        'adds' => '{1} 1 to cart|[0,*] :n to cart',
    ],
    'goal' => [
        'title' => 'How many searches find something',
        'goal' => 'Goal: :pct%',
        'after' => '{1} Approving the :n waiting fix takes you to about :pct.|[2,*] Approving the :n waiting fixes takes you to about :pct.',
        'none' => 'It fills in from the first searches.',
    ],
    'tip' => [
        'title' => 'How this works',
        'body' => 'What’s clear, like typos, Lets fixes on its own and shows you under “Fixed by Lets”. What needs your call waits here. Every fix can be undone.',
    ],
];
