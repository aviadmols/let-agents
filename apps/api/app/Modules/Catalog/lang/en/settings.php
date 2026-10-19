<?php

return [
    'max_products' => [
        'label' => 'Maximum products per shop',
        'description' => 'A sync stops reading at this number and says so. Nothing is marked removed after a stopped sync.',
    ],
    'max_content_items' => [
        'label' => 'Maximum articles and guides per shop',
        'description' => 'Content beyond this number is not read.',
    ],
    'site_pages_per_run' => [
        'label' => 'Site pages read a night',
        'description' => 'At most. An unchanged page is kept as it is; the rest are read the next night.',
    ],
    'page_choices_per_run' => [
        'label' => 'Product pages read a night',
        'description' => 'At most. Pages never read, or whose product changed, first.',
    ],
    'page_choices_delay_ms' => [
        'label' => 'Pause between two pages (milliseconds)',
        'description' => 'So the site is not loaded.',
    ],
];
