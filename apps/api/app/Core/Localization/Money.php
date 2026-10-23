<?php

namespace App\Core\Localization;

/**
 * Money as the panels show it, in the shop's own currency (shops.currency): its sign before the amount (₪, $, €, £),
 * or its code for another. A total drops the agorot from a thousand up; a price keeps them unless it is whole.
 */
final class Money
{
    private const SIGNS = ['ILS' => '₪', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];

    /** The currency's sign, or its code when it has none here. */
    public static function symbol(string $currency): string
    {
        return self::SIGNS[strtoupper($currency)] ?? strtoupper($currency);
    }

    /** A sum (revenue, an order): ₪1,250 / ₪49.90. */
    public static function total(float $amount, string $currency): string
    {
        return self::prefix($currency).number_format($amount, $amount >= 1000 ? 0 : 2);
    }

    /** One product's price, exact: ₪1,299.90 / ₪120. */
    public static function price(float $amount, string $currency): string
    {
        return self::prefix($currency).number_format($amount, $amount == floor($amount) ? 0 : 2);
    }

    private static function prefix(string $currency): string
    {
        return self::SIGNS[strtoupper($currency)] ?? strtoupper($currency).' ';
    }
}
