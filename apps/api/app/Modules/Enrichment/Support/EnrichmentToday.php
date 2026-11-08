<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Admin\Contracts\TodayFix;
use App\Modules\Admin\Contracts\TodayImprovements;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ApplyTeamValue;
use App\Modules\Enrichment\Filament\Merchant\Pages\MissingInfo;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Scanning\RequiredGaps;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Tenancy\Models\Shop;

/**
 * Details products lack that their photos already show, on Today's "Waiting for you": one card per detail (a metal,
 * a colour) with the products whose photo was read and waits for a person, approved in one tap as read ("Nice to
 * have"). The products still missing it with no reading wait on the missing-details screen. Code only here: the
 * reading itself was done before (SuggestFromPicture).
 */
final class EnrichmentToday implements TodayImprovements
{
    public function waiting(Shop $shop): array
    {
        $fixes = [];
        foreach ($this->gaps() as $gap) {
            $waiting = $this->waitingIn($gap);
            if ($waiting === []) {
                continue;
            }
            $images = CatalogProduct::query()->whereIn('external_id', array_slice(array_column($waiting, 'external_id'), 0, 6))->pluck('image_url', 'external_id');
            $pictures = array_values(array_filter(array_map(fn (array $p): ?string => $images[$p['external_id']] ?? null, array_slice($waiting, 0, 2))));
            // what each photo showed, as the Improvements screen lists it before the owner approves
            $facts = array_map(fn (array $p): array => ['title' => (string) $p['title'], 'picture' => $images[$p['external_id']] ?? null, 'label' => (string) $gap['attribute_label'], 'value' => (string) ($p['waiting'] ?? '')], array_slice($waiting, 0, 6));
            $fixes[] = new TodayFix(
                key: self::key($gap),
                chip: TodayFix::NICE,
                count: count($waiting),
                unit: 'products',
                title: trans_choice('enrichment::today.gap.title', count($waiting), ['n' => count($waiting), 'type' => $gap['type_label'], 'attribute' => $gap['attribute_label']]),
                text: __('enrichment::today.gap.text'),
                action: __('enrichment::today.gap.action', ['attribute' => $gap['attribute_label']]),
                oneTap: true,
                url: MissingInfo::getUrl(panel: 'merchant', tenant: $shop),
                pictures: $pictures,
                impact: count($waiting),
                kind: 'info',
                suggestion: ['type' => 'facts', 'facts' => $facts, 'more' => max(0, count($waiting) - count($facts))],
                secondary: ['label' => __('enrichment::today.gap.one_by_one'), 'url' => MissingInfo::getUrl(panel: 'merchant', tenant: $shop)],
            );
        }

        return $fixes;
    }

    public function approve(Shop $shop, string $key, ?int $userId, ?string $text = null): ?string
    {
        if (! str_starts_with($key, 'gap:')) {
            return null;
        }
        foreach ($this->gaps() as $gap) {
            if (self::key($gap) !== $key) {
                continue;
            }
            $multiple = (bool) EnrichmentVocabulary::query()->find($gap['vocabulary_id'])?->definition()->isMultiple($gap['attribute']);
            $done = 0;
            foreach ($this->waitingIn($gap) as $product) {
                $value = $multiple ? $product['waiting_keys'] : $product['waiting_keys'][0];
                $done += app(ApplyTeamValue::class)->set((string) $product['id'], (string) $gap['vocabulary_id'], (string) $gap['attribute'], $value)['ok'] ? 1 : 0;
            }

            return trans_choice('enrichment::today.gap.done', $done, ['n' => $done, 'attribute' => $gap['attribute_label']]);
        }

        return null;
    }

    /** A detail read from photos is checked one by one on the missing-details screen, never dismissed for every product at once. */
    public function dismiss(Shop $shop, string $key, ?int $userId): bool
    {
        return false;
    }

    public function wins(Shop $shop, int $limit): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    private function gaps(): array
    {
        return RequiredGaps::of([VocabularyDefinition::REQUIRED, VocabularyDefinition::RECOMMENDED], app()->getLocale() === 'en' ? 'en' : 'he');
    }

    /**
     * @param  array<string, mixed>  $gap
     * @return list<array{id: string, external_id: string, waiting_keys: list<string>}>
     */
    private function waitingIn(array $gap): array
    {
        return array_values(array_filter((array) $gap['missing'], fn (array $p): bool => ($p['waiting_keys'] ?? []) !== []));
    }

    /** @param array<string, mixed> $gap */
    private static function key(array $gap): string
    {
        return 'gap:'.$gap['vocabulary_id'].':'.$gap['attribute'];
    }
}
