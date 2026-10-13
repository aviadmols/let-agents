<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A question about the product on the page, or about the turn before, is answered and never kept
 * as a saved answer: the question's row keeps what was shown, so the nightly review reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_search_asks', function (Blueprint $table): void {
            $table->text('answer')->nullable()->after('answer_id');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_search_asks', fn (Blueprint $table) => $table->dropColumn('answer'));
    }
};
