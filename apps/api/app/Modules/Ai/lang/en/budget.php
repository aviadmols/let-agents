<?php

return [
    'cap_reached' => 'Model call blocked: :spent USD spent this month, this call is estimated at :estimate USD, and the monthly cap is :cap USD.',
    'shop_cap_reached' => 'Model call blocked: the shop spent :spent USD this month, this call is estimated at :estimate USD, and its monthly allowance is :cap USD.',

    // The strip at the top of every merchant screen. A share, never an amount.
    'strip' => [
        'title' => 'System use this month',
        'share' => ':percent% of the monthly allowance',
        'renews' => 'renews on :date',
        'date_format' => 'F j',
        'near' => 'Near the end of the allowance',
        'used_up' => 'This month\'s allowance is used up. The site keeps answering shoppers; night scans and suggestions wait for next month.',
        'used_up_all' => 'This month\'s allowance is used up. The site answers only from what is already saved until next month.',
        'set_by' => 'The allowance is set by Let\'s. To raise it, contact us.',
    ],

    // The same strip for the operator: amounts.
    'operator_strip' => [
        'shop' => ':shop this month',
        'spent' => '$:spent of $:cap (:percent%)',
        'every' => 'Every shop this month',
        'every_spent' => '$:spent of $:caps in shop allowances · overall cap $:global',
        'edit' => 'Shop allowances',
    ],

    // The card on the shop's home once the allowance is near its end. A share, never an amount.
    'home' => [
        'title' => ':percent% of the monthly allowance is used',
        'near' => 'At the end of it, night scans and suggestions wait for next month. To raise it, contact Let\'s.',
    ],

    // Operator screen: every shop's allowance.
    'screen' => [
        'title' => 'Shop allowances',
        'subheading' => 'What each shop spent on models this month against its allowance. The default is :default USD a month; the shop manager sees only a share.',
        'columns' => [
            'shop' => 'Shop',
            'used' => 'Used this month',
            'spent' => 'Spent',
            'cap' => 'Allowance',
            'calls' => 'Calls',
            'top' => 'Costliest',
            'state' => 'State',
        ],
        'own' => 'Custom',
        'state' => [
            'ok' => 'Fine',
            'near' => 'Near the allowance',
            'over' => 'Allowance used up',
            'idle' => 'No calls',
        ],
        'edit' => 'Allowance',
        'form' => [
            'title' => 'Monthly allowance · :shop',
            'cap' => 'Monthly allowance (USD)',
            'stops' => 'At the end of the allowance, stop what shoppers ask too',
            'reset' => 'Back to the default ($:default)',
            'save' => 'Save',
            'cancel' => 'Cancel',
            'saved' => ':shop\'s allowance saved: $:cap a month.',
            'cleared' => ':shop\'s allowance is back to the default.',
            'note' => 'The shop manager sees only the share used. The change applies at once.',
        ],
        'totals' => 'Total: $:spent of $:caps · :calls calls',
        'none' => 'No shops yet.',
    ],
];
