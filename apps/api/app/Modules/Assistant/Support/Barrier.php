<?php

namespace App\Modules\Assistant\Support;

/**
 * The barrier every shopper question passes first, in the search box and on a page: before the
 * saved answers are read or written and before any model.
 *
 * A visitor blocked for breaking the rules is not answered. A question that tries to instruct the
 * assistant, carries markup or floods is refused and counted against the visitor (see Offenders);
 * one with personal details is refused without counting; one about hurting oneself gets care and
 * where to find help, in the shopper's language.
 */
final class Barrier
{
    /**
     * Null when the question may go on; otherwise the outcome to show (blocked, support,
     * personal_data or out_of_scope), why, and for support the text itself.
     *
     * @return array{outcome: string, reason: string, answer?: string}|null
     */
    public static function check(string $shopId, string $question, string $visitorHash, ?string $ip, string $locale): ?array
    {
        if (Offenders::blocked($shopId, $visitorHash, $ip)) {
            return ['outcome' => 'blocked', 'reason' => 'blocked'];
        }

        $guard = QuestionGuard::inspect($question);

        if ($guard['action'] === QuestionGuard::SUPPORT) {
            return ['outcome' => 'support', 'reason' => 'support', 'answer' => QuestionGuard::supportText($locale)];
        }

        if ($guard['action'] === QuestionGuard::REFUSE) {
            if ($guard['counts']) {
                Offenders::record($shopId, $visitorHash, $ip);
            }

            return ['outcome' => $guard['reason'] === 'personal_data' ? 'personal_data' : 'out_of_scope', 'reason' => (string) $guard['reason']];
        }

        return null;
    }

    /** The shopper's network address from a storefront request; none from the console. */
    public static function ip(): ?string
    {
        return app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip();
    }
}
