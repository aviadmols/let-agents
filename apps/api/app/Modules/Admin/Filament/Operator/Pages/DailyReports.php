<?php

namespace App\Modules\Admin\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\DailySummaries;
use App\Modules\Admin\Contracts\DailySummary;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * Every shop's daily reports in one place, by day: the questions shoppers asked and what entered the
 * bank of answers, the site's daily check and what it proposed. From every module that tags
 * DailySummaries; one shop or all, one kind or all.
 */
final class DailyReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'overview';

    protected static ?int $navigationSort = 33;

    protected static ?string $slug = 'daily-reports';

    protected string $view = 'admin::operator.daily-reports';

    /** One shop's reports, by id, or every shop's. */
    #[Url]
    public ?string $shop = null;

    /** One kind (a source's index), or every kind. */
    #[Url]
    public ?int $kind = null;

    public static function getNavigationLabel(): string
    {
        return __('admin::daily.title');
    }

    public function getTitle(): string
    {
        return __('admin::daily.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::daily.subheading');
    }

    /** @return array<string, string> shop id => name */
    public function shops(): array
    {
        return app(TenantContext::class)->runUnscoped(fn () => Shop::query()->orderBy('name')->pluck('name', 'id')->all());
    }

    /** @return list<string> the kinds, by the sources' order */
    public function kinds(): array
    {
        return array_map(fn (DailySummaries $source): string => $source->label(), iterator_to_array(app()->tagged(DailySummaries::TAG), false));
    }

    /**
     * The reports by day, the latest day first; within a day by shop, then kind.
     *
     * @return array<string, list<array{shop: string, kind: string, summary: DailySummary}>>
     */
    public function days(): array
    {
        $since = now()->subDays((int) Settings::get('admin.daily_reports_days'));
        $shops = $this->shops();
        $byDay = [];

        foreach (iterator_to_array(app()->tagged(DailySummaries::TAG), false) as $i => $source) {
            if ($this->kind !== null && $this->kind !== $i) {
                continue;
            }
            try {
                $summaries = $source->summaries($this->shop ?: null, $since, operator: true);
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            foreach ($summaries as $summary) {
                $byDay[$summary->day->toDateString()][] = ['shop' => $shops[$summary->shopId] ?? $summary->shopId, 'kind' => $source->label(), 'summary' => $summary];
            }
        }

        krsort($byDay);
        foreach ($byDay as &$entries) {
            usort($entries, fn (array $a, array $b): int => [$a['shop'], $a['kind']] <=> [$b['shop'], $b['kind']]);
        }

        return $byDay;
    }
}
