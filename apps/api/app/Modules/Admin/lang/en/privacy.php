<?php

return [
    'title' => 'Privacy and account',
    'owners_only' => 'Only the account owners can do this',
    'back' => 'Back',

    'export' => [
        'title' => 'Download the shop\'s data',
        'help' => 'A ZIP file with everything kept about the shop: its settings, team, catalogue, questions and answers, searches, visits, and the details shoppers left (leads and saved lists). Each table opens in Excel.',
        'button' => 'Download the file',
        'note' => 'The file holds shoppers\' personal details. Keep it somewhere safe.',
        'readme' => "The data of :shop at Lets, as of :date.\n\n"
            ."shop.csv: the shop. team.csv: its people and roles. settings.csv: the settings chosen for it.\n"
            ."In the folders: a table for each kind of data, by area (Catalog, Leads, Shortlist: saved lists, Search, Assistant: questions and answers, Analytics: visits and orders, and more).\n\n"
            ."It holds shoppers' personal details (phone, email, name). Keep it somewhere safe, and delete it when no longer needed.\n"
            ."Not included: passwords and access keys, the search's own index (rebuilt from the catalogue) and the raw stream of page events.\n",
    ],

    'plan' => [
        'title' => 'Cancel the plan',
        'active' => 'Cancelling stops billing at once, and the search leaves the site. All the data is kept, and you can come back at any time.',
        'ended' => 'The plan was cancelled on :date. The search is off the site, and all the data is kept.',
        'again' => 'To come back, choose a plan on "Connection and plan", or write to us.',
        'button' => 'Cancel the plan',
        'confirm' => 'Cancel the plan now? The search leaves the site at once.',
        'yes' => 'Yes, cancel',
        'done' => 'The plan was cancelled. The search is off the site, and the data is kept.',
        'failed' => 'We could not cancel right now. Try again in a few minutes, or write to us.',
    ],

    'delete' => [
        'title' => 'Delete the shop and all its data',
        'help' => 'The shop, its catalogue, questions, leads, statistics and settings will be deleted for good. The search leaves the site at once and the plan is cancelled. The deletion itself happens after :days days, and until then you can change your mind.',
        'tip_export' => 'Download the shop\'s data first.',
        'type_name' => 'To confirm, type the shop\'s name: :name',
        'button' => 'Delete the shop',
        'confirm_button' => 'Delete the shop',
        'name_wrong' => 'That is not the shop\'s name.',
        'asked' => 'The shop will be deleted with all its data on :date. We sent you an email.',
        'pending' => 'The shop will be deleted on :date, with all its data. Until then you can keep it.',
        'pending_by' => 'Asked by: :name',
        'keep' => 'Keep the shop',
        'kept' => 'The shop stays. Its plan is still cancelled: to bring the search back to the site, choose a plan again.',
    ],

    'me' => [
        'title' => 'Delete my account',
        'help' => 'Your account will be deleted and you will leave every shop. The shops themselves keep working.',
        'type_phrase' => 'To confirm, type exactly:',
        'button' => 'Delete my account',
        'confirm_button' => 'Delete my account',
        'phrase_wrong' => 'Type exactly :phrase, in capital letters.',
        'alone' => 'You are the only owner of :shops. First make someone else an owner on the users page, or delete the shop.',
        'operator' => 'A Lets team account is not deleted from here.',
    ],

    'mail' => [
        'asked' => [
            'subject' => ':shop will be deleted in a few days',
            'title' => ':shop will be deleted',
            'lead' => ':name asked for :shop to be deleted from Lets. The search is already off the site and the plan is cancelled. On :date the shop will be deleted with all its data.',
            'keep_lead' => 'Changed your mind? Until :date you can keep the shop, and nothing will be deleted.',
            'button' => 'Keep the shop',
            'note' => 'If you asked for the deletion, there is nothing to do.',
        ],
        'kept' => [
            'subject' => ':shop will not be deleted',
            'title' => ':shop stays',
            'lead' => ':name called off the deletion of :shop. Nothing was deleted.',
            'button' => 'To the shop\'s panel',
            'note' => 'Its plan is still cancelled. To bring the search back to the site, choose a plan again on "Connection and plan".',
        ],
    ],

    'team' => [
        'plan_ended' => 'The owner of :shop cancelled its plan',
        'deletion_asked' => 'The owner of :shop asked for it to be deleted',
        'kept' => 'The owner of :shop called off its deletion',
        'deleted' => ':shop was deleted with all its data',
        'by' => 'Asked by: :name',
        'due' => 'Deletion date: :date',
        'by_hand' => 'No platform bills this shop: stop the billing by hand.',
        'plan_asked' => 'The owner of :shop asks to change plan',
        'plan' => 'Plan asked for: :plan',
    ],
];
