<?php

namespace App\Modules\Catalog\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The choices a product page asks for before "add to cart" that the store's feed does not carry:
 * fields of WooCommerce Product Add-Ons and the themes built on it (a length, a size, an engraving
 * choice), each with its values and the price the product comes to with each. Read from the
 * page's own add-to-cart form, so it serves any store that uses them, not one theme.
 *
 * A value's price, as the page states it:
 *   - data-percent (the add-on adds a share of the price): its data-price when the page wrote the
 *     total, else the price × (1 + percent / 100);
 *   - data-price-type "percentage_based": the price × (1 + raw / 100);
 *   - "flat_fee" / "quantity_based", or a bare data-price / data-raw-price: the price + it;
 *   - none: the product's price.
 * A length ("5.40 מטר", "3 m", "300 ס״מ") is read into metres, so a project can be counted.
 * Code only; nothing is sent anywhere.
 */
final class PageChoices
{
    private const MAX_FIELDS = 8;

    private const MAX_VALUES = 60;

    /** The add-to-cart form alone: what the fields are read from, and what a change is told by. */
    public static function form(string $html): ?string
    {
        return preg_match('~<form\b[^>]*class=["\'][^"\']*\bcart\b[^"\']*["\'][^>]*>.*?</form>~si', $html, $m) === 1 ? $m[0] : null;
    }

    /**
     * @return list<array{key: string, label: string, kind: string, required: bool, values: list<array{value: string, label: string, price: float|null, meters?: float}>}>
     */
    public static function read(string $form, ?float $price): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><html><body>'.$form.'</body></html>', LIBXML_NONET | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $fields = [];

        // Lists: one value chosen from a select.
        foreach ($xpath->query('//select[starts-with(@name, "addon-")]') ?: [] as $select) {
            if (! $select instanceof DOMElement) {
                continue;
            }
            $values = [];
            foreach ($xpath->query('.//option', $select) ?: [] as $option) {
                if ($option instanceof DOMElement && trim($option->getAttribute('value')) !== '') {
                    $values[] = self::value($option, trim((string) $option->textContent), $price);
                }
            }
            $fields[] = self::field($xpath, $select, $values, $select->hasAttribute('required'));
        }

        // Radio buttons and images: one value chosen from a group of the same name.
        $groups = [];
        foreach ($xpath->query('//input[@type="radio" and starts-with(@name, "addon-")]') ?: [] as $radio) {
            if ($radio instanceof DOMElement && trim($radio->getAttribute('value')) !== '') {
                $groups[$radio->getAttribute('name')][] = $radio;
            }
        }
        foreach ($groups as $radios) {
            $values = array_map(fn (DOMElement $radio): array => self::value($radio, self::radioLabel($xpath, $radio), $price), $radios);
            $fields[] = self::field($xpath, $radios[0], $values, $radios[0]->hasAttribute('required'));
        }

