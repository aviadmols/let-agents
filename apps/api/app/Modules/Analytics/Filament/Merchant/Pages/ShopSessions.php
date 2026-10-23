<?php

namespace App\Modules\Analytics\Filament\Merchant\Pages;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Filament\HasPeriod;
use App\Core\Localization\Money;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Analytics\Models\AnalyticsVisitStep;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Sessions, as the designer's panel lays them out: each visit to the store as a list of steps (pages,
 * searches, questions, saves, adds to cart, a purchase), filtered by what was searched, an IP, where it
 * came from, a campaign, what happened and the device; one opens over the page with every step in order.
 * Counts only, from AnalyticsVisit; an IP is shown for blocking, never a name or a contact.
 */
final class ShopSessions extends Page
{
    use HasPeriod;

    /** As far back as the shop's plan lets it see (analytics.history_days). */
    protected function historyDays(): ?int
    {
        $shop = Filament::getTenant();

        return $shop instanceof Shop ? (int) Settings::get('analytics.history_days', $shop->id) : null;
    }

    /** Shopper sessions are part of the shop's plan (analytics.sessions). */
    public static function canAccess(): bool
    {
        $shop = Filament::getTenant();

        return parent::canAccess() && (! $shop instanceof Shop || Features::enabled('analytics.sessions', $shop->id));
    }

    /** Rows a "load more" adds. */
    private const PAGE = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'sessions';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'sessions';

    protected string $view = 'analytics::merchant.sessions';

    #[Url]
    public string $q = '';

    #[Url]
    public string $ip = '';

    #[Url]
    public int $min = 0;

    #[Url]
    public string $src = '';

    #[Url]
    public string $param = '';

    /** @var list<string> */
    #[Url]
    public array $has = [];

    #[Url]
    public string $dev = '';

    /** One visitor's sessions (from a session's "all sessions of this visitor"). */
    #[Url]
    public string $visitor = '';

    /** The session open over the page. */
    #[Url]
    public ?int $session = null;

    public int $shown = self::PAGE;

    public static function getNavigationLabel(): string
    {
        return __('analytics::sessions.title');
    }

    public function getTitle(): string
    {
        return __('analytics::sessions.title');
    }

    public function getSubheading(): ?string
    {
        return __('analytics::sessions.subheading');
    }

    public function getHeader(): ?View
    {
        return view('analytics::merchant.sessions-head', ['title' => $this->getTitle(), 'subheading' => $this->getSubheading()]);
    }

    public function toggle(string $filter, string $value): void
    {
        match ($filter) {
            'min' => $this->min = (int) $value,
            'src' => $this->src = $this->src === $value || ! in_array($value, ['google', 'facebook', 'whatsapp', 'direct', 'email'], true) ? '' : $value,
            'param' => $this->param = $this->param === $value ? '' : mb_substr($value, 0, 120),
            'dev' => $this->dev = $this->dev === $value || ! in_array($value, ['phone', 'computer'], true) ? '' : $value,
            'has' => $this->has = in_array($value, $this->has, true)
                ? array_values(array_diff($this->has, [$value]))
                : (in_array($value, AnalyticsVisit::DID, true) ? [...$this->has, $value] : $this->has),
            default => null,
        };
        $this->shown = self::PAGE;
    }

    public function clear(string $what = 'all'): void
    {
        if ($what === 'all') {
            [$this->q, $this->ip, $this->min, $this->src, $this->param, $this->has, $this->dev, $this->visitor] = ['', '', 0, '', '', [], '', ''];
        } elseif (str_starts_with($what, 'has:')) {
            $this->has = array_values(array_diff($this->has, [substr($what, 4)]));
        } else {
            match ($what) {
                'q' => $this->q = '', 'ip' => $this->ip = '', 'min' => $this->min = 0, 'src' => $this->src = '',
                'param' => $this->param = '', 'dev' => $this->dev = '', 'visitor' => $this->visitor = '', default => null,
            };
        }
        $this->shown = self::PAGE;
    }

    public function updatedQ(): void
    {
        $this->shown = self::PAGE;
    }

    public function updatedIp(): void
    {
        $this->shown = self::PAGE;
    }

    public function more(): void
    {
        $this->shown += self::PAGE;
    }

    public function open(int $id): void
    {
        $this->session = $id;
    }

    public function close(): void
    {
        $this->session = null;
    }

    /** "All sessions of this visitor" / "all sessions from this IP", from an open session. */
    public function narrowTo(string $what): void
    {
        $visit = $this->session === null ? null : AnalyticsVisit::query()->find($this->session);

        if ($visit === null) {
            return;
        }

        $this->clear('all');

        if ($what === 'visitor' && $visit->visitor_hash !== null) {
            $this->visitor = substr($visit->visitor_hash, 0, 16);
        } elseif ($what === 'ip' && $visit->ip !== null) {
            $this->ip = $visit->ip;
        }

        $this->session = null;
    }

