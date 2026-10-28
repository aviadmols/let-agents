<?php

// The email to the store's team about a new lead (LeadReceivedMail).
return [
    'subject' => 'A new lead from :shop',
    'title' => 'Somebody left their details',
    'channel' => ['phone' => 'Phone', 'email' => 'Email'],
    'question' => 'What they asked',
    'page' => 'Where',
    'at' => 'Left on :at',
    'open' => 'Open the leads',
    'why' => 'You get this because you are on the store\'s team. It can be turned off in Settings, Leaving details.',
];
