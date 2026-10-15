<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Gueta Avigdor sells wood by length, chosen on the product page (Product Add-Ons), so its pages are
 * read for choices (catalog.page_choices), approved by Aviad on 2026-10-15: slowly, once a day.
 * An ordinary shop override; every other shop keeps the default, off.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->shops() as $shop) {
            DB::table('feature_overrides')->updateOrInsert(['key' => 'catalog.page_choices', 'scope' => (string) $shop], ['enabled' => true, 'updated_at' => now(), 'created_at' => now()]);
        }

        Cache::forget('core:feature_overrides');
    }

    public function down(): void
    {
        DB::table('feature_overrides')->where('key', 'catalog.page_choices')->whereIn('scope', array_map('strval', $this->shops()))->delete();
        Cache::forget('core:feature_overrides');
    }

    /** @return list<string> */
    private function shops(): array
    {
        return DB::table('shops')->where('slug', 'gueta-avigdor')
            ->orWhereIn('id', DB::table('store_connections')->where('site_url', 'like', '%guetaavigdor%')->select('shop_id'))
            ->pluck('id')->map(fn ($id): string => (string) $id)->unique()->values()->all();
    }
};
