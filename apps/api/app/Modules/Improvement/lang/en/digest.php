<?php

return [
    'title' => 'Lets · Daily summary · :shop · :date',
    'searches' => 'Yesterday: :searches {searches:search/searches}, :empty with no results, :clicks {clicks:click/clicks} on results.',
    'new_products' => 'New products that came into the system in the last day: :count (:read of them already read and described). In the catalog: :total.',
    'evidence' => 'Reviewed: :empty {empty:search that found/searches that found} nothing, :unclicked {unclicked:search/searches} nobody clicked, :questions {questions:question/questions} the site did not answer.',
    'quiet' => 'Nothing new today.',
    'waiting' => 'The next review comes after :left more searches (:searches of :needed since the last review).',
    'proposals' => 'Suggestions: :proposed, of which :accepted passed the check and :applied already joined search.',
    'usage' => 'System use this month: :percent% of the monthly allowance.',
    'stopped' => [
        'spend_cap' => 'The review stopped: the monthly allowance is used up.',
        'no_key' => 'The review stopped: a provider key is missing.',
        'other' => 'The review did not finish today. Details are in the activity log.',
    ],
];
