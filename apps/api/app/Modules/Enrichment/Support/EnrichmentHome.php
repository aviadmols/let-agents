<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Admin\Contracts\FirstStep;
use App\Modules\Admin\Contracts\FirstSteps;
use App\Modules\Admin\Contracts\HomeCard;
use App\Modules\Admin\Contracts\HomeCards;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Filament\Merchant\Pages\MissingInfo;
use App\Modules\Enrichment\Models\EnrichmentCodeReading;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Scanning\RequiredGaps;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * The catalogue on the shop's home: how many of the store's products were read (the "products scanned"
 * card, with when the last scan ran), and the first step "fill in missing details", done once the team
 * filled one. Asked inside the shop.
 */
final class EnrichmentHome implements FirstSteps, HomeCards
{
    public function cards(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array
    {
        $total = CatalogProduct::query()->active()->count();
        $read = EnrichmentCodeReading::query()->whereIn('product_id', CatalogProduct::query()->active()->select('id'))->count();
        $last = Run::query()->where('shop_id', $shop->id)->where('status', RunStatus::Succeeded)->latest('created_at')->value('created_at');

        return [new HomeCard(
            key: 'products',
            order: 30,
            label: __('enrichment::home.card.label'),
            value: number_format(min($read, $total)),
            of: __('enrichment::home.card.of', ['total' => number_format($total)]),
            note: $total > $read ? __('enrichment::home.card.waiting', ['count' => number_format($total - $read)]) : null,
            pill: $last === null ? __('enrichment::home.card.never') : __('enrichment::home.card.last', ['when' => $last->locale(app()->getLocale())->diffForHumans()]),
            tone: $last === null ? 'warn' : '',
        )];
    }

    public function steps(Shop $shop): array
    {
        // The gaps are counted once in a while: they are read from every product.
        $top = Cache::remember("enrichment:home:gap:{$shop->id}:".app()->getLocale(), 600, function (): ?array {
            $gap = RequiredGaps::of([VocabularyDefinition::REQUIRED], app()->getLocale() === 'en' ? 'en' : 'he')[0] ?? null;

            return $gap === null ? null : ['count' => count($gap['missing']), 'type' => $gap['type_label'], 'attribute' => $gap['attribute_label']];
        });

        return [new FirstStep(
            key: 'missing',
            order: 40,
            title: __('enrichment::home.step.title'),
            body: $top === null ? __('enrichment::home.step.body_none') : __('enrichment::home.step.body', $top),
            button: __('enrichment::home.step.button'),
            url: MissingInfo::getUrl(panel: 'merchant', tenant: $shop),
            done: EnrichmentFact::query()->where('origin', FactOrigin::Person)->exists(),
        )];
    }
}
