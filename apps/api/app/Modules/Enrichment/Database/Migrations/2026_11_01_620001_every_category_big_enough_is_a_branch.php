<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A store has as many branches as it has categories big enough for one (more than 5 products), with no cap (as Aviad
 * asked, 1.11). A value kept for a store or for the platform before gives way to the new defaults; a demo keeps its
 * own, which its budget sets.
 */
return new class extends Migration
{
    public function up(): void
    {
        $demos = DB::table('shops')->where('status', 'demo')->pluck('id')->map(fn ($id): string => (string) $id)->all();

        DB::table('setting_overrides')
            ->whereIn('key', ['enrichment.max_branches', 'enrichment.branch_min_products'])
            ->when($demos !== [], fn ($q) => $q->whereNotIn('scope', $demos))
            ->delete();

        Cache::forget('core:setting_overrides');
    }

    public function down(): void
    {
        // the values taken away were the old defaults
    }
};
