<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The same words from the same address on the same day are one search (CountSearch, Aviad 12.11): each search looks for
 * an earlier one of its words and address today, by this index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_logs', function (Blueprint $table): void {
            $table->index(['shop_id', 'query', 'ip', 'created_at'], 'search_logs_again');
        });
    }

    public function down(): void
    {
        Schema::table('search_logs', function (Blueprint $table): void {
            $table->dropIndex('search_logs_again');
        });
    }
};
