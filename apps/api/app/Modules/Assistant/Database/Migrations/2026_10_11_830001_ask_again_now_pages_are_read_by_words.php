<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Site questions saved as "not on the site" before the assistant read the pages whose title
 * holds the question's words are asked again the next time. Team and real answers stay.
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
