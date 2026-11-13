<?php

// Today, the shop owner's home (ShopOverview, the designer's redesign 11.2026). Written for a shop owner: what
// happened and what waits for them, never a model, a cost or a mechanism. Shoppers' words and product names are data.
return [
    'see' => 'See it as a shopper',

    'mood' => [
        'live' => 'Search is live.',
        'strong' => ['week' => 'Strong week.', 'month' => 'Strong month.', 'period' => 'A strong stretch.'],
        'steady' => ['week' => 'Steady week.', 'month' => 'Steady month.', 'period' => 'A steady stretch.'],
        'quiet' => ['week' => 'Quieter week.', 'month' => 'Quieter month.', 'period' => 'A quieter stretch.'],
    ],
    'lede' => [
        'first_day' => 'We’ve read your whole store. From the first shopper’s search, this page starts filling up.',
        'more' => ['week' => 'Search helped you sell more than last week.', 'month' => 'Search helped you sell more than last month.', 'period' => 'Search helped you sell more than in the period before.'],
        'less' => ['week' => 'Search sold a little less than last week.', 'month' => 'Search sold a little less than last month.', 'period' => 'Search sold a little less than in the period before.'],
        'same' => ['week' => 'Search sold as much as last week.', 'month' => 'Search sold as much as last month.', 'period' => 'Search sold as much as in the period before.'],
        'first' => ['week' => 'Search made its first sales this week.', 'month' => 'Search made its first sales this month.', 'period' => 'Search made its first sales in this period.'],
        'none' => ['week' => 'No sales through search yet this week, but shoppers are searching.', 'month' => 'No sales through search yet this month, but shoppers are searching.', 'period' => 'No sales through search in this period yet, but shoppers are searching.'],
        'waiting' => 'A few small things could make it even better.',
    ],

    'trend' => ['new' => 'New'],

    'hero' => [
        'label' => 'Sales through search · :period',
        'orders_started' => '{1} :n order started with a search.|[2,*] :n orders started with a search.',
        'no_orders' => 'No order started with a search yet.',
        'best' => ':day was the best day.',
        'best_with' => ':day was the best day, when shoppers went looking for',
        'adds' => '{1} added to cart from search|[0,*] added to cart from search',
        'orders' => '{1} order that started with a search|[0,*] orders that started with a search',
        'asked' => '{0} No searches or questions yet|{1} 1 search or question|[2,*] :n searches and questions',
        'found_pct' => ':pct% found it',
        'found_key' => 'Found what they wanted',
        'waiting_key' => 'Waiting for a fix',
    ],

    'chips' => [
        'required' => 'Required, with a date',
        'quick' => 'Quick win',
        'asked' => 'Asked often',
        'nice' => 'Nice to have',
        'bigger' => 'Bigger idea',
    ],
    'units' => [
        'searches' => '{1} 1 search|[0,*] :n searches',
        'times' => '{1} once|{2} twice|[0,*] :n times',
        'products' => '{1} 1 product|[0,*] :n products',
    ],

    'todo' => [
        'title' => 'Waiting for you',
        'sub' => 'A few minutes, and next time search knows on its own.',
        'sub_none' => 'Nothing waits for you right now.',
        'sub_first' => 'Small fixes that improve search will show up here',
        'all' => '{1} The improvement|[2,*] All :n improvements',
        'later' => 'Not now',
        'all_clear' => 'All set. We’ll show you here what changed, tomorrow morning.',
        'gone' => 'This one was already taken care of.',
        'none' => 'Nothing to fix yet',
        'none_body' => 'When shoppers search for something and don’t find it, or ask something we can’t answer, we’ll suggest a one-tap fix here.',
    ],

    'live' => [
        'title' => 'Right now in your store',
        'sub' => 'What shoppers search, and what they get',
        'pause' => 'Pause updates',
        'resume' => 'Resume updates',
        'search' => 'Search what shoppers typed',
        'kinds' => ['search' => 'Search', 'ask' => 'Question', 'photo' => 'Photo search'],
        'none' => 'Waiting for the first shopper',
        'none_body' => 'You can leave this page open. It updates on its own.',
        'none_found' => 'No search like that yet',
        'none_found_body' => 'Try other words, or clear the search to see everything.',
        'whole_visit' => 'See the whole visit',
        'foot' => 'Updates as it happens · open a search to see its details',
    ],

    'want' => [
        'title' => ['week' => 'What your shoppers want this week', 'period' => 'What your shoppers want'],
        'sub' => 'Top searches, and what they found',
        'no_click' => 'Nothing opened yet',
        'none' => 'No searches in this period',
        'none_body' => 'The words shoppers search most will show up here, with the product they opened.',
    ],

    'proof' => [
        'title' => 'You fixed it. It works.',
        'sub' => 'What happened since you improved search',
        'approved' => 'Approved :date',
        'now_find' => 'now they find :what',
        'found' => '{1} search found it|[0,*] searches found it',
        'found_short' => '{1} 1 found it|[0,*] :n found it',
        'adds' => '{1} added to cart|[0,*] added to cart',
        'sales' => 'in sales',
        'none' => 'Nothing approved yet',
        'none_body' => 'When you approve a fix, you’ll see here what it did: how many found it, and what went to the cart.',
    ],

    'picks' => [
        'title' => 'What sold through search',
        'sub' => 'The products shoppers found in search and added to cart',
        'to_cart' => '{1} :n to cart|[0,*] :n to cart',
        'by_photo' => 'searched by photo',
        'from_photo' => 'From a photo',
        'none' => 'Nothing added from search in this period',
        'none_body' => 'Products shoppers add to the cart from search will show up here, with the words that found them.',
    ],

    'first' => [
        'label' => 'Day one',
        'title' => 'Smart search is live on your site.',
        'see' => 'See it like a shopper',
        'see_body' => 'Open your store and search for something. It will be the first one to show up here.',
        'open' => 'Open my store',
        'try' => 'Try a search from here',
        'try_body' => 'Without leaving the dashboard, on your own products.',
        'try_button' => 'Try a search',
    ],
    'setup' => [
        'connected' => 'Connected to :platform',
        'read' => 'Read :products and :photos',
        'opens' => 'Search opens from your theme’s search field',
        'first' => 'The first shopper’s search · usually within an hour',
    ],
    'platforms' => ['shopify' => 'Shopify', 'woocommerce' => 'WooCommerce', 'wordpress' => 'WordPress', 'site' => 'your site'],
    'n_products' => '{1} 1 product|[0,*] :n products',
    'n_photos' => '{1} 1 photo|[0,*] :n photos',

    'learned' => [
        'title' => 'What Lets already knows about your store',
        'sub' => 'From the first read. It updates on its own when you add products.',
        'products' => '{1} product|[0,*] products',
        'photos' => '{1} photo read|[0,*] photos read',
        'tags' => '{1} tag built|[0,*] tags built',
        'pages' => '{1} page or policy|[0,*] pages and policies',
    ],

    'mail' => [
        'when' => [
            'daily' => 'Every morning',
            'weekly' => 'Every Monday morning',
            'monthly' => 'On the 1st of every month',
        ],
        'text' => ':when we’ll email you a report on one page: what they searched, what sold, and what’s waiting for you.',
        'off' => 'The store report is off.',
        'settings' => 'Report settings',
        'to' => 'Email address to send to',
        'send' => 'Send now',
        'bad_email' => 'That doesn’t look like an email address.',
        'too_many' => 'A few reports were sent in the last hour. Try again a little later.',
        'see' => 'See an example',
    ],

    'back' => [
        'title' => 'Welcome back: your data is here',
        'text' => 'On :date you asked for this store to be deleted, and installed the app again before the deletion. So nothing was deleted: the catalogue, the questions, the searches and the settings are as you left them.',
        'fresh' => 'Want to start from scratch? Delete the store again on the privacy page, and install the app after the deletion.',
        'ok' => 'Got it',
        'privacy' => 'To the privacy page',
    ],
];
