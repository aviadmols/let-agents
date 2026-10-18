<?php

// What each agent does, for the agents screen. Plain words.
return [
    'answerer' => 'Answers a shopper\'s question about a product or guide from the page\'s information. A small model checks the question is about the page and the answer rests on it.',
    'site_answerer' => 'Only after Enter: writes an answer from the nearest passages of the site and cites them, and a model of another family checks every fact is in them.',
    'ask_reviewer' => 'Every morning reads yesterday\'s questions, what the assistant answered and picked and what shoppers did, scores each question and the day, and writes the shop owner what to improve.',
    'post_reader' => 'Every night reads the new or changed posts and articles, writes what they say about the store itself (a service, a tool on the site, how to order), and a model of another family keeps only what the post states plainly. The assistant answers from it too.',
    'team_helper' => 'When the team presses the button on the shopper questions screen: writes a draft from the product, the store pages and the words the team typed, leaving [check: ...] where nothing says. A model of the other family checks it, and code flags any number not in the information.',
    'answer_preparer' => 'After every sync: chooses in code the questions about the store its pages answer and asks them as a shopper would, through the writer and the checker. Only an answer that passed its check is kept and shown, with its sources.',
];
