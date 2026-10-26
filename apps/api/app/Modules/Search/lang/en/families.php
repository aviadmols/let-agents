<?php

return [
    'nav' => 'Word families',
    'title' => 'Word families',
    'subheading' => 'Words shoppers mean as one thing. A search for one also finds the products that say another.',
    'tabs' => [
        'waiting' => 'Waiting for you',
        'approved' => 'Approved',
        'open' => 'Searched, no family',
    ],
    'new' => [
        'title' => 'New family',
        'note' => 'A few words, separated by commas.',
        'placeholder' => 'hoodie, hooded sweatshirt, קפוצון',
        'button' => 'Create',
    ],
    'waiting' => [
        'help' => 'Families we found in the store\'s words. They already work in search. Approve them, fix them, or mark them as wrong.',
        'none' => 'No families are waiting for you.',
    ],
    'approved' => [
        'help' => 'Families you approved, fixed or created. They stay as they are, even when products change.',
        'none' => 'You have not approved any families yet.',
    ],
    'open' => [
        'help' => 'Words shoppers searched in the last :days days that have no family. Add them to a family, or hide them.',
        'searches' => '{1} one search|[2,*] :n searches',
        'empty' => '{1} once with no results|[2,*] :n times with no results',
        'near' => 'Maybe with:',
        'join_pick' => 'Add to a family…',
        'join_label' => 'Family for :word',
        'join' => 'Add',
        'hide' => 'Hide',
        'none' => 'Everything searched lately already belongs to a family.',
    ],
    'add' => 'word',
    'add_placeholder' => 'Word to add',
    'drop' => 'Take :word out of the family',
    'approve' => 'Approve',
    'reject' => 'Not right',
    'remove' => 'Delete family',
    'remove_confirm' => 'Delete this family? Its words will not be joined together again.',
    'too_few' => 'A family needs at least two words.',
    'done' => [
        'saved' => 'Saved',
        'rejected' => 'This family will not be offered again',
        'emptied' => 'One word was left, so the family was removed',
        'hidden' => 'The word is hidden',
        'updating' => 'Search on the site updates within a minute.',
    ],
];
