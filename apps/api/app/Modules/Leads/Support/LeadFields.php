<?php

namespace App\Modules\Leads\Support;

use App\Core\Facades\Settings;

/**
 * The fields a shop may ask a shopper who leaves details, as the merchant's settings offer them: a bank
 * of common ones (each with the type the flow checks it as) and fields of the shop's own (free text).
 *
 * Whatever the screen sends is made into a flow's fields here, in code: unknown keys dropped, each key
 * once, a label of at most 60 characters, and a way back (a phone or an email) always first and always
 * required, because without one there is nobody to come back to.
 */
final class LeadFields
{
    /** The bank, in the order the screen offers it: key => the type FlowMachine checks it as. */
    public const BANK = [
        'phone' => 'phone',
        'name' => 'name',
        'email' => 'email',
        'city' => 'text',
        'project' => 'text',
        'budget' => 'text',
        'when' => 'text',
        'quantity' => 'text',
        'business' => 'text',
        'note' => 'text',
    ];

    /** A field of the shop's own: "own_" and a few letters the screen makes up. */
    public const OWN = '/^own_[a-z0-9]{1,24}$/';

    /** Kept on a lead with what they gave; never a field's key. */
    public const QUESTION = 'question';

    public const MAX = 12;

    public const LABEL_MAX = 60;

    /**
     * The bank in the shop's language: what the shopper will read.
     *
     * @return list<array{key: string, type: string, label: string}>
     */
    public static function bank(string $locale): array
    {
        $out = [];

        foreach (self::BANK as $key => $type) {
            $out[] = ['key' => $key, 'type' => $type, 'label' => (string) __('leads::shop_settings.bank.'.$key, [], $locale)];
        }

        return $out;
    }

    /**
     * What a shop asks before it set anything: a phone, then a name if they like.
     *
     * @return list<array{key: string, type: string, label: string, required: bool}>
     */
    public static function defaults(string $locale): array
    {
        return self::clean([
            ['key' => 'phone', 'required' => true],
            ['key' => 'name', 'required' => false],
        ], [], $locale);
    }

    /**
     * The fields as a flow keeps them, from what the screen sent.
     *
     * @param  array<mixed>  $sent
     * @param  array<string, array<string, mixed>>  $known  the flow in force's fields, by key: they keep their type
     * @return list<array<string, mixed>>
     */
    public static function clean(array $sent, array $known, string $locale): array
    {
        $out = [];

        foreach ($sent as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');

            if ($key === self::QUESTION || isset($out[$key]) || preg_match('/^[A-Za-z0-9_-]{1,30}$/', $key) !== 1) {
                continue;
            }

            $kept = match (true) {
                isset($known[$key]) => $known[$key],
                isset(self::BANK[$key]) => ['key' => $key, 'type' => self::BANK[$key]],
                preg_match(self::OWN, $key) === 1 => ['key' => $key, 'type' => 'text'],
                default => null,
            };

            if ($kept === null) {
                continue;
            }

            $label = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($field['label'] ?? ''))), 0, self::LABEL_MAX);
            $kept['label'] = $label !== '' ? $label : (isset(self::BANK[$key]) ? (string) __('leads::shop_settings.bank.'.$key, [], $locale) : (string) ($kept['label'] ?? $key));
            $kept['required'] = (bool) ($field['required'] ?? false);
            $out[$key] = $kept;
        }

        $out = array_values($out);
        $contact = null;

        foreach ($out as $i => $field) {
            if (in_array($field['type'] ?? '', ['phone', 'email'], true)) {
                $contact = $i;
                break;
            }
        }

        // A way back first: the one they chose, or a phone.
        $first = $contact === null
            ? ['key' => 'phone', 'type' => 'phone', 'label' => (string) __('leads::shop_settings.bank.phone', [], $locale)]
            : $out[$contact];

        if ($contact !== null) {
            array_splice($out, $contact, 1);
        }

        $first['required'] = true;

        return array_slice([$first, ...$out], 0, self::MAX);
    }

    /** The shop's language for what shoppers read, of the languages these words exist in. */
    public static function locale(?string $contentLocale): string
    {
        return in_array($contentLocale, ['he', 'en'], true) ? $contentLocale : 'he';
    }

    /** The heading of the form: the shop's own, or the built-in one. */
    public static function title(string $shopId, string $locale): string
    {
        return trim((string) Settings::get('leads.form_title', $shopId)) ?: (string) __('leads::form.title', [], $locale);
    }

    /** What the shopper is told after leaving details, when the flow does not say. */
    public static function promise(string $locale): string
    {
        return (string) __('leads::form.promise', [], $locale);
    }
}
