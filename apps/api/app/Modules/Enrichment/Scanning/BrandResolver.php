<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;

/**
 * Who makes a product, read in code. Stores rarely fill a brand field: the pilot store has it on
 * some power tools, as a "מותג" attribute on some care products, and otherwise only in the title
 * ("של חברת FLOOD", "מבית Einhell") or deep in the description ("לרכישת מוצרי STANLEY נוספים").
 *
 * Sources, most trusted first: the brand field, a brand attribute, the title, the short
 * description, the description. In text, a brand counts only as a whole word, and a description
 * that names several brands decides nothing unless one clearly dominates.
 */
final class BrandResolver
{
    /** Spellings seen in Israeli hardware stores, lowercased => the brand's name. */
    private const KNOWN = [
        'makita' => 'Makita', 'מקיטה' => 'Makita',
        'stanley' => 'Stanley', 'סטנלי' => 'Stanley',
        'einhell' => 'Einhell', 'איינהל' => 'Einhell',
        'dewalt' => 'DeWalt', 'דיוולט' => 'DeWalt',
        'bosch' => 'Bosch',
        'milwaukee' => 'Milwaukee', 'מילווקי' => 'Milwaukee',
        'ryobi' => 'Ryobi', 'ריובי' => 'Ryobi',
        'black+decker' => 'Black+Decker', 'black & decker' => 'Black+Decker', 'black and decker' => 'Black+Decker', 'בלק אנד דקר' => 'Black+Decker',
        'metabo' => 'Metabo', 'hikoki' => 'HiKOKI', 'dremel' => 'Dremel', 'festool' => 'Festool', 'worx' => 'Worx',
        'ingco' => 'Ingco', 'total tools' => 'Total',
        'hunter' => 'Hunter', 'האנטר' => 'Hunter',
        'kreg' => 'Kreg', 'קרג' => 'Kreg',
        'karcher' => 'Kärcher', 'kärcher' => 'Kärcher', 'קרשר' => 'Kärcher',
        'lavor' => 'Lavor',
        'blanchon' => 'Blanchon', 'בלנשון' => 'Blanchon',
        'flood' => 'Flood',
        'bondex' => 'Bondex', 'בונדקס' => 'Bondex',
        'watco' => 'Watco',
        'adler' => 'Adler',
        'syntilor' => 'Syntilor',
        'ciranova' => 'Ciranova',
        'soudal' => 'Soudal',
        'wd-40' => 'WD-40',
        'crc' => 'CRC',
        '3m' => '3M',
        'torpedo' => 'Torpedo', 'טורפדו' => 'Torpedo',
        'timbertech' => 'TimberTech', 'טימברטק' => 'TimberTech',
        'dasso' => 'Dasso',
        'camo' => 'Camo',
        'mibrag' => 'מיברג', 'מיברג' => 'מיברג',
        'יעקבי' => 'יעקבי', 'יעקובי' => 'יעקבי',
        'vileda' => 'Vileda', 'וילדה' => 'Vileda',
        'tambour' => 'Tambour', 'טמבור' => 'Tambour',
        'nirlat' => 'Nirlat', 'נירלט' => 'Nirlat',
    ];

    private const BRAND_ATTRIBUTE = '~^(?:מותג|brand|יצרן|manufacturer)$~iu';

    /** Words that introduce the maker, followed by the brand within a few words. */
    private const MAKER_WORDS = '(?:מבית|של חברת|חברת|מתוצרת|מוצרי|המותג|היצרן|יצרן|by)';

    private const MAKER_IN_TITLE = '~(?:מבית|של חברת|מתוצרת)\s+([A-Z][A-Za-z0-9+&.\-]{1,24})~u';

    /** @var array<string, string> lowercased spelling => brand */
    private array $spellings;

    /** @param array<string, string> $storeBrands extra spellings from the store, lowercased => brand */
    public function __construct(array $storeBrands = [])
    {
        $this->spellings = $storeBrands + self::KNOWN;

        // Longer spellings first, so "black and decker" wins over a shorter overlapping name.
        uksort($this->spellings, fn (string $a, string $b): int => [mb_strlen($b), $a] <=> [mb_strlen($a), $b]);
    }

    /**
     * Brand names the store itself uses, from brand fields and brand attributes.
     *
     * @param  iterable<CatalogProduct>  $products
     */
    public static function forCatalog(iterable $products): self
    {
        $brands = [];

        foreach ($products as $product) {
            foreach (self::fieldValues($product) as $value) {
                // a Shopify Vendor its own product never says is no brand to look for in other products' words either
                if (self::vendorOnly($product, $value) && ! self::saidByProduct($product, $value)) {
                    continue;
                }
                $brand = self::KNOWN[mb_strtolower($value)] ?? $value;
                $brands[mb_strtolower($value)] = $brand;
            }
        }

        return new self($brands);
    }

    /**
     * Whether a field value is only a Shopify product's Vendor: where many stores keep who they bought from, or their own
     * codes (Aviad, 20.11: "the Vendor in Shopify's settings is mostly the store's own details it does not want shown").
     */
    private static function vendorOnly(CatalogProduct $product, string $value): bool
    {
        $vendor = trim((string) ($product->payload['vendor'] ?? ''));

        return $vendor !== '' && mb_strtolower($vendor) === mb_strtolower(trim($value));
    }

