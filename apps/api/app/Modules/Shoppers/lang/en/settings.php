<?php

return [
    'recent_products_count' => [
        'label' => 'How many products to show',
        'description' => 'Products in the "products you viewed" circle, most visited first.',
    ],
    'recent_days' => [
        'label' => 'Memory for a shopper who left nothing',
        'description' => 'How many days back an anonymous shopper\'s views are counted. The memory lives in their browser.',
    ],
    'recent_days_identified' => [
        'label' => 'Memory for a shopper who signed up',
        'description' => 'How many days back the views of someone who signed up are counted. Once they prove the contact, they see the views of all their browsers.',
    ],
    'country_code' => [
        'label' => 'Country code',
        'description' => 'Digits only, e.g. 972. A number typed locally (050…) is converted with it, so one person is not counted twice.',
    ],
    'code_valid_minutes' => [
        'label' => 'How long a code is valid',
        'description' => 'Minutes before the code that was sent stops working.',
    ],
    'code_attempts' => [
        'label' => 'Tries at typing the code',
        'description' => 'After this many wrong tries a new code is needed.',
    ],
    'signups_per_visitor_per_day' => [
        'label' => 'Sign-ups a day per shopper',
        'description' => 'Keeps one browser from sending codes in bulk.',
    ],
    'signups_per_minute' => [
        'label' => 'Sign-up requests per minute, per visitor address',
        'description' => 'Beyond this, requests are refused until the minute ends.',
    ],
    'signup_title' => [
        'label' => 'The sentence that invites them to leave a contact',
        'description' => 'For example: shall we keep the products you viewed? Empty shows the default.',
    ],
    'signup_note' => [
        'label' => 'A small line under the sentence',
        'description' => 'For example: and we keep the products you viewed, on every device. Empty shows nothing.',
    ],
    'signup_consent' => [
        'label' => 'The consent wording',
        'description' => 'What the shopper agrees to when leaving a contact. It must say the shop will see the contact and the products they viewed. Empty shows the built-in wording.',
    ],
    'email_team_callbacks' => [
        'label' => 'Email the team when a shopper leaves details',
        'description' => 'A shopper who asked to be called back (left a phone or an email): the store team gets an email with the details, what they asked, and on which page.',
    ],
];
