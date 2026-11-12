<?php

// The store report (StoreReport): its settings page and the email itself (Tomer's design, 12.11).
return [
    'nav' => 'Store report',

    'settings' => [
        'title' => 'What your shoppers searched, asked and couldn’t find',
        'lede' => 'And what Lets did for you. One email, no need to open the panel.',
        'example' => 'See a sample report',
        'how_often' => 'How often',
        'options' => ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'off' => 'Off'],
        'next' => 'Next report: :when',
        'none' => 'No reports are sent',
        'when' => [
            'daily' => 'Every day at 8:00, in the store’s time zone',
            'weekly' => 'Every Monday at 8:00, in the store’s time zone',
            'monthly' => 'On the 1st of every month at 8:00, in the store’s time zone',
        ],
        'off_note' => 'The report is off. All the data still waits for you in the panel, under Searches.',
        'who' => 'Who gets it',
        'who_note' => 'The store’s owners',
        'send' => 'Get one now',
        'send_note' => 'The latest report, straight to your inbox',
        'send_button' => 'Send it to me',
        'owners_only' => 'Only the store’s owners can change how often.',
        'fine' => 'Shoppers’ words are shown without names, emails or phone numbers.',
        'saved' => 'Saved',
        'sent' => 'Sent to :email',
        'nothing' => 'Nothing to report yet: no one has searched in this period.',
    ],

    'subject' => [
        'daily' => 'Your day in search: :searches searches, :questions questions',
        'weekly' => 'Your week in search: :searches searches, :questions questions',
        'monthly' => 'Your month in search: :searches searches, :questions questions',
    ],

    'mail' => [
        'kicker' => [
            'daily' => 'Your day in search · :shop',
            'weekly' => 'Your week in search · :shop',
            'monthly' => 'Your month in search · :shop',
        ],
        'searched' => 'shoppers searched your store.',
        'asked' => 'asked a question.',
        'lede' => 'Here’s what they looked for, what Lets showed them, and what they did next.',
        'stat' => ['searches' => 'searches', 'questions' => 'questions', 'found' => 'found what they looked for'],
        'pts' => ':n pts',
        'top' => 'What they searched',
        'top_found' => 'Top result: :title',
        'rising' => [
            'daily' => 'Rising today:',
            'weekly' => 'Rising this week:',
            'monthly' => 'Rising this month:',
        ],
        'rising_times' => [
            'daily' => ':query, searched :n× more than the day before.',
            'weekly' => ':query, searched :n× more than last week.',
            'monthly' => ':query, searched :n× more than last month.',
        ],
        'rising_new' => ':query, searched :n times, and not at all the period before.',
        'missing' => 'What they couldn’t find',
        'sold' => 'Found most through search',
        'to_cart' => ':n to cart',
        'money' => 'in sales through search',
        'adds' => 'added to cart from search',
        'orders' => 'orders through search',
        'button' => [
            'daily' => 'See the full day in your panel',
            'weekly' => 'See the full week in your panel',
            'monthly' => 'See the full month in your panel',
        ],
        'every' => [
            'daily' => 'You get this report every morning.',
            'weekly' => 'You get this report every Monday.',
            'monthly' => 'You get this report on the 1st of every month.',
        ],
        'change' => 'Change how often',
        'questions' => 'Questions? Just reply, or write to',
        'fine' => 'Let’s · Search Beyond · Shoppers’ words are shown without names, emails or phone numbers.',
    ],
];
