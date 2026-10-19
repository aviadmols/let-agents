<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a section of a page or post is about, so a question finds it by more than its words:
 *   - topics          returns, shipping, warranty... read in code from the section (Topics), " returns shipping "
 *   - ask_embedding   a second vector, of the questions the section answers (SectionQuestions)
 *   - asks_hash       what that vector was made from (model and questions): unchanged, never embedded again
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retrieval_chunks', function (Blueprint $table): void {
            $table->string('topics', 300)->nullable()->after('text_hash');
            $table->string('asks_hash', 64)->nullable()->after('embedded_at');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE retrieval_chunks ADD COLUMN ask_embedding vector');
        } else {
            Schema::table('retrieval_chunks', fn (Blueprint $table) => $table->text('ask_embedding')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('retrieval_chunks', function (Blueprint $table): void {
            $table->dropColumn(['topics', 'asks_hash', 'ask_embedding']);
        });
    }
};
