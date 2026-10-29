<?php

return [
    'subject_one' => 'Lets: :title',
    'subject_many' => 'Lets: :count problems in the system',
    'urgent' => 'Urgent:',
    'urgent_badge' => 'Urgent',
    'lead' => 'System check, :at',
    'next' => 'What now:',
    'button' => 'Details in the panel',
    'note' => 'Each problem is sent once, and again only if it lasts another day. A budget problem is sent once a month. The thresholds are in the "System alerts" settings.',
    'no_shop' => 'not of one shop',
    'example' => 'The error: ":error"',

    'budget' => [
        'shoppers_blocked' => 'Shoppers\' questions on the site are no longer answered by AI either, only from saved answers.',
        'shoppers_served' => 'Shoppers\' questions on the site are still answered.',
        'pace' => 'At this month\'s pace so far, it runs out around :date.',
        'pace_ok' => 'At this month\'s pace so far, it lasts to the end of the month.',
        'reached' => [
            'platform' => [
                'title' => 'The system\'s monthly budget is spent',
                'what' => ':spent USD of :cap was spent this month. From now on every model call is blocked in every shop: tagging, scans, the nightly review. :shoppers',
            ],
            'shop' => [
                'title' => 'The monthly allowance of :shop is spent',
                'what' => ':shop spent :spent USD of :cap this month. From now on model calls for it are blocked: tagging, scans, the nightly review. :shoppers',
            ],
            'next' => 'The block lifts by itself on :reset, the start of next month. To go on now, raise the cap on the budgets screen. The work that stopped goes on the night after.',
        ],
        'near' => [
            'platform' => [
                'title' => 'The system\'s monthly budget is nearly spent (:percent%)',
                'what' => ':spent USD of :cap was spent this month. :pace',
            ],
            'shop' => [
                'title' => 'The monthly allowance of :shop is nearly spent (:percent%)',
                'what' => ':shop spent :spent USD of :cap this month. :pace',
            ],
            'next' => 'Once it is spent, model calls are blocked until :reset. If needed, raise the cap on the budgets screen.',
        ],
    ],

    'queue' => [
        'title' => 'Background work is not running',
        'what' => ':count jobs wait in the queue, the oldest since :at, and none was taken. The worker has likely stopped.',
        'next' => 'Until it is back nothing runs in the background: syncs, tagging, scans and emails to clients. Check the worker service on Railway and restart it.',
    ],

    'runs' => [
        'title' => 'Failing again and again: :action (:count times)',
        'what' => 'In the last :minutes minutes (:shops).',
        'next' => [
            'no_key' => 'The AI provider\'s key is missing or invalid, and every call through it will fail. Update it on the AI providers screen.',
            'rate_limited' => 'This usually passes by itself within minutes. If it comes back, raise the request limit with the provider.',
            'not_json' => 'This usually passes. If it comes back, check the prompt or the answer schema of this work.',
            'timeout' => 'The next run tries again. If it comes back, the work is likely too long and needs a fix.',
            'database' => 'This is likely a bug in the code that needs a fix. The details are in the agent log.',
            'provider' => 'The next run tries again. If it comes back, check whether the provider has an outage.',
            'other' => 'The full details are in the agent log.',
        ],
    ],

    'reasons' => [
        'no_key' => 'The provider refused the key, or there is none.',
        'rate_limited' => 'The provider asked to slow down: too many requests in a short time.',
        'not_json' => 'The model answered in a shape that is not valid.',
        'timeout' => 'The connection was cut or time ran out before the work ended.',
        'database' => 'Saving to the database failed.',
        'provider' => 'The provider returned an error.',
        'other' => 'An unexpected error.',
    ],

    'jobs' => [
        'title' => 'Background work stopped: :job (:count times)',
        'what' => 'In the last :minutes minutes.',
        'next' => 'What this work was meant to do is not done until it runs again. If it comes back, it needs a fix in the code.',
    ],

    'stuck' => [
        'title' => 'Stuck runs: :action (over :hours hours)',
        'what' => ':count runs started and did not end, the first at :at. The work was likely cut in the middle, for example by a server restart.',
        'next' => 'What did not end waits for the next run. If this happens every day, check this work.',
    ],

    'sync' => [
        'title' => ':shop has not synced since :at',
        'what' => 'The last sync that worked was :hours hours ago.',
        'failed' => 'The last attempt failed: ":error".',
        'not_tried' => 'There was no attempt to sync since.',
        'next' => 'New products and changes in the store do not reach the search. Check the store\'s connection and the nightly sync.',
    ],
];
