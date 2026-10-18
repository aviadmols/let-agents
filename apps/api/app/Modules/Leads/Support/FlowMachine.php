<?php

namespace App\Modules\Leads\Support;

use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Shoppers\Contracts\ReadsContacts;

/**
 * The conversation that is trying to get somewhere, as a machine rather than a conversation.
 *
 * A model asked to collect a phone number will collect a phone number, and it will also ask twice,
 * accept "0500000000", forget the consent, and one day decide a postcode would be useful. None of
 * that is a prompt problem. So the whole of it is here, in code: which field comes next, whether
 * what was typed is a real one, when consent is required, and when to stop asking.
 *
 * What the model does in this flow is answer questions about the page, which is what it is good
 * at. It never sees a field, never sees an answer, and never decides the next step.
 *
 * The machine holds no state of its own. The widget sends back what it has so far and is told
 * what to do next, so a reader who reloads the page loses a step rather than a lead, and nothing
 * about them sits in a session anywhere.
 */
final class FlowMachine
{
    /** Asked for details and told no: not asked again in this visit. */
    public const DECLINED = 'declined';

    /** Everything is in. */
    public const DONE = 'done';

    /** Waiting for the person to agree before anything is kept. */
    public const CONSENT = 'consent';

    /** Waiting for one of the flow's fields. */
    public const FIELD = 'field';

    /**
     * What to do next, given what the reader has given so far.
     *
     * @param  array<string, string>  $given  field key => what they typed
     * @return array{state: string, field: array<string, mixed>|null, done: int, total: int}
     */
    public static function next(LeadFlow $flow, array $given, bool $declined = false, bool $consented = false, bool $enough = false): array
    {
        $fields = $flow->asked();
        $total = count($fields) + 1;

        if ($declined) {
            return ['state' => self::DECLINED, 'field' => null, 'done' => 0, 'total' => $total];
        }

        // They stopped answering after leaving a way to reach them (as Aviad asked, 2026-10-18: a phone
        // alone is a lead): the rest is skipped, and the consent is still asked before anything is kept.
        if ($enough && self::reaches($flow, $given)) {
            return ['state' => $consented ? self::DONE : self::CONSENT, 'field' => null, 'done' => count($given), 'total' => $total];
        }

        foreach ($fields as $field) {
            $answer = trim((string) ($given[$field['key']] ?? ''));

            // An optional field somebody skipped is answered as far as this is concerned.
            if ($answer === '' && ! ($field['required'] ?? false) && array_key_exists($field['key'], $given)) {
                continue;
            }

            if ($answer === '') {
                return [
                    'state' => self::FIELD,
                    'field' => $field,
                    'done' => count($given),
                    'total' => $total,
                ];
            }
        }

        // Consent last: agreeing before seeing what is being asked for is not agreeing.
        return [
            'state' => $consented ? self::DONE : self::CONSENT,
            'field' => null,
            'done' => count($fields),
            'total' => $total,
        ];
    }

    /**
     * Whether what they gave so far has a phone or an email in it (already checked by check()).
     *
     * @param  array<string, string>  $given
     */
    public static function reaches(LeadFlow $flow, array $given): bool
    {
        foreach ($flow->asked() as $field) {
            if (in_array($field['type'], ['phone', 'email'], true) && trim((string) ($given[$field['key']] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether what was typed is a real answer to this field.
     *
     * A phone and an email are checked against the same parser the sign-up uses, so a number that
     * would be accepted here and rejected there cannot exist.
     *
     * @param  array<string, mixed>  $field
     * @return array{ok: bool, value: string, reason: ?string}
     */
    public static function check(array $field, string $raw, string $shopId): array
    {
        $value = trim($raw);
        $required = (bool) ($field['required'] ?? false);

        if ($value === '') {
            return $required
                ? ['ok' => false, 'value' => '', 'reason' => 'required']
                : ['ok' => true, 'value' => '', 'reason' => null];
        }

        if (in_array($field['type'], ['phone', 'email'], true)) {
            $contact = app(ReadsContacts::class)->read($value, $shopId);

            if ($contact === null || $contact['channel'] !== $field['type']) {
                return ['ok' => false, 'value' => '', 'reason' => 'not_a_'.$field['type']];
            }

            return ['ok' => true, 'value' => $contact['value'], 'reason' => null];
        }

        if (mb_strlen($value) > 200) {
            return ['ok' => false, 'value' => '', 'reason' => 'too_long'];
        }

        // Somewhere to be reached is not somewhere to be sold to: a line of free text that is all
        // link is somebody using the form as a letterbox.
        if ($field['type'] === 'text' && preg_match('~https?://|www\.~i', $value) === 1) {
            return ['ok' => false, 'value' => '', 'reason' => 'no_links'];
        }

        return ['ok' => true, 'value' => $value, 'reason' => null];
    }

    /**
     * How much this lead is worth following up, out of a hundred, decided in code.
     *
     * Not a judgement of the person: a count of what is actually there. A lead with a verified
     * phone, a name and an answer to the shop's own question is worth more of somebody's morning
     * than an email address typed to make a box go away, and a team with fifty of them should be
     * told which is which by arithmetic rather than by a model's impression.
     *
     * @param  array<string, string>  $given
     */
    public static function quality(LeadFlow $flow, array $given, int $questionsAsked): int
    {
        $fields = $flow->asked();
        $answered = 0;

        foreach ($fields as $field) {
            if (trim((string) ($given[$field['key']] ?? '')) !== '') {
                $answered++;
            }
        }

        // Half for what they gave, a quarter for giving more than they had to, a quarter for
        // having been interested enough to ask something first.
        $completeness = $fields === [] ? 0 : (int) round(50 * $answered / count($fields));
        $optional = count($fields) - count(array_filter($fields, fn (array $f): bool => (bool) ($f['required'] ?? false)));
        $extra = $optional === 0 ? 25 : (int) round(25 * max(0, $answered - (count($fields) - $optional)) / $optional);
        $engaged = (int) round(25 * min(1, $questionsAsked / 2));

        return max(0, min(100, $completeness + $extra + $engaged));
    }
}
