<x-filament-widgets::widget>
    @if ($kinds !== [])
        <style>
            .la-days { display: flex; gap: 12px; overflow-x: auto; scroll-snap-type: x mandatory; scroll-behavior: smooth; padding: 2px 2px 10px; scrollbar-width: thin; }
            .la-day { flex: 0 0 min(340px, 86%); scroll-snap-align: start; display: grid; align-content: start; gap: 10px; padding: 16px; border-radius: 16px; border: 1px solid var(--la-line, rgba(127,127,127,.2)); background: var(--la-surface, transparent); }
            .la-day h3 { margin: 0; font-size: 15px; font-weight: 700; }
            .la-day p { margin: 0; font-size: 13.5px; line-height: 1.55; white-space: pre-line; color: var(--la-muted); }
            .la-day ul { margin: 4px 0 0; padding-inline-start: 18px; display: grid; gap: 3px; font-size: 13px; }
            .la-day-list > strong { font-size: 12.5px; }
            .la-days-nav { display: flex; gap: 6px; }
            .la-days-nav button { width: 30px; height: 30px; border-radius: 50%; border: 1px solid var(--la-line, rgba(127,127,127,.3)); background: transparent; cursor: pointer; }
        </style>
        <div style="display: grid; gap: 16px">
            @foreach ($kinds as $kind)
                <section class="la-card" data-daily-reports x-data="{ go(d) { const r = $refs.row; r.scrollBy({ left: (getComputedStyle(r).direction === 'rtl' ? -1 : 1) * d * r.clientWidth * 0.85, behavior: 'smooth' }) } }">
                    <div class="la-card-body" style="display: grid; gap: 12px">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px">
                            <h2 style="margin: 0; font-size: 17px; font-weight: 700">{{ $kind['label'] }}</h2>
                            @if (count($kind['days']) > 1)
                                <div class="la-days-nav">
                                    <button type="button" x-on:click="go(-1)" aria-label="{{ __('admin::daily.newer') }}">›</button>
                                    <button type="button" x-on:click="go(1)" aria-label="{{ __('admin::daily.older') }}">‹</button>
                                </div>
                            @endif
                        </div>
                        <div class="la-days" x-ref="row">
                            @foreach ($kind['days'] as $day)
                                <article class="la-day" data-day="{{ $day->day->toDateString() }}">
                                    <div style="display: flex; justify-content: space-between; gap: 8px; align-items: baseline">
                                        <h3>{{ $day->day->translatedFormat('l, j.n') }}</h3>
                                        @if ($loop->first)<span class="la-badge" data-tone="info"><i></i>{{ __('admin::daily.latest') }}</span>@endif
                                    </div>
                                    <span class="la-badge" data-tone="{{ $day->tone ?: 'info' }}" style="justify-self: start"><i></i>{{ $day->headline }}</span>
                                    @if ($day->summary)<p dir="auto">{{ $day->summary }}</p>@endif
                                    @foreach ($day->lists as $list)
                                        <div class="la-day-list">
                                            <strong>{{ $list['label'] }}</strong>
                                            <ul>@foreach ($list['items'] as $item)<li dir="auto">{{ $item }}</li>@endforeach</ul>
                                        </div>
                                    @endforeach
                                    @if ($day->url)<a class="lp-link" href="{{ $day->url }}" style="font-size: 13px">{{ __('admin::daily.open') }}</a>@endif
                                </article>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</x-filament-widgets::widget>
