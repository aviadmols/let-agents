<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each search on its own row, beside the daily counts: the words, how many it found, what was
 * clicked, and the visit (address, device, browser, system, session, page, where the shopper came
 * from). A photo search keeps its visit too. The address and the session are cleared after
 * search.visit_keep_days; the searches stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('query', 120);
            $table->unsignedInteger('results')->default(0);
            $table->string('clicked', 80)->nullable();
            self::visit($table);
            $table->timestamp('created_at')->nullable();

            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'session_id']);
        });

        Schema::table('search_photo_asks', function (Blueprint $table) {
            self::visit($table);
            $table->index(['shop_id', 'session_id']);
        });
    }

    private static function visit(Blueprint $table): void
    {
        $table->string('ip', 45)->nullable();
        $table->string('device', 10)->nullable();
        $table->string('browser', 30)->nullable();
        $table->string('os', 20)->nullable();
        $table->string('session_id', 64)->nullable();
        $table->string('page', 300)->nullable();
        $table->string('referrer', 120)->nullable();
    }

    public function down(): void
    {
        Schema::dropIfExists('search_logs');
        Schema::table('search_photo_asks', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'session_id']);
            $table->dropColumn(['ip', 'device', 'browser', 'os', 'session_id', 'page', 'referrer']);
        });
    }
};
