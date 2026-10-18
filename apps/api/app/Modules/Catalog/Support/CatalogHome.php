<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Admin\Contracts\HomeCard;
use App\Modules\Admin\Contracts\HomeCards;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** The site's pages and posts on the shop's home: how many were read, of each kind, and when last. Asked inside the shop. */
final class CatalogHome implements HomeCards
{
    public function cards(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        $read = CatalogContent::query()->active()->where('type', '!=', CatalogContent::KNOWLEDGE);
        $posts = (clone $read)->where('type', CatalogContent::POST)->count();
        $all = (clone $read)->count();
        $last = (clone $read)->max('synced_at');

        return [new HomeCard(
            key: 'pages',
            order: 40,
            label: __('catalog::home.card.label'),
            value: number_format($all),
            note: __('catalog::home.card.note', ['posts' => number_format($posts), 'pages' => number_format($all - $posts)]),
            pill: $last === null ? null : __('catalog::home.card.last', ['when' => Carbon::parse($last)->locale(app()->getLocale())->diffForHumans()]),
        )];
    }
}
