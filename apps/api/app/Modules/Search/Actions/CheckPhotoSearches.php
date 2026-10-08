<?php

namespace App\Modules\Search\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Models\SearchPhotoAsk;
use App\Modules\Search\Support\PhotoPlacer;

/**
 * Reads the marked photos again with the reader as it is now, and writes on each whether it got
 * what the team said. After a change to a prompt, a model or the placing, this is how the team
 * sees whether photo search got better: "18 of 20 right" instead of a feeling.
 *
 * A row marked right expects what it showed then; a row marked wrong expects what the team
 * wrote. A row marked right that showed nothing expects nothing again.
 */
final class CheckPhotoSearches
{
    private const NEAR = 5000;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ReadPhotoTags $reader,
        private readonly SemanticSearch $semantic,
    ) {}

    /** @return array{checked: int, right: int} */
    public function handle(string $shopId): array
    {
        return $this->tenant->run($shopId, function () use ($shopId): array {
            $checked = 0;
            $right = 0;

            foreach (SearchPhotoAsk::query()->whereNotNull('verdict')->whereNotNull('photo')->orderBy('created_at')->lazy(20) as $ask) {
                $bytes = $ask->photoBytes();
                $expected = $ask->verdict === SearchPhotoAsk::RIGHT ? $ask->main : $ask->expected;

                if ($bytes === null || ($ask->verdict === SearchPhotoAsk::WRONG && blank($expected))) {
                    continue;
                }

                $hits = $this->semantic->picturesNearPhoto($shopId, 'image/jpeg', $bytes, self::NEAR);
                $look = $this->reader->handle($shopId, 'image/jpeg', $bytes, $hits);

                if ($look->tags === null) {
                    continue; // could not be read now: not a verdict on the reader
                }

                $ok = blank($expected)
                    ? $look->main === null
                    : (($look->main !== null && PhotoPlacer::agrees((string) $expected, $look->main)) || ($look->object !== null && PhotoPlacer::agrees((string) $expected, $look->object)));

                $ask->forceFill(['checked_main' => $look->main ?? $look->object, 'checked_right' => $ok, 'checked_at' => now()])->save();
                $checked++;
                $right += $ok ? 1 : 0;
            }

            return ['checked' => $checked, 'right' => $right];
        });
    }
}
