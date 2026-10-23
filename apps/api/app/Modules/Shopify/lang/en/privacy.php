<?php

return [
    'notice' => [
        'title' => 'A shopper asked for their data',
        'body' => 'On :date a shopper asked the store, through Shopify, for what Lets keeps about them. Download the file and send it to them by :due. Only the store\'s owners can download it.',
        'taken' => 'The file was downloaded on :date. Only the store\'s owners can download it again.',
        'source' => 'Shopify',
        'download' => 'Download the file',
    ],
    'mail' => [
        'subject' => 'A shopper asked :shop for their data',
        'title' => 'A shopper asked for their data',
        'lead' => 'On :date a shopper asked :shop, through Shopify, for what Lets keeps about them. The file is ready: download it and send it to them within 30 days.',
        'button' => 'Download the file',
        'note' => 'The file holds the shopper\'s details, so only the store\'s owners can download it, signed in to the panel. It is also in the panel\'s notices.',
    ],
];
