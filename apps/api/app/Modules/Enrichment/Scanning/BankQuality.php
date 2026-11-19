<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * How good a bank's types are beyond how many products they cover (Aviad, 19.11: "70% is not good enough by itself: hundreds
 * of products filed as 'toy' do not help one who looks for a developmental toy for a baby"), in code, for any store:
 *
 * - by department: the share of each of the store's own departments (its type field, else its top category) that got a
 *   type, so a whole department the bank reads poorly shows (toys at 30% beside clothes at 90%);
 * - specificity: how many different kinds each type holds, by what its products' names open with: a type over many kinds
 *   (wardrobes, cribs and mattresses as "furniture") is broad, and the share of typed products under broad types says how
 *   often the bank settled for a department.
 *
 * Accuracy (whether a product's type is right) is a model's judgement on a sample: MeasureBankQuality.
 */
final class BankQuality
{
    /** A department of fewer products than this is not measured on its own. */
    public const MIN_DEPARTMENT = 5;

    /** A department under this share typed is one the bank reads poorly. */
    public const WEAK_DEPARTMENT = 0.5;

    /** A type whose products open their names with this many different words (each said twice or more) is broad. */
    public const BROAD_KINDS = 5;

    /**
     * Each typed product's type: the first of the bank's types that reads it, as code reads them in order.
     *
     * @param  array{types: list<array<string, mixed>>}  $bank
     * @param  array<string, array<int, true>>  $sets  BankEvidence::typeSets
     * @return array<int, string>
     */
    public static function typeOf(array $bank, array $sets): array
    {
        $out = [];
        foreach ($bank['types'] as $type) {
            foreach (array_keys($sets[(string) $type['key']] ?? []) as $index) {
                $out[$index] ??= (string) $type['key'];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{title: string, department?: string}>  $texts
     * @param  array<int, string>  $typeOf
     * @return list<array{department: string, products: int, typed: int, share: float, weak: bool}>
     */
    public static function departments(array $texts, array $typeOf): array
    {
        $by = [];
        foreach ($texts as $index => $text) {
            $department = trim((string) ($text['department'] ?? ''));
            if ($department === '') {
                continue;
            }
            $by[$department] ??= ['products' => 0, 'typed' => 0];
            $by[$department]['products']++;
            $by[$department]['typed'] += isset($typeOf[$index]) ? 1 : 0;
        }
        $out = [];
        foreach ($by as $department => $n) {
            if ($n['products'] < self::MIN_DEPARTMENT) {
                continue;
            }
            $share = round($n['typed'] / $n['products'], 3);
            $out[] = ['department' => (string) $department, 'products' => $n['products'], 'typed' => $n['typed'], 'share' => $share, 'weak' => $share < self::WEAK_DEPARTMENT];
        }
        usort($out, fn (array $a, array $b): int => [$b['products'], $a['department']] <=> [$a['products'], $b['department']]);

        return $out;
    }

    /**
     * @param  list<array{title: string}>  $texts
     * @param  array<int, string>  $typeOf
     * @param  array{types: list<array<string, mixed>>}  $bank
     * @return array{types: list<array{key: string, label: string, products: int, kinds: int, broad: bool}>, typed: int, broad_products: int, broad_share: float}
     */
    public static function specificity(array $texts, array $typeOf, array $bank): array
    {
        $openings = [];
        foreach ($typeOf as $index => $key) {
            $first = self::opening((string) ($texts[$index]['title'] ?? ''));
            if ($first !== '') {
                $openings[$key][$first] = ($openings[$key][$first] ?? 0) + 1;
            }
        }
        $types = [];
        $broadProducts = 0;
        $counts = array_count_values($typeOf);
        foreach ($bank['types'] as $type) {
            $key = (string) $type['key'];
            $kinds = count(array_filter($openings[$key] ?? [], fn (int $n): bool => $n >= 2));
            $broad = $kinds >= self::BROAD_KINDS;
            $products = (int) ($counts[$key] ?? 0);
            $broadProducts += $broad ? $products : 0;
            $label = $type['label'] ?? $key;
            $types[] = ['key' => $key, 'label' => is_array($label) ? (string) (reset($label) ?: $key) : (string) $label, 'products' => $products, 'kinds' => $kinds, 'broad' => $broad];
        }

        return ['types' => $types, 'typed' => count($typeOf), 'broad_products' => $broadProducts, 'broad_share' => $typeOf === [] ? 0.0 : round($broadProducts / count($typeOf), 3)];
    }

    /** The first word of a product's name that is not a number, as code reads words. */
    private static function opening(string $title): string
    {
        foreach (CatalogueFacts::tokens(TextNormalizer::forMatching($title), []) as $token) {
            if (preg_match('/^\d/u', $token) !== 1) {
                return $token;
            }
        }

        return '';
    }
}
