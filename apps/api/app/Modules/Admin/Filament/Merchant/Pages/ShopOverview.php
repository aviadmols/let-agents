<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Filament\HasPeriod;
use App\Core\Localization\Money;
use App\Modules\Admin\Contracts\FirstStep;
use App\Modules\Admin\Contracts\HomeCard;
use App\Modules\Admin\Contracts\OverviewBlock;
use App\Modules\Admin\Contracts\ReportSeries;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Admin\Contracts\TodayFix;
use App\Modules\Admin\Contracts\TodayWin;
use App\Modules\Admin\Support\Home;
use App\Modules\Admin\Support\Onboarding as FirstRun;
use App\Modules\Admin\Support\Sparkline;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use UnitEnum;

/**
 * Today, the shop owner's home, as the designer's redesign lays it out (Tomer, 11.2026): the greeting with one
 * sentence about the period, the period (7, 30 or 90 days, or dates; 7 by default) and "See it as a shopper"; the
 * money through search on ink with its line, the adds and orders beside it, and how many searches found something;
 * the three fixes waiting, each one tap or one link; the searches as they happen (TodayFeed, its own component, so
 * only it refreshes); the words searched most and what they found; a fix approved and what it did since; and what
 * went to the cart through search. A store with no search yet sees its first day instead: what is done, what Lets
 * already knows, and how to make the first search. Every part comes from the modules (Today, Home, HomeNumbers);
 * nothing here knows a module. No model, cost or mechanism.
 */
final class ShopOverview extends Page
{
    use HasPeriod;

    /** Hidden once every first step is done and the manager hid the closing line. */
    public const STEPS_HIDDEN = 'steps.hidden';

    /** The step "see it as a shopper", done when the manager opened the store from here. */
    public const LOOK_DONE = 'step.look';

    /** Today's parts that count over the period are kept this long: the page redraws on every tap. */
    private const CACHE_SECONDS = 120;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'overview';

    protected string $view = 'admin::merchant.home';

    /** @var list<TodayFix>|null */
    private ?array $fixesCache = null;

    /** @var list<FirstStep>|null */
    private ?array $stepsCache = null;

    /** @var array<string, mixed>|null */
    private ?array $numbersCache = null;

    public static function getNavigationLabel(): string
    {
        return __('admin::home.nav');
    }

    /** A shop not set up yet opens on its first-run screens, with the address it came with (Shopify's frame). */
    public function mount(): void
    {
        if (FirstRun::lands($this->shop(), Auth::user())) {
            $query = (string) request()->getQueryString();
            $this->redirect(Onboarding::getUrl().($query === '' ? '' : '?'.$query));

            return;
        }

        // Today opens on the last 7 days, unless the address chose a period.
        if (! request()->has('days') && ! request()->has('from')) {
            $this->days = 7;
        }
    }

    public function getTitle(): string
    {
        return $this->hello();
    }

    /** "Good morning, Maya", by the shop's own clock (shops.timezone): a store in New York is greeted by its morning. */
    public function hello(): string
    {
        try {
            $hour = (int) now()->timezone((string) $this->shop()->timezone ?: 'Asia/Jerusalem')->format('G');
        } catch (\Throwable) {
            $hour = (int) now()->timezone('Asia/Jerusalem')->format('G');
        }
        $part = $hour < 5 ? 'night' : ($hour < 12 ? 'morning' : ($hour < 17 ? 'noon' : ($hour < 21 ? 'evening' : 'night')));
        $name = $this->firstName();

        return $name === ''
            ? __("admin::home.greeting.{$part}_plain")
            : __("admin::home.greeting.{$part}", ['name' => $name]);
    }

    /** The first name, past a title ("Mr.", "ד״ר"); none for a store's own account (one a platform signs in). */
    private function firstName(): string
    {
        $email = (string) Auth::user()?->email;
        if ((str_starts_with($email, 'owner@') && str_ends_with($email, '.myshopify.com')) || (str_starts_with($email, 'store@') && str_ends_with($email, '.wordpress'))) {
            return '';
        }

        return (string) collect(explode(' ', trim((string) Auth::user()?->name)))->first(fn (string $part): bool => $part !== '' && ! str_ends_with($part, '.') && ! in_array($part, ['ד״ר', 'דר', 'פרופ׳'], true));
    }

    /** The heading row as the design draws it: the greeting, the sentence, the period and "See it as a shopper". */
    public function getHeader(): ?View
    {
        return view('admin::merchant.home-head', ['title' => $this->hello(), 'mood' => $this->mood(), 'lede' => $this->lede(), 'subheading' => $this->subheading()]);
    }

    public function subheading(): string
    {
        return __('admin::home.subheading', ['shop' => $this->shop()->name, 'period' => $this->periodLabel()]);
    }

