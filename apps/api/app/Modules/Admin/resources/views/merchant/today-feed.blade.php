{{--
    Today's "Right now in your store" (TodayFeed): the latest searches, photo searches and questions with what each
    shopper got. Refreshed every few seconds by the browser, unless paused, pointed at, focused or a row is open.
    Opening a row shows everything the search log keeps about it, and a link to the whole visit in the log.
--}}
@php($t = fn (string $key, array $params = []) => __('admin::today.live.'.$key, $params))
@php($icons = [
    'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m20 20-4-4"/></svg>',
    'ask' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4a8 8 0 0 0-6.8 12.2L4 20l3.9-1.2A8 8 0 1 0 12 4z"/></svg>',
    'photo' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="2.5"/><circle cx="12" cy="12.5" r="3.2"/></svg>',
])
@php($mark = '<svg viewBox="0 0 225 225" fill="currentColor" aria-hidden="true"><path d="m0,225c0-62.13,50.37-112.5,112.5-112.5s112.5,50.37,112.5,112.5H0Z"/><path d="m225,0c0,62.13-50.37,112.5-112.5,112.5S0,62.13,0,0h225Z"/></svg>')
<div class="lp-d-live-in"
     x-data="{ paused: window.matchMedia('(prefers-reduced-motion: reduce)').matches, hover: false }"
     x-init="setInterval(() => { if (! paused && ! hover && ! document.hidden && $wire.open === '' ) $wire.$refresh() }, 5000)"
     data-live-feed>
    <div class="lp-d-card-head">
        <div>
            <h2 class="lp-d-card-title" id="live-title"><span class="lp-d-pulse" aria-hidden="true"></span><span>{{ $t('title') }}</span></h2>
            <p class="lp-d-card-sub">{{ $t('sub') }}</p>
        </div>
        <button type="button" class="lp-d-pause" @click="paused = ! paused" :aria-pressed="paused.toString()" :aria-label="paused ? @js($t('resume')) : @js($t('pause'))" aria-label="{{ $t('pause') }}">
            <svg x-show="! paused" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
            <svg x-show="paused" x-cloak viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5l11 7-11 7z"/></svg>
        </button>
    </div>

    <label class="lp-d-live-search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg>
        <span class="sr-only">{{ $t('search') }}</span>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $t('search') }}" autocomplete="off" dir="auto">
    </label>

    @if ($rows === [])
        <div class="lp-d-zero">
            <span class="lp-d-zero-mark lp-d-waiting">{!! $mark !!}</span>
            @if ($search !== '')
                <b>{{ $t('none_found') }}</b><span>{{ $t('none_found_body') }}</span>
            @else
                <b>{{ $t('none') }}</b><span>{{ $t('none_body') }}</span>
            @endif
        </div>
    @else
        <ul class="lp-d-feed" @mouseenter="hover = true" @mouseleave="hover = false" @focusin="hover = true" @focusout="hover = false">
            @foreach ($rows as $row)
                <li wire:key="feed-{{ $row['id'] }}" @class(['is-open' => $open === $row['id']])>
                    <button type="button" class="lp-d-feed-row" wire:click="toggle(@js($row['id']))" aria-expanded="{{ $open === $row['id'] ? 'true' : 'false' }}">
                        @if ($row['picture'])<img src="{{ $row['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif
                        <span class="lp-d-feed-text">
                            <span class="lp-d-feed-q" dir="auto" title="{{ $row['query'] }}">{{ $row['query'] }}</span>
                            <span class="lp-d-feed-a"><span class="lp-d-feed-kind" title="{{ $t('kinds.'.$row['kind']) }}">{!! $icons[$row['kind']] ?? $icons['search'] !!}</span><bdi>{{ $row['got'] }}</bdi></span>
                        </span>
                        <span class="lp-d-feed-when lp-d-num">{{ \Illuminate\Support\Carbon::instance($row['at'])->locale(app()->getLocale())->shortRelativeToNowDiffForHumans() }}</span>
                    </button>
                    @if ($open === $row['id'])
                        <dl class="lp-d-feed-details" data-feed-details>
                            @foreach ($row['details'] as [$label, $value])
                                <div><dt>{{ $label }}</dt><dd dir="auto">{{ $value }}</dd></div>
                            @endforeach
                            @if ($row['url'] ?? null)
                                <a class="lp-d-feed-more" href="{{ $row['url'] }}">{{ $t('whole_visit') }}</a>
                            @endif
                        </dl>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    <p class="lp-d-live-foot">{{ $t('foot') }}</p>
</div>