    /** @return Builder<AnalyticsVisit> the period's visits, filtered */
    public function query(): Builder
    {
        [$since, $until] = $this->range();
        $query = AnalyticsVisit::query()->where('preview', false)->where('started_at', '>=', $since)->where('started_at', '<', $until);

        if (($q = mb_strtolower(trim($this->q))) !== '') {
            $query->where('words', 'like', '%'.addcslashes($q, '%_\\').'%');
        }
        if (($ip = trim($this->ip)) !== '') {
            $query->where('ip', 'like', addcslashes($ip, '%_\\').'%');
        }
        if ($this->min > 0) {
            $query->where('steps', '>=', $this->min);
        }
        if ($this->src !== '') {
            $this->src === 'facebook' ? $query->whereIn('source', ['facebook', 'instagram']) : $query->where('source', $this->src);
        }
        if ($this->param !== '') {
            in_array($this->param, ['gclid', 'fbclid', 'ref'], true)
                ? $query->where('params', 'like', '%"'.$this->param.'"%')
                : $query->where('campaign', $this->param);
        }
        foreach ($this->has as $did) {
            $query->where('did', 'like', '%"'.$did.'"%');
        }
        if ($this->dev !== '') {
            $this->dev === 'phone' ? $query->whereIn('device', ['phone', 'tablet']) : $query->where('device', 'computer');
        }
        if ($this->visitor !== '') {
            $query->where('visitor_hash', 'like', $this->visitor.'%');
        }

        return $query;
    }

    /** @return array{sessions: int, visitors: int, returning: int, steps: float, searched: int, carts: int, orders: int} */
    public function stats(): array
    {
        $row = $this->query()->toBase()->selectRaw('count(*) as n, count(distinct visitor_hash) as v, sum(case when visit_number > 1 then 1 else 0 end) as r, avg(steps) as s')->first();
        $count = fn (string $did): int => (clone $this->query())->where('did', 'like', '%"'.$did.'"%')->count();

        return [
            'sessions' => (int) ($row->n ?? 0),
            'visitors' => (int) ($row->v ?? 0),
            'returning' => (int) ($row->r ?? 0),
            'steps' => round((float) ($row->s ?? 0), 1),
            'searched' => (clone $this->query())->where(fn (Builder $q) => $q->where('did', 'like', '%"search"%')->orWhere('did', 'like', '%"photo"%')->orWhere('did', 'like', '%"ask"%'))->count(),
            'carts' => $count('cart'),
            'orders' => $count('order'),
        ];
    }

    /** @return Collection<int, AnalyticsVisit> */
    public function visits(): Collection
    {
        return $this->query()->latest('started_at')->limit($this->shown + 1)->get();
    }

    /** @return list<string> the period's campaigns, most visits first, for the filter */
    public function campaigns(): array
    {
        [$since, $until] = $this->range();

        return AnalyticsVisit::query()->where('preview', false)->where('started_at', '>=', $since)->where('started_at', '<', $until)->whereNotNull('campaign')
            ->toBase()->selectRaw('campaign, count(*) as n')->groupBy('campaign')->orderByDesc('n')->limit(6)->pluck('campaign')->map(fn ($c): string => (string) $c)->all();
    }

    /**
     * The open session and its steps, each with what it was about, ready to show.
     *
     * @return array{visit: AnalyticsVisit, steps: list<array{kind: string, at: Carbon, text: string, note: string, gap: int}>}|null
     */
    public function opened(): ?array
    {
        $visit = $this->session === null ? null : AnalyticsVisit::query()->find($this->session);

        if ($visit === null) {
            return null;
        }

        $steps = $visit->stepsInOrder()->limit(300)->get();
        $products = CatalogProduct::query()->whereIn('external_id', $steps->pluck('item')->filter()->unique()->values())->pluck('title', 'external_id');
        $paths = $steps->where('kind', 'view')->pluck('path')->filter()->unique()->values();
        $pages = $this->titlesByPath($paths);
        $shown = [];
        $previous = null;
        $first = true;

        foreach ($steps as $step) {
            $title = $step->item !== null ? (string) ($products[$step->item] ?? '') : '';
            $shown[] = [
                'kind' => $step->kind,
                'at' => $step->at,
                'text' => $this->stepText($step, $title, $pages[(string) $step->path] ?? null, $first),
                'note' => $this->stepNote($step, $first ? $visit : null),
                'gap' => $previous === null ? 0 : (int) floor($previous->diffInSeconds($step->at, true) / 60),
            ];
            $first = $first && $step->kind !== 'view';
            $previous = $step->at;
        }

        return ['visit' => $visit, 'steps' => $shown];
    }

