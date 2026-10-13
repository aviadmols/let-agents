<?php

return [
    'open' => 'Open',
    'search' => [
        'name' => 'Site search',
        'on' => ':products products in the search · :count searches on the site in the last 7 days',
        'setup' => ':products products in the search, but no searches were seen on the site this week. It is probably not connected to the theme\'s search box yet.',
        'off' => 'Off: the theme\'s own search works on the site',
        'connect' => 'How to connect',
    ],
    'photos' => [
        'name' => 'Search by photo',
        'on' => 'Shoppers can take or upload a photo and find similar products',
        'off' => 'Off: it can be turned on on the search display screen',
    ],
    'empty' => [
        'title' => '":query": :searches searches, :empty with no results',
        'body' => 'Shoppers look for this and find nothing. A synonym or a matching product would solve it.',
        'action' => 'To site searches',
    ],
];
