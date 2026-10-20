<?php

return [
    'picker_provider' => ['label' => 'Picker provider', 'description' => 'The model that chooses which products belong to the set.'],
    'picker_model' => ['label' => 'Picker model', 'description' => 'The model\'s name at the provider.'],
    'picker_input_usd_per_million' => ['label' => 'Picker input price', 'description' => 'Dollars per million tokens, for the cost.'],
    'picker_output_usd_per_million' => ['label' => 'Picker output price', 'description' => 'Dollars per million tokens, for the cost.'],
    'checker_provider' => ['label' => 'Checker provider', 'description' => 'The model that checks the choice. Must be of another family than the picker.'],
    'checker_model' => ['label' => 'Checker model', 'description' => 'The model\'s name at the provider.'],
    'checker_input_usd_per_million' => ['label' => 'Checker input price', 'description' => 'Dollars per million tokens, for the cost.'],
    'checker_output_usd_per_million' => ['label' => 'Checker output price', 'description' => 'Dollars per million tokens, for the cost.'],
    'max_output_tokens' => ['label' => 'Longest model reply', 'description' => 'Tokens per call.'],
    'max_candidates' => ['label' => 'Products to choose from', 'description' => 'How many products at most code sends the model to choose from.'],
    'candidate_chars' => ['label' => 'Text per product', 'description' => 'How many characters of each product\'s description the model reads.'],
    'max_set' => ['label' => 'Products in a set', 'description' => 'How many products at most a shopper is shown in one set.'],
    'whole_catalog_max' => ['label' => 'A small store', 'description' => 'In a store with up to this many products the model chooses from all of them, not only from what the words found. So a product whose description does not say the word is found too ("Dracula" for one who asked for horror books).'],
    'whole_catalog_chars' => ['label' => 'Text per product in a small store', 'description' => 'How many characters of each product\'s description the model reads when it sees the whole store.'],
    'focus_over' => ['label' => 'A large set', 'description' => 'Over how many products in a set the shopper is offered sub-categories to narrow it by.'],
    'generic_percent' => ['label' => 'A general word', 'description' => 'A word in the title or category of at least this share of the products ("books" in a bookshop) sets none apart and is not searched.'],
];
