<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A question asked in the search box belongs to the whole site, not to one page: neither
        // product_id nor content_id. Its answer shows the store's pages it was written from.
        Schema::table('assistant_answers', function (Blueprint $table) {
            $table->json('sources')->nullable()->after('source');
            $table->index(['shop_id', 'question_key']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_answers', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'question_key']);
            $table->dropColumn('sources');
        });
    }
};
