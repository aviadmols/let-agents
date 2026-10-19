<?php

namespace App\Modules\Assistant\Support;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use Throwable;

/**
 * What a shopper is offered when the store's pages had no answer, as the shop chose it
 * (assistant.no_answer) and as far as the shop can keep it:
 *
 * - team: the team on WhatsApp, in the opening hours the storefront reads at the shop's time; outside
 *   them the shopper leaves details. Needs WhatsApp on with the shop's own number and the offer on
 *   (assistant.search_whatsapp); without it, this is "details".
 * - details: the shopper leaves a way back (shoppers.callbacks); without it, this is "none".
 * - none: only "not found".
 *
 * Code only, read with every reply that is not an answer, so the storefront never offers what the
 * shop turned off.
 */
final class NoAnswer
{
    public const TEAM = 'team';

    public const DETAILS = 'details';

    public const NONE = 'none';

    public const ALL = [self::TEAM, self::DETAILS, self::NONE];

    public static function offer(string $shopId): string
    {
        $chosen = (string) Settings::get('assistant.no_answer', $shopId);

        if ($chosen === self::TEAM && self::whatsapp($shopId)) {
            return self::TEAM;
        }

        if ($chosen !== self::NONE && Features::enabled('shoppers.callbacks', $shopId)) {
            return self::DETAILS;
        }

        return self::NONE;
    }

    /** Whether the shop's WhatsApp can be offered at all: on, with the shop's own number, and the offer on. */
    public static function whatsapp(string $shopId): bool
    {
        try {
            // The shop's own number only: a number saved for every shop at once is never another shop's.
            $number = (string) preg_replace('/\D/', '', (string) Settings::overrideFor('widget.whatsapp_number', $shopId));
        } catch (Throwable) {
            return false;
        }

        return strlen($number) >= 8
            && Features::enabled('widget.whatsapp', $shopId)
            && Features::enabled('assistant.search_whatsapp', $shopId);
    }
}
