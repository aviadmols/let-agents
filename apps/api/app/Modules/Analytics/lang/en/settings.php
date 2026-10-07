<?php

return [
    'attribution_days' => [
        'label' => 'Attribution window',
        'description' => 'An order counts toward Let Agents when the visitor used the widget within this many days before ordering.',
    ],
    'beacons_per_minute' => [
        'label' => 'Event batches per minute, per visitor address',
        'description' => 'Beyond this, batches are refused until the minute ends.',
    ],
    'score_window_days' => [
        'label' => 'Learning period',
        'description' => 'Nightly scores for each widget section use the events and orders of these many days.',
    ],
    'score_prior_exposures' => [
        'label' => 'Weight of the shop-wide average',
        'description' => 'How many exposures a page needs before its score moves away from the same section shop-wide. Higher changes more slowly.',
    ],
    'drop_related_after_opens' => [
        'label' => 'Drop related products that do not work',
        'description' => 'A product shown inside a section that nobody clicked after this many openings of the section on that page stops showing.',
    ],
    'popularity_window_days' => [
        'label' => 'Popularity period',
        'description' => 'Adds to the cart and orders of these many days are counted per product every night.',
    ],
    'popular_min_score' => [
        'label' => 'Threshold for a popular product',
        'description' => 'Adds to the cart plus twice the orders. Below it a product is not marked popular even at the top of the list.',
    ],
    'popular_top_percent' => [
        'label' => 'Share of products marked popular',
        'description' => 'Only products in this top share of the whole live catalog, by the count, are marked popular.',
    ],
    'retention_days' => [
        'label' => 'Keep events for',
        'description' => 'Older events are deleted every night. Orders are kept.',
    ],
    'holdout_percent' => [
        'label' => 'Share of shoppers held out',
        'description' => 'The part of shoppers shown the fixed order instead of the learned one. Without them the system can be said to have changed, but not to have improved. 0 turns the measurement off.',
    ],
    'measure_min_exposures' => [
        'label' => 'How much is enough before saying anything',
        'description' => 'Below this on either side the measurement says "too early" instead of inventing a conclusion.',
    ],
    'prior_min_shops' => [
        'label' => 'How many shops make a starting point',
        'description' => 'How many shops of a trade are needed before an average is worth giving a new shop. Fewer than that and one shop\'s habits are not its trade\'s.',
    ],
];
