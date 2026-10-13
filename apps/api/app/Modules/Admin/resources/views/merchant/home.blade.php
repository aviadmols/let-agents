<x-filament-panels::page>
    @php($home = \App\Modules\Admin\Filament\Merchant\Pages\ShopOverview::class)
    @php($statuses = $this->statuses())
    @php($notices = $this->notices())
    @php($today = $this->today())
    @php($numbers = $this->numbers())
    @php($figures = $this->figures())
    @php($urgent = collect($notices)->first(fn ($n) => $n->kind === 'todo' && in_array($n->tone, ['warn', 'bad'], true)))
    @php($icons = ['todo' => 'M12 3l10 18H2z M12 10v4M12 17h.01', 'setup' => 'M12 6v6l4 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'insight' => 'M9 18h6M10 21h4M12 3a6 6 0 0 0-4 10.5c.7.6 1 1.3 1 2.5h6c0-1.2.3-1.9 1-2.5A6 6 0 0 0 12 3z', 'report' => 'M5 12l5 5L20 7'])
    @php($badge = ['todo' => ['warn', 'admin::home.kinds.todo'], 'setup' => ['warn', 'admin::home.kinds.setup'], 'insight' => ['tip', 'admin::home.kinds.insight'], 'report' => ['good', 'admin::home.kinds.report']])

    <div class="la-page" data-home>
        {{-- What waits today: up to three cards, a different three each day. --}}
        @if ($today !== [])
            <section aria-label="{{ __('admin::home.today.title') }}" style="display: grid; gap: 10px" data-today>
                <div class="la-toolbar">
                    <p class="la-eyebrow">{{ __('admin::home.today.title') }} · {{ __('admin::home.today.count', ['shown' => count($today), 'all' => count(array_filter($notices, fn ($n) => $n->kind !== 'report'))]) }}</p>
                    <button type="button" class="la-btn la-btn-quiet la-btn-sm la-toolbar-end" wire:click="{{ $this->todayHidden() ? 'showToday' : 'hideToday' }}">{{ __($this->todayHidden() ? 'admin::home.today.show' : 'admin::home.today.hide') }}</button>
                </div>
                @unless ($this->todayHidden())
                    <div class="la-today">
                        @foreach ($today as $card)
                            <article class="la-today-card" data-card="{{ $card->key }}">
                                <span class="la-badge" data-tone="{{ $card->kind === 'todo' && $card->tone === 'info' ? 'info' : $badge[$card->kind][0] }}"><i></i>{{ __($card->kind === 'todo' && $card->tone === 'info' ? 'admin::home.kinds.approve' : $badge[$card->kind][1]) }}</span>
                                <h3>{{ $card->title }}</h3>
                                @if ($card->body)<p>{{ $card->body }}</p>@endif
                                @if ($card->url)
                                    <div class="la-actions">
                                        <a href="{{ $card->url }}" class="la-btn {{ $loop->first ? 'la-btn-primary' : '' }}">{{ $card->action ?? __('admin::home.open') }}</a>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endunless
            </section>
        @endif

        {{-- What is on and what is not. --}}
        @if ($statuses !== [])
            <section class="la-card" data-statuses>
                <div class="la-card-head">
                    <div><h2>{{ __('admin::home.statuses.title') }}</h2><p>{{ __('admin::home.statuses.help') }}</p></div>
                    <span class="la-badge" data-tone="{{ collect($statuses)->contains(fn ($s) => $s->state !== 'on') ? 'warn' : 'good' }}"><i></i>{{ __('admin::home.statuses.count', ['on' => collect($statuses)->where('state', 'on')->count(), 'all' => count($statuses)]) }}</span>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr))">
                    @foreach ($statuses as $status)
                        <div style="display: grid; gap: 6px; align-content: start; padding: 14px 18px; box-shadow: inset -1px -1px 0 var(--la-line-2)" data-status="{{ $status->key }}" data-state="{{ $status->state }}">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px">
                                <strong style="font-size: 14px; font-weight: 600">{{ $status->name }}</strong>
                                <span class="la-badge" data-tone="{{ ['on' => 'good', 'off' => '', 'setup' => 'warn'][$status->state] ?? '' }}"><i></i>{{ __('admin::home.states.'.$status->state) }}</span>
                            </div>
                            <span style="font-size: 13px; color: var(--la-muted)">{{ $status->note }}</span>
                            @if ($status->url)
                                <a href="{{ $status->url }}" class="la-link" style="font-size: 12px">{{ $status->action ?? __('admin::home.open') }} ←</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- The period, as on app.lets.co.il. --}}
        <div class="la-toolbar">
            <span class="la-eyebrow">{{ __('admin::home.period') }}</span>
            <div class="la-seg" role="group" aria-label="{{ __('admin::home.period') }}">
                @foreach ($home::PERIODS as $period)
                    <button type="button" wire:click="setDays({{ $period }})" aria-pressed="{{ $days === $period ? 'true' : 'false' }}">{{ __('admin::home.last_days', ['days' => $period]) }}</button>
                @endforeach
            </div>
        </div>

        {{-- The period's numbers, against the one before. --}}
        @if ($numbers)
            @php($uses = array_sum(array_map(fn ($f) => $f->uses, $figures)))
            @php($usesBefore = array_sum(array_map(fn ($f) => (int) $f->previousUses, $figures)))
            <section class="la-stats" aria-label="{{ __('admin::home.numbers.title') }}" data-numbers>
                @foreach ([
                    'visitors' => [number_format($numbers['visitors']['now']), $home::delta($numbers['visitors']['now'], $numbers['visitors']['before']), __('admin::home.numbers.before', ['days' => $days]), false],
                    'uses' => [number_format($uses), $home::delta($uses, $usesBefore), __('admin::home.numbers.uses_note'), false],
                    'adds' => [number_format($numbers['adds']['now']), $home::delta($numbers['adds']['now'], $numbers['adds']['before']), __('admin::home.numbers.before', ['days' => $days]), false],
                    'orders' => [number_format($numbers['orders']['now']), $home::delta($numbers['orders']['now'], $numbers['orders']['before']), $numbers['all_orders'] > 0 ? __('admin::home.numbers.share', ['share' => (int) round(100 * $numbers['orders']['now'] / $numbers['all_orders'])]) : __('admin::home.numbers.before', ['days' => $days]), true],
                    'revenue' => [$home::money($numbers['revenue']['now'], $numbers['currency']), $home::delta($numbers['revenue']['now'], $numbers['revenue']['before']), __('admin::home.numbers.before', ['days' => $days]), true],
                ] as $key => [$value, $delta, $note, $money])
                    <div class="la-stat" @if ($money) data-money @endif data-number="{{ $key }}">
                        <span class="la-stat-label">{{ __('admin::home.numbers.'.$key) }}</span>
                        <span class="la-stat-value"><bdi>{{ $value }}</bdi></span>
                        <span class="la-stat-foot"><span class="la-delta" data-tone="{{ $delta['tone'] }}">{{ $delta['text'] }}</span>{{ $note }}</span>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- What needs an answer now. --}}
        @if ($urgent)
            <div class="la-strip" data-urgent>
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icons['todo'] }}"></path></svg>
                <p><strong>{{ $urgent->title }}.</strong> {{ $urgent->body }}</p>
                @if ($urgent->url)<a href="{{ $urgent->url }}" class="la-btn la-btn-sm">{{ $urgent->action ?? __('admin::home.open') }}</a>@endif
            </div>
        @endif

        <div class="la-grid-2">
            {{-- What each module brought. --}}
            <section class="la-card" data-modules>
                <div class="la-card-head">
                    <div><h2>{{ __('admin::home.modules.title') }}</h2><p>{{ __('admin::home.modules.help', ['days' => $days]) }}</p></div>
                </div>
                @if ($figures === [])
                    <p class="la-empty">{{ __('admin::home.modules.none') }}</p>
                @else
                    @php($currency = $numbers['currency'] ?? 'ILS')
                    <div class="la-table-wrap">
                        <table class="la-table">
                            <thead>
                                <tr>
                                    <th>{{ __('admin::home.modules.module') }}</th>
                                    <th class="la-num">{{ __('admin::home.modules.uses') }}</th>
                                    <th class="la-num">{{ __('admin::home.modules.adds') }}</th>
                                    <th class="la-num">{{ __('admin::home.modules.orders') }}</th>
                                    <th class="la-num">{{ __('admin::home.modules.revenue') }}</th>
                                    <th class="la-num">{{ __('admin::home.modules.change') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($figures as $figure)
                                    @php($change = $home::delta($figure->uses, (int) $figure->previousUses))
                                    <tr data-module="{{ $figure->key }}">
                                        <td class="la-strong">@if ($figure->url)<a href="{{ $figure->url }}" style="color: inherit; text-decoration: none">{{ $figure->name }}</a>@else{{ $figure->name }}@endif</td>
                                        <td class="la-num">{{ number_format($figure->uses) }}</td>
                                        <td class="la-num">{{ $figure->adds === null ? '—' : number_format($figure->adds) }}</td>
                                        <td class="la-num">{{ $figure->orders === null ? '—' : number_format($figure->orders) }}</td>
                                        <td class="la-num">{{ $figure->revenue === null ? '—' : $home::money($figure->revenue, $currency) }}</td>
                                        <td class="la-num"><span class="la-delta" data-tone="{{ $change['tone'] }}">{{ $change['text'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="la-card-foot"><span>{{ __('admin::home.modules.dash') }}</span></div>
                @endif
            </section>

            {{-- The latest notices. --}}
            <section class="la-card" data-notices>
                <div class="la-card-head">
                    <h2>{{ __('admin::home.notices.title') }}</h2>
                    <a href="{{ $this->inboxUrl() }}" class="la-link">{{ __('admin::home.notices.all', ['count' => count($notices)]) }}</a>
                </div>
                @forelse (array_slice($notices, 0, 5) as $notice)
                    <a @if ($notice->url) href="{{ $notice->url }}" @endif class="la-list-row" style="align-items: flex-start">
                        <span class="la-dot" data-tone="{{ $notice->tone === '' ? '' : $notice->tone }}" style="margin-top: 7px"></span>
                        <span class="la-grow">{{ $notice->title }}<small>{{ $notice->source }}@if ($notice->at) · {{ $notice->at->diffForHumans() }}@endif</small></span>
                    </a>
                @empty
                    <p class="la-empty">{{ __('admin::home.notices.none') }}</p>
                @endforelse
            </section>
        </div>
    </div>
</x-filament-panels::page>
