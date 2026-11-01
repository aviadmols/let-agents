<?php

namespace App\Core\Text;

/**
 * A cell of a file a person opens in Excel or Sheets (1.11): text a store or a shopper wrote that begins like a formula
 * ("=HYPERLINK(…)", "+…", "@…") is kept as text, with a leading apostrophe, so opening the file never runs it. A number
 * ("-5", "+972…" is not one) stays as it is.
 */
final class CsvCell
{
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<array-key, mixed>
     */
    public static function row(array $row): array
    {
        return array_map(self::safe(...), $row);
    }
}
