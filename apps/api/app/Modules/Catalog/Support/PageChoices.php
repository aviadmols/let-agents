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

        return ['key' => rawurldecode($input->getAttribute('name')), 'label' => $label, 'kind' => $kind, 'required' => $required, 'values' => $values];
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
            'value' => rawurldecode(trim($node->getAttribute('value'))),
            'label' => $label,
            'price' => $total === null ? null : round($total, 2),
            'meters' => $meters,
        ], fn ($v): bool => $v !== null) + ['price' => null];
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
