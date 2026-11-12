<?php

// The Store knowledge screen (the designer's redesign, 11.2026).
return [
    'nav' => 'Store knowledge',
    'title' => 'Store knowledge',
    'lede' => 'Everything Lets knows about your store, and where it learned it. What your pages don’t say, you add here.',

    'teach' => [
        'title' => 'Teach Lets something',
        'text' => 'One sentence is enough. The assistant uses it in answers from the very next search.',
        'label' => 'Something your pages don’t say',
        'placeholder' => 'For example: we gift wrap for free, just ask at checkout',
        'add' => 'Add',
        'examples' => ['Shipping to Eilat takes 3–5 days', 'All clothes are pre-washed', 'Swimwear is final sale'],
    ],

    'tabs' => [
        'label' => 'Knowledge',
        'pages' => 'Pages and policies',
        'products' => 'Products',
        'notes' => 'What you added',
        'rules' => 'Rules',
    ],

    'pages' => [
        'answers' => '{0} —|{1} <b>1</b>answer|[2,*] <b>:n</b>answers',
        'read' => 'Read',
        'waiting' => 'Reading soon',
        'not_read' => 'Not read yet',
        'more' => '{1} And one more page|[2,*] And :n more pages',
        'none' => 'No pages found on your site yet. They’re read with the next scan.',
        'posts' => 'Posts',
        'post_facts' => '{0} Nothing about the store in it|{1} One thing learned|[2,*] :n things learned',
        'post_link' => 'A post that wasn’t read? Paste its link',
        'post_add' => 'Read it',
        'facts_open' => 'What it says',
        'facts_close' => 'Close',
        'more_posts' => '{1} And one more post|[2,*] And :n more posts',
        'found' => 'Said on your product pages and by your team',
        'more_found' => '{1} And one more|[2,*] And :n more',
        'remove' => 'Remove',
    ],

    'products' => [
        'find' => 'Find a product',
        'none' => 'No products yet. They show up here after the first scan.',
        'missing_dot' => 'Missing a detail',
        'understood' => 'What we understood',
        'from' => [
            'title' => 'from the title',
            'page' => 'from the page',
            'photo' => 'from the photo',
            'team' => 'added by you',
            'understood' => 'understood',
        ],
        'missing' => 'missing',
        'nothing' => 'Nothing understood about it yet. It’s read with the next scan.',
        'saw' => 'What we saw in the photo',
        'see' => 'See it on the site',
        'wrong' => 'Something wrong?',
    ],

    'notes' => [
        'zero' => 'Nothing added yet',
        'zero_text' => 'What your pages don’t say, like gift wrap or pickup, you write in the line above.',
        'added' => 'Added :when',
        'edit' => 'Edit',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'remove' => 'Remove',
        'remove_confirm' => 'Remove this line? The assistant stops using it.',
    ],

    // pages and posts of the site the team stopped from the "why" of an answer, each brought back from here
    'ignored' => [
        'list' => 'Pages the assistant does not use',
        'note' => 'You stopped them from "Why this answer". The assistant does not answer from them or from what was read from them.',
        'stopped' => 'Stopped :when',
        'back' => 'Bring back',
        'brought_back' => 'The page is back. The assistant will use it again.',
    ],

    // a long text the team pastes: found by what it says, the piece that answers brought to the answer
    'text' => [
        'open' => 'Have a longer text? Paste a paragraph or a document',
        'title_label' => 'Title (optional)',
        'title_placeholder' => 'For example: Diamond grades guide',
        'body_label' => 'The text',
        'body_placeholder' => 'Paste a paragraph, a guide, a policy or anything about your store here. Several paragraphs are fine.',
        'help' => 'The assistant doesn’t read the whole text for every answer: it searches it and brings only the part that answers the question.',
        'save' => 'Save the text',
        'cancel' => 'Cancel',
        'saved' => 'Text saved',
        'saved_body' => 'In a few minutes the assistant starts using it in answers.',
        'removed' => 'Text removed',
        'list' => 'Longer texts',
        'words' => '{1} One word|[2,*] :n words',
        'ready' => 'Used in answers',
        'preparing' => 'Getting ready, a few more minutes',
        'more' => 'Show all',
        'less' => 'Show less',
        'remove_confirm' => 'Remove this text? The assistant stops using it.',
        'too_long' => 'The text is too long. Up to :n characters, or split it into a few texts.',
    ],

    'rules' => [
        'sub' => 'Rules that apply to a whole group of products, without editing each one.',
        'zero' => 'No rules yet',
        'zero_text' => 'A rule applies to a whole group of products, like “all swimwear is final sale”.',
        'applies' => '{0} Applies to no product yet|{1} Applies to one product|[2,*] Applies to :n products',
        'status' => [
            'reading' => 'Reading',
            'proposed' => 'Waiting for your OK',
            'applied' => 'Active',
            'unclear' => 'Needs a hand',
            'failed' => 'Needs a hand',
            'rejected' => 'Not applied',
        ],
        'changes' => 'What changes',
    ],

    'knows' => [
        'title' => 'What Lets knows',
        'products' => 'Products',
        'photos' => 'Photos read',
        'pages' => 'Pages and posts',
        'added' => 'Things you added',
        'read' => 'Last read <b>:when</b>. A new product is read within minutes of going up.',
        'never' => 'Your store is read for the first time after it connects. A new product is read within minutes of going up.',
    ],

    'tip' => [
        'title' => 'Nothing changes on your site',
        'text' => 'Lets only reads your store. What you add here improves search and answers, and your pages stay exactly as they are.',
    ],
];
