<?php

namespace App\Modules\Assistant\Support;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswerRule;
use Illuminate\Support\Carbon;

/**
 * The store's rules for its answers, in its own words: how to answer ("never offer a coupon", "a question about sizes
 * gets the size guide"), never a fact, which is the store's information (the knowledge centre). Read by code before
 * anything else: a rule that is empty, too long, a link, someone's details, or an attempt to change how the assistant
 * works is refused here, and no model is asked (CheckAnswerChange asks the rest).
 */
final class AnswerRules
{
    /** What code refuses a rule for, as the team is told it (assistant::ui.inbox.why.refused.*). */
    public const EMPTY = 'empty';

    public const LONG = 'long';

    public const LINK = 'link';

    public const DETAILS = 'details';

    public const INSTRUCTIONS = 'instructions';

    public const MANY = 'many';

    public const SAME = 'same';

    /**
     * The store's rules as the writer is given them, oldest first: their order is the order they were written in, and
     * hashed into what a saved answer was written with (sorted explicitly: Postgres and SQLite order alike).
     *
     * @return list<string>
     */
    public static function texts(string $shopId): array
    {
        return app(TenantContext::class)->run($shopId, fn (): array => AssistantAnswerRule::query()
            ->orderBy('created_at')->orderBy('id')->pluck('text')
            ->map(fn ($text): string => self::clean((string) $text))->filter(fn (string $text): bool => $text !== '')->values()->all());
    }

    /**
     * The store's rules with who wrote them and when, for the team's screen.
     *
     * @return list<array{id: string, text: string, at: Carbon|null}>
     */
    public static function rows(string $shopId): array
    {
        return app(TenantContext::class)->run($shopId, fn (): array => AssistantAnswerRule::query()
            ->orderBy('created_at')->orderBy('id')->get(['id', 'text', 'created_at'])
            ->map(fn (AssistantAnswerRule $rule): array => ['id' => (string) $rule->id, 'text' => (string) $rule->text, 'at' => $rule->created_at])->all());
    }

    /** One paragraph: its spaces as one, no line breaks. */
    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Why code refuses a rule, or null when a model may read it. $others: the store's other rules (one being replaced
     * is not among them).
     *
     * @param  list<string>  $others
     */
    public static function refused(string $shopId, string $text, array $others): ?string
    {
        $text = self::clean($text);

        if (mb_strlen($text) < 4) {
            return self::EMPTY;
        }

        if (mb_strlen($text) > (int) Settings::get('assistant.answer_rule_chars')) {
            return self::LONG;
        }

        // a place to send shoppers is the store's information, never a rule (and never a link a model was told to give)
        if (preg_match('~https?://|www\.|\b[\w-]+\.(?:co\.il|com|net|org|il)\b~iu', $text) === 1) {
            return self::LINK;
        }

        // the guard a shopper's question meets: an attempt to change how the assistant works, or markup
        $guard = QuestionGuard::inspect($text);
        if (in_array($guard['reason'], ['injection', 'markup'], true)) {
            return self::INSTRUCTIONS;
        }

        // a phone, an email, an id: someone's details, or the store's own, which belong in its information
        if (array_diff((array) $guard['personal'], ['link']) !== []) {
            return self::DETAILS;
        }

        $same = mb_strtolower($text);
        foreach ($others as $other) {
            if (mb_strtolower(self::clean($other)) === $same) {
                return self::SAME;
            }
        }

        if (count($others) >= (int) Settings::get('assistant.answer_rules_max', $shopId)) {
            return self::MANY;
        }

        return null;
    }
}
