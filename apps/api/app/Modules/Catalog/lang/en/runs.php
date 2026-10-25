<?php

return [
    'stock' => 'Stock check: :products products went out of stock or back.',
    'refreshed' => 'Prices and stock refreshed: :updated products changed, :removed taken off the store.',
    'choices_read' => 'Product pages: :read read, :changed changed; :with products ask for a choice (a length, a size) before the cart.',
    'changes_synced' => 'Changes from the store: :created new products, :updated changed, :removed taken off the store.',
    'site_pages' => 'Site pages: :new new, :changed changed, :removed left the site.',
    'site_pages_none' => 'Site pages: the store has no public address to read from.',
    'synced' => ':products {products:product/products} read: :created new, :updated changed, :removed no longer published. :categories {categories:category/categories}, :content {content:article/articles}.',
    'synced_capped' => 'Stopped at the limit of :limit products: :products read, :created new, :updated changed. Nothing was marked removed. Raise the limit in Settings to read the rest.',
    'no_connection' => 'This shop has no store connection. Add one in Store connections.',
    'pages_sampled' => ':read product pages read, at least one of every kind (:kinds kinds): versions linked as products of their own in :siblings, a subscription in :subscriptions, choices only on the page: :fields.',
    // A storefront closed to visitors (StorefrontLock): what each reading of its pages did instead.
    'locked' => [
        'sample' => 'Product pages: the store is closed to visitors (a password, maintenance or coming-soon page), so none were read. The products come from :platform; their pages are read once the store opens.',
        'site' => 'Site pages: the store is closed to visitors (a password, maintenance or coming-soon page), so its pages come from :platform for now. Cleared, having shown only the closed page: :cleared.',
        'choices' => 'Product pages: the store is closed to visitors, so the choices on them (a length, a size) are read once it opens.',
    ],
];
