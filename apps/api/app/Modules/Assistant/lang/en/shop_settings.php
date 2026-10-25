<?php

// Settings · The assistant (AssistantSettings), as the designer's panel words it.
return [
    'title' => 'The assistant',
    'help' => 'The assistant answers shoppers from the site\'s pages and the knowledge centre, and cites where the answer came from. A checked answer is kept: the next shopper gets it with no model and no cost.',
    'on' => 'On',
    'off' => 'Off',
    'state' => ':asked {asked:question/questions} in 30 days · :answered answered from the knowledge · :waiting waiting for you',
    'where' => 'Where shoppers can ask',
    'on_products' => 'On product pages',
    'on_products_sub' => 'Questions about this product, under the add to cart button. For example "Does the size fit the shelf?"',
    'on_content' => 'On content pages and articles',
    'on_content_sub' => 'Questions about the article, and about the products it mentions',
    'on_search' => 'In the search window',
    'on_search_sub' => 'A shopper who types a question in the search box, about the whole store. For example ":example"',
    // The example question, by where the shop is: a place in Israel for an Israeli shop, another country for any other.
    'on_search_example' => ['israel' => 'Do you ship to Eilat?', 'abroad' => 'Do you ship to Canada?'],
    'limits' => 'Limits, so the monthly allowance lasts',
    'visitor_limit' => 'Limit each shopper\'s questions',
    'visitor_limit_sub' => 'Off: every shopper asks as much as they like. The whole store\'s daily limit stays, so the monthly allowance lasts',
    'per_visitor' => 'Questions per shopper a day',
    'per_visitor_sub' => 'Beyond this the shopper is sent to the team (WhatsApp or leaving details). 20 is enough for any real shopper',
    'per_visitor_tip' => 'Counted per browser. A shopper past the number sees "ask the team" instead of an answer.',
    'per_shop' => 'Questions per store a day',
    'per_shop_sub' => 'After this the assistant answers only from saved answers until midnight',
    'per_shop_busiest' => 'Last month: up to :n a day',
    'per_shop_tip' => 'A safety brake on the monthly allowance. 400 a day = 12,000 a month, far beyond most stores.',
    'no_answer' => 'When the site has no answer',
    'no_answer_sub' => 'What the shopper sees when nothing was found',
    'no_answer_options' => [
        'team' => 'The team + WhatsApp',
        'details' => 'Leave details',
        'none' => 'Just "not found"',
    ],
    'not_sure' => '"Not sure what fits?" under the results',
    'not_sure_sub' => 'In the chat: our AI recommends among the results, with an outside AI to go on with below. Outside: straight to an outside AI',
    'not_sure_options' => [
        'inside' => 'In the chat',
        'outside' => 'Out to an outside AI',
    ],
];
