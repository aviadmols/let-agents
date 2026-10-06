<?php

return [
    'title' => 'Rega · Daily summary · :shop · :date',
    'searches' => 'Yesterday: :searches searches, :empty with no results, :clicks clicks on results.',
    'evidence' => 'Reviewed: :empty searches that found nothing, :unclicked searches nobody clicked, :questions questions the site did not answer.',
    'quiet' => 'Nothing new today.',
    'proposals' => 'Suggestions: :proposed, of which :accepted passed the check and :applied already joined search.',
    'budget' => 'Budget: :spent $ of :cap $ this month.',
    'stopped' => [
        'spend_cap' => 'The review stopped: the monthly budget ran out.',
        'no_key' => 'The review stopped: a provider key is missing.',
        'other' => 'The review did not finish today. Details are in the activity log.',
    ],
];
