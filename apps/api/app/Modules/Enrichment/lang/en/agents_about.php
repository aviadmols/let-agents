<?php

// What each agent does, for the agents screen. Plain words.
return [
    'nightly_model' => 'At night: reads what code could not read alone (product facts, article reading and key points), and a model of another family checks what the reader was unsure of.',
    'picture_reader' => 'On request, from the missing details screen: looks at the product\'s picture and suggests a value for a detail of looks only (colour, stone, shape), from the vocabulary\'s values and with how sure it is. A person approves.',
    'auditor' => 'Once a week: checks a sample of articles, proposes reading rules for what code missed, and a second model reviews the proposal. It only proposes.',
    'schema_discovery' => 'On request: code counts a sample of products (fields, tags, measurements, repeated words), a model writes a vocabulary from the counts, a model of the other family reviews it, and the operator approves.',
    'web_lookup' => 'On request, from the missing details screen: a model that searches the web names sources with the sentence each rests on; code checks the page holds it; a model of the other family checks it is the same product; a person approves.',
    'colour_namer' => 'Each night, only when there are new names: the store\'s colour names code does not know and that have no swatch are placed among the shades. One model places, a model of another family checks, and only what both agree on goes in. Each name is asked once.',
];
