<?php

return [
    'schema_rounds' => [
        'label' => 'Revision rounds for the vocabulary',
        'description' => 'When the reviewer says "needs work", the writer fixes what was removed, what is missing and the notes, and the reviewer checks again. 0: no revision.',
    ],
    'max_requests_per_task_file' => [
        'label' => 'Maximum requests in one task file',
        'description' => 'Larger jobs are split: create another file when the first is done.',
    ],
    'max_highlight_text_chars' => [
        'label' => 'Product text for writing highlights',
        'description' => 'Usage, installation and care advice usually comes at the end of a description, so writing highlights gets longer text. Headings are kept only with what they introduce.',
    ],
    'max_agent_text_chars' => [
        'label' => 'Product text sent to a model',
        'description' => 'Code shortens each product to this many characters, keeping lines with numbers and specs first. Measurements are found before shortening is applied to prose.',
    ],
    'max_products_per_article' => [
        'label' => 'Products shown next to an article',
        'description' => 'The most products chosen for one article or guide. Linked products come first, then products from the categories a checker approved.',
    ],
    'min_set_size' => [
        'label' => 'Smallest set for a superlative',
        'description' => 'No "lightest" or "cheapest" among fewer comparable products in stock than this.',
    ],
    'max_upload_kilobytes' => [
        'label' => 'Largest results file',
        'description' => 'Uploads above this size are refused.',
    ],
    'model_answer_tokens' => [
        'label' => 'Longest answer a model may write',
        'description' => 'How many tokens a model may spend on one product.',
    ],
    'nightly_model_requests' => [
        'label' => 'Products a model reads each night',
        'description' => 'How many products are sent to a model nightly. 0 turns model reading off. The monthly spend cap still wins.',
    ],
    'copurchase_window_days' => [
        'label' => 'How far back orders are read',
        'description' => 'The window what-is-bought-with-what is learned from. Longer gives more data; shorter follows the season.',
    ],
    'copurchase_min_orders' => [
        'label' => 'Orders needed before two products are linked',
        'description' => 'Below this it is a coincidence rather than a pattern, and no relation is made.',
    ],
    'reader_provider' => [
        'label' => 'Provider of the nightly product and article reader',
        'description' => 'openai by default.',
    ],
    'reader_model' => [
        'label' => 'The model that reads products and articles at night',
        'description' => 'Reads facts, key points and article matches.',
    ],
    'reader_input_usd_per_million' => [
        'label' => 'Input price of the reader, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'reader_output_usd_per_million' => [
        'label' => 'Output price of the reader, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'reviewer_provider' => [
        'label' => 'Provider of the model that checks what the reader was unsure of',
        'description' => 'Another family than the reader. anthropic by default.',
    ],
    'reviewer_model' => [
        'label' => 'Model that checks what the reader was unsure of',
        'description' => 'Says of each waiting detail: right, wrong, or unsure. Unsure goes to a person.',
    ],
    'reviewer_input_usd_per_million' => [
        'label' => 'Input price of the reviewer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'reviewer_output_usd_per_million' => [
        'label' => 'Output price of the reviewer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'nightly_review_requests' => [
        'label' => 'Products the reviewer checks a night',
        'description' => 'Products with details waiting for review. 0 turns the review off.',
    ],
    'picture_provider' => [
        'label' => 'Provider of the model that suggests a detail from the picture',
        'description' => 'On the missing details screen: suggests a value for a detail of looks (colour, stone, shape) from the product\'s picture. A person approves.',
    ],
    'picture_model' => [
        'label' => 'Model that suggests a detail from the picture',
        'description' => 'Picks only from the vocabulary\'s values, and says how sure it is.',
    ],
    'picture_input_usd_per_million' => [
        'label' => 'Input price of the picture model, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'picture_output_usd_per_million' => [
        'label' => 'Output price of the picture model, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'compare_provider' => [
        'label' => 'Provider of the model compared with the reader',
        'description' => 'In the model comparison on the gaps screen: the second model, against the night\'s reader.',
    ],
    'compare_model' => [
        'label' => 'Model compared with the reader',
        'description' => 'Reads the same products with the same prompt. Nothing it says is kept.',
    ],
    'compare_input_usd_per_million' => [
        'label' => 'Input price of the compared model, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'compare_output_usd_per_million' => [
        'label' => 'Output price of the compared model, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'compare_products' => [
        'label' => 'Products in a model comparison',
        'description' => 'The same products every time, shared between the vocabularies.',
    ],
    'audit_writer_provider' => [
        'label' => 'Provider of the model that proposes reading rules',
        'description' => 'openai by default.',
    ],
    'audit_writer_model' => [
        'label' => 'The model that proposes article reading rules',
        'description' => 'Proposes what code missed when reading articles.',
    ],
    'audit_writer_input_usd_per_million' => [
        'label' => 'Input price of the proposer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'audit_writer_output_usd_per_million' => [
        'label' => 'Output price of the proposer, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'audit_checker_provider' => [
        'label' => 'Provider of the model that checks article reading',
        'description' => 'Best from another family than the proposer.',
    ],
    'audit_checker_model' => [
        'label' => 'The model that checks article reading',
        'description' => 'Checks a sample of articles and the proposed rules.',
    ],
    'audit_checker_input_usd_per_million' => [
        'label' => 'Input price of the checker, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'audit_checker_output_usd_per_million' => [
        'label' => 'Output price of the checker, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'branch_min_products' => [
        'label' => 'Fewest products for a branch of its own',
        'description' => 'A category with fewer products is not given a vocabulary of its own.',
    ],
    'branch_split_percent' => [
        'label' => 'Share of the catalog that splits a category into its subcategories',
        'description' => 'A category holding more than this share of all products, with at least two big subcategories, is split into them.',
    ],
    'max_branches' => [
        'label' => 'Most branches in one scan',
        'description' => 'The biggest branches first. The rest of the catalog is read with the closest branch, or not at all.',
    ],
    'schema_pass_score' => [
        'label' => 'Score a vocabulary needs to pass',
        'description' => '0 to 100, from what code measures on a pilot and from the reviewing model. Below it the vocabulary waits for a person.',
    ],
    'web_researcher_provider' => [
        'label' => 'Provider that searches the web for a product detail',
        'description' => 'A family other than the verifier\'s. It must be able to search the web (Anthropic).',
    ],
    'web_researcher_model' => [
        'label' => 'Model that searches the web for a product detail',
        'description' => 'Reads the pages it finds and answers with a value and the sentences it rests on.',
    ],
    'web_researcher_input_usd_per_million' => [
        'label' => 'Input price of the web researcher, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'web_researcher_output_usd_per_million' => [
        'label' => 'Output price of the web researcher, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'web_verifier_provider' => [
        'label' => 'Provider that checks the web answer is about the same product',
        'description' => 'A family other than the researcher\'s.',
    ],
    'web_verifier_model' => [
        'label' => 'Model that checks the web answer is about the same product',
        'description' => 'Reads only the quoted sentences and the product, never the whole page.',
    ],
    'web_verifier_input_usd_per_million' => [
        'label' => 'Input price of the web verifier, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'web_verifier_output_usd_per_million' => [
        'label' => 'Output price of the web verifier, per million tokens',
        'description' => 'From the price list of the provider.',
    ],
    'web_search_usd_per_search' => [
        'label' => 'Price of one web search',
        'description' => 'The provider charges per search on top of the tokens.',
    ],
    'web_max_searches' => [
        'label' => 'Most searches for one product',
        'description' => 'One lookup asks for every missing detail of a product at once.',
    ],
    'web_checks_per_month' => [
        'label' => 'Web checks per shop per month',
        'description' => 'A product and its details are checked at most once in 90 days. 0 turns it off for the shop.',
    ],
    'web_manufacturer_domains' => [
        'label' => 'Manufacturer websites that count as enough on their own',
        'description' => 'Comma separated domains (makita.com, boschtools.com). Otherwise two independent sites must say the same.',
    ],
    'schema_sample' => [
        'label' => 'Products sampled to discover the vocabulary',
        'description' => 'From every kind the store sells, in turn. Code counts them; a model reads only the counts.',
    ],
    'schema_writer_provider' => [
        'label' => 'Provider that writes the vocabulary',
        'description' => 'A family other than the reviewer\'s.',
    ],
    'schema_writer_model' => [
        'label' => 'Model that writes the vocabulary',
        'description' => 'Writes product types and attributes from code\'s counts.',
    ],
    'schema_writer_input_usd_per_million' => [
        'label' => 'Writer input price (per million tokens)',
        'description' => 'For each run\'s cost.',
    ],
    'schema_writer_output_usd_per_million' => [
        'label' => 'Writer output price (per million tokens)',
        'description' => 'For each run\'s cost.',
    ],
    'schema_reviewer_provider' => [
        'label' => 'Provider that reviews the vocabulary',
        'description' => 'A family other than the writer\'s.',
    ],
    'schema_reviewer_model' => [
        'label' => 'Model that reviews the vocabulary',
        'description' => 'Drops what the counts do not support or whose patterns catch other things.',
    ],
    'schema_reviewer_input_usd_per_million' => [
        'label' => 'Reviewer input price (per million tokens)',
        'description' => 'For each run\'s cost.',
    ],
    'schema_reviewer_output_usd_per_million' => [
        'label' => 'Reviewer output price (per million tokens)',
        'description' => 'For each run\'s cost.',
    ],
];
