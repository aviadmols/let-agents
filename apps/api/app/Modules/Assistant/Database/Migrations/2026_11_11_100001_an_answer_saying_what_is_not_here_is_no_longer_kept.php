<?php

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Answers kept before 11.11 that say what was asked for is not here in words the check did not know yet ("אין כאן עגיל
 * מטפס בודד": Rocks, shown to every shopper who searched "מטפס"). Such answers are this asker's only (save()); these
 * go, so the next shopper is answered afresh. The team's own answers stay. Once.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('assistant_answers')->where('outcome', 'answered')->whereNotNull('answer')
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'team'))
            ->orderBy('id')->select(['id', 'answer'])
            ->chunk(500, function ($rows): void {
                $ids = $rows->filter(fn ($row): bool => AnswerSiteQuestion::saysMissing((string) $row->answer))->pluck('id')->all();
                if ($ids !== []) {
                    DB::table('assistant_answers')->whereIn('id', $ids)->delete();
                }
            });
    }

    public function down(): void
    {
        // what was removed told every later shopper that something is missing; nothing to bring back
    }
};
