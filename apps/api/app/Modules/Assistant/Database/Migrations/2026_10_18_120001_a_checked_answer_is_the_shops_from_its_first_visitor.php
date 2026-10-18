<?php

use App\Core\Facades\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * As Aviad asked (2026-10-18): a question a shopper asked and got a checked answer to is kept in the
 * shop's answers, and the next shopper gets it from there without a model. assistant.share_after_visitors
 * now defaults to 1, so the first clean, safe answer is shared.
 *
 * Answers already given clean to at least one visitor, in a shop that keeps the default, are shared now
 * rather than waiting for more visitors. One that never reached anyone clean (visitors = 0) stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $shops = DB::table('assistant_answers')->where('outcome', 'answered')->where('status', 'shown')->whereNull('shared_at')->where('visitors', '>=', 1)
            ->distinct()->pluck('shop_id');

        foreach ($shops as $shop) {
            if ((int) Settings::get('assistant.share_after_visitors', (string) $shop) > 1) {
                continue;
            }

            DB::table('assistant_answers')->where('shop_id', $shop)->where('outcome', 'answered')->where('status', 'shown')
                ->whereNull('shared_at')->where('visitors', '>=', 1)->update(['shared_at' => now()]);
        }
    }

    public function down(): void
    {
        // Sharing is not undone: those answers were shown to their visitors and passed every check.
    }
};
