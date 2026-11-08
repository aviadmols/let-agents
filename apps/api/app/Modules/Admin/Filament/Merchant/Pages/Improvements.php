<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Localization\Money;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\AutoFixes;
use App\Modules\Admin\Contracts\ReportSeries;
use App\Modules\Admin\Contracts\TodayFix;
use App\Modules\Admin\Contracts\TodayWin;
use App\Modules\Admin\Support\Today;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;
use UnitEnum;

/**
 * Improvements, as the designer's redesign lays it out (Tomer, 11.2026): one ranked list of every fix waiting, from every
 * module that brings them (TodayImprovements: the review's proposals, word families, details read from photos, the
 * questions without an answer), each one card with what shoppers ran into, why, how many it touches, the suggestion
 * itself (the rule and the products shoppers will see, the drafted answer to edit, what each photo showed), the one tap
 * that approves it, the other way to fix it, "Not relevant", and what exactly shoppers typed. Then what Lets fixed on its
 * own, with undo (AutoFixes), and what the owner approved with what it did since. On the side, how many searches find
 * something against the goal. Nothing here knows a module. No model, cost or mechanism.
 */
final class Improvements extends Page
{
    /** The goal the ring shows: this share of searches and questions find something. */
    public const GOAL = 95;

    /** waiting | auto | done */
    public string $tab = 'waiting';

    /** all, or one of TodayFix::KINDS */
    public string $kind = 'all';

    /** @var array<string, string> the drafts as the owner edits them, by the fix's key hashed (a key has ":" Livewire reads as a path) */
    public array $drafts = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'improvements';

    protected string $view = 'admin::merchant.improvements';

    /** @var list<TodayFix>|null */
    private ?array $fixesCache = null;

    public static function getNavigationLabel(): string
    {
        return __('admin::improvements.nav');
    }

    public static function getNavigationBadge(): ?string
    {
        $shop = Filament::getTenant();
        if (! $shop instanceof Shop) {
            return null;
        }
        $n = count(app(Today::class)->fixes($shop));

        return $n > 0 ? (string) $n : null;
    }

    public function getTitle(): string
    {
        return __('admin::improvements.title');
    }

    public function getHeader(): ?View
    {
        return view('admin::merchant.screen-head', ['title' => $this->getTitle(), 'lede' => __('admin::improvements.lede')]);
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, ['waiting', 'auto', 'done'], true) ? $tab : 'waiting';
    }

    public function filter(string $kind): void
    {
        $this->kind = in_array($kind, TodayFix::KINDS, true) ? $kind : 'all';
    }

    /** @return list<TodayFix> every fix waiting, the weightiest first */
    public function fixes(): array
    {
        return $this->fixesCache ??= app(Today::class)->fixes($this->shop());
    }

    /** @return list<TodayFix> the fixes of the kind chosen */
    public function shown(): array
    {
        return array_values(array_filter($this->fixes(), fn (TodayFix $f): bool => $this->kind === 'all' || $f->kind === $this->kind));
    }

    /** @return array<string, int> how many of each kind wait, only the kinds there are */
    public function kinds(): array
    {
        $counts = array_fill_keys(TodayFix::KINDS, 0);
        foreach ($this->fixes() as $fix) {
            $counts[$fix->kind] = ($counts[$fix->kind] ?? 0) + 1;
        }

        return array_filter($counts);
    }

    public static function draftKey(string $key): string
    {
        return substr(sha1($key), 0, 16);
    }

    /** The one tap: approved as the owner left the suggestion (an edited answer too); the card leaves, the next one moves up. */
    public function approve(string $key): void
    {
        $text = $this->drafts[self::draftKey($key)] ?? null;
        $said = app(Today::class)->approve($this->shop(), $key, Auth::id(), is_string($text) && trim($text) !== '' ? trim($text) : null);
        unset($this->drafts[self::draftKey($key)]);
        $this->fixesCache = null;
        $said === null
            ? Notification::make()->warning()->title(__('admin::today.todo.gone'))->send()
            : Notification::make()->success()->title($said)->send();
    }

    /** "Not relevant": dismissed for good where its source keeps that, put off otherwise. */
    public function dismiss(string $key): void
    {
        app(Today::class)->dismiss($this->shop(), $key, Auth::id());
        $this->fixesCache = null;
        Notification::make()->success()->title(__('admin::improvements.dismissed'))->send();
    }

    /** "Not now": until next week. */
    public function later(string $key): void
    {
        app(Today::class)->later($this->shop(), $key, Auth::id());
        $this->fixesCache = null;
    }

    /** Undo a fix Lets made on its own. */
    public function undo(string $key): void
    {
        foreach (app()->tagged(AutoFixes::TAG) as $source) {
            try {
                $said = app(TenantContext::class)->run((string) $this->shop()->id, fn (): ?string => $source->undo($this->shop(), $key, Auth::id()));
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            if ($said !== null) {
                Notification::make()->success()->title($said)->send();

                return;
            }
        }
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} what Lets fixed on its own */
    public function auto(): array
    {
        $rows = [];
        $total = 0;
        foreach (app()->tagged(AutoFixes::TAG) as $source) {
            try {
                [$some, $n] = app(TenantContext::class)->run((string) $this->shop()->id, fn (): array => [$source->fixed($this->shop(), 20), $source->count($this->shop())]);
                array_push($rows, ...$some);
                $total += $n;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['rows' => array_slice($rows, 0, 20), 'total' => $total];
    }

    /** @return list<array<string, mixed>> what the owner approved, and what it did since */
    public function approved(): array
    {
        $currency = (string) ($this->shop()->currency ?: 'ILS');

        return array_map(fn (TodayWin $w): array => [
            'key' => $w->key,
            'searched' => $w->searched,
            'finds' => $w->finds,
            'approved' => Carbon::instance($w->approvedAt)->timezone(ReportSeries::TZ)->locale(app()->getLocale())->isoFormat(app()->getLocale() === 'he' ? 'D.M' : 'MMM D'),
            'found' => $w->found,
            'adds' => $w->adds,
            'sales' => $w->sales,
            'money' => $w->sales === null ? null : Money::total($w->sales, $currency),
            'picture' => $w->picture,
        ], app(Today::class)->wins($this->shop(), 40));
    }

    /** @return array{approved: int, found: int, sales: string} the band: since the owner started */
    public function tally(array $approved): array
    {
        return [
            'approved' => count($approved),
            'found' => (int) array_sum(array_column($approved, 'found')),
            'sales' => Money::total((float) array_sum(array_map(fn (array $a): float => (float) ($a['sales'] ?? 0), $approved)), (string) ($this->shop()->currency ?: 'ILS')),
        ];
    }

    /** @return array{pct: int|null, after: int|null, waiting: int} how many searches find something (30 days), and after the waiting fixes */
    public function goal(): array
    {
        $found = app(Today::class)->found($this->shop(), now()->subDays(30), now());
        $asked = $found === null ? 0 : $found['searches'] + $found['questions'];
        if ($asked === 0) {
            return ['pct' => null, 'after' => null, 'waiting' => count($this->fixes())];
        }
        // what the fixes for searches that found nothing would find, as far as their searches go
        $helped = array_sum(array_map(fn (TodayFix $f): int => in_array($f->kind, ['nothing', 'question'], true) ? $f->impact : 0, $this->fixes()));
        $pct = (int) round(100 * $found['found'] / $asked);

        return ['pct' => $pct, 'after' => min(100, max($pct, (int) round(100 * ($found['found'] + $helped) / $asked))), 'waiting' => count($this->fixes())];
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
