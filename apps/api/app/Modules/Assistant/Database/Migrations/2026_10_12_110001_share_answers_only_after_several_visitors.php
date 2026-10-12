<?php

use App\Modules\Assistant\Support\Privacy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A model's answer reaches other shoppers only once several different visitors asked it and each
 * got a clean, safe answer, or once the team approved it: one visitor cannot plant an answer for
 * everyone, nor push it up the search suggestions by asking it again and again.
 *
 * Answers already shown before this keep being shown: they are marked shared from when they were
 * written. Search box answers are asked again anyway, under the new rules (prompt version 4).
 *
 * The questions already in the search box log lose their personal details (phones, emails, cards,
 * IDs, addresses), as every new one will before it is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_answers', function (Blueprint $table) {
            // How many different visitors got this answer clean; the visitors themselves are below.
            $table->unsignedSmallInteger('visitors')->default(0);
            // When it began to be shown to every shopper: after enough visitors, or the team's approval.
            $table->timestamp('shared_at')->nullable();
            // What the checks said when it was written: safe, injection, personal data, policy.
            $table->json('flags')->nullable();
        });

        Schema::create('assistant_answer_visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('answer_id')->constrained('assistant_answers')->cascadeOnDelete();
            // The shopper id, hashed and salted per shop, as for the daily limits.
            $table->string('visitor_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['answer_id', 'visitor_hash']);
        });

        DB::table('assistant_answers')->where('outcome', 'answered')->whereNull('shared_at')->update(['shared_at' => DB::raw('updated_at')]);

        DB::table('assistant_search_asks')->orderBy('id')->select(['id', 'question'])->chunk(500, function ($asks): void {
            foreach ($asks as $ask) {
                $redacted = Privacy::redact((string) $ask->question)['text'];

                if ($redacted !== $ask->question) {
                    DB::table('assistant_search_asks')->where('id', $ask->id)->update(['question' => mb_substr($redacted, 0, 500)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_answer_visitors');

        Schema::table('assistant_answers', function (Blueprint $table) {
            $table->dropColumn(['visitors', 'shared_at', 'flags']);
        });
    }
};
