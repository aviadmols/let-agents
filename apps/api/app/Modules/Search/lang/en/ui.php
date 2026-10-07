<?php

return [
    'title' => 'Site searches',
    'subheading' => 'What shoppers searched, what was not found, and what they clicked. A synonym connects a word shoppers type with the word the store uses.',
    'no_shop' => 'No shop yet.',
    'days' => 'Last :days days',
    'totals' => [
        'searches' => 'Searches',
        'distinct' => 'Different wordings',
        'empty_share' => 'Searches with no results',
        'click_share' => 'Searches that led to a click',
    ],
    'columns' => [
        'query' => 'Searched for',
        'searches' => 'Times',
        'empty' => 'No results',
        'clicks' => 'Clicks',
        'results' => 'Results',
        'result' => 'Result',
        'queries' => 'From different searches',
    ],
    'empty' => [
        'heading' => 'Searches that found nothing',
        'description' => 'Here are the products shoppers want and cannot find, or words the store calls something else. A synonym solves the second.',
        'none' => 'Every search in this period found something.',
        'make_synonym' => 'Synonym',
    ],
    'synonyms' => [
        'heading' => 'Synonyms',
        'description' => 'What shoppers type, and what it means in the store. For example: board = wood. Products that say "wood" are found when searching "board" too.',
        'term' => 'What people type',
        'means' => 'What it means in the store',
        'add' => 'Add',
        'added' => 'Synonym added',
        'applies_after_build' => 'It joins the search at the next update, tonight, or right after "Update search now".',
        'invalid' => 'Two different words are needed.',
        'removed' => 'Synonym removed',
        'remove' => 'Remove',
        'from_review' => 'From the daily review',
    ],
    'top' => [
        'heading' => 'Most common searches',
        'none' => 'No searches in this period yet.',
    ],
    'clicked' => [
        'heading' => 'What was clicked from search',
        'none' => 'No clicks in this period yet.',
    ],
    'index' => [
        'heading' => 'Search update',
        'status' => 'Updated :when: :products products, :content guides and pages, :categories categories.',
        'never' => 'Search has not been updated for this shop yet.',
        'build_now' => 'Update search now',
        'built' => 'Search updated',
        'failed' => 'The update failed. Details are in the activity log.',
    ],
    'photo_query' => 'Search by photo',
];