    /** The words after the greeting: how the period went, or that search is live on the first day. */
    public function mood(): string
    {
        if ($this->firstDay()) {
            return __('admin::today.mood.live');
        }
        $revenue = $this->numbers()['revenue'] ?? null;
        $change = $revenue === null || (float) $revenue['before'] === 0.0 ? null : ($revenue['now'] - $revenue['before']) / $revenue['before'];

        return __('admin::today.mood.'.match (true) {
            $change === null => 'steady',
            $change >= 0.05 => 'strong',
            $change <= -0.05 => 'quiet',
            default => 'steady',
        }.'.'.$this->periodWord());
    }

    /** One sentence that sums up the period. */
    public function lede(): string
    {
        if ($this->firstDay()) {
            return __('admin::today.lede.first_day');
        }
        $revenue = $this->numbers()['revenue'] ?? ['now' => 0, 'before' => 0];
        $how = match (true) {
            (float) $revenue['now'] <= 0 => 'none',
            (float) $revenue['before'] <= 0 => 'first',
            $revenue['now'] > $revenue['before'] => 'more',
            $revenue['now'] < $revenue['before'] => 'less',
            default => 'same',
        };

        return __("admin::today.lede.{$how}.".$this->periodWord()).($this->fixes()['total'] > 0 ? ' '.__('admin::today.lede.waiting') : '');
    }

    /** week, month or period: how the period before is named ("than last week"). */
    private function periodWord(): string
    {
        return match ($this->days) {
            7 => 'week',
            30 => 'month',
            default => 'period',
        };
    }

    /** A store no shopper has searched or asked in yet, with nothing waiting for the owner: Today shows its first day. */
    public function firstDay(): bool
    {
        $quiet = (bool) Cache::remember('today:'.$this->shop()->id.':first-day', self::CACHE_SECONDS, function (): bool {
            $found = app(Today::class)->found($this->shop(), now()->subDays(90), now());

            return $found !== null && $found['searches'] + $found['questions'] === 0;
        });

        // a question asked on a product page waits even before the first search
        return $quiet && $this->fixes()['total'] === 0;
    }

    /**
     * The ink hero: the money through search with its change and its line, the best step marked, the orders and the
     * adds with their change, and how many searches and questions found something.
     *
     * @return array<string, mixed>|null
     */
    public function hero(): ?array
    {
        $numbers = $this->numbers();
        if ($numbers === null) {
            return null;
        }
        [$since, $until] = $this->range();

        return $this->cached('hero', function () use ($numbers, $since, $until): array {
            $revenue = (array) ($numbers['series']['revenue'] ?? []);
            $steps = ReportSeries::points($since, $until, []);
            $values = array_map(fn (array $p): float => (float) ($revenue[$p['day']] ?? 0), $steps);
            $best = $values === [] || max($values) <= 0 ? null : (int) array_search(max($values), $values, true);
            $unit = ReportSeries::scale($since, $until)['unit'];
            // "the last 7 days" runs over 8 dates (today's is still going): a week, named by its days
            $week = $unit === 'day' && count($steps) <= 8;
            $locale = app()->getLocale();
            $label = fn (string $day): string => match (true) {
                $unit === 'hour' => Carbon::createFromFormat('Y-m-d H', $day, ReportSeries::TZ)->format('H:00'),
                $unit === 'month' => Carbon::createFromFormat('Y-m', $day, ReportSeries::TZ)->locale($locale)->isoFormat('MMM'),
                $week => $locale === 'he'
                    ? Carbon::parse($day, ReportSeries::TZ)->locale('he')->isoFormat('dd').'׳'
                    : Carbon::parse($day, ReportSeries::TZ)->locale($locale)->isoFormat('ddd'),
                default => Carbon::parse($day, ReportSeries::TZ)->format('j.n'),
            };
            // a week's days all named; a longer period at most 7 names, evenly spaced, the best always among them
            $every = $week ? 1 : max(1, (int) ceil(count($steps) / 7));
            $labels = [];
            foreach ($steps as $i => $step) {
                $labels[] = ['text' => $i % $every === 0 || $i === $best ? $label($step['day']) : '', 'best' => $i === $best];
            }
            $bestDay = $best === null || $unit !== 'day' ? null : Carbon::parse($steps[$best]['day'], ReportSeries::TZ);
            $found = app(Today::class)->found($this->shop(), $since, $until);
            $asked = $found === null ? 0 : $found['searches'] + $found['questions'];

            return [
                'money' => self::money((float) $numbers['revenue']['now'], (string) $numbers['currency']),
                'revenue' => self::change((float) $numbers['revenue']['now'], (float) $numbers['revenue']['before']),
                'orders' => ['n' => (int) $numbers['orders']['now']] + self::change($numbers['orders']['now'], $numbers['orders']['before']),
                'adds' => ['n' => (int) $numbers['adds']['now']] + self::change($numbers['adds']['now'], $numbers['adds']['before']),
                'line' => Sparkline::of($values, $best),
                'labels' => $labels,
                'best_day' => $bestDay === null ? null : ($week ? $bestDay->locale($locale)->isoFormat('dddd') : $bestDay->format('j.n')),
                'best_query' => $bestDay === null ? null : app(Today::class)->topOn($this->shop(), $bestDay),
                'asked' => $asked,
                'found_pct' => $asked === 0 ? null : (int) round(100 * $found['found'] / $asked),
            ];
        });
    }

