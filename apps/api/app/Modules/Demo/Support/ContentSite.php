<?php

namespace App\Modules\Demo\Support;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Features\FeatureRegistry;
use App\Core\Settings\SettingRegistry;
use App\Modules\Tenancy\Enums\SearchMatters;
use App\Modules\Tenancy\Models\Shop;

/**
 * How a demo of a content site (WordPress with no store: tours, courses, events and articles about them) searches:
 * nothing goes into a cart, so each card's one action is "Read more" on the item's own page; its articles are shown
 * after the second row of cards, not after all of them; and its articles are found by what they say, not only by
 * their titles. The drawer is as it is: these are the store's own settings, as any store may set them.
 */
final class ContentSite
{
    /** @var array<string, bool|int|string> */
    private const SETTINGS = [
        'search.show_cart' => false,
        'search.card_action' => 'read_more',
        'search.guides_after' => 4,
        'search.store_currency' => true,
    ];

    public static function settle(string $shopId): void
    {
        foreach (self::SETTINGS as $key => $value) {
            if (app(SettingRegistry::class)->has($key)) {
                Settings::set($key, $value, $shopId);
            }
        }
        if (app(FeatureRegistry::class)->has('search.content_by_meaning')) {
            Features::override('search.content_by_meaning', true, $shopId);
        }

        // what the site is, as Settings · General shows it: a content site, unless the shop already said otherwise; and
        // what matters in its search, its content (no picture read, no search by photo: Gil Travel, 28.10, opened with a
        // camera it had no use for), unless the shop or the operator already chose
        $shop = Shop::query()->find($shopId);
        if ($shop === null) {
            return;
        }
        $profile = (array) ($shop->profile ?? []);
        $chose = SearchMatters::tryFrom((string) ($profile['matters'] ?? '')) !== null;
        $shop->forceFill(['profile' => [
            ...$profile,
            'kind' => in_array($profile['kind'] ?? 'store', ['store'], true) ? 'content' : $profile['kind'],
            'matters' => $chose ? $profile['matters'] : SearchMatters::Content->value,
        ]])->save();
        if (! $chose) {
            SearchMatters::Content->apply($shop);
        }
    }
}
