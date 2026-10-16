<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\BuildCards;
use App\Modules\Enrichment\Contracts\ReadsCards;
use App\Modules\Enrichment\Models\EnrichmentCard;
use App\Modules\Runs\Enums\RunTrigger;

/**
 * The stored cards (BuildCards). A product or a page met before its card was written gets one built
 * now, so a reader never waits for the next sync or the night.
 */
final class StoredCards implements ReadsCards
{
    public function product(string $productId): ?array
    {
        return $this->products([$productId])[$productId] ?? null;
    }

    public function products(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('strval', $productIds)));
        $found = EnrichmentCard::query()->where('kind', EnrichmentCard::PRODUCT)->whereIn('subject_id', $productIds)->pluck('card', 'subject_id')->all();
        $missing = array_values(array_diff($productIds, array_keys($found)));

        if ($missing !== []) {
            $products = CatalogProduct::query()->whereIn('id', $missing)->whereNull('removed_at')->get(['id', 'shop_id', 'external_id']);
            if ($products->isNotEmpty()) {
                app(BuildCards::class)->handle((string) $products->first()->shop_id, $products->pluck('external_id')->map(fn ($id): string => (string) $id)->all(), RunTrigger::System);
                $found += EnrichmentCard::query()->where('kind', EnrichmentCard::PRODUCT)->whereIn('subject_id', $missing)->pluck('card', 'subject_id')->all();
            }
        }

        return array_map(fn ($card): array => is_array($card) ? $card : (array) json_decode((string) $card, true), $found);
    }

    public function content(string $contentId): ?array
    {
        $card = EnrichmentCard::query()->where('kind', EnrichmentCard::CONTENT)->where('subject_id', $contentId)->value('card');

        if ($card === null && ($content = CatalogContent::query()->whereKey($contentId)->whereNull('removed_at')->first()) !== null) {
            // pages are carded with the whole shop's cards (only before the first build: a sync cards them after)
            app(BuildCards::class)->handle((string) $content->shop_id, null, RunTrigger::System);
            $card = EnrichmentCard::query()->where('kind', EnrichmentCard::CONTENT)->where('subject_id', $contentId)->value('card');
        }

        return $card === null ? null : (is_array($card) ? $card : (array) json_decode((string) $card, true));
    }

    public function store(): ?array
    {
        $card = EnrichmentCard::query()->where('kind', EnrichmentCard::STORE)->where('subject_id', EnrichmentCard::STORE)->value('card');

        return $card === null ? null : (is_array($card) ? $card : (array) json_decode((string) $card, true));
    }
}
