<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reply in the search box ("יש משהו עם זהב צהוב?") is logged with the conversation it answered in, so the quality
 * baseline can ask it again as it was asked, never as a question of its own; a case keeps that conversation; and a
 * run's result keeps what each step took (the topic check, the writer, the checker: time and tokens) and the nearness
 * of the pieces sent, so a change is measured on time and context, not only on the answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_search_asks', function (Blueprint $table): void {
            $table->json('thread')->nullable()->after('products');
        });
        Schema::table('assistant_quality_cases', function (Blueprint $table): void {
            $table->json('thread')->nullable()->after('products');
        });
        Schema::table('assistant_quality_results', function (Blueprint $table): void {
            $table->json('trace')->nullable()->after('duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_search_asks', fn (Blueprint $table) => $table->dropColumn('thread'));
        Schema::table('assistant_quality_cases', fn (Blueprint $table) => $table->dropColumn('thread'));
        Schema::table('assistant_quality_results', fn (Blueprint $table) => $table->dropColumn('trace'));
    }
};
