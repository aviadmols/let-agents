<?php

use App\Core\Settings\Models\SettingOverride;
use App\Core\Settings\OverrideScope;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Migrations\Migration;

/**
 * A WhatsApp number is one shop's own and is never inherited: a number saved for every shop at
 * once was the pilot store's, and it showed up on a new store's search. It moves to the stores
 * it was meant for (the ones on the plugin at the time) and the shared value goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $shared = SettingOverride::query()->where('key', 'widget.whatsapp_number')->where('scope', OverrideScope::GLOBAL)->first();

        if ($shared === null || trim((string) $shared->value) === '') {
            $shared?->delete();

            return;
        }

        foreach (Shop::query()->where('platform', ShopPlatform::WooCommerce->value)->pluck('id') as $shopId) {
            SettingOverride::query()->firstOrCreate(['key' => 'widget.whatsapp_number', 'scope' => (string) $shopId], ['value' => $shared->value]);
        }

        $shared->delete();
    }

    public function down(): void {}
};
