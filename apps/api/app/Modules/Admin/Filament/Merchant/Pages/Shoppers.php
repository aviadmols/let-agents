<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Core\Facades\Features;
use App\Core\Filament\HasPeriod;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\SavedProducts;
use App\Modules\Admin\Contracts\ShopperRequests;
use App\Modules\Admin\Contracts\ShopperVisits;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

/**
 * Shoppers, as the designer's redesign lays it out (Tomer, 11.2026): who is waiting for the store, how shoppers move
 * through it, and what they save for later. Four numbers (visits and how many searched, who waits and since when, who
 * left their details, the saved lists); the shoppers waiting for a reply, each answered on WhatsApp or by email in one
 * tap; where the visits with a search came from; the latest visits told as short stories; and the products saved most,
 * with how many of them sold. From the modules through ShopperRequests, ShopperVisits and SavedProducts; nothing here
 * knows a module. No address, no raw campaign tags: those stay on the visits log. No model.
 */
final class Shoppers extends Page
{
    use HasPeriod;

    private const CACHE_SECONDS = 120;

    /** Waiting requests read; the number counts these. */
    private const WAITING = 100;

    /** Waiting requests shown. */
    private const WAITING_SHOWN = 6;

    private const STORIES = 8;

    /** "Show more" stops here; the visits log holds the rest. */
    private const STORIES_MAX = 64;

    private const SAVED_SHOWN = 5;

    /** Where a story's shopper came from, as the story names it. */
    public const WHO = ['returning', 'google', 'facebook', 'instagram', 'whatsapp', 'email', 'direct', 'other'];

    public const DEVICES = ['phone', 'tablet', 'computer'];

    public const ENDED = ['order', 'cart', 'saved', 'none'];

    /** Also the visits with no search. */
    public bool $plain = false;

    /** Recent visits narrowed: a word the shopper typed or a product they met. */
    #[Url(as: 'vq')]
    public string $visitQ = '';

    #[Url(as: 'vfrom')]
    public string $visitFrom = '';

    #[Url(as: 'vdevice')]
    public string $visitDevice = '';

    #[Url(as: 'vend')]
    public string $visitEnded = '';

    public int $shown = self::STORIES;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'shoppers';

    protected string $view = 'admin::merchant.shoppers';

    public static function getNavigationLabel(): string
    {
        return __('admin::shoppers.nav');
    }

    /** "Waiting for a reply" beside the name, while someone waits. */
    public static function getNavigationBadge(): ?string
    {
        $shop = Filament::getTenant();
        if (! $shop instanceof Shop) {
            return null;
        }
        $n = (int) Cache::remember('shoppers:'.$shop->id.':badge', self::CACHE_SECONDS, fn (): int => count(self::waitingOf($shop, self::WAITING)));

        return $n > 0 ? (string) $n : null;
    }

    public function mount(): void
    {
        if (! request()->has('days') && ! request()->has('from')) {
            $this->days = 7;
        }
    }

    public function getTitle(): string
    {
        return __('admin::shoppers.title');
    }

    public function getHeader(): ?View
    {
        return view('admin::merchant.screen-head', ['title' => $this->getTitle(), 'lede' => __('admin::shoppers.lede')]);
    }

    /** No visit with a search in 90 days, no one waiting and no saved list: one empty state per card. */
    public function firstDay(): bool
    {
        return (bool) Cache::remember('shoppers:'.$this->shop()->id.':first', self::CACHE_SECONDS, function (): bool {
            $since = now()->subDays(90);
            $visits = $this->visits(fn (ShopperVisits $v): array => $v->numbers($this->shop(), $since, now())) ?? ['searched' => 0];
            $saved = $this->saved(fn (SavedProducts $s): array => $s->numbers($this->shop(), $since, now())) ?? ['lists' => 0];

            return (int) $visits['searched'] === 0 && (int) $saved['lists'] === 0 && $this->waiting() === [];
        });
    }

