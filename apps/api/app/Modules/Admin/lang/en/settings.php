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
];