        return array_slice(array_values(array_filter($fields, fn (array $field): bool => $field['values'] !== [])), 0, self::MAX_FIELDS);
    }

    /**
     * @param  list<array{value: string, label: string, price: float|null, meters?: float}>  $values
     * @return array{key: string, label: string, kind: string, required: bool, values: list<array{value: string, label: string, price: float|null, meters?: float}>}
     */
    private static function field(DOMXPath $xpath, DOMElement $input, array $values, bool $required): array
    {
        $label = self::fieldLabel($xpath, $input);
        $values = array_slice($values, 0, self::MAX_VALUES);
        $kind = match (true) {
            preg_match('/אורך|length/iu', $label) === 1 || ($values !== [] && count(array_filter($values, fn (array $v): bool => isset($v['meters']))) === count($values)) => 'length',
            preg_match('/מידה|גודל|size/iu', $label) === 1 => 'size',
            preg_match('/צבע|גוון|colou?r/iu', $label) === 1 => 'colour',
            default => 'choice',
        };

        // The name exactly as the page has it (a theme may keep it percent-encoded): the form posts it so.
        return ['key' => $input->getAttribute('name'), 'label' => $label, 'kind' => $kind, 'required' => $required, 'values' => $values];
    }

    /** @return array{value: string, label: string, price: float|null, meters?: float} */
    private static function value(DOMElement $node, string $label, ?float $price): array
    {
        $label = self::plain($label);
        $number = fn (string $name): ?float => is_numeric(trim($node->getAttribute($name))) ? (float) trim($node->getAttribute($name)) : null;
        $percent = $number('data-percent');
        $stated = $number('data-price');
        $raw = $number('data-raw-price') ?? $stated;
        $type = strtolower(trim($node->getAttribute('data-price-type')));

        $total = match (true) {
            $percent !== null => $stated ?? ($price === null ? null : $price * (1 + $percent / 100)),
            $type === 'percentage_based' => $price === null || $raw === null ? null : $price * (1 + $raw / 100),
            $raw !== null => $price === null ? null : $price + $raw,
            default => $price,
        };
        $meters = self::meters($label);

        return array_filter([
            'value' => trim($node->getAttribute('value')),
            'label' => $label,
            'price' => $total === null ? null : round($total, 2),
            'meters' => $meters,
        ], fn ($v): bool => $v !== null) + ['price' => null];
    }

    /**
     * The size the page says the product itself is, when it is one item of one size (a ring "מידה
     * נוכחית: 54"): a size attribute on the page's size picker (data-current-size="54"), else a size
     * label with a number in the page's own text ("מידה: 54", "מידת הטבעת 7", "Ring size: 7.5").
     * Null when the page says none, or only a size table.
     *
     * @return array{size: float, label: string}|null
     */
    public static function size(string $html): ?array
    {
        $number = '(\d{1,3}(?:[.,]\d{1,2})?)';

        if (preg_match('~\bdata-(?:current-|product-|ring-)?size=["\']\s*'.$number.'\s*["\']~iu', $html, $m) === 1) {
            $label = preg_match('~\bdata-current-size-label=["\']([^"\']{1,40})["\']~u', $html, $l) === 1 ? html_entity_decode($l[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';

            return self::sized($m[1], $label);
        }

        $text = html_entity_decode(strip_tags((string) preg_replace('~<(script|style|table)\b.*?</\1>~siu', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        if (preg_match('/(מידה נוכחית|מידת הטבעת|מידת טבעת|מידה|ring size|current size|size)\s*[:：]\s*'.$number.'(?!\s*(?:ס"מ|ס״מ|מ"מ|מ״מ|cm|mm|%|₪|\d))/iu', $text, $m) === 1) {
            return self::sized($m[2], trim($m[1]));
        }

        return null;
    }

    /** The other sizes are within this many of the one it is: a resize, not a size table's whole range. */
    private const RESIZE_REACH = 15;

    /**
     * Every size the page offers the product in, its own among them, when it offers others: a ring of 53
     * the store makes smaller or larger ("הקטנה ל 51", "הגדלה ל 54", "Resize to 7.5"), or a picker whose
     * choices hold its size and others around it. Sorted; empty when the page offers none but its own.
     *
     * @return list<float>
     */
    public static function offered(string $html, float $current): array
    {
        $number = '(\d{1,3}(?:[.,]\d{1,2})?)';
        $found = [];

        $text = html_entity_decode(strip_tags((string) preg_replace('~<(script|style)\b.*?</\1>~siu', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match_all('/(?:הגדלה|הקטנה|התאמה|הרחבה)\s*ל[\s\-־]*'.$number.'|resize(?:d)?\s+to\s+'.$number.'/iu', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                $found[] = (float) str_replace(',', '.', $hit[1] !== '' ? $hit[1] : $hit[2]);
            }
        }

        // a picker of radio choices, one of them its own size: the others are the sizes offered
        $groups = [];
        if (preg_match_all('~<input\b[^>]*\btype=["\']radio["\'][^>]*>~iu', $html, $inputs) > 0) {
            foreach ($inputs[0] as $input) {
                if (preg_match('~\bname=["\']([^"\']+)["\']~u', $input, $n) === 1 && preg_match('~\bvalue=["\']\s*'.$number.'\s*["\']~u', $input, $v) === 1) {
                    $groups[$n[1]][] = (float) str_replace(',', '.', $v[1]);
                }
            }
        }
        foreach ($groups as $values) {
            if (count($values) >= 2 && in_array($current, $values, true)) {
                array_push($found, ...$values);
            }
        }

        $found = array_values(array_unique(array_filter($found, fn (float $z): bool => $z > 0 && abs($z - $current) <= self::RESIZE_REACH)));
        if ($found === [] || $found === [$current]) {
            return [];
        }
        $found[] = $current;
        $found = array_values(array_unique(array_map(fn (float $z): float => round($z, 2), $found)));
        sort($found);

        return $found;
    }

    /** @return array{size: float, label: string}|null */
    private static function sized(string $value, string $label): ?array
    {
        $size = (float) str_replace(',', '.', $value);

        // A size, not a weight, a year or a price.
        return $size > 0 && $size <= 120 ? ['size' => round($size, 2), 'label' => mb_substr(trim($label), 0, 60)] : null;
    }

    /** "5.40 מטר", "3 m", "2.4 מ'", "300 ס״מ", "120cm": metres; anything else: null. */
    public static function meters(string $label): ?float
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:מטר|מ\'|מ׳|m\b|meters?\b|metres?\b)/iu', $label, $m) === 1) {
            return round((float) str_replace(',', '.', $m[1]), 3);
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:ס"מ|ס״מ|סמ\b|cm\b)/iu', $label, $m) === 1) {
            return round((float) str_replace(',', '.', $m[1]) / 100, 3);
        }

        return null;
    }

    private static function fieldLabel(DOMXPath $xpath, DOMElement $input): string
    {
        $id = $input->getAttribute('id');
        $label = $id === '' ? null : $xpath->query('//label[@for="'.addslashes($id).'"]')?->item(0);

        if ($label === null) {
            // Product Add-Ons: the field's name sits in its wrapper, above the input.
            $label = $xpath->query('ancestor::*[contains(concat(" ", @class, " "), " wc-pao-addon ")][1]//*[contains(@class, "wc-pao-addon-name")]', $input)?->item(0);
        }

        return $label === null ? '' : self::plain((string) $label->textContent);
    }

    private static function radioLabel(DOMXPath $xpath, DOMElement $radio): string
    {
        $id = $radio->getAttribute('id');
        $label = $id === '' ? null : $xpath->query('//label[@for="'.addslashes($id).'"]')?->item(0);
        $label ??= $xpath->query('ancestor::label[1]', $radio)?->item(0);

        return $label === null ? $radio->getAttribute('value') : (string) $label->textContent;
    }

    /** Text as a shopper reads it: no required star, no price in brackets, one space. */
    private static function plain(string $text): string
    {
        $text = (string) preg_replace('/\s*\*\s*$/u', '', trim((string) preg_replace('/\s+/u', ' ', $text)));

        return trim((string) preg_replace('/\s*\(\s*[+\-]?\s*[₪$€]?\s*[\d.,]+\s*[₪$€%]?\s*\)\s*$/u', '', $text));
    }
}
