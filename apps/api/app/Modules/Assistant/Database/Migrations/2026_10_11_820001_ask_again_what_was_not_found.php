<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Site questions saved as "not on the site" before the store's pages were all read (Shopify
 * policies, pages added since) are asked again the next time, instead of answering from the
 * old "not found". Team answers and real answers stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('assistant_answers')->whereNull('product_id')->whereNull('content_id')
            ->where('outcome', 'no_info')->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'team'))
            ->delete();
    }

    public function down(): void {}
};
