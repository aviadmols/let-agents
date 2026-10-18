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
    'site_scope' => [
        'label' => 'Check that a search box question is about the store',
        'description' => 'Before an answer is written, a small model decides whether the question is about the store, its products, services, policies or pages, and whether it asks for something harmful. A sensitive subject the store sells is still the store\'s. About $0.0002 a new question.',
    ],
    'team_drafts' => [
        'label' => 'AI help writing answers to shopper questions',
        'description' => 'On the shopper questions screen: a button that drafts an answer from the product information and the store pages, or from a few words the team typed. Nothing is published without the team.',
    ],
    'prepare_answers' => [
        'label' => 'Ready answers to questions about the store',
        'description' => 'After every sync: the questions shoppers ask about the store (delivery, returns, warranty, payments…) are chosen in code from what the store\'s pages say, and answered ahead from the site\'s pages, checked by another model family. Only an answer that passed its check is shown to shoppers, without a model. Asked again only when the page it was written from changes.',
    ],
];
