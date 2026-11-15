<?php

// The email to the store's team when a shopper leaves details to be called back (CallbackReceivedMail).
return [
    'callback' => [
        'subject' => 'Someone asks you to get back to them · :shop',
        'title' => 'Someone left their details and asks you to get back to them',
        'name' => 'Name',
        'channel' => ['phone' => 'Phone', 'email' => 'Email'],
        'question' => 'About',
        'page' => 'Where',
        'at' => 'Left on :at',
        'open' => 'Everyone waiting for you',
        'why' => 'This email was sent because you are on the store team, and the shopper agreed to be contacted. You can turn it off in the settings.',
    ],
];
