<?php

namespace App\Modules\Admin\Filament\Operator\Pages;

use App\Modules\Admin\Contracts\TimelineEntry;
use App\Modules\Admin\Support\Timeline;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Everything that happened in one shop, as a timeline: scans, products that came in, pages and posts
 * read, what users did, insight reviews, leads; each with what it cost in AI. Gathered from every
 * module (TimelineSource). The operator's only: the merchant never sees a cost.
 */
final class ShopTimeline extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 34;

    protected static ?string $slug = 'timeline';

    protected string $view = 'admin::operator.timeline';

    /** The shop, by slug or id; the first shop when none is chosen. */
    #[Url]
    public ?string $shop = null;

    /** One kind of entry only (TimelineEntry::KINDS), or every kind. */
    #[Url]
    public string $kind = '';

    #[Url]
    public int $days = 30;

    public static function getNavigationLabel(): string
    {
        return __('admin::timeline.title');
    }

    public function getTitle(): string
    {
        return __('admin::timeline.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::timeline.subheading');
    }

    public function pick(string $kind): void
    {
        $this->kind = $this->kind === $kind || ! in_array($kind, TimelineEntry::KINDS, true) ? '' : $kind;
    }

    public function period(int $days): void
    {
        $this->days = in_array($days, [7, 30, 90], true) ? $days : 30;
    }

    /** @return list<array{id: string, slug: string, name: string}> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->get(['id', 'slug', 'name'])
            ->map(fn (Shop $s): array => ['id' => $s->id, 'slug' => $s->slug, 'name' => $s->name])->all();
    }

    public function current(): ?Shop
    {
        $query = Shop::query()->orderBy('name');

        return ($this->shop === null || $this->shop === '' ? null : (clone $query)->where('slug', $this->shop)->orWhere('id', $this->shop)->first()) ?? $query->first();
    }

    /** @return list<TimelineEntry> every entry of the period, before the kind filter: for the counts */
    public function all(): array
    {
        $shop = $this->current();

        return $shop === null ? [] : app(Timeline::class)->entries($shop, $this->days);
    }

    /** @return array<string, list<TimelineEntry>> */
    public function days(): array
    {
        $all = $this->all();
        $shown = $this->kind === '' ? $all : array_values(array_filter($all, fn (TimelineEntry $e): bool => $e->kind === $this->kind));

        return Timeline::byDay($shown);
    }
}
