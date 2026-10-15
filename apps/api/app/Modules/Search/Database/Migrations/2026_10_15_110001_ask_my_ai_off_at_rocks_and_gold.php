<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Ask my AI" stays on at Gueta Avigdor and is off at Rocks and Gold for now, as Aviad asked
 * (2026-10-15). An ordinary shop override: the operator turns it back on from the shop's features.
 */
return new class extends Migration
{
    public function up(): void
    {
        $shop = DB::table('shops')->where('slug', 'rocks-and-gold')->value('id');

        if ($shop === null) {
            return;
        }

        DB::table('feature_overrides')->updateOrInsert(['key' => 'search.ai_share', 'scope' => (string) $shop], ['enabled' => false, 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget('core:feature_overrides');
    }

    public function down(): void
    {
        $shop = DB::table('shops')->where('slug', 'rocks-and-gold')->value('id');

        if ($shop !== null) {
            DB::table('feature_overrides')->where('key', 'search.ai_share')->where('scope', (string) $shop)->delete();
            Cache::forget('core:feature_overrides');
        }
    }
};
