<?php

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Answers kept before 25.10 to questions that point at the products in front of the shopper ("מה ההבדל ביניהם?",
 * "איזה מהם הכי משתלם?"): they hold one shopper's picks and were served to everyone who asked in the same words.
 * They go; the team's own answers stay. Once: such answers are no longer kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('assistant_answers')->whereNull('product_id')->whereNull('content_id')
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'team'))
            ->orderBy('id')->select(['id', 'question'])
            ->chunk(500, function ($rows): void {
                $ids = $rows->filter(fn ($row): bool => AnswerSiteQuestion::pointsBack((string) $row->question))->pluck('id')->all();
                if ($ids !== []) {
                    DB::table('assistant_answers')->whereIn('id', $ids)->delete();
                }
            });
    }

    public function down(): void
    {
        // what was removed was wrong for everyone but its first asker; nothing to bring back
    }
};
