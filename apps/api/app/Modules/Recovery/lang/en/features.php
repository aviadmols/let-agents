<?php

return [
    'capture' => [
        'label' => 'Email popup before payment',
        'description' => 'Before checkout the shopper is asked for an email. The store keeps the cart as an order waiting for payment, with the email.',
    ],
    'report' => [
        'label' => 'Reports on unpaid carts',
        'description' => 'After the set wait, if it was not paid, a short report is written: what interested the shopper, what they searched, how they reached the cart and what to send them.',
    ],
];
