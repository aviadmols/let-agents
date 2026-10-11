<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Until TenantContext::run() left cross-shop mode, a shop's nightly work (search index, picture
 * and text vectors, enrichment) read every shop's products and wrote the results under its own
 * shop. The catalog itself was synced per shop and is clean. Here every row that names a product
 * of another shop goes, the vectors made from another shop's text go, and each shop's search
 * index is built again from its own catalog alone.
 */
return new class extends Migration
{
    /** Shop-owned tables that point at a product, and the column. */
    private const BY_PRODUCT = [
        'retrieval_images' => 'product_id',
        'retrieval_matches' => 'product_id',
        'retrieval_match_requests' => 'product_id',
        'enrichment_facts' => 'product_id',
        'enrichment_rankings' => 'product_id',
        'enrichment_code_readings' => 'product_id',
        'enrichment_batch_items' => 'product_id',
        'enrichment_content_products' => 'product_id',
        'enrichment_product_relations' => 'product_id',
        'assistant_answers' => 'product_id',
    ];

    public function up(): void
    {
        foreach (self::BY_PRODUCT as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, 'shop_id')) {
                continue;
            }

            DB::table($table)->whereIn($column, DB::table('catalog_products as p')->select('p.id')->whereColumn('p.shop_id', '!=', "{$table}.shop_id")->whereColumn('p.id', "{$table}.{$column}"))->delete();
        }

        // A relation pointing at another shop's product, whichever side.
        if (Schema::hasTable('enrichment_product_relations') && Schema::hasColumn('enrichment_product_relations', 'related_product_id')) {
            DB::table('enrichment_product_relations')->whereIn('related_product_id', DB::table('catalog_products as p')->select('p.id')->whereColumn('p.shop_id', '!=', 'enrichment_product_relations.shop_id')->whereColumn('p.id', 'enrichment_product_relations.related_product_id'))->delete();
        }

        // Text vectors made from another shop's product or page.
        if (Schema::hasTable('retrieval_chunks')) {
            DB::table('retrieval_chunks')->where('source', 'product')->whereIn('source_id', DB::table('catalog_products as p')->select('p.id')->whereColumn('p.shop_id', '!=', 'retrieval_chunks.shop_id')->whereColumn('p.id', 'retrieval_chunks.source_id'))->delete();
            DB::table('retrieval_chunks')->where('source', 'content')->whereIn('source_id', DB::table('catalog_content as c')->select('c.id')->whereColumn('c.shop_id', '!=', 'retrieval_chunks.shop_id')->whereColumn('c.id', 'retrieval_chunks.source_id'))->delete();
        }

        // Each shop's search index again, from its own catalog, on the queue.
        if (! app()->runningUnitTests()) {
            Artisan::queue('search', ['step' => 'index', '--all' => true])->onQueue('long');
        }
    }

    public function down(): void {}
};
