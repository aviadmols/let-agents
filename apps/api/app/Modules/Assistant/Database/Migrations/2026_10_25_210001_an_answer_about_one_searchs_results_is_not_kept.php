<?php

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Answers kept to the drawer's own question about a search's results ("מה הכי כדאי ומתאים לי מבין התוצאות לחיפוש
 * …?"): one shopper's search, its typing slips and their picks, shown to everyone in "What people ask here" (Sela,
 * 25.10). They go; the team's own answers stay. Once: such questions now point back (AnswerSiteQuestion::REFERS) and
 * are not kept.
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
        // one shopper's search was everyone's; nothing to bring back
    }
};
