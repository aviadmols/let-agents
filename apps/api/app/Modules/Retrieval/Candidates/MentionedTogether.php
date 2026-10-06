<?php

namespace App\Modules\Retrieval\Candidates;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\Candidate;
use App\Modules\Retrieval\Contracts\CandidateSource;

/** Products the store's own guides and pages name alongside this one. */
final class MentionedTogether implements CandidateSource
{
    /** @var array<string, array<string, int>> external ID => partner external ID => guides naming both */
    private array $together = [];

    /** @var array<string, string> external ID => catalogue ID */
    private array $ids = [];

    public function key(): string
    {
        return 'mentioned_together';
    }

    public function prepare(string $shopId): void
    {
        $this->together = [];
        $this->ids = CatalogProduct::query()->active()->pluck('id', 'external_id')->map(fn ($id): string => (string) $id)->all();

        foreach (CatalogContent::query()->active()->whereNotNull('product_external_ids')->lazy(200) as $content) {
            $named = array_values(array_unique(array_map('strval', (array) $content->product_external_ids)));

            // A catalogue page naming forty products says nothing about any two of them.
            if (count($named) < 2 || count($named) > 12) {
                continue;
            }

            foreach ($named as $one) {
                foreach ($named as $other) {
                    if ($one !== $other) {
                        $this->together[$one][$other] = ($this->together[$one][$other] ?? 0) + 1;
                    }
                }
            }
        }
    }

    public function candidates(CatalogProduct $product, int $limit): array
    {
        $partners = $this->together[$product->external_id] ?? [];
        arsort($partners);
        $found = [];

        foreach ($partners as $external => $guides) {
            $id = $this->ids[(string) $external] ?? null;

            if ($id !== null && count($found) < $limit) {
                $found[] = new Candidate($id, $this->key(), (float) $guides, ['guides_together' => $guides]);
            }
        }

        return $found;
    }
}
