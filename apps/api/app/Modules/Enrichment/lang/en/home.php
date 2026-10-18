<?php

return [
    'card' => [
        'label' => 'Products scanned',
        'of' => 'of :total',
        'waiting' => ':count waiting to be read',
        'last' => 'Last scan :when',
        'never' => 'Not scanned yet',
    ],
    'step' => [
        'title' => 'Fill in missing details',
        'body' => ':count :type products have no :attribute, so they do not show when a shopper filters by it.',
        'body_none' => 'When products are missing a detail (size, thickness, colour), they show up here. One value of yours, and they all show in the filters.',
        'button' => 'Show me',
    ],
];
