<?php

use App\Core\Facades\Features;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Migrations\Migration;

/**
 * Photo search is part of what a Shopify store gets, from the first day. The stores installed
 * before that was so get it now, as a new install does.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Shop::query()->where('platform', ShopPlatform::Shopify->value)->pluck('id') as $shopId) {
            Features::override('search.photos', true, (string) $shopId);
            Features::override('retrieval.image_index', true, (string) $shopId);
        }
    }

    public function down(): void {}
};
