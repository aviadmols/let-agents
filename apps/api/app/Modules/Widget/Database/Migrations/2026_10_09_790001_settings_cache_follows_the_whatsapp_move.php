<?php

use App\Core\Facades\Settings;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Migrations\Migration;

/**
 * The move of the shared WhatsApp number to the plugin stores was first written straight to the
 * table, past the settings' own cache, so a server that had the old map kept serving it. This
 * does the same move once more through the settings themselves (nothing to do where it is done)
 * and clears the shared value, which makes the cache follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        $shared = Settings::overrideFor('widget.whatsapp_number');

        if (is_string($shared) && trim($shared) !== '') {
            foreach (Shop::query()->where('platform', ShopPlatform::WooCommerce->value)->pluck('id') as $shopId) {
                if (Settings::overrideFor('widget.whatsapp_number', (string) $shopId) === null) {
                    Settings::set('widget.whatsapp_number', $shared, (string) $shopId);
                }
            }
        }

        Settings::clear('widget.whatsapp_number');
    }

    public function down(): void {}
};