    /**
     * The change from the period before, for a chip: up, down or flat, and by how much. "New" when there was none.
     *
     * @return array{dir: string, pct: int|null}
     */
    public static function change(int|float $now, int|float $before): array
    {
        if ((float) $before === 0.0) {
            return ['dir' => $now > 0 ? 'new' : 'flat', 'pct' => null];
        }
        $pct = (int) round(100 * ($now - $before) / $before);

        return ['dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'), 'pct' => abs($pct)];
    }

    /** @return array{items: list<TodayFix>, total: int} the three fixes Today shows, of how many */
    public function fixes(): array
    {
        $all = $this->fixesCache ??= app(Today::class)->fixes($this->shop());

        return ['items' => array_slice($all, 0, 3), 'total' => count($all)];
    }

    /** The one tap of a fix: approved here, a toast says what changed, the next one moves up. */
    public function approveFix(string $key): void
    {
        $said = app(Today::class)->approve($this->shop(), $key, Auth::id());
        $this->fixesCache = null;
        $this->forget();
        $said === null
            ? Notification::make()->warning()->title(__('admin::today.todo.gone'))->send()
            : Notification::make()->success()->title($said)->send();
    }

    /** "Not now": the fix leaves Today until next week. */
    public function later(string $key): void
    {
        app(Today::class)->later($this->shop(), $key, Auth::id());
        $this->fixesCache = null;
    }

    /**
     * The fixes approved and what they did since, as plain rows (the cache keeps no objects).
     *
     * @return list<array{key: string, searched: string, finds: string, approved: string, found: int, adds: int, sales: string|null, picture: string|null}>
     */
    public function wins(): array
    {
        $currency = (string) ($this->numbers()['currency'] ?? ($this->shop()->currency ?: 'ILS'));

        return $this->cached('wins', fn (): array => array_map(fn (TodayWin $win): array => [
            'key' => $win->key,
            'searched' => $win->searched,
            'finds' => $win->finds,
            'approved' => Carbon::instance($win->approvedAt)->timezone(ReportSeries::TZ)->locale(app()->getLocale())->isoFormat(app()->getLocale() === 'he' ? 'D.M' : 'MMM D'),
            'found' => $win->found,
            'adds' => $win->adds,
            'sales' => $win->sales === null ? null : self::money($win->sales, $currency),
            'picture' => $win->picture,
        ], app(Today::class)->wins($this->shop(), 3)));
    }

    /** @return list<array{query: string, searches: int, before: int, found: string|null, picture: string|null}> */
    public function top(): array
    {
        [$since, $until] = $this->range();

        return $this->cached('top', fn (): array => app(Today::class)->top($this->shop(), $since, $until, 5));
    }

    /** @return list<array{title: string, picture: string|null, adds: int, phrase: string|null, photo: bool}> */
    public function sold(): array
    {
        [$since, $until] = $this->range();

        return $this->cached('sold', fn (): array => app(Today::class)->sold($this->shop(), $since, $until, 5));
    }

    /** @return array{products: int, photos: int, tags: int, pages: int} what the first read found */
    public function learned(): array
    {
        return (array) Cache::remember('today:'.$this->shop()->id.':learned', self::CACHE_SECONDS, function (): array {
            $facts = app(FirstRun::class)->facts($this->shop());

            return [
                'products' => (int) ($facts['products'] ?? 0),
                'photos' => (int) ($facts['pictures_read'] ?? 0),
                'tags' => (int) ($facts['tags'] ?? 0),
                'pages' => (int) ($facts['pages'] ?? 0),
            ];
        });
    }

    /** @return list<array{html: string, done: bool}> the first day's list: connected, read, opens from the theme, the first search */
    public function setup(): array
    {
        $learned = $this->learned();
        $steps = collect($this->steps())->keyBy('key');
        $platform = $this->shop()->platform;
        $platform = $platform instanceof BackedEnum ? (string) $platform->value : (string) ($platform ?? '');
        $platformName = trans()->has('admin::today.platforms.'.$platform) ? __('admin::today.platforms.'.$platform) : __('admin::today.platforms.site');
        $bold = fn (string $text): string => '<b>'.e($text).'</b>';

        return [
            ['html' => e(__('admin::today.setup.connected', ['platform' => $platformName])), 'done' => true],
            ['html' => __('admin::today.setup.read', [
                'products' => $bold(trans_choice('admin::today.n_products', $learned['products'], ['n' => number_format($learned['products'])])),
                'photos' => $bold(trans_choice('admin::today.n_photos', $learned['photos'], ['n' => number_format($learned['photos'])])),
            ]), 'done' => $learned['products'] > 0],
            ['html' => e(__('admin::today.setup.opens')), 'done' => (bool) ($steps->get('on')?->done ?? false)],
            ['html' => e(__('admin::today.setup.first')), 'done' => false],
        ];
    }

    /** Where the store is, for "See it as a shopper" (the first step "look"). */
    public function storeUrl(): ?string
    {
        return collect($this->steps())->firstWhere('key', 'look')?->url;
    }

    /** The search check screen, for "Try a search" on the first day, when the panel has it. */
    public function trySearchUrl(): ?string
    {
        $route = 'filament.merchant.pages.search.check';

        return Route::has($route) ? route($route, ['tenant' => $this->shop()]) : null;
    }

    public function shopId(): string
    {
        return (string) $this->shop()->getKey();
    }

    /** @return list<HomeCard> */
    public function cards(): array
    {
        [$since, $until] = $this->range();

        return app(Home::class)->cards($this->shop(), $since, $until);
    }

    /** @return list<FirstStep> */
    public function steps(): array
    {
        return $this->stepsCache ??= app(Home::class)->steps($this->shop());
    }

    public function stepsHidden(): bool
    {
        return DB::table('shop_notices_done')->where('shop_id', $this->shop()->id)->where('key', self::STEPS_HIDDEN)->exists();
    }

    public function hideSteps(): void
    {
        app(Home::class)->markDone($this->shop(), self::STEPS_HIDDEN, auth()->id());
    }

    /** The manager opened the store as a shopper sees it, from the step. */
    public function looked(): void
    {
        app(Home::class)->markDone($this->shop(), self::LOOK_DONE, auth()->id());
    }

    /** @return list<ShopNotice> */
    public function attention(): array
    {
        return app(Home::class)->attention($this->shop());
    }

    /** @return array<string, mixed>|null */
    public function numbers(): ?array
    {
        [$since, $until] = $this->range();

        return $this->numbersCache ??= app(Home::class)->numbers($this->shop(), $this->days ?: 30, $since, $until);
    }

    /** The change from the period before, as the pill under a number shows it. */
    public static function delta(int|float $now, int|float $before): array
    {
        if ($before == 0) {
            return $now > 0 ? ['text' => __('admin::home.new'), 'tone' => 'good'] : ['text' => '—', 'tone' => ''];
        }

        $change = (int) round(100 * ($now - $before) / $before);

        return [
            'text' => ($change > 0 ? '▲ ' : ($change < 0 ? '▼ ' : '● ')).abs($change).'%',
            'tone' => $change > 0 ? 'good' : ($change < 0 ? 'bad' : ''),
        ];
    }

    public static function money(float $amount, string $currency): string
    {
        return Money::total($amount, $currency);
    }

    public function inboxUrl(): string
    {
        return ShopNotices::getUrl();
    }

    protected function periodChanged(): void
    {
        $this->numbersCache = null;
    }

    /**
     * What modules put at the top of the overview (OverviewBlock): the store's scan, while it is new.
     *
     * @return list<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        // Today opens on the money (the redesign): the store's scan and what was read lately belong to its first day
        if (! $this->firstDay()) {
            return [];
        }

        return array_values(array_filter(
            Filament::getCurrentPanel()?->getWidgets() ?? [],
            fn (string $widget): bool => is_subclass_of($widget, OverviewBlock::class),
        ));
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * A part of Today over the period, kept a little: the page redraws on every tap.
     *
     * @param  callable(): array<mixed>  $make
     * @return array<mixed>
     */
    private function cached(string $part, callable $make): array
    {
        return (array) Cache::remember($this->cacheKey($part), self::CACHE_SECONDS, $make);
    }

    private function cacheKey(string $part): string
    {
        [$since, $until] = $this->range();

        return 'today:'.$this->shop()->id.':'.app()->getLocale().':'.$part.':'.$since->format('YmdH').'-'.$until->format('YmdH');
    }

    /** After a fix is approved, the counts it changes are read again. */
    private function forget(): void
    {
        foreach (['wins', 'top', 'hero'] as $part) {
            Cache::forget($this->cacheKey($part));
        }
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
