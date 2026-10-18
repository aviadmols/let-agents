<x-filament-panels::page>
    {{-- Sessions, as the designer's panel lays them out (ShopSessions): four numbers, the filters, the
         visits, and one visit open over the page with every step in order. No name, no contact. --}}
    @php($t = fn (string $key, array $params = []) => __('analytics::sessions.'.$key, $params))
    @php($page = \App\Modules\Analytics\Filament\Merchant\Pages\ShopSessions::class)
    @php($tz = 'Asia/Jerusalem')
    @php($stats = $this->stats())
    @php($pct = fn (int $n): string => $stats['sessions'] > 0 ? (string) round(100 * $n / $stats['sessions']) : '0')
    @php($visits = $this->visits())
    @php($icons = ['view' => 'doc', 'search' => 'search', 'photo' => 'camera', 'ask' => 'chat', 'open' => 'spark', 'heart' => 'heart', 'cart' => 'cart', 'order' => 'check', 'lead' => 'pen'])

    <div class="lp-page" data-sessions>
        <section class="lp-grid-4">
            <div class="lp-stat"><span class="lp-stat-l">{{ $t('stats.sessions') }}</span><span class="lp-stat-n">{{ number_format($stats['sessions']) }}</span><span class="lp-stat-d">{{ $t('stats.visitors', ['n' => number_format($stats['visitors']), 'returning' => $pct($stats['returning'])]) }}</span></div>
            <div class="lp-stat"><span class="lp-stat-l">{{ $t('stats.steps') }}</span><span class="lp-stat-n">{{ number_format($stats['steps'], 1) }}</span><span class="lp-stat-d">{{ $t('stats.steps_note') }}</span></div>
            <div class="lp-stat"><span class="lp-stat-l">{{ $t('stats.searched') }}</span><span class="lp-stat-n">{{ $pct($stats['searched']) }}%</span><span class="lp-stat-d">{{ $t('stats.searched_note', ['n' => number_format($stats['searched'])]) }}</span></div>
            <div class="lp-stat"><span class="lp-stat-l">{{ $t('stats.cart') }}</span><span class="lp-stat-n">{{ $pct($stats['carts']) }}% · {{ $stats['sessions'] > 0 ? round(100 * $stats['orders'] / $stats['sessions'], 1) : 0 }}%</span><span class="lp-stat-d">{{ $t('stats.cart_note', ['carts' => number_format($stats['carts']), 'orders' => number_format($stats['orders'])]) }}</span></div>
        </section>

        <section class="lp-card">
            <div class="lp-filters">
                <div class="lp-filter-line">
                    <span class="lp-lbl">{{ $t('filters.q') }}</span>
                    <span class="lp-field"><svg aria-hidden="true"><use href="#lp-i-search"/></svg><input class="lp-input lp-input-sm" type="search" wire:model.live.debounce.400ms="q" placeholder="{{ $t('filters.q_placeholder') }}" aria-label="{{ $t('filters.q') }}"></span>
                    <span class="lp-lbl">{{ $t('filters.ip') }}</span>
                    <input class="lp-input lp-input-sm" type="search" wire:model.live.debounce.400ms="ip" dir="ltr" placeholder="77.127." aria-label="{{ $t('filters.ip') }}">
                    <span class="lp-lbl">{{ $t('filters.min') }}</span>
                    <div class="lp-chips">
                        @foreach ([0 => $t('filters.all'), 5 => '5+', 10 => '10+'] as $n => $label)
                            <button type="button" wire:click="toggle('min', '{{ $n }}')" @class(['lp-chip lp-chip-sm', 'is-on' => $this->min === $n])><bdi dir="ltr">{{ $label }}</bdi></button>
                        @endforeach
                    </div>
                </div>
                <div class="lp-filter-line">
                    <span class="lp-lbl">{{ $t('filters.src') }}</span>
                    <div class="lp-chips">
                        @foreach (['google', 'facebook', 'whatsapp', 'direct', 'email'] as $src)
                            <button type="button" wire:click="toggle('src', '{{ $src }}')" @class(['lp-chip lp-chip-sm', 'is-on' => $this->src === $src])>{{ $t('filters.sources.'.$src) }}</button>
                        @endforeach
                    </div>
                    <span class="lp-lbl">{{ $t('filters.param') }}</span>
                    <div class="lp-chips">
                        @foreach ([...$this->campaigns(), 'gclid', 'fbclid', 'ref'] as $param)
                            <button type="button" wire:click="toggle('param', @js($param))" @class(['lp-chip lp-chip-sm', 'is-on' => $this->param === $param]) dir="ltr">{{ in_array($param, ['gclid', 'fbclid', 'ref'], true) ? $param : 'utm_campaign='.$param }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="lp-filter-line">
                    <span class="lp-lbl">{{ $t('filters.has') }}</span>
                    <div class="lp-chips">
                        @foreach (\App\Modules\Analytics\Models\AnalyticsVisit::DID as $did)
                            <button type="button" wire:click="toggle('has', '{{ $did }}')" @class(['lp-chip lp-chip-sm', 'is-on' => in_array($did, $this->has, true)])>{{ $t('filters.did.'.$did) }}</button>
                        @endforeach
                    </div>
                    <span class="lp-lbl">{{ $t('filters.dev') }}</span>
                    <div class="lp-chips">
                        @foreach (['phone', 'computer'] as $dev)
                            <button type="button" wire:click="toggle('dev', '{{ $dev }}')" @class(['lp-chip lp-chip-sm', 'is-on' => $this->dev === $dev])>{{ $t('devices.'.$dev) }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="lp-ftags">
                    @php($tags = array_filter([
                        'q' => $this->q !== '' ? $t('tags.q', ['q' => $this->q]) : null,
                        'ip' => $this->ip !== '' ? 'IP '.$this->ip : null,
                        'min' => $this->min > 0 ? $t('tags.min', ['n' => $this->min]) : null,
                        'src' => $this->src !== '' ? $t('tags.src', ['src' => $t('filters.sources.'.$this->src)]) : null,
                        'param' => $this->param !== '' ? $this->param : null,
                        'dev' => $this->dev !== '' ? $t('devices.'.$this->dev) : null,
                        'visitor' => $this->visitor !== '' ? $t('tags.visitor') : null,
                        ...collect($this->has)->mapWithKeys(fn (string $h): array => ['has:'.$h => $t('tags.has', ['what' => $t('filters.did.'.$h)])])->all(),
                    ]))
                    @forelse ($tags as $key => $label)
                        <span class="lp-ftag">{{ $label }}<button type="button" wire:click="clear('{{ $key }}')" aria-label="{{ $t('tags.clear_one') }}"><svg><use href="#lp-i-x"/></svg></button></span>
                    @empty
                        <span class="lp-note">{{ $t('tags.none') }}</span>
                    @endforelse
                    @if ($tags !== [])<button type="button" class="lp-link" wire:click="clear('all')">{{ $t('tags.clear') }}</button>@endif
                </div>
            </div>
        </section>

        <section class="lp-card" data-session-list>
            <div class="lp-card-t"><h2>{{ $t('title') }}</h2><span class="lp-note">{{ $t('count', ['n' => number_format($stats['sessions'])]) }}</span></div>
            @forelse ($visits->take($this->shown) as $visit)
                @php($at = $visit->started_at->copy()->timezone($tz))
                <div @class(['lp-sess', 'is-on' => $this->session === $visit->id]) role="button" tabindex="0" wire:click="open({{ $visit->id }})" wire:keydown.enter="open({{ $visit->id }})" data-visit="{{ $visit->id }}">
                    <span class="lp-sess-when">
                        <b>{{ $at->format('H:i') }}</b>
                        <span>{{ $at->isToday() ? $t('today') : ($at->isYesterday() ? $t('yesterday') : $at->format('j.n')) }}</span>
                        <span>{{ $t('minutes', ['d' => $page::duration($visit)]) }}</span>
                    </span>
                    <span class="lp-sess-mid">
                        <span class="lp-sess-l1">
                            <b>{{ $page::visitorLabel($visit) }}</b>
                            <span class="lp-pill">{{ $page::sourceLabel($visit) }}</span>
                            @if ($visit->campaign)<span class="lp-pill" dir="ltr">{{ $visit->campaign }}</span>@endif
                        </span>
                        <span class="lp-sess-l2">
                            @php($searches = (array) $visit->searches)
                            @php($asks = (array) $visit->asks)
                            @if ($searches !== [])<span class="lp-k">{{ $t('row.searched') }}</span> {{ collect($searches)->take(2)->map(fn ($s) => '״'.$s.'״')->implode(', ') }}@if (count($searches) > 2) <span class="lp-k">+{{ count($searches) - 2 }}</span>@endif @endif
                            @if ($searches !== [] && $asks !== [])<span class="lp-k"> · </span>@endif
                            @if ($asks !== [])<span class="lp-k">{{ $t('row.asked') }}</span> ״{{ $asks[0] }}״@if (count($asks) > 1) <span class="lp-k">+{{ count($asks) - 1 }}</span>@endif @endif
                            @if ($searches === [] && $asks === [])<span class="lp-k">{{ $visit->has('heart') ? $t('row.saved_only') : $t('row.browsed') }}</span>@endif
                        </span>
                        <span class="lp-sess-l3">{{ $page::deviceLabel($visit) }}@if ($visit->ip) · <span dir="ltr">{{ $visit->ip }}</span>@endif</span>
                    </span>
                    <span class="lp-sess-end">
                        @if ($visit->has('order'))
                            <span class="lp-sess-total">{{ $page::money($visit->order_total) }}</span>
                        @else
                            <span class="lp-sess-n">{{ $t('row.steps', ['steps' => $visit->steps, 'pages' => $visit->pages]) }}</span>
                        @endif
                        <span class="lp-sess-outs">
                            @foreach (['search' => 'search', 'ask' => 'chat', 'cart' => 'cart', 'order' => 'check'] as $did => $icon)
                                <span @class(['is-on' => $visit->has($did) || ($did === 'search' && $visit->has('photo'))]) title="{{ $t('filters.did.'.$did) }}"><svg><use href="#lp-i-{{ $icon }}"/></svg></span>
                            @endforeach
                        </span>
                    </span>
                    <svg class="lp-sess-go" aria-hidden="true"><use href="#lp-i-down"/></svg>
                </div>
            @empty
                <p class="lp-empty">{{ $t('none') }}</p>
            @endforelse
            @if ($visits->count() > $this->shown)
                <div x-data x-intersect="$wire.more()" class="lp-more"><button type="button" class="lp-link" wire:click="more">{{ $t('more') }}</button></div>
            @endif
            <p class="lp-note" style="margin: 12px 0 0">{{ $t('privacy') }}</p>
        </section>
    </div>

    @if ($open = $this->opened())
        @php($visit = $open['visit'])
        @php($at = $visit->started_at->copy()->timezone($tz))
        <div class="lp-drawer" x-data x-init="document.documentElement.style.overflow = 'hidden'" x-on:keydown.escape.window="$wire.close()" x-on:remove="document.documentElement.style.overflow = ''" wire:key="session-{{ $visit->id }}">
            <div class="lp-drawer-scrim" wire:click="close" x-on:click="document.documentElement.style.overflow = ''"></div>
            <div class="lp-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="session-title">
                <div class="lp-drawer-head">
                    <div>
                        <h2 id="session-title">{{ $page::visitorLabel($visit) }}</h2>
                        <div class="lp-note">{{ ($at->isToday() ? $t('today') : ($at->isYesterday() ? $t('yesterday') : $at->format('j.n'))).' '.$at->format('H:i') }} · {{ $t('minutes_long', ['d' => $page::duration($visit)]) }} · {{ collect([
                            $t('sum.pages', ['n' => $visit->pages]),
                            count((array) $visit->searches) ? $t('sum.searches', ['n' => count((array) $visit->searches)]) : null,
                            count((array) $visit->asks) ? $t('sum.asks', ['n' => count((array) $visit->asks)]) : null,
                            $visit->has('cart') ? $t('sum.cart') : null,
                            $visit->has('order') ? $t('sum.order', ['total' => $page::money($visit->order_total)]) : null,
                            $visit->has('lead') ? $t('sum.lead') : null,
                        ])->filter()->implode(' · ') }}</div>
                    </div>
                    <button type="button" class="lp-drawer-close" wire:click="close" x-on:click="document.documentElement.style.overflow = ''" aria-label="{{ __('admin::panels.close') }}"><svg><use href="#lp-i-x"/></svg></button>
                </div>
                <div class="lp-meta-grid">
                    <div><span>{{ $t('meta.from') }}</span><span>{{ $page::sourceLabel($visit) }}@if ($visit->referrer) · <span dir="ltr">{{ $visit->referrer }}</span>@endif</span></div>
                    <div><span>{{ $t('meta.device') }}</span><span>{{ $page::deviceLabel($visit) }}</span></div>
                    <div><span>IP</span><span dir="ltr" style="text-align: start">{{ $visit->ip ?? '—' }}</span></div>
                    <div><span>{{ $t('meta.landing') }}</span><span dir="ltr" style="text-align: end">{{ $visit->landing ?? '—' }}</span></div>
                    <div class="lp-meta-wide"><span>{{ $t('meta.params') }}</span><span dir="ltr" style="text-align: end">
                        @forelse ((array) $visit->params as $name => $value)
                            <span class="lp-chip lp-chip-sm lp-chip-quiet">{{ $name }}={{ $value }}</span>
                        @empty
                            <span class="lp-note">{{ $t('meta.no_params') }}</span>
                        @endforelse
                    </span></div>
                </div>
                <div class="lp-note" style="margin: 4px 0 10px">{{ $t('meta.order') }}</div>
                <div class="lp-evs">
                    @foreach ($open['steps'] as $step)
                        @if ($step['gap'] >= 1)<div class="lp-ev lp-ev-gap">{{ trans_choice('analytics::sessions.gap', $step['gap'], ['n' => $step['gap']]) }}</div>@endif
                        <div class="lp-ev">
                            <span @class(['lp-ev-ic', 'is-good' => $step['kind'] === 'order', 'is-hot' => in_array($step['kind'], ['cart', 'lead'], true)])><svg><use href="#lp-i-{{ $icons[$step['kind']] ?? 'doc' }}"/></svg></span>
                            <span class="lp-ev-body">{{ $step['text'] }}@if ($step['note'] !== '')<small>{{ $step['note'] }}</small>@endif</span>
                            <span class="lp-ev-t" dir="ltr">{{ $step['at']->copy()->timezone($tz)->format('H:i:s') }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="lp-drawer-foot">
                    @if ($visit->visitor_hash)<button type="button" class="lp-btn lp-btn-sm" wire:click="narrowTo('visitor')">{{ $t('actions.visitor') }}</button>@endif
                    @if ($visit->ip)<button type="button" class="lp-btn lp-btn-sm" wire:click="narrowTo('ip')">{{ $t('actions.ip') }}</button>@endif
                    <button type="button" class="lp-link" x-data="{ copied: false }" x-on:click="navigator.clipboard?.writeText(window.location.href); copied = true; setTimeout(() => copied = false, 1600)"><span x-show="! copied">{{ $t('actions.copy') }}</span><span x-show="copied" x-cloak>{{ $t('actions.copied') }}</span></button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
