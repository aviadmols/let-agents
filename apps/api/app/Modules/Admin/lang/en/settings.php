<?php

return [
    'default_locale' => [
        'label' => 'Default admin language',
        'description' => 'For users who have not picked a language and whose browser does not ask for a supported one.',
        'options' => [
            'he' => 'Hebrew',
            'en' => 'English',
        ],
    ],
    'invitation_days' => [
        'label' => 'User invitation lifetime',
        'description' => 'How many days the link in an invitation to a shop\'s panel works. Sending it again starts the count over.',
    ],
    'max_team_members' => [
        'label' => 'Users of the shop\'s panel',
        'description' => 'Invitations not yet accepted count too. The shop\'s plan may hold it lower.',
    ],
    'daily_reports_days' => [
        'label' => 'Days of daily reports',
        'description' => 'How many days back the daily reports show on the shop\'s home and on the daily reports page.',
    ],
    'shop_deletion_days' => [
        'label' => 'Days until a shop is deleted',
        'description' => 'When a shop\'s owner asks for it to be deleted, it turns off at once and is deleted with all its data after this many days. Until then they can keep it.',
    ],
    'report_frequency' => [
        'label' => 'How often the store report is sent',
        'description' => 'An email to the store\'s owners: what shoppers searched, asked and could not find. Owners choose it in Settings, Store report.',
        'options' => [
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'off' => 'Off',
        ],
    ],
    'report_sent' => [
        'label' => 'The last report sent',
        'description' => 'The period of the last report sent, so none is sent twice. Written by the system.',
    ],
    'deletion_called_off' => [
        'label' => 'Deletion called off by installing again',
        'description' => 'The day the store installed the app again while its deletion was pending: Today tells the owner the data is back. Written by the system.',
    ],
    'reports_sending' => [
        'label' => 'Sending store reports',
        'description' => '1 sends the store reports to the stores\' owners, as each chose. 0: no report is sent.',
    ],
];
