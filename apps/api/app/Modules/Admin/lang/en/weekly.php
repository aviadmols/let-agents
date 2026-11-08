<?php

// The Monday email to the shop owner (WeeklySummaryMail), first sketch.
return [
    'subject' => 'Your week at :shop · :week',
    'money_label' => 'Sales through search this week',
    'orders' => '{0} No order started with a search yet.|{1} :n order started with a search,|[2,*] :n orders started with a search,',
    'adds' => '{0} and nothing went to the cart from search.|{1} and :n product went to the cart from search.|[2,*] and :n products went to the cart from search.',
    'asked' => '{1} 1 search or question|[0,*] :n searches and questions',
    'found' => ':pct% found it',
    'waiting_title' => 'What’s waiting for you',
    'waiting_sub' => '{1} One small fix, a minute or two.|[2,*] :n small fixes. The first three take a few minutes.',
    'waiting_none' => 'Nothing this week. Search is in good shape.',
    'open' => 'Open Today',
    'searched_title' => 'What they searched',
    'sold_title' => 'What sold through search',
    'to_cart' => '{1} 1 to cart|[0,*] :n to cart',
    'footer' => 'You get this email every Monday morning because you run this store on Lets.',
    'preview' => [
        'title' => 'The weekly email',
        'sub' => 'An example of the email we’ll send every Monday morning, with your store’s real numbers.',
    ],
];
