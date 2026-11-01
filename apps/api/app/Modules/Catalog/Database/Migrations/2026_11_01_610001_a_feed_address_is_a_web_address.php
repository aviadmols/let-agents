<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A page's or a picture's address from a store's feed is kept only when it is a web address (FeedRecord::webUrl, 1.11):
 * the rows read before are cleaned the same way, so no "javascript:" or "data:" link already kept reaches a page.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['catalog_products', 'catalog_contents', 'catalog_categories'] as $table) {
            foreach (['url', 'image_url'] as $column) {
                // a table made by a later migration, or one without the column, has nothing to clean
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)->whereNotNull($column)
                    ->where(fn ($q) => $q->whereRaw("lower(trim({$column})) not like 'http://%'")->whereRaw("lower(trim({$column})) not like 'https://%'"))
                    ->update([$column => null]);
            }
        }
    }

    public function down(): void
    {
        // the addresses taken away were no addresses
    }
};
