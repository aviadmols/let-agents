<script type="application/ld+json">{!! json_encode([
    '@'.'context' => 'https://schema.org',
    '@type' => 'ItemList',
    'name' => $name,
    'itemListElement' => array_map(fn (array $p, int $i): array => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => array_filter([
        '@type' => 'Product',
        'productID' => $p['id'] ?? null,
        'name' => $p['title'],
        'url' => $p['url'] ?? null,
        'image' => $p['image'] ?? null,
        'sku' => $p['sku'] ?? null,
        'brand' => isset($p['brand']) ? ['@type' => 'Brand', 'name' => $p['brand']] : null,
        'description' => $p['description'] ?? null,
        'additionalProperty' => array_map(fn (string $name, array $values): array => ['@type' => 'PropertyValue', 'name' => $name, 'value' => implode(', ', $values)], array_keys($p['details'] ?? []), $p['details'] ?? []),
        'offers' => isset($p['price']) ? array_filter(['@type' => 'Offer', 'price' => $p['price'], 'priceCurrency' => $p['currency'] ?? 'ILS', 'availability' => ($p['in_stock'] ?? false) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', 'url' => $p['buy_url'] ?? $p['url'] ?? null]) : null,
    ])], $products, array_keys($products)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
