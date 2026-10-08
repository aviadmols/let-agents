<?php

$positions = [
    'after' => 'After the element',
    'before' => 'Before the element',
    'prepend' => 'Inside the element, at the start',
    'append' => 'Inside the element, at the end',
];

return [
    'layout' => [
        'label' => 'Layout',
        'description' => 'Circles: a row of circles with the key sentence above them, each opening a panel. Assistant: one closed line that invites a click and opens into a conversation whose suggestions are the same parts. Tag bank: "you may also want to see" over a row of tags such as "materials for building a deck" or "deck screws", each opening its results; the tags are written at night by themselves, and their order is learned from what shoppers open and buy. Same content, no model call on page load.',
        'options' => ['circles' => 'Circles', 'chat' => 'Assistant (chat)', 'tags' => 'Tag bank'],
    ],
    'product_selector' => [
        'label' => 'Where to show it on product pages (CSS selector)',
        'description' => 'A class or any CSS selector from the store theme, such as .product-summary or form.cart. The first matching element is used.',
    ],
    'product_position' => [
        'label' => 'Position on product pages',
        'description' => 'Where to place the widget relative to that element.',
        'options' => $positions,
    ],
    'content_selector' => [
        'label' => 'Where to show it on articles (CSS selector)',
        'description' => 'A class or CSS selector in the article template, such as .entry-content.',
    ],
    'content_position' => [
        'label' => 'Position on articles',
        'description' => 'Where to place the widget relative to that element.',
        'options' => $positions,
    ],
    'floating_fallback' => [
        'label' => 'Float at the bottom when the element is missing',
        'description' => 'When no element matches, show a small button at the bottom of the screen instead of nothing.',
    ],
    'common_highlight_products' => [
        'label' => 'Highlight repeated across many products',
        'description' => 'A highlight whose quote appears on at least this many products, such as a standing note about size tolerance, is shown last and never becomes the key sentence.',
    ],
    'max_products' => [
        'label' => 'Products in each list',
        'description' => 'Most products shown under "goes well with it" and next to an article.',
    ],
    'page_cache_seconds' => [
        'label' => 'Keep page content for',
        'description' => 'How long the widget content for a page is kept before it is built again. Prices and stock are always live.',
    ],
    'page_requests_per_minute' => [
        'label' => 'Page content requests per minute, per visitor address',
        'description' => 'Beyond this, requests are refused until the minute ends.',
    ],
    'popularity_min_count' => [
        'label' => 'Smallest count shown',
        'description' => 'Below this many adds to the cart or orders, the number is not shown to shoppers. Two adds prove nothing.',
    ],
    'whatsapp_number' => [
        'label' => 'The shop WhatsApp number',
        'description' => 'International digits only, such as 972501234567. Without a number the strip is not shown.',
    ],
    'whatsapp_title' => [
        'label' => 'The sentence on the strip',
        'description' => 'For example: Want a video of this product? Empty shows the default sentence.',
    ],
    'whatsapp_button' => [
        'label' => 'The button text',
        'description' => 'For example: Chat on WhatsApp.',
    ],
    'whatsapp_message' => [
        'label' => 'The message WhatsApp opens with',
        'description' => 'The shopper sends it to the shop. :product becomes the product name and :url the page address.',
    ],
    'whatsapp_offline_note' => [
        'label' => 'The note outside opening hours',
        'description' => 'Shown under the button when nobody is there now. Empty shows the default.',
    ],
    'hours_sunday' => [
        'label' => 'Answering hours on Sunday',
        'description' => 'As 09:00-18:00. Empty means no answer that day. Edited on the "Answering hours" screen.',
    ],
    'hours_monday' => [
        'label' => 'Answering hours on Monday',
        'description' => 'As 09:00-18:00. Empty means no answer that day. Edited on the "Answering hours" screen.',
    ],
    'hours_tuesday' => [
        'label' => 'Answering hours on Tuesday',
        'description' => 'As 09:00-18:00. Empty means no answer that day. Edited on the "Answering hours" screen.',
    ],
    'hours_wednesday' => [
        'label' => 'Answering hours on Wednesday',
        'description' => 'As 09:00-18:00. Empty means no answer that day. Edited on the "Answering hours" screen.',
    ],
    'hours_thursday' => [
        'label' => 'Answering hours on Thursday',
        'description' => 'As 09:00-18:00. Empty means no answer that day. Edited on the "Answering hours" screen.',
    ],
    'hours_friday' => [
        'label' => 'Answering hours on Friday',
        'description' => 'As 09:00-18:00. Empty means no answer that day. Edited on the "Answering hours" screen.',
    ],
    'hours_saturday' => [
        'label' => 'Answering hours on Saturday',
        'description' => 'As 09:00-18:00. Empty means no answer that day. Edited on the "Answering hours" screen.',
    ],
    'whatsapp_timezone' => [
        'label' => 'The shop time zone',
        'description' => 'An IANA time zone, such as Asia/Jerusalem.',
    ],
    'whatsapp_when_offline' => [
        'label' => 'Outside opening hours',
        'description' => 'Show the strip with a note, or hide it.',
        'options' => ['show' => 'Show with a note', 'hide' => 'Hide'],
    ],
];
