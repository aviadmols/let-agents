<x-filament-panels::page>
    {{-- The shop's analytics, as the designer's panel lays them out (ShopAnalytics): one screen, or one list in full. --}}
    @php($t = fn (string $key, array $params = []) => __('analytics::report.'.$key, $params))
    @php($page = \App\Modules\Analytics\Filament\Merchant\Pages\ShopAnalytics::class)

    {{-- one row of any list: a rank or a picture, what it is, a line under it, a pill, its number, its button --}}
    @php($row = function (string $list, \App\Modules\Admin\Contracts\ReportRow $r, ?int $rank = null, bool $full = false) use ($t): string {
        // a searched word opens the sessions that searched it, in the same period
        $link = in_array($list, ['words', 'empty', 'noclick'], true) && ! str_starts_with($r->key, '[') ? $this->sessionsFor($r->key) : null;

        return view('analytics::merchant.report-row', ['list' => $list, 'r' => $r, 'rank' => $rank, 't' => $t, 'link' => $link, 'full' => $full])->render();
    })

    @php($f = $this->figures())
    @php($q = ($f['questions'] ?? []) + ['all' => 0, 'answered' => 0, 'unanswered' => 0, 'off' => 0, 'search' => 0])
    @php($adds = $f['adds'] ?? ['all' => 0, 'search' => 0, 'widget' => 0, 'page' => 0])
    @php($orders = $f['orders'] ?? ['n' => 0, 'revenue' => 0, 'currency' => (string) (\Filament\Facades\Filament::getTenant()?->currency ?: 'ILS')])
    @php($views = (int) ($f['views'] ?? 0))
    @php($daily = $this->chart())
    <div class="lp-page" data-analytics>
        <section class="lp-card" data-store-info>
            <div class="lp-card-t"><h2>{{ $t('store.title') }}</h2></div>
            <div class="lp-grid-3">
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('store.views') }}</span><span class="lp-stat-n">{{ number_format($views) }}</span></div>
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('store.opens') }}</span><span class="lp-stat-n">{{ number_format((int) ($f['opens'] ?? 0)) }}</span>@if ($views > 0)<span class="lp-stat-d">{{ $t('store.of_views', ['n' => round(100 * (int) ($f['opens'] ?? 0) / $views)]) }}</span>@endif</div>
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('store.searches') }}</span><span class="lp-stat-n">{{ number_format((int) ($f['searches'] ?? 0) + (int) ($f['photo_searches'] ?? 0)) }}</span><span class="lp-stat-d">{{ $t('store.by_photo', ['n' => number_format((int) ($f['photo_searches'] ?? 0))]) }}</span></div>
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('store.questions') }}</span><span class="lp-stat-n">{{ number_format($q['all']) }}</span><span class="lp-stat-d">{{ $t('store.questions_split', ['answered' => number_format($q['answered']), 'unanswered' => number_format($q['unanswered']), 'off' => number_format($q['off'])]) }}</span></div>
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('store.adds') }}</span><span class="lp-stat-n">{{ number_format($adds['all']) }}</span><span class="lp-stat-d">{{ $t('store.adds_split', ['search' => number_format($adds['search']), 'widget' => number_format($adds['widget']), 'page' => number_format($adds['page'])]) }}</span></div>
                <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('store.orders') }}</span><span class="lp-stat-n">{{ number_format($orders['n']) }}</span><span class="lp-stat-d">{{ $page::money((float) $orders['revenue'], (string) $orders['currency']) }}</span></div>
            </div>
            @if ($daily !== [])
                @php($top = max(1, max(array_column($daily, 'n'))))
                @php($n = count($daily))
                {{-- time runs as the panel's language reads: in Hebrew the first day at the right edge, in English at the left --}}
                @php($rtl = \App\Core\Localization\Locales::direction(app()->getLocale()) === 'rtl')
                @php($xy = collect($daily)->values()->map(fn (array $d, int $i): array => ['x' => round($n > 1 ? ($rtl ? 100 - (100 * $i / ($n - 1)) : 100 * $i / ($n - 1)) : 50, 3), 'y' => round(100 - 88 * $d['n'] / $top, 2), 'label' => $d['label'], 'n' => $d['n']])->all())
                @php($points = collect($xy)->map(fn (array $p): string => ($p['x'] * 6).','.($p['y'] * 1.6))->implode(' '))
                @php($ticks = collect($xy)->filter(fn (array $p, int $i): bool => $n <= 8 || $i % (int) ceil($n / 7) === 0 || $i === $n - 1))
                <div class="lp-card-t" style="margin: 18px 0 6px">
                    <span class="lp-note">{{ $t('store.chart.'.$this->measure) }}</span>
                    <div class="lp-tabs lp-tabs-sm" role="tablist" aria-label="{{ $t('store.chart.choose') }}">
                        @foreach ($page::MEASURES as $measure)
                            <button type="button" role="tab" wire:click="measure('{{ $measure }}')" aria-selected="{{ $this->measure === $measure ? 'true' : 'false' }}" @class(['is-on' => $this->measure === $measure])>{{ $t('store.chart.tabs.'.$measure) }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="lp-chart" x-data="{ at: null, points: @js($xy) }" @mouseleave="at = null"
                     @mousemove="const r = $el.querySelector('svg').getBoundingClientRect(); const x = (($event.clientX - r.left) / r.width) * 100; at = points.reduce((best, p, i) => Math.abs(p.x - x) < Math.abs(points[best].x - x) ? i : best, 0)">
                    <div class="lp-chart-plot">
                    <svg class="lp-spark" viewBox="0 0 600 160" preserveAspectRatio="none" role="img" aria-label="{{ $t('store.chart.'.$this->measure) }}">
                        <g stroke="var(--la-line)" stroke-width="1"><line x1="0" y1="19" x2="600" y2="19"/><line x1="0" y1="89" x2="600" y2="89"/><line x1="0" y1="160" x2="600" y2="160"/></g>
                        <polyline points="{{ $points }} 0,160 600,160" fill="currentColor" fill-opacity=".08" stroke="none"/>
                        <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
                        <line x1="0" x2="0" y1="0" y2="160" stroke="var(--la-axis)" stroke-width="1" vector-effect="non-scaling-stroke" x-show="at !== null" x-cloak :x1="at === null ? 0 : points[at].x * 6" :x2="at === null ? 0 : points[at].x * 6"/>
                    </svg>
                    @foreach ($xy as $i => $p)
                        <span class="lp-dot" style="left: {{ $p['x'] }}%; top: {{ $p['y'] }}%" :class="{ 'is-on': at === {{ $i }} }"></span>
                    @endforeach
                    </div>
                    <span class="lp-chart-top">{{ $top }}</span>
                    <div class="lp-chart-tip" x-show="at !== null" x-cloak :style="at === null ? '' : `left: ${points[at].x}%; top: ${points[at].y * 1.6}px`">
                        <b x-text="at === null ? '' : points[at].n"></b> <span x-text="at === null ? '' : points[at].label"></span>
                    </div>
                    <div class="lp-chart-axis">
                        @foreach ($ticks as $p)<span style="left: {{ $p['x'] }}%">{{ $p['label'] }}</span>@endforeach
                    </div>
                </div>
            @endif
        </section>

        <div class="lp-grid-2">
            @if ($this->has('adds'))
                @php($list = $this->rows('adds'))
                <section class="lp-card" data-card-list="adds">
                    <div class="lp-card-t"><h2>{{ $t('lists.adds.title') }}</h2><span class="lp-note">{{ $t('lists.adds.note') }}</span></div>
                    @forelse ($list['rows'] as $r){!! $row('adds', $r) !!}@empty<p class="lp-empty">{{ $t('none') }}</p>@endforelse
                    @if ($list['total'] > count($list['rows']))<button type="button" class="lp-link lp-all" wire:click="open('adds')">{{ $t('all', ['n' => number_format($list['total'])]) }}</button>@endif
                </section>
            @endif
            @if ($this->has('wish'))
                @php($w = $f['wishlist'] ?? ['lists' => 0, 'saved' => 0, 'phone' => 0])
                @php($list = $this->rows('wish'))
                <section class="lp-card" data-card-list="wish">
                    <div class="lp-card-t"><h2>{{ $t('lists.wish.title') }}</h2><span class="lp-note">{{ $this->periodLabel() }}</span></div>
                    <div class="lp-grid-3" style="margin-bottom: 10px">
                        <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('wish.lists') }}</span><span class="lp-stat-n lp-stat-n-sm">{{ number_format($w['lists']) }}</span></div>
                        <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('wish.saved') }}</span><span class="lp-stat-n lp-stat-n-sm">{{ number_format($w['saved']) }}</span></div>
                        <div class="lp-stat lp-stat-flat"><span class="lp-stat-l">{{ $t('wish.phone') }}</span><span class="lp-stat-n lp-stat-n-sm">{{ number_format($w['phone']) }}</span></div>
                    </div>
                    <div class="lp-note" style="margin: 14px 0 4px">{{ $t('wish.most') }}</div>
                    @forelse ($list['rows'] as $r){!! $row('wish', $r) !!}@empty<p class="lp-empty">{{ $t('none') }}</p>@endforelse
                    @if ($list['total'] > count($list['rows']))<button type="button" class="lp-link lp-all" wire:click="open('wish')">{{ $t('all', ['n' => number_format($list['total'])]) }}</button>@endif
                </section>
            @endif
        </div>

        <div class="lp-grid-2">
            @foreach (['words' => number_format((int) ($f['searches'] ?? 0)), 'questions' => number_format($q['search'])] as $key => $count)
                @if ($this->has($key))
                    @php($list = $this->rows($key))
                    <section class="lp-card" data-card-list="{{ $key }}">
                        <div class="lp-card-t"><h2>{{ $t('lists.'.$key.'.title') }}</h2><span class="lp-note">{{ $t('lists.'.$key.'.note', ['n' => $count]) }}</span></div>
                        @forelse ($list['rows'] as $r){!! $row($key, $r) !!}@empty<p class="lp-empty">{{ $t('none') }}</p>@endforelse
                        @if ($list['total'] > count($list['rows']))<button type="button" class="lp-link lp-all" wire:click="open('{{ $key }}')">{{ $t('all', ['n' => number_format($list['total'])]) }}</button>@endif
                        @if ($key === 'questions' && $list['total'] > 0)<button type="button" class="lp-link lp-all" wire:click="open('asked')" data-open-asked>{{ $t('asked_tabs.open') }}</button>@endif
                    </section>
                @endif
            @endforeach
        </div>

        @if ($this->has('empty'))
            @php($empty = $this->rows('empty'))
            @php($noclick = $this->rows('noclick'))
            @php($list = $this->failed === 'noclick' ? $noclick : $empty)
            <section class="lp-card" data-card-list="failed">
                <div class="lp-card-t">
                    <h2>{{ $t('failed.title') }}</h2>
                    <div class="lp-tabs lp-tabs-sm" role="tablist">
                        <button type="button" role="tab" wire:click="showFailed('empty')" @class(['is-on' => $this->failed === 'empty'])>{{ $t('failed.empty') }} <span class="lp-pill" data-tone="bad">{{ number_format($empty['total']) }}</span></button>
                        <button type="button" role="tab" wire:click="showFailed('noclick')" @class(['is-on' => $this->failed === 'noclick'])>{{ $t('failed.noclick') }} <span class="lp-pill" data-tone="warn">{{ number_format($noclick['total']) }}</span></button>
                    </div>
                </div>
                <p class="lp-note" style="margin: 0 0 8px">{{ $t('failed.'.$this->failed.'_help') }}</p>
                @forelse ($list['rows'] as $i => $r){!! $row($this->failed, $r, $i + 1) !!}@empty<p class="lp-empty">{{ $t('failed.none') }}</p>@endforelse
                @if ($list['total'] > count($list['rows']))<button type="button" class="lp-link lp-all" wire:click="open('{{ $this->failed }}')">{{ $t('all', ['n' => number_format($list['total'])]) }}</button>@endif
            </section>
        @endif

        @if ($this->has('ips'))
            @php($list = $this->rows('ips'))
            <section class="lp-card" data-card-list="ips">
                <div class="lp-card-t"><h2>{{ $t('lists.ips.title') }}</h2><span class="lp-note">{{ $t('lists.ips.note', ['n' => (int) ($f['ips']['over'] ?? 200)]) }}</span></div>
                @forelse ($list['rows'] as $i => $r){!! $row('ips', $r, $i + 1) !!}@empty<p class="lp-empty">{{ $t('ips_none') }}</p>@endforelse
                @if ($list['total'] > count($list['rows']))<button type="button" class="lp-link lp-all" wire:click="open('ips')">{{ $t('all', ['n' => number_format($list['total'])]) }}</button>@endif
            </section>
        @endif
    </div>
    {{-- a whole list, over the screen from the side, with its search, sort and filters --}}
    @if ($this->list !== '')
        @php($found = $this->rows($this->list, $this->limit))
        <div class="lp-drawer" x-data x-init="document.documentElement.style.overflow = 'hidden'" x-on:keydown.escape.window="document.documentElement.style.overflow = ''; $wire.open('')" wire:key="list-{{ $this->list }}" data-list="{{ $this->list }}">
            <div class="lp-drawer-scrim" x-on:click="document.documentElement.style.overflow = ''; $wire.open('')"></div>
            <div class="lp-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="list-title">
                <div class="lp-drawer-head">
                    <div>
                        <h2 id="list-title">{{ $t('lists.'.$this->list.'.title') }}</h2>
                        <div class="lp-note">{{ $t('lists.'.$this->list.'.sub', ['period' => $this->periodLabel()]) }}</div>
                    </div>
                    <button type="button" class="lp-drawer-close" x-on:click="document.documentElement.style.overflow = ''; $wire.open('')" aria-label="{{ __('admin::panels.close') }}"><svg><use href="#lp-i-x"/></svg></button>
                </div>
                <div class="lp-list-tools">
                    <span class="lp-field lp-field-wide"><svg aria-hidden="true"><use href="#lp-i-search"/></svg><input class="lp-input lp-input-sm" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $t('find') }}" aria-label="{{ $t('find') }}"></span>
                    @if (in_array($this->list, ['empty', 'noclick'], true))
                        <div class="lp-tabs lp-tabs-sm" role="group">
                            <button type="button" wire:click="open('empty')" @class(['is-on' => $this->list === 'empty'])>{{ $t('failed.empty') }}</button>
                            <button type="button" wire:click="open('noclick')" @class(['is-on' => $this->list === 'noclick'])>{{ $t('failed.noclick') }}</button>
                        </div>
                    @endif
                    {{-- the questions asked most, or every question by the day it was asked, with what the shopper got --}}
                    @if (in_array($this->list, ['questions', 'asked'], true))
                        <div class="lp-tabs lp-tabs-sm" role="group" data-questions-tabs>
                            <button type="button" wire:click="open('questions')" @class(['is-on' => $this->list === 'questions'])>{{ $t('asked_tabs.top') }}</button>
                            <button type="button" wire:click="open('asked')" @class(['is-on' => $this->list === 'asked'])>{{ $t('asked_tabs.days') }}</button>
                        </div>
                    @endif
                    <div class="lp-tabs lp-tabs-sm" role="group" aria-label="{{ $t('sort') }}" @if ($this->list === 'asked') hidden @endif>
                        <button type="button" wire:click="sortBy('value')" @class(['is-on' => $this->sort === 'value'])>{{ $t('by_count') }}</button>
                        <button type="button" wire:click="sortBy('name')" @class(['is-on' => $this->sort === 'name'])>{{ $t('by_name') }}</button>
                        @if (in_array($this->list, \App\Modules\Analytics\Filament\Merchant\Pages\ShopAnalytics::BY_DATE, true))
                            <button type="button" wire:click="sortBy('date')" @class(['is-on' => $this->sort === 'date']) data-sort-date>{{ $t('by_date') }}</button>
                        @endif
                    </div>
                    <span class="lp-note">{{ $t('shown', ['shown' => number_format(count($found['rows'])), 'all' => number_format($found['total'])]) }}</span>
                </div>
                @forelse ($found['rows'] as $i => $r)
                    {{-- a list read by day: the day's heading where it changes --}}
                    @if ($r->group !== '' && ($i === 0 || $found['rows'][$i - 1]->group !== $r->group))
                        <h3 style="margin: 22px 0 4px; padding-bottom: 6px; border-bottom: 1px solid var(--la-axis); font-size: 14px; font-weight: 700; color: var(--la-ink)" data-day>{{ $r->group }}</h3>
                    @endif
                    {!! $row($this->list, $r, in_array($this->list, ['empty', 'noclick', 'ips'], true) ? $i + 1 : null, true) !!}
                @empty
                    <p class="lp-empty">{{ $t('none') }}</p>
                @endforelse
                @if ($found['total'] > count($found['rows']))
                    <div x-data x-intersect="$wire.more()" class="lp-more"><button type="button" class="lp-link" wire:click="more">{{ $t('more') }}</button></div>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
