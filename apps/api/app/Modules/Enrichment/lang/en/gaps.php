<?php

return [
    'title' => 'Product detail gaps',
    'tryon_heading' => 'Try-on',
    'tryon_help' => 'Clothes, jewelry and whole pieces of furniture can be tried on a shopper or placed in a room. Decided in code from each branch\'s vocabulary; a branch whose vocabulary says nothing is not marked either way.',
    'tryon_counts' => ':yes products can be tried on, :no cannot, :undecided are not decided yet.',
    'importance' => ['required' => 'required', 'recommended' => 'recommended', 'optional' => 'optional'],
    'required_heading' => ':type: :attribute (:importance)',
    'required_share' => 'Known for :have of :of products. Missing for :missing.',
    'help' => 'A detail most products of a kind are known to have and a few are not: 37 of 41 drills say what is in the box, 4 do not. First: products whose own text does mention it, likely missed by the reading. You can send them to be read again by the model.',
    'none' => 'No gaps: for every kind of product, what most products are known to have, all do.',
    'heading' => ':type: :attribute',
    'share' => 'Known for :have of :of products. Missing for :missing.',
    'text_says' => 'The product\'s text mentions: :words',
    'waiting' => 'Waiting for review: :value',
    'reread' => 'Read again with the model',
    'reread_confirm' => 'Send :count products to be read again by the model? It costs what reading them did.',
    'sent' => ':count products were sent to be read again. The results show in a few minutes, in the scan log.',
];
