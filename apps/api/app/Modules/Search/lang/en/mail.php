<?php

return [
    'scan_check' => [
        'subject' => 'Search check of :store: :failed of :tried did not find',
        'title' => 'Search check of :store',
        'mode_apply' => 'What failed was mended for this store alone',
        'mode_dry' => 'Trial mode: nothing written, only what would have been mended',
        'summary' => ':tried searches tried, :failed did not bring what they should. Cost: $:cost of a $:cap cap.',
        'failed_title' => 'Searches that did not bring what they should',
        'found' => ':n results',
        'after_ok' => 'after mending: found',
        'after_still' => 'after mending: still not',
        'why' => [
            'kind' => 'a kind',
            'colour' => 'a kind and colour',
            'audience' => 'a kind and whom',
            'name' => 'a product\'s name',
            'latin' => 'a Latin name in Hebrew',
            'shopper' => 'as a shopper',
        ],
        'mended_title' => 'What was mended',
        'would_title' => 'What would have been mended',
        'tag' => 'Tag «:tag» on :n products whose description says so',
        'outcome' => [
            'resolved' => 'will show',
            'refused' => 'refused by the check',
            'none' => 'no match found',
        ],
        'synonym' => 'the store\'s word: «:word»',
        'questions_title' => 'Questions to the assistant',
        'answer' => [
            'answered' => 'answered',
            'not' => 'did not answer',
        ],
        'button' => 'Full details',
    ],
];
