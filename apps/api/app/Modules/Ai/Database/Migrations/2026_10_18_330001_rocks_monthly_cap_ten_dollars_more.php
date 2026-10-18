<?php

use App\Core\Facades\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rocks and Gold's monthly allowance for model work, $10 more than it is, as Aviad asked (2026-10-18):
 * the default ($30) or the shop's own amount if one was set. An ordinary shop override of
 * ai.shop_monthly_cap_usd, changed from the configuration screen like any other.
 */
return new class extends Migration
{
    private const KEY = 'ai.shop_monthly_cap_usd';

    private const MORE = 10.0;

    public function up(): void
    {
        $this->move(self::MORE);
    }

    public function down(): void
    {
        $this->move(-self::MORE);
    }

    private function move(float $by): void
    {
        foreach ($this->shops() as $shop) {
            // Through the settings, so the amount is checked against the setting's bounds and every cache forgets the old one.
            Settings::set(self::KEY, max(0.0, (float) Settings::get(self::KEY, $shop) + $by), $shop);
        }
    }

    /** @return list<string> */
    private function shops(): array
    {
        return DB::table('shops')->where('slug', 'rocks-and-gold')
            ->orWhereIn('id', DB::table('store_connections')->where('site_url', 'like', '%rocksandgold%')->select('shop_id'))
            ->pluck('id')->map(fn ($id): string => (string) $id)->unique()->values()->all();
    }
};