    /**
     * Whether the product's own words (its title, descriptions and tags) say a name, whole: in full, or the part of it in
     * one script ("סגל Segal" said as "Segal" or "סגל", with the letters Hebrew joins before a word).
     */
    private static function saidByProduct(CatalogProduct $product, string $name): bool
    {
        $text = TextNormalizer::clean(strip_tags($product->title.' ; '.$product->shortDescription().' ; '.$product->description().' ; '.implode(' ; ', array_map('strval', (array) ($product->payload['tags'] ?? [])))));
        $parts = [trim($name)];
        if (preg_match('/\p{Hebrew}/u', $name) === 1 && preg_match('/\p{Latin}/u', $name) === 1) {
            $parts[] = trim((string) preg_replace('/[^\p{Hebrew}\s\'"׳״-]+/u', ' ', $name));
            $parts[] = trim((string) preg_replace('/[^\p{Latin}\d\s&+.\'-]+/u', ' ', $name));
        }
        foreach (array_filter($parts, fn (string $p): bool => mb_strlen($p) >= 2) as $part) {
            $prefix = preg_match('/^\p{Hebrew}/u', $part) === 1 ? '[והבלמש]?' : '';
            if (preg_match('/(?<![\p{L}\p{N}])'.$prefix.preg_quote((string) preg_replace('/\s+/u', ' ', $part), '/').'(?![\p{L}\p{N}])/iu', (string) preg_replace('/\s+/u', ' ', $text)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{brand: string, source: string, quote: string}|null
     */
    public function resolve(CatalogProduct $product): ?array
    {
        foreach (self::fieldValues($product) as $value) {
            // a Shopify Vendor is the brand only where the product's own words say it too (Rocks keeps its consignors'
            // names there: "Aeraj", "Sivan", "לא ידוע"); else the brand is read from the words, as for any product
            $vendor = self::vendorOnly($product, $value);
            if ($vendor && ! self::saidByProduct($product, $value)) {
                continue;
            }

            // (a Vendor its product says is told apart: what was read of a Vendor alone before is never taken for it)
            $source = $vendor ? 'store_field_said' : (mb_strtolower(trim((string) $product->brand)) === mb_strtolower(trim($value)) ? 'store_field' : 'store_attribute');

            return ['brand' => $this->spellings[mb_strtolower($value)] ?? $value, 'source' => $source, 'quote' => $value];
        }

        foreach (['title' => $product->title, 'short_description' => $product->shortDescription(), 'description' => $product->description()] as $source => $text) {
            $clean = TextNormalizer::clean(strip_tags((string) $text));
            $found = $this->inText($clean);

            // A description names other brands too ("we recommend Blanchon oil for this oak slab").
            // There a brand counts only when introduced as the maker, or named twice.
            if ($source !== 'title') {
                $found = array_filter($found, fn (int $count, string $brand): bool => $count >= 2 || $this->introducedAsMaker($clean, $brand), ARRAY_FILTER_USE_BOTH);
            }

            // A maker the list does not know, introduced in the title: "ספריי להסרת עובש מבית SAG".
            if ($found === [] && $source === 'title' && preg_match(self::MAKER_IN_TITLE, $clean, $match)) {
                return ['brand' => $match[1], 'source' => 'title_maker', 'quote' => $match[0]];
            }

            if ($found === []) {
                continue;
            }

            arsort($found);
            $brands = array_keys($found);
            $counts = array_values($found);

            // One brand, or one clearly ahead of the rest in a long description.
            if (count($brands) === 1 || ($source === 'description' && $counts[0] >= 2 * $counts[1])) {
                return ['brand' => $brands[0], 'source' => $source, 'quote' => $this->quote((string) $text, $brands[0])];
            }

            if ($source === 'title') {
                return null;
            }
        }

        return null;
    }

    /** @return array<string, int> brand => how many times it is named */
    private function inText(string $text): array
    {
        $found = [];
        $lower = mb_strtolower($text);

        foreach ($this->spellings as $spelling => $brand) {
            $pattern = preg_match('~\p{Hebrew}~u', $spelling)
                ? '~(?<![\p{L}])[המבלו]?'.preg_quote($spelling, '~').'(?![\p{L}])~u'
                : '~(?<![\p{L}\d])'.preg_quote($spelling, '~').'(?![\p{L}\d])~u';

            $count = (int) preg_match_all($pattern, $lower);

            if ($count > 0) {
                $found[$brand] = ($found[$brand] ?? 0) + $count;
                // Blank out the match so a shorter spelling inside it does not count again.
                $lower = (string) preg_replace($pattern, ' ', $lower);
            }
        }

        return $found;
    }

    private function introducedAsMaker(string $text, string $brand): bool
    {
        foreach ($this->spellings as $spelling => $name) {
            if ($name === $brand && preg_match('~'.self::MAKER_WORDS.'[^.\n]{0,30}?(?<![\p{L}\d])'.preg_quote($spelling, '~').'(?![\p{L}\d])~iu', $text)) {
                return true;
            }
        }

        return false;
    }

    private function quote(string $text, string $brand): string
    {
        $text = TextNormalizer::clean(strip_tags($text));

        foreach ($this->spellings as $spelling => $name) {
            if ($name === $brand && ($position = mb_stripos($text, $spelling)) !== false) {
                return mb_substr($text, max(0, $position - 30), mb_strlen($spelling) + 60);
            }
        }

        return $brand;
    }

    /** @return list<string> */
    private static function fieldValues(CatalogProduct $product): array
    {
        $values = [];

        if (is_string($product->brand) && trim($product->brand) !== '') {
            $values[] = trim($product->brand);
        }

        foreach ($product->storeAttributes() as $attribute) {
            if (preg_match(self::BRAND_ATTRIBUTE, trim($attribute['name'])) && count($attribute['values']) === 1) {
                $values[] = trim($attribute['values'][0]);
            }
        }

        return $values;
    }
}
