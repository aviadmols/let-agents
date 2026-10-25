<?php

return [
    'code' => [
        'subject' => 'Your Lets demo code: :code',
        'title' => 'One more step and the demo starts',
        'lead' => 'This is the code for the Lets demo on :site. Type it on the demo page, and the scan starts.',
        'note' => 'The code is good for 15 minutes. Did not ask for a demo? You can ignore this email.',
    ],
    'ready' => [
        'subject' => 'The Lets demo on :site is ready',
        'title' => 'The demo of :site is ready',
        'lead' => 'The search, photo search and AI answers already work on the products and pages of :site. Try searching as a shopper would.',
        'button' => 'Open the demo',
        'note' => 'The demo is available until :date, then deleted with everything read from the site.',
    ],
    'alert' => [
        'subject' => 'Lets demo: :site needs a look',
        'title' => 'The demo of :site needs a look',
        'button' => 'Open the demos list',
        'note' => 'Told once for this problem. The demos list has its cost, agent log and a button to scan it again.',
    ],
    'problems' => [
        'failed' => 'The demo of :site failed: :error.',
        'stalled' => 'The scan of :site has done nothing for :minutes minutes (last at :at). A step may have been cut off or be held.',
    ],
];