    /** @return array{visits: int, searched: int, waiting: int, oldest: CarbonInterface|null, signups: int, lists: int, products: int} */
    public function numbers(): array
    {
        [$since, $until] = $this->range();
        $counts = $this->cached('numbers', function () use ($since, $until): array {
            $visits = $this->visits(fn (ShopperVisits $v): array => $v->numbers($this->shop(), $since, $until)) ?? ['visits' => 0, 'searched' => 0];
            $saved = $this->saved(fn (SavedProducts $s): array => $s->numbers($this->shop(), $since, $until)) ?? ['lists' => 0, 'products' => 0];
            $signups = 0;
            foreach (app()->tagged(ShopperRequests::TAG) as $source) {
                $signups += (int) ($this->run(fn (): int => $source->signUps($this->shop(), $since, $until)) ?? 0);
            }

            return ['visits' => (int) $visits['visits'], 'searched' => (int) $visits['searched'], 'signups' => $signups, 'lists' => (int) $saved['lists'], 'products' => (int) $saved['products']];
        });
        $waiting = self::waitingOf($this->shop(), self::WAITING);

        return $counts + ['waiting' => count($waiting), 'oldest' => $waiting === [] ? null : end($waiting)['at']];
    }

    /** @return list<array{key: string, name: string|null, channel: string, question: string, at: CarbonInterface, visit: string|null}> who waits, the newest first, each with the visit they asked from */
    public function waiting(): array
    {
        return array_map(function (array $w): array {
            $id = is_string($w['visitor'] ?? null) && $w['at'] instanceof CarbonInterface
                ? $this->visits(fn (ShopperVisits $v): ?int => $v->visitOf($this->shop(), $w['visitor'], $w['at']))
                : null;
            unset($w['visitor']);

            return $w + ['visit' => is_int($id) ? $this->visitUrl($id) : null];
        }, array_slice(self::waitingOf($this->shop(), self::WAITING), 0, self::WAITING_SHOWN));
    }

    /** "Reply on WhatsApp" / "Reply by email": the module that keeps the request opens the reply; the contact never sits on the page. */
    public function replyTo(string $key): void
    {
        foreach (app()->tagged(ShopperRequests::TAG) as $source) {
            $url = $this->run(fn (): ?string => $source->replyUrl($this->shop(), $key, Auth::id()));
            if (is_string($url) && $url !== '') {
                $this->js('(() => { const w = window.open('.json_encode($url).', "_blank", "noopener"); if (! w) { window.location.href = '.json_encode($url).'; } })()');

                return;
            }
        }
        Notification::make()->warning()->title(__('admin::shoppers.waiting.no_reply'))->send();
    }

    public function answered(string $key): void
    {
        foreach (app()->tagged(ShopperRequests::TAG) as $source) {
            if ($this->run(fn (): bool => $source->answered($this->shop(), $key, Auth::id())) === true) {
                Cache::forget('shoppers:'.$this->shop()->id.':badge');
                Notification::make()->success()->title(__('admin::shoppers.waiting.done'))->send();

                return;
            }
        }
    }

    /** @return array{rows: list<array{source: string, n: int, pct: int}>, searchiest: string|null, campaign: array{name: string, visits: int}|null} */
    public function sources(): array
    {
        [$since, $until] = $this->range();

        return $this->cached('sources', fn (): array => $this->visits(fn (ShopperVisits $v): array => $v->sources($this->shop(), $since, $until)) ?? ['rows' => [], 'searchiest' => null, 'campaign' => null]);
    }

    /** @return list<array<string, mixed>> one more than shown, so the screen knows there are more */
    public function stories(): array
    {
        [$since, $until] = $this->range();
        $filter = $this->visitFilter();
        $limit = min($this->shown, self::STORIES_MAX) + 1;

        // kept as plain values: the time as a timestamp (the cache keeps no objects)
        return $this->cached('stories-'.($this->plain ? 'all' : 'searched').'-'.$limit.'-'.md5((string) json_encode($filter)), fn (): array => array_map(
            fn (array $s): array => ['at' => $s['at']->getTimestamp()] + $s,
            $this->visits(fn (ShopperVisits $v): array => $v->stories($this->shop(), $since, $until, $this->plain, $limit, $filter)) ?? [],
        ));
    }

    /** @return array{q?: string, who?: string, device?: string, ended?: string} the filters that hold a known value */
    public function visitFilter(): array
    {
        return array_filter([
            'q' => mb_substr(trim($this->visitQ), 0, 80),
            'who' => in_array($this->visitFrom, self::WHO, true) ? $this->visitFrom : '',
            'device' => in_array($this->visitDevice, self::DEVICES, true) ? $this->visitDevice : '',
            'ended' => in_array($this->visitEnded, self::ENDED, true) ? $this->visitEnded : '',
        ], fn (string $v): bool => $v !== '');
    }

