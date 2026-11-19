<?php

// What each agent does, for the agents screen. Plain words.
return [
    'nightly_model' => 'At night: reads what code could not read alone (product facts, article reading and key points), and a model of another family checks what the reader was unsure of.',
    'picture_reader' => 'On request, from the missing details screen: looks at the product\'s picture and suggests a value for a detail of looks only (colour, stone, shape), from the vocabulary\'s values and with how sure it is. A person approves.',
    'auditor' => 'Once a week: checks a sample of articles, proposes reading rules for what code missed, and a second model reviews the proposal. It only proposes.',
    'schema_discovery' => 'On request: code counts a sample of products (fields, tags, measurements, repeated words), a model writes a vocabulary from the counts, a model of the other family reviews it, and the operator approves.',
    'web_lookup' => 'On request, from the missing details screen: a model that searches the web names sources with the sentence each rests on; code checks the page holds it; a model of the other family checks it is the same product; a person approves.',
    'colour_namer' => 'Each night, only when there are new names: the store\'s colour names code does not know and that have no swatch are placed among the shades. One model places, a model of another family checks, and only what both agree on goes in. Each name is asked once.',
    'expert_bank' => 'On request, an experiment only: code counts the store\'s words and fields, an advanced model writes product types, attributes and values from them, code measures every word on the products and removes what has no evidence, one correction round, and a model of another family scores every item and can only remove. The result is kept as a report; a person can make one that passed the vocabulary of the store, and undo it.',
    'goes_with_map' => 'On request, once per store: code lists the store\'s own kinds of products with how many each holds, one model writes which kinds a shopper needs together (a drill and its bits, a dress and a belt), a model of another family scores every pair and can only remove, and code keeps the pairs both kinds hold products for. The map is stored; the assistant reads it, without a model, when a shopper asks what else goes with a product. Asked again only when the kinds change.',
];
