<?php

return [
    'title' => 'Settings and flags',
    'scope_global_help' => 'Global values. Every shop inherits them unless it has its own value.',
    'scope_shop_help' => 'Values for :shop only. An empty field inherits the global value.',
    'range' => 'Allowed range: :min to :max.',
    'save' => 'Save',
    'default_option' => 'Default (:value)',
    'saved' => 'Settings saved',
    'display_title' => 'How it shows in your store',
    'display_help' => 'What shows in your store, where it sits and how it is worded. A change is saved at once and reaches shoppers within a few minutes.',
    'groups' => [
        'shown' => ['title' => 'What shows in the store', 'help' => 'Which pages get the widget, how it looks and how many products a list holds.'],
        'placement' => ['title' => 'Where it sits on the page', 'help' => 'By a class or selector of your theme. When none is found the widget floats in the corner.'],
        'panels' => ['title' => 'What the shopper is offered', 'help' => 'Every panel the widget can show. What is off here simply never appears to customers.'],
        'assistant' => ['title' => 'What the assistant answers', 'help' => 'Answers are written from the store\'s own information and checked before a shopper sees them.'],
        'whatsapp' => ['title' => 'Talking to the team on WhatsApp', 'help' => 'The number and the wording. Answering hours are on the "Answering hours" screen.'],
        'signup' => ['title' => 'Products they viewed, and signing up', 'help' => 'What is kept for a returning shopper, and what the sign-up asks.'],
        'advanced' => ['title' => 'Advanced — platform tuning', 'help' => 'Caps, time windows, scanning and models. Not needed to run a store; open it only if you know what you are changing.'],
    ],
    'tabs' => [
        'shop' => 'Store settings',
        'advanced' => 'Advanced',
    ],
];
