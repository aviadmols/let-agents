<?php

// An invitation to a shop's panel: the email, and the page its link opens (InvitationController).
return [
    'mail' => [
        'subject' => 'An invitation to :shop on Lets',
        'title' => 'You are invited to :shop',
        'by' => ':name invited you to the :shop panel on Lets.',
        'by_team' => 'You are invited to the :shop panel on Lets.',
        'as' => 'Your role: :role (:what).',
        'button' => 'Set a password and come in',
        'expires' => 'The link works until :date. If it expires, ask the account owner to send it again.',
    ],
    'page' => [
        'title' => 'Join :shop',
        'lead' => 'You come in as :role: :what. Choose a name and a password, and that is it.',
        'email' => 'Email',
        'name' => 'Name',
        'password' => 'Password',
        'password_again' => 'Again, to confirm',
        'submit' => 'Set a password and come in',
        'name_short' => 'The name is too short.',
        'password_short' => 'The password needs at least :min characters.',
        'password_mismatch' => 'The passwords do not match.',
        'joined_title' => 'You were added to :shop',
        'joined' => 'Your account exists already, and now it can open this store too, as :role. Sign in with your usual password.',
        'sign_in' => 'Sign in',
        'closed_title' => 'This link no longer works',
        'closed' => [
            'gone' => 'The link was not found. A newer invitation may have been sent: look for it in your email.',
            'used' => 'This invitation was used already. Sign in with your email and password.',
            'expired' => 'The invitation expired. Ask the account owner to send it again.',
        ],
    ],
];
