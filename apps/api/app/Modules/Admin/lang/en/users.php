<?php

return [
    'singular' => 'User',
    'plural' => 'Users',
    'sections' => [
        'account' => 'Account',
        'access' => 'Access',
    ],
    'fields' => [
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
        'password_keep' => 'Leave empty to keep the current password.',
        'locale' => 'Admin language',
        'locale_auto' => 'Automatic, from the browser',
        'is_operator' => 'System operator',
        'is_operator_help' => 'Full access to every shop, costs, models and settings.',
        'shops' => 'Shops',
        'shops_help' => 'The shops this user sees in the merchant panel.',
    ],
    'roles' => [
        'owner' => 'Owner',
        'member' => 'Team',
        'viewer' => 'Viewer',
    ],
    // What each role may do, as the users page and the invitation say it.
    'role_text' => [
        'owner' => 'Everything, the plan and the users included',
        'member' => 'Answers questions and requests, edits knowledge and tags',
        'viewer' => 'Sees analytics and reports only',
    ],
];
