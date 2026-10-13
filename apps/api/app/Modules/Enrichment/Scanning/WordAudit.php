<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;

/**
 * Where a shop's vocabulary would mislead code, found across its whole catalog before it does:
 *
 *   inside  a value's pattern found only inside a longer word ("ספיר" in "ספירלה"), which code
 *           now passes over: the words it fell in, how often, and a title they come from;
 *   groups  a category or a tag that names several values of one attribute ("ספיר, רובי, אמרלד"),
 *           which code now reads as a collection, deciding nothing.
 *
 * Read only: it changes no fact. For the operator, before approving a vocabulary or after a
 * catalog changes (php artisan enrichment words {shop}).
 */
final class WordAudit
{
    private const HEAD = 1200;

    /**
     * @param  iterable<CatalogProduct>  $products
     * @return array{inside: list<array{attribute: string, value: string, word: string, products: int, example: string}>, groups: list<array{attribute: string, name: string, values: list<string>, products: int}>}
     */
    public static function of(VocabularyDefinition $vocabulary, iterable $products): array
    {
        $values = [];
        foreach ($vocabulary->attributes() as $attribute) {
            if (($attribute['type'] ?? null) === 'enum') {
                foreach ((array) ($attribute['values'] ?? []) as $value) {
                    $values[] = [(string) $attribute['key'], (string) $value['key'], (array) ($value['patterns'] ?? [])];
                }
            }
        }

        $inside = [];
        $groups = [];

        foreach ($products as $product) {
            $names = array_values(array_filter([
                ...array_map(fn (array $path): string => trim((string) end($path)), array_filter($product->categoryPaths())),
                ...array_map(fn ($tag): string => trim((string) $tag), (array) ($product->payload['tags'] ?? [])),
            ]));
            $text = implode("\n", [(string) $product->title, mb_substr(strip_tags($product->description()), 0, self::HEAD), ...$names]);

            foreach ($values as [$attribute, $value, $patterns]) {
                foreach (self::partOfWords($text, $patterns) as $word) {
                    $key = "{$attribute}|{$value}|{$word}";
                    $inside[$key] ??= ['attribute' => $attribute, 'value' => $value, 'word' => $word, 'products' => 0, 'example' => mb_substr((string) $product->title, 0, 90)];
                    $inside[$key]['products']++;
                }
            }

            foreach ($names as $name) {
                $named = [];
                foreach ($values as [$attribute, $value, $patterns]) {
                    if (PatternMatch::any($name, $patterns)) {
                        $named[$attribute][] = $value;
                    }
                }
                foreach ($named as $attribute => $list) {
                    if (count($list) > 1) {
                        $key = "{$attribute}|{$name}";
                        $groups[$key] ??= ['attribute' => $attribute, 'name' => $name, 'values' => $list, 'products' => 0];
                        $groups[$key]['products']++;
                    }
                }
            }
        }

        $byProducts = fn (array $a, array $b): int => $b['products'] <=> $a['products'];
        usort($inside, $byProducts);
        usort($groups, $byProducts);

        return ['inside' => array_values($inside), 'groups' => array_values($groups)];
    }

    /**
     * The longer words a pattern was found inside, each once, when it is found nowhere as a word.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private static function partOfWords(string $text, array $patterns): array
    {
        $words = [];

        foreach ($patterns as $pattern) {
            if (@preg_match_all('~'.$pattern.'~imu', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [$found, $offset]) {
                    if ($found === '' || PatternMatch::whole($text, (int) $offset, strlen($found))) {
                        continue;
                    }
                    preg_match('/(\p{L}*)\z/u', substr($text, 0, (int) $offset), $before);
                    preg_match('/^(\p{L}*)/u', substr($text, (int) $offset + strlen($found)), $after);
                    $words[($before[1] ?? '').$found.($after[1] ?? '')] = true;
                }
            }
        }

        return array_keys($words);
    }
}
