<?php

namespace App\Modules\Tenancy\Contracts;

use App\Modules\Tenancy\Models\Shop;

/**
 * Opens a new shop with a normalized domain and a unique address slug: the operator's form, and a
 * store that installs the Shopify app.
 */
interface CreatesShops
{
    /** @param array{name: string, domain: string, platform: string, slug?: string|null, content_locale?: string, currency?: string, timezone?: string} $attributes */
    public function handle(array $attributes): Shop;
}
