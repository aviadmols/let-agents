<?php

return [
    'answer_model' => [
        'label' => 'Model that writes answers',
        'description' => 'An OpenAI model ID from the key\'s list, such as gpt-5.4-mini.',
    ],
    'scope_model' => [
        'label' => 'Model that checks a question is about the product',
        'description' => 'A small, cheap model. A question that is not about the product never reaches the writing model.',
    ],
    'reasoning_effort' => [
        'label' => 'How much the model thinks before answering',
        'description' => 'More thinking gives more careful answers, but slower and more expensive ones.',
        'options' => [
            'model_default' => 'The model\'s default',
            'minimal' => 'Minimal',
            'low' => 'Low',
            'medium' => 'Medium',
        ],
    ],
    'context_mode' => [
        'label' => 'What the writing model is given',
        'description' => 'Full: every product shown and every passage found, as before. Dynamic: the products and passages the question needs, with the fields its kind of question asks about.',
        'options' => [
            'full' => 'Full',
            'dynamic' => 'Dynamic',
        ],
    ],
    'context_min_products' => [
        'label' => 'Products always given',
        'description' => 'The first products the shopper was shown are always given, whatever their score: the safety floor against losing the right answer.',
    ],
    'context_keep_ratio' => [
        'label' => 'How close to the best product a product must score',
        'description' => 'Dynamic only. 0.5: at least half the best score.',
    ],
    'context_open_keep_ratio' => [
        'label' => 'The same, for open questions and recommendations',
        'description' => 'Dynamic only. Lower, so a recommendation sees more products.',
    ],
    'answer_input_usd_per_million' => [
        'label' => 'Writing model input price per million tokens',
        'description' => 'From OpenAI\'s price list. Used for the estimate before each call and for the recorded cost. Better too high than too low.',
    ],
    'answer_output_usd_per_million' => [
        'label' => 'Writing model output price per million tokens',
        'description' => 'From OpenAI\'s price list. Reasoning tokens count as output.',
    ],
    'scope_input_usd_per_million' => [
        'label' => 'Checking model input price per million tokens',
        'description' => 'From OpenAI\'s price list.',
    ],
    'scope_output_usd_per_million' => [
        'label' => 'Checking model output price per million tokens',
        'description' => 'From OpenAI\'s price list.',
    ],
    'answer_max_output_tokens' => [
        'label' => 'Answer token cap',
        'description' => 'Includes reasoning tokens. Too low can leave the answer empty.',
    ],
    'max_question_chars' => [
        'label' => 'Longest question',
        'description' => 'Longer questions are cut.',
    ],
    'max_text_chars' => [
        'label' => 'Product description sent with a question',
        'description' => 'Answers are written from checked facts, highlights and the description up to this length.',
    ],
    'questions_per_visitor_per_day' => [
        'label' => 'New questions per visitor per day',
        'description' => 'A question answered before does not count.',
    ],
    'questions_per_address_per_day' => [
        'label' => 'New questions per network address per day',
        'description' => 'Stops a bot that makes up a new identity for every question. An office or home with several people shares one address.',
    ],
    'questions_per_shop_per_day' => [
        'label' => 'New questions per shop per day',
        'description' => 'Past this, shoppers are pointed to the store team until tomorrow.',
    ],
    'asks_per_minute' => [
        'label' => 'Question requests per minute, per address',
        'description' => 'Flood protection, including questions answered from memory.',
    ],
    'suggested_questions' => [
        'label' => 'Suggested questions in the box',
        'description' => 'The questions asked most about the product first, then common ones.',
    ],
    'site_check_provider' => [
        'label' => 'Provider of the model that checks search answers',
        'description' => 'anthropic by default. Must be from another family than the writing model.',
    ],
    'site_check_model' => [
        'label' => 'The model that checks search answers',
        'description' => 'For example claude-haiku-5-5.',
    ],
    'site_check_max_output_tokens' => [
        'label' => 'Longest check',
        'description' => 'In tokens, the model\'s thinking included: Haiku 5.5 thinks before it answers, and a check cut short fails the question.',
    ],
    'site_check_input_usd_per_million' => [
        'label' => 'Input price of the checker, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'site_check_output_usd_per_million' => [
        'label' => 'Output price of the checker, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'site_passages' => [
        'label' => 'Pieces of the site per question',
        'description' => 'The nearest by meaning. More pieces, more tokens.',
    ],
    'site_min_similarity' => [
        'label' => 'Minimum nearness of a piece to the question',
        'description' => 'Below this a piece is not sent, and with no piece no writing model is asked.',
    ],
    'site_retry_days' => [
        'label' => '"Not found" is asked again after',
        'description' => 'Days. So a page the store added since makes it into the answer.',
    ],
    'answer_provider' => [
        'label' => 'Provider of the answering model',
        'description' => 'openai by default.',
    ],
    'scope_provider' => [
        'label' => 'Provider of the small checking model',
        'description' => 'Checks the question is about the page and the answer rests on it. Best from another family than the answering model.',
    ],
    'search_whatsapp_message' => [
        'label' => 'The message WhatsApp opens with from the search',
        'description' => 'Empty for the usual message. :question becomes the shopper\'s question.',
    ],
    'review_provider' => [
        'label' => 'Provider of the model that reviews search questions',
        'description' => 'Another family than the answering model.',
    ],
    'review_model' => [
        'label' => 'The model that reviews search questions',
        'description' => 'For example claude-haiku-4-5.',
    ],
    'review_input_usd_per_million' => [
        'label' => 'Input price of the daily reviewer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'review_output_usd_per_million' => [
        'label' => 'Output price of the daily reviewer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'review_max_output_tokens' => [
        'label' => 'Token cap for the daily report',
        'description' => 'Including thinking.',
    ],
    'review_max_asks' => [
        'label' => 'How many questions are reviewed a day',
        'description' => 'The first of that day.',
    ],
    'knowledge_pages' => [
        'label' => 'Product pages read',
        'description' => 'How many product pages, spread over the catalogue, are read to find what they all repeat.',
    ],
    'knowledge_share' => [
        'label' => 'Share of pages that must repeat a sentence',
        'description' => 'The share of pages a sentence must appear on to count as information about the store. 0.6 = sixty percent.',
    ],
];
