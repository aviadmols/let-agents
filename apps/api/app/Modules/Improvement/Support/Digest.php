<?php

namespace App\Modules\Improvement\Support;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The day's report for one shop, a few lines long, written in code from the numbers: never by a
 * model, so it is always exact and always free.
 */
final class Digest
{
    private const LISTED = 5;

    /** @param list<ImprovementProposal> $proposals */
    public static function write(string $shopId, ImprovementReview $review, array $proposals, ?string $stopped = null): string
    {
        $shop = Shop::query()->find($shopId);
        $yesterday = now()->subDay()->toDateString();
        $searches = SearchTerm::query()->whereDate('day', $yesterday)->selectRaw('COALESCE(SUM(searches),0) as searches, COALESCE(SUM(empty),0) as empty, COALESCE(SUM(clicks),0) as clicks')->first();
        $evidence = (array) $review->evidence;

        $lines = [
            __('improvement::digest.title', ['shop' => (string) $shop?->name, 'date' => now()->format('d.m.Y')]),
            '',
            __('improvement::digest.searches', [
                'searches' => number_format((int) $searches->searches),
                'empty' => number_format((int) $searches->empty),
                'clicks' => number_format((int) $searches->clicks),
            ]),
            __('improvement::digest.evidence', [
                'empty' => (string) count($evidence['empty'] ?? []),
                'unclicked' => (string) count($evidence['unclicked'] ?? []),
                'questions' => (string) count($evidence['questions'] ?? []),
            ]),
        ];

        $lines[] = match (true) {
            $stopped !== null => __('improvement::digest.stopped.'.(in_array($stopped, ['spend_cap', 'no_key'], true) ? $stopped : 'other')),
            $review->status === ImprovementReview::QUIET => __('improvement::digest.quiet'),
            default => __('improvement::digest.proposals', [
                'proposed' => (string) $review->proposed,
                'accepted' => (string) $review->accepted,
                'applied' => (string) $review->applied,
            ]),
        };

        $waiting = array_slice(array_values(array_filter($proposals, fn (ImprovementProposal $p): bool => $p->status === ImprovementProposal::PENDING)), 0, self::LISTED);

        foreach ($waiting as $proposal) {
            $lines[] = '  · '.__("improvement::ui.kinds.{$proposal->kind}").': '.$proposal->title;
        }

        try {
            $spend = app(SpendGuard::class);
            $lines[] = __('improvement::digest.budget', ['spent' => number_format($spend->spentThisMonth(), 2), 'cap' => number_format($spend->cap(), 2)]);
        } catch (Throwable) {
            // The report goes out without the budget line rather than not at all.
        }

        return implode("\n", $lines);
    }

    /** Sends the report when the shop gave an address and the app has a real mailer. */
    public static function mail(string $shopId, string $digest): bool
    {
        $to = trim((string) Settings::get('improvement.digest_email', $shopId));

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL) || in_array((string) config('mail.default'), ['log', ''], true)) {
            return false;
        }

        try {
            Mail::raw($digest, fn ($message) => $message->to($to)->subject(strtok($digest, "\n")));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
