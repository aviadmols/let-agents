<?php

return [
    'on_products' => [
        'label' => 'Free questions on product pages',
        'description' => 'A shopper asks about the product and gets an answer from its information only. Questions answered before come back from memory without a model.',
    ],
    'on_content' => [
        'label' => 'Free questions on article pages',
        'description' => 'A reader asks about the guide and is answered from the guide itself, including "sum this up for me". Questions already answered come back from memory with no model.',
    ],
    'trade_terms' => [
        'label' => 'A bank of trade terms for every store',
        'description' => 'In the scan and every night, code gathers from the store\'s data the words that may be terms of its trade (codes in product titles, materials, values the products are sold by, and what shoppers asked the meaning of). One model writes a short explanation for each, and a model of another family keeps only what is correct. The assistant uses an explanation only when the question names its term. A word checked once is never sent again.',
    ],
    'read_voice' => [
        'label' => 'Reading the store’s tone in its scan',
        'description' => 'In the store’s scan, a small model reads how the store writes to its customers (its product descriptions and posts) and sets the tone the assistant answers in. A tone the store chose itself never changes.',
    ],
    'on_search' => [
        'label' => 'Questions in the search box',
        'description' => 'A shopper who types a question in the search and presses Enter gets an answer from the site pages, with the pages it came from. A saved answer shows while typing. No model is asked while typing.',
    ],
    'search_whatsapp' => [
        'label' => 'WhatsApp when search has no match',
        'description' => 'When the assistant finds no answer or product that exactly fits a question from the search box, a message to contact the shop on WhatsApp shows, with the question already written.',
    ],
    'ask_review' => [
        'label' => 'Daily report on questions in the search',
        'description' => 'Every morning: a check of yesterday\'s questions and of what the assistant answered, a score for each question and for the day, and what to improve.',
    ],
    'knowledge' => [
        'label' => 'What the store says about itself',
        'description' => 'What every product page repeats (returns, warranty, payments, shipping), what the team wrote, and the team\'s answers: the assistant reads them before any other page. Read by code, no model.',
    ],
    'second_look' => [
        'label' => 'A wider search when no answer was found',
        'description' => 'When the assistant found no answer in the pages the question\'s own words brought, it asks the question in other ways (its parts, its topic as stores write it, and if needed up to three wordings from a small model), searches with each and tries again. So "what is the returns policy?" finds the returns page too.',
    ],
    'site_scope' => [
        'label' => 'Check that a search box question is about the store',
        'description' => 'Before an answer is written, a small model decides whether the question is about the store, its products, services, policies or pages, and whether it asks for something harmful. A sensitive subject the store sells is still the store\'s. About $0.0002 a new question.',
    ],
    'goes_with' => [
        'label' => 'Products that go with a product',
        'description' => 'A shopper on a product page who asks for a product of another kind to go with it ("is there a ring to match this bracelet?", "matching earrings?"), or names a product by its SKU: the assistant is given the store\'s products of that kind in stock, those sharing most with it (metal, colour, style, collection, motif) first, and chooses among them. If the store has none in stock, it says none was found, never offering another kind instead.',
    ],
    'answer_searches' => [
        'label' => 'Search buttons under an answer about products',
        'description' => 'Under an answer that shows products, a button for each search that holds what was asked, with its filters already chosen ("Rings · Diamond · White gold · up to ₪3,000 (12)"), so the shopper sees every matching product and narrows them. For a product to go with another, the searches are by what the products share with it. At most three, each only when it shows at least two products, more than the answer did. No button under a question about shipping, hours or a policy.',
    ],
    'team_drafts' => [
        'label' => 'AI help writing answers to shopper questions',
        'description' => 'On the shopper questions screen: a button that drafts an answer from the product information and the store pages, or from a few words the team typed. Nothing is published without the team.',
    ],
    'visitor_limit' => [
        'label' => 'Limit each shopper\'s questions',
        'description' => 'Each shopper and each network address asks up to the daily number set. Off: no limit per shopper; the whole store\'s daily limit stays.',
    ],
    'prepare_answers' => [
        'label' => 'Ready answers to questions about the store',
        'description' => 'After every sync: the questions shoppers ask about the store (delivery, returns, warranty, payments…) are chosen in code from what the store\'s pages say, and answered ahead from the site\'s pages, checked by another model family. Only an answer that passed its check is shown to shoppers, without a model. Asked again only when the page it was written from changes.',
    ],
];
