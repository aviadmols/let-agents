<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Filament\HasPeriod;
use App\Core\Localization\Money;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ReportSeries;
use App\Modules\Admin\Contracts\SearchesScreen;
use App\Modules\Admin\Support\Today;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Throwable;
use UnitEnum;

/**
 * Searches, as the designer's redesign lays it out (Tomer, 11.2026): what shoppers look for, in their own words, and what
 * they find. Four numbers (searches against the period before, questions and how many got an answer, photo searches and
 * what went to the cart from them, how many found what they wanted); the days, by searches or by adds; what is rising;
 * the top searches with what was opened, what went to the cart and what sold; what they ask and where the answer came
 * from; their photo searches; the phrases that come back; and the store's own search to try. A store with no search
 * yet sees one empty state and the search to try. From the Search module through SearchesScreen; nothing here knows a
 * module. No model.
 */
final class Searches extends Page
{
    use HasPeriod;

    private const CACHE_SECONDS = 120;

    /** searches | adds: what the days' bars count */
    public string $measure = 'searches';

    /** searched | sales | fix: how the top searches are ordered */
    public string $sort = 'searched';

    public string $tryQ = '';

    /** @var array{total: int, products: list<array<string, mixed>>}|null */
    public ?array $tried = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'searches';

    protected string $view = 'admin::merchant.searches';

    public static function getNavigationLabel(): string
    {
        return __('admin::searches.nav');
    }

    public function mount(): void
    {
        if (! request()->has('days') && ! request()->has('from')) {
            $this->days = 7;
        }
    }

    public function getTitle(): string
    {
        return __('admin::searches.title');
    }

    public function getHeader(): ?View
    {
        return view('admin::merchant.screen-head', ['title' => $this->getTitle(), 'lede' => __('admin::searches.lede')]);
    }

    public function measure(string $measure): void
    {
        $this->measure = $measure === 'adds' ? 'adds' : 'searches';
    }

    public function sortBy(string $sort): void
    {
        $this->sort = in_array($sort, ['searched', 'sales', 'fix'], true) ? $sort : 'searched';
    }

    /** "Try a search": what a shopper would get for the words, from the store's own search. Counts nothing. */
    public function tryIt(?string $words = null): void
    {
        if ($words !== null) {
            $this->tryQ = $words;
        }
        $words = trim($this->tryQ);
        $this->tried = $words === '' ? null : ($this->screen(fn (SearchesScreen $s): array => $s->tryIt($this->shop(), $words, 6)) ?? ['total' => 0, 'products' => []]);
    }

    /** No search and no question in the last 90 days: one empty state, and the search to try. */
    public function firstDay(): bool
    {
        return (bool) Cache::remember('searches:'.$this->shop()->id.':first', self::CACHE_SECONDS, function (): bool {
            $found = app(Today::class)->found($this->shop(), now()->subDays(90), now());

            return $found !== null && $found['searches'] + $found['questions'] === 0;
        });
    }

    /** @return array<string, mixed> */
    public function kpis(): array
    {
        [$since, $until, $before] = $this->window();

        return $this->cached('kpis', fn (): array => $this->screen(fn (SearchesScreen $s): array => $s->kpis($this->shop(), $since, $until, $before)) ?? []);
    }