    /** "Shopper #a3f9 · visit 3": a short, stable name from the anonymous id, never a person's. */
    public static function visitorLabel(AnalyticsVisit $visit): string
    {
        $tag = $visit->visitor_hash !== null ? substr($visit->visitor_hash, 0, 4) : substr(hash('sha256', $visit->session_id), 0, 4);

        return __('analytics::sessions.visitor', ['tag' => $tag]).' · '.($visit->visit_number <= 1 ? __('analytics::sessions.first_visit') : __('analytics::sessions.visit_n', ['n' => $visit->visit_number]));
    }

    public static function sourceLabel(AnalyticsVisit $visit): string
    {
        $label = __('analytics::sessions.sources.'.$visit->source);

        return match (true) {
            $visit->paid => $label.' · '.__('analytics::sessions.paid'),
            $visit->source === 'google' => $label.' · '.__('analytics::sessions.organic'),
            default => $label,
        };
    }

    public static function deviceLabel(AnalyticsVisit $visit): string
    {
        return implode(' · ', array_filter([$visit->os === 'other' ? null : $visit->os, $visit->browser === 'other' ? null : $visit->browser])) ?: __('analytics::sessions.devices.'.($visit->device ?? 'computer'));
    }

    public static function duration(AnalyticsVisit $visit): string
    {
        $seconds = $visit->duration();

        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    public static function money(?string $amount): string
    {
        $shop = Filament::getTenant();
        $currency = $shop instanceof Shop ? (string) $shop->currency : 'ILS';

        return Money::total((float) $amount, $currency);
    }

    private function stepText(AnalyticsVisitStep $step, string $title, ?string $page, bool $first): string
    {
        $s = fn (string $key, array $p = []): string => __('analytics::sessions.steps.'.$key, $p);

        return match ($step->kind) {
            'view' => $title !== ''
                ? $s($first ? 'landed_product' : 'viewed_product', ['title' => $title])
                : $s($first ? 'landed' : 'viewed', ['page' => $page ?? __('analytics::sessions.pages.'.($step->page_type ?? 'other'))]),
            'search' => $s('searched', ['q' => (string) $step->label]),
            'photo' => $s('photo'),
            'ask' => $s('asked', ['q' => (string) $step->label]),
            'open' => $s('opened'),
            'heart' => $title !== '' ? $s('saved_product', ['title' => $title]) : $s('saved'),
            'cart' => $title !== '' ? $s('added_product', ['title' => $title, 'n' => max(1, (int) $step->number)]) : $s('added'),
            'order' => $s('ordered'),
            'lead' => $s('lead'),
            default => $step->kind,
        };
    }

    private function stepNote(AnalyticsVisitStep $step, ?AnalyticsVisit $landing): string
    {
        $s = fn (string $key, array $p = []): string => __('analytics::sessions.notes.'.$key, $p);

        return match ($step->kind) {
            'view' => $landing !== null ? $s('from', ['source' => self::sourceLabel($landing)]).($landing->campaign ? ' · '.$landing->campaign : '') : '',
            'search' => $step->number === null ? '' : ($step->number === 0 ? $s('no_results') : $s('results', ['n' => $step->number])),
            'photo' => trim(($step->label ? $s('seen', ['what' => $step->label]).' · ' : '').($step->number === null ? '' : $s('results', ['n' => $step->number])), ' ·'),
            'order' => self::money($step->value).($step->number ? ' · '.$s('items', ['n' => $step->number]) : ''),
            default => '',
        };
    }

    /**
     * The store's own name for each page path a visit saw: a product, a page or post, a category.
     *
     * @param  Collection<int, string>  $paths
     * @return array<string, string>
     */
    private function titlesByPath(Collection $paths): array
    {
        if ($paths->isEmpty()) {
            return [];
        }

        $byPath = [];
        $key = fn (?string $url): string => rtrim((string) parse_url((string) $url, PHP_URL_PATH), '/');

        foreach (CatalogContent::query()->active()->whereIn('type', [CatalogContent::PAGE, CatalogContent::POST])->whereNotNull('url')->get(['title', 'url']) as $content) {
            $byPath[$key($content->url)] = (string) $content->title;
        }

        $found = [];
        foreach ($paths as $path) {
            $title = $byPath[rtrim(rawurldecode((string) $path), '/')] ?? $byPath[rtrim((string) $path, '/')] ?? null;
            if ($title !== null) {
                $found[(string) $path] = $title;
            }
        }

        return $found;
    }
}
