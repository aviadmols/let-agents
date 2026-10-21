<?php

namespace App\Modules\Assistant\Support;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many new questions reach a model in a day, counted three ways: one shopper (the id their
 * browser keeps), one network address, and the whole shop. A bot can make up a new shopper id
 * for every question, but not a new address, and the shop's cap holds whatever it does. Saved
 * answers are free and never counted.
 *
 * A store may lift the limit on each shopper (assistant.visitor_limit off): then neither the shopper
 * nor the address is counted, and the shop's own daily cap still keeps its plan and its budget.
 */
final class DailyQuestions
{
    public static function allow(string $shopId, string $visitorHash, ?string $ip): bool
    {
        $each = Features::enabled('assistant.visitor_limit', $shopId);
        $limits = ["assistant:shop:{$shopId}" => (int) Settings::get('assistant.questions_per_shop_per_day', $shopId)];

        if ($each) {
            $limits["assistant:visitor:{$shopId}:{$visitorHash}"] = (int) Settings::get('assistant.questions_per_visitor_per_day', $shopId);
        }

        if ($each && $ip !== null && $ip !== '') {
            // The address only as a hash salted per shop, like the shopper id.
            $limits['assistant:network:'.$shopId.':'.hash('sha256', $shopId.'|'.$ip)] = (int) Settings::get('assistant.questions_per_address_per_day', $shopId);
        }

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return false;
            }
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 86400);
        }

        return true;
    }
}
