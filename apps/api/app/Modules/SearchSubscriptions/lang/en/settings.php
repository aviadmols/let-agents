<?php

return [
    'placement' => ['label' => 'Where the offer shows', 'description' => 'At the end of every search\'s results, or only when there is no exact match.'],
    'send_day' => ['label' => 'Send day', 'description' => 'The day of the week the email goes out, in the store\'s time.'],
    'send_hour' => ['label' => 'Send hour', 'description' => 'The hour the email goes out, in the store\'s time.'],
    'logo_url' => ['label' => 'Logo for the email head', 'description' => 'An image address. With none, the store\'s name is shown.'],
    'reply_to' => ['label' => 'Reply-to address', 'description' => 'Where a shopper\'s reply to the weekly email goes. Empty: no reply-to address.'],
    'products_per_email' => ['label' => 'Products per email', 'description' => 'The most products in one email, best matches first.'],
    'searches_per_email' => ['label' => 'Searches per address', 'description' => 'The most active searches for one email address in a store.'],
    'mail_requests_per_second' => ['label' => 'Weekly email pace', 'description' => 'Requests a second to the mail service for the weekly emails, each up to 100 emails. Within the mail account\'s own limit, with room left for the other emails.'],
    'keep_days' => ['label' => 'Subscription length', 'description' => 'After how many days a subscription stops, unless the shopper keeps it.'],
    'remind_days_before' => ['label' => 'Reminder before the end', 'description' => 'How many days before the end the "Still interested?" email goes out.'],
    'unopened_stop' => ['label' => 'Stop after unopened emails', 'description' => 'After how many unopened emails in a row a subscription stops.'],
    'bulk_import_skip' => ['label' => 'Skip a bulk import', 'description' => 'Products added together in this number within an hour are not "new" to shoppers. 0: no skipping.'],
    'signups_per_minute' => ['label' => 'Sign-ups per minute', 'description' => 'A limit per IP address against bots.'],
    'forget_after_days' => ['label' => 'Delete after removal', 'description' => 'How many days after a store removed Lets its subscriptions are deleted.'],
    'plans' => ['label' => 'Plans that include it', 'description' => 'Plan codes, separated by commas (growth, pro). A store on any other plan, or on none, gets no offer and no email.'],
    'blocked_words' => ['label' => 'Words a search may not hold', 'description' => 'Separated by commas: offensive words, on top of the built-in ones. A search with one of them cannot be subscribed to.'],
];
