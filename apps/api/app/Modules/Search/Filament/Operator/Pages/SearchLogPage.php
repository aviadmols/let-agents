<?php

namespace App\Modules\Search\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Models\SearchLog;
use App\Modules\Search\Models\SearchPhotoAsk;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Every search on the store's site, the latest first, with what it found, what was clicked and
 * the visit: the address, the device, browser and system, the page, where the shopper came from
 * and the session. A session or an address shows what that visitor did: their searches and their
 * photo searches. The address and the session are kept search.visit_keep_days.
 */
class SearchLogPage extends Page
{
    public const SHOWN = 200;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'analytics';

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'analytics/search-log';

    protected string $view = 'search::operator.search-log';

    #[Url]
    public ?string $shop = null;

    /** One session, or every search when empty. */
    #[Url]
    public string $session = '';

    /** One address, or every search when empty. */
    #[Url]
    public string $ip = '';

    public static function getNavigationLabel(): string
    {
        return __('search::ui.log.title');
    }

    public function getTitle(): string
    {
        return __('search::ui.log.title');
    }

    public function getSubheading(): ?string
    {
        return __('search::ui.log.subheading');
    }

    public function mount(): void
    {
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    public function picksShop(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function clearFilter(): void
    {
        $this->session = '';
        $this->ip = '';
    }

    /** @return Collection<int, SearchLog> the searches, with the clicked product's name as clicked_title */
    public function searches(): Collection
    {
        if ($this->shop === null) {
            return collect();
        }

        return app(TenantContext::class)->run((string) $this->shop, function (): Collection {
            $rows = $this->filtered(SearchLog::query())->latest('id')->limit(self::SHOWN)->get();
            $titles = CatalogProduct::query()->whereIn('external_id', $rows->pluck('clicked')->filter()->map(fn (string $id): string => substr($id, 2))->unique()->values())->pluck('title', 'external_id');

            return $rows->each(function (SearchLog $row) use ($titles): void {
                $row->setAttribute('clicked_title', $row->clicked === null ? null : ($titles[substr($row->clicked, 2)] ?? $row->clicked));
            });
        });
    }

    /** @return Collection<int, SearchPhotoAsk> the photo searches of the session or address chosen */
    public function photos(): Collection
    {
        if ($this->shop === null || ($this->session === '' && $this->ip === '')) {
            return collect();
        }

        return app(TenantContext::class)->run((string) $this->shop, fn (): Collection => $this->filtered(SearchPhotoAsk::query())->latest()->limit(50)
            ->get(['id', 'thumb', 'object', 'total', 'created_at', 'ip', 'device', 'browser', 'os', 'session_id', 'page', 'referrer']));
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    private function filtered($query)
    {
        return $query
            ->when($this->session !== '', fn ($q) => $q->where('session_id', $this->session))
            ->when($this->ip !== '', fn ($q) => $q->where('ip', $this->ip));
    }
}
