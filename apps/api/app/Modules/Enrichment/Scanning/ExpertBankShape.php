<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * The expert's reply made into a bank code can measure: every item given an id by code (`type:ring`,
 * `attribute:metal`, `value:metal.gold`), its words cleaned as the products' text is, and what does not hold its form
 * left out and said. Nothing is mended: a key that is no key, an item with no word, a second item of the same key are
 * removed, never rewritten.
 *
 * The expert writes words, not patterns. Code makes each word a pattern read as a whole word (PatternMatch), so no
 * pattern a model wrote ever reads a product.
 */
final class ExpertBankShape
{
    public const KEY = '/^[a-z][a-z0-9_]{0,40}$/';

    /** Words kept on one type or value. */
    public const MAX_WORDS = 12;

    private const MAX_WORD_LENGTH = 60;

    private const MAX_LABEL_LENGTH = 80;

    /** The form a Hebrew letter takes at the end of a word, and the one it has when an ending follows ("אדום", "אדומים"). */
    private const FINAL_LETTERS = ['ם' => 'מ', 'ן' => 'נ', 'ץ' => 'צ', 'ף' => 'פ', 'ך' => 'כ'];

    /**
     * @param  array<string, mixed>  $reply  the expert's reply, already held to its schema
     * @return array{0: array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}, 1: list<array<string, mixed>>} the bank, and what was left out
     */
    public static function fromReply(array $reply, string $step): array
    {
        $removed = [];
        $out = function (string $kind, string $id, string $label, string $why, string $detail = '') use (&$removed, $step): void {
            $removed[] = ['id' => $id, 'kind' => $kind, 'label' => $label, 'by' => 'code', 'step' => $step, 'why' => $why, 'detail' => $detail];
        };

        $types = [];
        foreach ((array) ($reply['types'] ?? []) as $entry) {
            $item = self::item((array) $entry, 'type', 'type:', $types, $out);
            if ($item !== null) {
                $types[$item['key']] = $item;
            }
        }

        $attributes = [];
        foreach ((array) ($reply['attributes'] ?? []) as $entry) {
            $entry = (array) $entry;
            $key = (string) ($entry['key'] ?? '');
            $label = self::label((string) ($entry['label'] ?? ''));
            $id = 'attribute:'.$key;
            if (preg_match(self::KEY, $key) !== 1) {
                $out('attribute', $id, $label, 'bad_key');

                continue;
            }
            if (isset($attributes[$key])) {
                $out('attribute', $id, $label, 'duplicate');

                continue;
            }
            if ($label === '') {
                $out('attribute', $id, $label, 'no_label');

                continue;
            }
            // The types it says it belongs to, of those the bank has. None named: every product.
            $named = array_values(array_unique(array_map('strval', (array) ($entry['types'] ?? []))));
            $on = array_values(array_filter($named, fn (string $type): bool => isset($types[$type])));
            if ($named !== [] && $on === []) {
                $out('attribute', $id, $label, 'no_type', implode(', ', $named));

                continue;
            }
            $values = [];
            foreach ((array) ($entry['values'] ?? []) as $value) {
                $item = self::item((array) $value, 'value', 'value:'.$key.'.', $values, $out);
                if ($item !== null) {
                    $values[$item['key']] = $item;
                }
            }
            if ($values === []) {
                $out('attribute', $id, $label, 'no_values');

                continue;
            }
            $attributes[$key] = ['id' => $id, 'key' => $key, 'label' => $label, 'multiple' => ($entry['multiple'] ?? false) === true, 'types' => $on, 'values' => array_values($values)];
        }

        return [['types' => array_values($types), 'attributes' => array_values($attributes)], $removed];
    }

    /**
     * A type or a value: its key, its label and its words, or null when it does not hold.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $taken  the keys already used beside it
     * @return array{id: string, key: string, label: string, words: list<string>}|null
     */
    private static function item(array $entry, string $kind, string $prefix, array $taken, callable $out): ?array
    {
        $key = (string) ($entry['key'] ?? '');
        $label = self::label((string) ($entry['label'] ?? ''));
        $id = $prefix.$key;

        if (preg_match(self::KEY, $key) !== 1) {
            $out($kind, $id, $label, 'bad_key');

            return null;
        }
        if (isset($taken[$key])) {
            $out($kind, $id, $label, 'duplicate');

            return null;
        }
        if ($label === '') {
            $out($kind, $id, $label, 'no_label');

            return null;
        }
        $words = self::words((array) ($entry['words'] ?? []));
        if ($words === []) {
            $out($kind, $id, $label, 'no_words');

            return null;
        }

        return ['id' => $id, 'key' => $key, 'label' => $label, 'words' => $words];
    }

    /**
     * Words as the products' text is compared: lower case, one kind of quote and dash, single spaces. A word of one
     * character, of no letter, or too long to be a word of a store is left out; the same word is kept once.
     *
     * @param  list<mixed>  $words
     * @return list<string>
     */
    public static function words(array $words): array
    {
        $out = [];
        foreach ($words as $word) {
            $word = is_string($word) ? TextNormalizer::forMatching($word) : '';
            if (mb_strlen($word) < 2 || mb_strlen($word) > self::MAX_WORD_LENGTH || preg_match('/\p{L}/u', $word) !== 1 || str_contains($word, ';')) {
                continue;
            }
            $out[$word] = true;
        }

        return array_slice(array_map('strval', array_keys($out)), 0, self::MAX_WORDS);
    }

    /**
     * A word as the pattern PatternMatch reads: its letters as they are, a space or a hyphen between its words, and a
     * Hebrew final letter also in the form it takes before an ending, so "אדום" reads "אדומים" too.
     */
    public static function pattern(string $word): string
    {
        $parts = preg_split('/[\s\-]+/u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $last = count($parts) - 1;
        $quoted = [];

        foreach ($parts as $i => $part) {
            $end = mb_substr($part, -1);
            $quoted[] = $i === $last && isset(self::FINAL_LETTERS[$end])
                ? preg_quote(mb_substr($part, 0, -1), '~').'['.$end.self::FINAL_LETTERS[$end].']'
                : preg_quote($part, '~');
        }

        return implode('[\s\-]+', $quoted);
    }

    /**
     * The bank as it is kept: every type and value also with the patterns code read it by.
     *
     * @param  array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}  $bank
     * @return array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}
     */
    public static function withPatterns(array $bank): array
    {
        $with = fn (array $item): array => $item + ['patterns' => array_map(self::pattern(...), $item['words'])];
        $bank['types'] = array_map($with, $bank['types']);
        foreach ($bank['attributes'] as &$attribute) {
            $attribute['values'] = array_map($with, $attribute['values']);
        }
        unset($attribute);

        return $bank;
    }

    /**
     * Every id code gave, in the bank's order: each type, each attribute, each value.
     *
     * @param  array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}  $bank
     * @return list<string>
     */
    public static function ids(array $bank): array
    {
        $ids = array_column($bank['types'], 'id');
        foreach ($bank['attributes'] as $attribute) {
            $ids[] = $attribute['id'];
            foreach ($attribute['values'] as $value) {
                $ids[] = $value['id'];
            }
        }

        return array_map('strval', $ids);
    }

    /** A label on one line, with nothing around it; never trim(): it is Hebrew as often as not. */
    private static function label(string $label): string
    {
        return mb_substr((string) preg_replace('/^\s+|\s+$/u', '', (string) preg_replace('/\s+/u', ' ', TextNormalizer::clean($label))), 0, self::MAX_LABEL_LENGTH);
    }
}
