<?php

namespace App\Modules\Demo\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Modules\Demo\Actions\SweepDemos;
use App\Modules\Demo\Models\DemoSite;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Every demo asked for, the latest first: the store and who asked (and whether they want to hear from us), what
 * they said of the site, where it stands, how much it read and what it cost, and until when. The team opens one,
 * gives it more days, or ends it now (its store deleted with all it holds, as at its end).
 */
class Demos extends Page
{
    public const SHOWN = 200;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'shops';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'demos';

    protected string $view = 'demo::operator.demos';

    public string $status = '';

    public static function getNavigationLabel(): string
    {
        return __('demo::operator.title');
    }

    public function getTitle(): string
    {
        return __('demo::operator.title');
    }

    public function getSubheading(): ?string
    {
        return __('demo::operator.subheading', ['days' => Settings::get('demo.keep_days'), 'per_day' => Settings::get('demo.per_day'), 'budget' => Settings::get('demo.budget_usd')]);
    }

    /**
     * The demos, each with what it cost us: kept on its row for what ended (an earlier try, or the demo itself),
     * and, while its store lasts, its store's model calls so far, building it and its visitors apart.
     *
     * @return Collection<int, DemoSite>
     */
    public function demos(): Collection
    {
        return DemoSite::query()->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest()->limit(self::SHOWN)->get()
            ->each(function (DemoSite $d): void {
                $live = $d->status !== DemoSite::EXPIRED && $d->shop_id !== null ? SweepDemos::costs($d->shop_id) : null;
                $d->setAttribute('spent', $live === null && $d->cost_usd === null ? null : round((float) $d->cost_usd + array_sum($live ?? []), 4));
                $d->setAttribute('spent_parts', $live);
            });
    }

    /** @return array<string, int> status => how many, for the filter */
    public function counts(): array
    {
        return DemoSite::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
    }

    public function extend(string $id): void
    {
        $site = DemoSite::query()->find($id);
        if ($site === null || ! $site->live()) {
            return;
        }
        $site->forceFill(['expires_at' => ($site->expires_at ?? now())->addDays((int) Settings::get('demo.keep_days'))])->save();
        DemoSite::query()->where('shop_id', $site->shop_id)->whereKeyNot($site->id)->update(['expires_at' => $site->expires_at]);
        Notification::make()->title(__('demo::operator.extended'))->success()->send();
    }

    public function endNow(string $id): void
    {
        $site = DemoSite::query()->find($id);
        if ($site === null || $site->status === DemoSite::EXPIRED) {
            return;
        }
        // every request shown this store's demo ends with it
        DemoSite::query()->where('shop_id', $site->shop_id ?? '')->whereKeyNot($site->id)->whereIn('status', [DemoSite::SCANNING, DemoSite::READY])
            ->get()->each(fn (DemoSite $other) => $other->forceFill(['status' => DemoSite::EXPIRED, 'screenshot' => null, 'screenshot_mobile' => null])->save());
        SweepDemos::end($site);
        Notification::make()->title(__('demo::operator.ended'))->success()->send();
    }
}
