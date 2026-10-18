<?php

return [
    'name' => 'The assistant',
    'on' => 'Answers shoppers in the search and on product pages · :count questions in 7 days',
    'off' => 'Off: shoppers cannot ask questions',
    'open' => 'To shopper questions',
    'questions' => 'Shopper questions',
    'open_title' => '{1} One shopper question waits for an answer|[2,*] :count shopper questions wait for an answer',
    'open_body' => '":question" was asked :times times and the assistant could not answer. One answer of yours goes into what it knows about the store, and it answers at once.',
    'answer' => 'Answer now',
    'card' => [
        'label' => 'Questions shoppers asked',
        'answered' => ':count answered from what the store knows',
    ],
    'step' => [
        'title' => 'Answer the first question',
        'body' => '":question" was asked :times times. Lets can already write a draft.',
        'body_none' => 'When a shopper asks something the site has no answer to, it shows up here. One answer of yours, and every shopper gets it.',
        'button' => 'Show me',
    ],
];
