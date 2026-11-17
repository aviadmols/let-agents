<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Want to look deeper with your own AI?" (search.ai_share) is off for a shop installed from now on, as Aviad asked
 * (2026-11-17): the feature's default is now off. Every shop already here keeps what it had: one that never chose is
 * given "on", as it was; one that chose keeps its choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $chose = DB::table('feature_overrides')->where('key', 'search.ai_share')->pluck('scope')->map(fn ($s): string => (string) $s)->all();
        foreach (DB::table('shops')->pluck('id') as $shop) {
            if (! in_array((string) $shop, $chose, true)) {
                DB::table('feature_overrides')->insert(['key' => 'search.ai_share', 'scope' => (string) $shop, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        Cache::forget('core:feature_overrides');
    }

    public function down(): void
    {
        // with the default back on, an "on" for a shop says nothing
        DB::table('feature_overrides')->where('key', 'search.ai_share')->where('enabled', true)->delete();
        Cache::forget('core:feature_overrides');
    }
};
