<?php

return [
    'title' => 'Store knowledge status',
    'help' => 'Everything the system knows about this shop, where it came from, and what is still missing.',
    'pick_a_shop' => 'Choose a shop above to see what is known about it. Knowledge belongs to one shop and is never shown across all of them.',
    'known_share_of' => [
        'products' => 'of products have something checked known about them',
        'articles' => 'of articles have points found in them',
    ],
    'week_ago' => 'A week ago: :n%',
    'scanned' => ':products products, :articles articles and :pages content pages were scanned.',
    'scanned_short' => ':products products · :articles articles · :pages pages',
    'vertical' => 'Trade',
    'vertical_unknown' => 'not recognised from the catalogue yet',
    'confidence' => 'read from the catalogue, :n% confident',
    'verticals' => [
        'hardware-store' => 'Hardware and building supplies',
    ],

    'signals_title' => 'What the system learns from',
    'signals_help' => 'What shoppers did over the last :days days. A system learns from what it measures, so this is the part of the screen that matters most.',
    'signals_note' => 'A weak or missing signal is not learned from. Once orders start arriving they decide, instead of clicks.',
    'optimising_for' => 'Today the learning follows: :signal',
    'optimising_none' => 'There is not enough yet to learn from anything. What is shown is the fixed order.',

    'gaps_title' => 'What is missing',
    'gaps_help' => 'The one part of this screen that says what to do next.',
    'no_gaps' => 'Nothing is missing.',
    'closed_by' => 'Closed by: :step',
    'nothing_closes_this' => 'No step can close this one — the information is simply not on the site.',

    'layers_title' => 'The layers of what is known',
    'layers_help' => 'In the order they happen: what the shop shared, what code read, what a model added, what a person decided, what was computed, and what shoppers asked.',
    'layers' => [
        'scanned' => 'What the shop shared',
        'read_in_code' => 'What code read',
        'read_by_model' => 'What a model added',
        'decided' => 'What a person decided',
        'computed' => 'What was computed',
        'articles' => 'Articles points were found in',
        'asked' => 'What shoppers asked',
    ],
    'of_products' => ':n products (:share%)',
    'of_articles' => ':n articles (:share%)',
    'facts' => ':n facts',
    'computed' => ':rankings superlatives · :relations relations · :popular popular products',
    'questions' => ':answered answered · :no_info no information · :refused refused',
    'by_kind' => 'How many products each kind of knowledge covers:',

    'freshness_title' => 'When each step last ran',
    'freshness_help' => 'Old knowledge is knowledge that may no longer be true.',
    'never' => 'never ran',
    'stale' => 'old',

    'arrangement_title' => 'What a shopper sees, in order',
    'arrangement_help' => 'The order the learning settled on, from what shoppers opened and clicked.',
    'arrangement_default' => 'No order has been learned yet. The fixed order is shown.',

    'privacy' => 'This knowledge belongs to this shop alone. Only general rules and patterns ever travel between shops — never products, prices, questions or shoppers.',
];
