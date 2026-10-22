<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Admin\Contracts\ShopFacts;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Tenancy\Models\Shop;

/**
 * What the assistant knows, for the first-run screens: the answers ready for what shoppers ask, and one
 * thing most of the store's product pages say (what BuildShopKnowledge read from them, shipping first),
 * with on how many pages it was. Nothing is read here: only what the scan already kept. Asked inside the shop.
 */
final class AssistantShopFacts implements ShopFacts
{
    /** What a shopper asks first, in this order: the first line about it is the one shown. */
    private const FIRST = [
        '/משלוח|אספק|shipping|delivery/iu',
        '/החזר|החלפ|refund|return/iu',
        '/אחריות|warranty/iu',
    ];

    private const MAX_CHARS = 160;

    public function shopFacts(Shop $shop): array
    {
        $facts = ['answers' => AssistantAnswer::query()->where('status', AssistantAnswer::SHOWN)->where('outcome', AssistantAnswer::ANSWERED)->count()];
        $seen = $this->seen();

        return $seen === null ? $facts : $facts + ['seen' => $seen];
    }

    /** @return array{text: string, pages: int}|null */
    private function seen(): ?array
    {
        $row = CatalogContent::query()->where('type', CatalogContent::KNOWLEDGE)->where('external_id', BuildShopKnowledge::REPEATED)->whereNull('removed_at')->first();

        if ($row === null) {
            return null;
        }

        $lines = array_values(array_filter(array_map(
            fn (string $line): string => (string) preg_replace('/^[\s\-–•]+|\s+$/u', '', $line),
            explode("\n", (string) $row->body),
        ), fn (string $line): bool => $line !== ''));

        if ($lines === []) {
            return null;
        }

        $pick = $lines[0];
        foreach (self::FIRST as $pattern) {
            $found = array_values(array_filter($lines, fn (string $line): bool => preg_match($pattern, $line) === 1));
            if ($found !== []) {
                $pick = $found[0];
                break;
            }
        }

        $counts = (array) (((array) $row->terms)['counts'] ?? []);

        return [
            'text' => mb_strlen($pick) > self::MAX_CHARS ? rtrim(mb_substr($pick, 0, self::MAX_CHARS - 1)).'…' : $pick,
            'pages' => (int) ($counts[RepeatedText::key($pick)] ?? 0),
        ];
    }
}
