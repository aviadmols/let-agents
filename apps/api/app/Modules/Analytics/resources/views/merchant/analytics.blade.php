<x-filament-panels::page>
    {{-- The shop's analytics, as the designer's panel lays them out (ShopAnalytics): one screen, or one list in full. --}}
    @php($t = fn (string $key, array $params = []) => __('analytics::report.'.$key, $params))
    @php($page = \App\Modules\Analytics\Filament\Merchant\Pages\ShopAnalytics::class)

    {{-- one row of any list: a rank or a picture, what it is, a line under it, a pill, its number, its button --}}
    @php($row = function (string $list, \App\Modules\Admin\Contracts\ReportRow $r, ?int $rank = null) use ($t): string {
        return view('analytics::merchant.report-row', ['list' => $list, 'r' => $r, 'rank' => $rank, 't' => $t])->render();
    })

    @if ($this->list !== '')
        @php($found = $this->rows($this->list, $this->limit))
        <div class="lp-page" data-list="{{ $this->list }}">
            <section class="lp-card">
                <div class="lp-list-tools">
                    <input class="lp-input lp-input-sm" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $t('find') }}" aria-label="{{ $t('find') }}">
                    <div class="lp-tabs lp-tabs-sm" role="group" aria-label="{{ $t('sort') }}">
                        <button type="button" wire:click="sortBy('value')" @class(['is-on' => $this->sort === 'value'])>{{ $t('by_count') }}</button>
                        <button type="button" wire:click="sortBy('name')" @class(['is-on' => $this->sort === 'name'])>{{ $t('by_name') }}</button>
                    </div>
                    <span class="lp-note">{{ $t('shown', ['shown' => number_format(count($found['rows'])), 'all' => number_format($found['total'])]) }}</span>
                </div>
                @forelse ($found['rows'] as $i => $r)
                    {!! $row($this->list, $r, in_array($this->list, ['empty', 'noclick', 'ips'], true) ? $i + 1 : null) !!}
                @empty
                    <p class="lp-empty">{{ $t('none') }}</p>
                @endforelse
                @if ($found['total'] > count($found['rows']))
                    <div x-data x-intersect="$wire.more()" class="lp-more"><button type="button" class="lp-link" wire:click="more">{{ $t('more') }}</button></div>
                @endif
            </section>
        </div>
    @else
        @php($f = $this->figures())
        @php($q = $f['questions'] ?? ['all' => 0, 'answered' => 0, 'unanswered' => 0, 'off' => 0])
        @php($adds = $f['adds'] ?? ['all' => 0, 'search' => 0, 'widget' => 0, 'page' => 0])
        @php($orders = $f['orders'] ?? ['n' => 0, 'revenue' => 0, 'currency' => 'ILS'])
        @php($views = (int) ($f['views'] ?? 0))
        @php($daily = $f['daily_adds'] ?? [])
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
                    @php($points = collect($daily)->values()->map(fn (array $d, int $i): string => round($n > 1 ? 600 - (600 * $i / ($n - 1)) : 300, 1).','.round(150 - 130 * $d['n'] / $top, 1))->implode(' '))
                    <div class="lp-note" style="margin: 18px 0 6px">{{ $t('store.adds_by_day') }}</div>
                    <svg class="lp-spark" viewBox="0 0 600 160" preserveAspectRatio="none" role="img" aria-label="{{ $t('store.adds_by_day') }}">
                        <g stroke="var(--la-line)" stroke-width="1"><line x1="0" y1="20" x2="600" y2="20"/><line x1="0" y1="85" x2="600" y2="85"/><line x1="0" y1="150" x2="600" y2="150"/></g>
                        <polyline points="{{ $points }} 0,150 600,150" fill="currentColor" fill-opacity=".08" stroke="none"/>
                        <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
                        <text x="4" y="16" font-size="11" fill="var(--la-muted)">{{ $top }}</text>
                    </svg>
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
                @foreach (['words' => number_format((int) ($f['searches'] ?? 0)), 'questions' => number_format($q['all'])] as $key => $count)
                    @if ($this->has($key))
                        @php($list = $this->rows($key))
                        <section class="lp-card" data-card-list="{{ $key }}">
                            <div class="lp-card-t"><h2>{{ $t('lists.'.$key.'.title') }}</h2><span class="lp-note">{{ $t('lists.'.$key.'.note', ['n' => $count]) }}</span></div>
                            @forelse ($list['rows'] as $r){!! $row($key, $r) !!}@empty<p class="lp-empty">{{ $t('none') }}</p>@endforelse
                            @if ($list['total'] > count($list['rows']))<button type="button" class="lp-link lp-all" wire:click="open('{{ $key }}')">{{ $t('all', ['n' => number_format($list['total'])]) }}</button>@endif
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
    @endif
</x-filament-panels::page>
