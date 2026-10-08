<?php

return [
    'scans' => [
        'title' => 'Scan log',
        'subheading' => 'Every catalogue sync and picture scan of the shop, what each did, and every picture: scanned, waiting or unreadable and why.',
        'runs' => 'Scans',
        'runs_about' => 'The last 40, newest first. A running scan updates by itself.',
        'no_runs' => 'No scan has run for this shop yet.',
        'pictures' => 'Product pictures',
        'pictures_about' => ':scanned of :total scanned · :pending waiting · :failed unreadable',
        'no_pictures' => 'No pictures match.',
        'search' => 'Search by product name or number',
        'stopped' => 'Stopped: :reason',
        'hosts' => 'The pictures are on:',
        'cols' => [
            'when' => 'When',
            'what' => 'What',
            'status' => 'Status',
            'result' => 'Result',
            'took' => 'Took',
            'picture' => 'Picture',
            'product' => 'Product',
            'state' => 'State',
            'scanned_at' => 'Scanned',
        ],
        'kinds' => [
            'catalog_syncer' => 'Catalogue sync',
            'retrieval_indexer' => 'Text index',
            'retrieval_image_indexer' => 'Picture scan',
        ],
        'trigger' => [
            'manual' => 'Pressed in the panel',
            'schedule' => 'Nightly run',
            'webhook' => 'Change in the store',
            'system' => 'Automatic',
        ],
        'status' => [
            'running' => 'Running',
            'succeeded' => 'Succeeded',
            'failed' => 'Failed',
            'cut_off' => 'Cut off',
        ],
        'filter' => [
            'all' => 'All',
            'scanned' => 'Scanned',
            'pending' => 'Waiting',
            'failed' => 'Unreadable',
        ],
        'state' => [
            'scanned' => 'Scanned',
            'pending' => 'Waiting',
            'failed' => 'Unreadable',
        ],
        'reasons' => [
            'unreachable' => 'Address does not answer',
            'not_image' => 'Not an image file',
            'too_big' => 'File too big',
            'other' => 'Other error',
        ],
    ],
];
