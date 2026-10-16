<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The on-page module's looks are gone (the search drawer took their place, 2026-10-16), and with them
 * widget.layout. A shop that chose the tag bank as its look had its page tags written for that alone
 * (StoredPageTags::wanted), so its tags keep being written: search.page_tags is turned on for it, as an
 * ordinary shop override the operator can turn off from the shop's features. The old setting's rows stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tagBankShops() as $scope) {
            DB::table('feature_overrides')->updateOrInsert(['key' => 'search.page_tags', 'scope' => $scope], ['enabled' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
        Cache::forget('core:feature_overrides');
    }

    public function down(): void
    {
        foreach ($this->tagBankShops() as $scope) {
            DB::table('feature_overrides')->where('key', 'search.page_tags')->where('scope', $scope)->delete();
        }
        Cache::forget('core:feature_overrides');
    }

    /** @return list<string> the scopes (shops) whose module look was the tag bank */
    private function tagBankShops(): array
    {
        return DB::table('setting_overrides')->where('key', 'widget.layout')->get(['scope', 'value'])
            ->filter(fn (object $row): bool => json_decode((string) $row->value, true) === 'tags')
            ->map(fn (object $row): string => (string) $row->scope)
            ->values()->all();
    }
};