    /**
     * The days' bars for the chosen measure, the peak marked, and the words searched most on it.
     *
     * @return array{bars: list<array{label: string, n: int, peak: bool, h: int}>, peak: array{day: string, n: int, query: string|null}|null}
     */
    public function chart(): array
    {
        [$since, $until] = $this->window();
        $daily = $this->cached('daily', fn (): array => $this->screen(fn (SearchesScreen $s): array => $s->daily($this->shop(), $since, $until)) ?? ['searches' => [], 'adds' => []]);
        $points = ReportSeries::points($since, $until, (array) ($daily[$this->measure] ?? []));
        $max = max(1, ...array_map(fn (array $p): int => $p['n'], $points ?: [['n' => 0]]));
        $peak = null;
        foreach ($points as $i => $p) {
            if ($p['n'] > 0 && ($peak === null || $p['n'] > $points[$peak]['n'])) {
                $peak = $i;
            }
        }
        $unit = ReportSeries::scale($since, $until)['unit'];
        $week = $unit === 'day' && count($points) <= 8;
        $locale = app()->getLocale();
        $label = fn (string $day): string => match (true) {
            $week => $locale === 'he' ? Carbon::parse($day, ReportSeries::TZ)->locale('he')->isoFormat('dd').'׳' : Carbon::parse($day, ReportSeries::TZ)->locale($locale)->isoFormat('ddd'),
            $unit === 'hour' => Carbon::createFromFormat('Y-m-d H', $day, ReportSeries::TZ)->format('H:00'),
            $unit === 'month' => Carbon::createFromFormat('Y-m', $day, ReportSeries::TZ)->locale($locale)->isoFormat('MMM'),
            default => Carbon::parse($day, ReportSeries::TZ)->format('j.n'),
        };
        $every = $week ? 1 : max(1, (int) ceil(count($points) / 10));
        $bars = [];
        foreach ($points as $i => $p) {
            $bars[] = ['label' => $i % $every === 0 || $i === $peak ? $label($p['day']) : '', 'n' => $p['n'], 'peak' => $i === $peak, 'h' => (int) round(100 * $p['n'] / $max)];
        }
        $peakDay = $peak === null || $unit !== 'day' ? null : Carbon::parse($points[$peak]['day'], ReportSeries::TZ);

        return ['bars' => $bars, 'peak' => $peakDay === null ? null : [
            'day' => $week ? $peakDay->locale($locale)->isoFormat('dddd') : $peakDay->format('j.n'),
            'n' => $points[$peak]['n'],
            'query' => $this->measure === 'searches' ? app(Today::class)->topOn($this->shop(), $peakDay) : null,
        ]];
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        [$since, $until, $before] = $this->window();

        return $this->cached('rows-'.$this->sort, fn (): array => $this->screen(fn (SearchesScreen $s): array => $s->rows($this->shop(), $since, $until, $before, $this->sort, 8)) ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function rising(): array
    {
        [$since, $until, $before] = $this->window();

        return $this->cached('rising', fn (): array => $this->screen(fn (SearchesScreen $s): array => $s->rising($this->shop(), $since, $until, $before, 4)) ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function questions(): array
    {
        [$since, $until] = $this->window();

        return $this->cached('questions', fn (): array => $this->screen(fn (SearchesScreen $s): array => $s->questions($this->shop(), $since, $until, 5)) ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function photos(): array
    {
        [$since, $until] = $this->window();

        return $this->cached('photos', fn (): array => $this->screen(fn (SearchesScreen $s): array => $s->photos($this->shop(), $since, $until, 4)) ?? []);
    }

    /** @return list<array{phrase: string, n: int}> */
    public function words(): array
    {
        [$since, $until] = $this->window();

        return $this->cached('words', fn (): array => $this->screen(fn (SearchesScreen $s): array => $s->words($this->shop(), $since, $until, 10)) ?? []);
    }

    /** The waiting fixes, for "N fixes waiting" and the rows that wait for one. */
    public function fixesWaiting(): int
    {
        return (int) Cache::remember('searches:'.$this->shop()->id.':fixes', self::CACHE_SECONDS, fn (): int => count(app(Today::class)->fixes($this->shop())));
    }

    public function improvementsUrl(): string
    {
        $route = 'filament.merchant.pages.improvements';

        return Route::has($route) ? route($route, ['tenant' => $this->shop()]) : ShopNotices::getUrl();
    }

    /** One question on the questions screen, its "why" open: what the answer came from, and how to change it. */
    public function whyUrl(string $question): ?string
    {
        $url = $this->questionsUrl();

        return $url === null ? null : $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['find' => $question, 'explain' => '1']);
    }

    /** Every question, with the answer it got (the questions list), behind "All questions". */
    public function questionsUrl(): ?string
    {
        return $this->pageUrl('filament.merchant.pages.assistant.questions');
    }

    /** Every photo search, with what it found (the photo searches list), behind "All photo searches". */
    public function photosUrl(): ?string
    {
        return $this->pageUrl('filament.merchant.pages.search.photos');
    }

    public function money(float $amount): string
    {
        return Money::total($amount, (string) ($this->shop()->currency ?: 'ILS'));
    }

    private function pageUrl(string $route): ?string
    {
        return Route::has($route) ? route($route, ['tenant' => $this->shop()]) : null;
    }

    /** @return array{0: Carbon, 1: Carbon, 2: Carbon} the period, and the start of the one of the same length before it */
    private function window(): array
    {
        [$since, $until] = $this->range();

        return [$since, $until, $since->copy()->sub($since->diff($until))];
    }

    /**
     * @template T
     *
     * @param  callable(SearchesScreen): T  $ask
     * @return T|null
     */
    private function screen(callable $ask): mixed
    {
        foreach (app()->tagged(SearchesScreen::TAG) as $source) {
            try {
                return app(TenantContext::class)->run((string) $this->shop()->id, fn () => $ask($source));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    /**
     * @param  callable(): array<mixed>  $make
     * @return array<mixed>
     */
    private function cached(string $part, callable $make): array
    {
        [$since, $until] = $this->window();

        return (array) Cache::remember('searches:'.$this->shop()->id.':'.app()->getLocale().':'.$part.':'.$since->format('YmdH').'-'.$until->format('YmdH'), self::CACHE_SECONDS, $make);
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
