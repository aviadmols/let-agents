<?php

// The search's part of the analytics screen (Search\Support\SearchReport).
return [
    'searches' => ':n {n:search/searches}',
    'last' => 'last :when',
    'people' => ':n different shoppers',
    'results' => ':n results',
    'clicks' => '{0} 0 clicks|{1} one click|[2,*] :n clicks',
    'adds_per_ten' => ':n adds to cart per 10 searches',
    'empty_pill' => ':n with no results',
    'synonym_added' => '":term" also finds ":means" from now on. The search updates at its next build.',
    'today' => 'today',
    'yesterday' => 'yesterday',
    'days_ago' => ':n days ago',
    'ip_top' => ':n requests on the busiest day',
    'ip_split' => ':searches {searches:search/searches}, :clicks {clicks:click/clicks}',
    'ip_days' => ':n days in a row',
    'ip_bot' => 'probably a bot',
    'ip_blocked_on' => 'blocked :date',
    'ip_blocked' => 'Blocked',
    'ip_unusual' => 'Unusual',
    'ip_high' => 'High',
    'blocked' => ':ip is blocked. Search and the assistant will not answer it any more.',
    'unblocked' => 'The block on :ip was lifted.',
];