    /** A narrower list starts again from the first few. */
    public function updated(string $name): void
    {
        if (in_array($name, ['visitQ', 'visitFrom', 'visitDevice', 'visitEnded', 'plain'], true)) {
            $this->shown = self::STORIES;
        }
    }

    public function more(): void
    {
        $this->shown = min($this->shown + self::STORIES, self::STORIES_MAX);
    }

    public function canShowMore(): bool
    {
        return $this->shown < self::STORIES_MAX;
    }

    /** "Clear": every visit filter off. */
    public function filter(): void
    {
        $this->visitQ = $this->visitFrom = $this->visitDevice = $this->visitEnded = '';
        $this->shown = self::STORIES;
    }

    /** One visit, open over the visits log with every step in order. */
    public function visitUrl(int $id): ?string
    {
        $route = 'filament.merchant.pages.sessions';

        return Route::has($route) && Features::enabled('analytics.sessions', $this->shop()->id) ? route($route, ['tenant' => $this->shop(), 'session' => $id]) : null;
    }

    /** @return list<array{id: string, title: string, picture: string|null, saved: int, bought: int}> */
    public function savedProducts(): array
    {
        [$since, $until] = $this->range();

        return $this->cached('saved', function () use ($since, $until): array {
            $top = $this->saved(fn (SavedProducts $s): array => $s->top($this->shop(), $since, $until, self::SAVED_SHOWN)) ?? [];
            $bought = $top === [] ? [] : ($this->visits(fn (ShopperVisits $v): array => $v->bought($this->shop(), array_column($top, 'id'), $since, $until)) ?? []);

            return array_map(fn (array $p): array => $p + ['bought' => (int) ($bought[$p['id']] ?? 0)], $top);
        });
    }

    public function exportSaved(): ?StreamedResponse
    {
        return $this->saved(fn (SavedProducts $s): StreamedResponse => $s->export($this->shop()));
    }

    /** Where the WhatsApp button is set up, for "No one's waiting". */
    public function whatsappUrl(): ?string
    {
        $route = 'filament.merchant.pages.settings.whatsapp';

        return Route::has($route) ? route($route, ['tenant' => $this->shop()]) : null;
    }

    /** The visits log (every visit, its steps and its address), behind "Every visit". */
    public function visitsLogUrl(): ?string
    {
        $route = 'filament.merchant.pages.sessions';

        return Route::has($route) ? route($route, ['tenant' => $this->shop()]) : null;
    }

    /** @return list<array{key: string, name: string|null, channel: string, question: string, at: CarbonInterface}> */
    private static function waitingOf(Shop $shop, int $limit): array
    {
        $all = [];
        foreach (app()->tagged(ShopperRequests::TAG) as $source) {
            try {
                $all = [...$all, ...app(TenantContext::class)->run((string) $shop->id, fn (): array => $source->waiting($shop, $limit))];
            } catch (Throwable $e) {
                report($e);
            }
        }
        usort($all, fn (array $a, array $b): int => [$b['at']?->getTimestamp() ?? 0, $b['key']] <=> [$a['at']?->getTimestamp() ?? 0, $a['key']]);

        return array_slice($all, 0, $limit);
    }

    /**
     * @template T
     *
     * @param  callable(ShopperVisits): T  $ask
     * @return T|null
     */
    private function visits(callable $ask): mixed
    {
        foreach (app()->tagged(ShopperVisits::TAG) as $source) {
            return $this->run(fn () => $ask($source));
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  callable(SavedProducts): T  $ask
     * @return T|null
     */
    private function saved(callable $ask): mixed
    {
        foreach (app()->tagged(SavedProducts::TAG) as $source) {
            return $this->run(fn () => $ask($source));
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $do
     * @return T|null
     */
    private function run(callable $do): mixed
    {
        try {
            return app(TenantContext::class)->run((string) $this->shop()->id, $do);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  callable(): array<mixed>  $make
     * @return array<mixed>
     */
    private function cached(string $part, callable $make): array
    {
        [$since, $until] = $this->range();

        return (array) Cache::remember('shoppers:'.$this->shop()->id.':'.app()->getLocale().':'.$part.':'.$since->format('YmdH').'-'.$until->format('YmdH'), self::CACHE_SECONDS, $make);
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
