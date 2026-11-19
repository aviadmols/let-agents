<?php

namespace App\Modules\Search\Support;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Actions\ArrangeFilters;
use App\Modules\Search\Contracts\FilterLayouts;
use App\Modules\Search\Models\SearchFilterLayout;

/** The newest arrangement of a store's filters that did not fail, while its flag is on (FilterLayouts). */
final class StoredFilterLayouts implements FilterLayouts
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function applied(string $shopId): ?array
    {
        if (! Features::enabled(ArrangeFilters::FEATURE, $shopId)) {
            return null;
        }
        $layout = $this->tenant->run($shopId, fn (): ?SearchFilterLayout => SearchFilterLayout::query()->where('status', '!=', SearchFilterLayout::FAILED)
            ->orderByDesc('created_at')->orderByDesc('id')->first());
        $applied = $layout?->applied;

        return is_array($applied) && ! FilterLayoutGate::empty($applied) ? $applied : null;
    }
}
