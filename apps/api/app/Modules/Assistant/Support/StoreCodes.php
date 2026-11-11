<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Facades\Cache;

/**
 * The codes a store writes on its products and pages: grades, standards, sizes and models in Latin letters and digits
 * ("GH", "VS", "VVS1", "14K", "S925", "IP68"). A question that names one is about the store, by code and before any model
 * (Rocks 11.11: "מה הכוונה ב GH ו VS" was refused as not about the store; the store's rings say "GH" and "VS", and a short
 * code is too far by meaning to be found). A code is a word of capitals (two at least), or of letters and digits together;
 * an ordinary word is never one, in any language, so an English store's words do not make every question its own. Asked
 * inside the shop.
 */
final class StoreCodes
{
    /** The codes kept, the most written first. */
    private const MAX_CODES = 5000;

    private const CACHE_HOURS = 24;

    /** A code: capitals (2-8), or letters and digits mixed (at most 10), as one word. */
    private const CODE = '/(?<![\p{L}\p{N}])(?:[A-Z]{2,8}|(?=[A-Za-z]{0,9}\d)(?=\d{0,9}[A-Za-z])[A-Za-z0-9]{2,10})(?![\p{L}\p{N}])/u';

    /**
     * The codes of the question the store writes, as typed, in their order; none when it names none.
     *
     * @return list<string>
     */
    public static function in(string $shopId, string $question): array
    {
        preg_match_all(self::CODE, $question, $m);

        if ($m[0] === []) {
            return [];
        }

        $codes = self::codes($shopId);

        return array_values(array_unique(array_filter($m[0], fn (string $code): bool => isset($codes[mb_strtoupper($code)]))));
    }

    /**
     * Every code the store writes, upper case, with how often: its products (title, SKU, brand, description) and its
     * pages and knowledge (title, excerpt, body). Kept for a day, and read again as soon as any of them changes.
     *
     * @return array<string, int>
     */
    public static function codes(string $shopId): array
    {
        $stamp = implode('|', [
            CatalogContent::query()->active()->count(), (string) CatalogContent::query()->active()->max('updated_at'),
            CatalogProduct::query()->active()->count(), (string) CatalogProduct::query()->active()->max('updated_at'),
        ]);

        return Cache::remember('assistant.store_codes.'.$shopId.'.'.md5($stamp), now()->addHours(self::CACHE_HOURS), function (): array {
            $counts = [];
            $add = function (mixed $text) use (&$counts): void {
                preg_match_all(self::CODE, strip_tags(is_string($text) ? $text : ''), $m);
                foreach ($m[0] as $code) {
                    $key = mb_strtoupper($code);
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            };

            CatalogContent::query()->active()->select(['id', 'title', 'excerpt', 'body'])->chunkById(200, function ($pages) use ($add): void {
                foreach ($pages as $page) {
                    $add($page->title);
                    $add($page->excerpt);
                    $add($page->body);
                }
            });
            CatalogProduct::query()->active()->select(['id', 'title', 'sku', 'brand', 'payload'])->chunkById(500, function ($products) use ($add): void {
                foreach ($products as $product) {
                    $add($product->title);
                    $add($product->sku);
                    $add($product->brand);
                    $description = $product->payload['description'] ?? '';
                    $add(is_string($description) ? mb_substr($description, 0, 4000) : '');
                    $add($product->payload['short_description'] ?? '');
                }
            });

            // the most written first, then by the code, so the same store reads the same list on every server
            uksort($counts, fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

            return array_slice($counts, 0, self::MAX_CODES, true);
        });
    }
}
