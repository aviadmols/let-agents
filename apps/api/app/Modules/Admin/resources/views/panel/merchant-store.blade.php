{{--
    The store at the top of the sidebar (the redesign, 11.2026): its initials, its name, and whether search is live in it.
    A person with several stores switches here.
--}}
@php($t = fn (string $key, array $params = []) => __('admin::panels.top.'.$key, $params))
@php($initials = mb_strtoupper(collect(preg_split('/\s+/u', trim($shop->name)) ?: [])->filter()->take(2)->map(fn (string $w): string => mb_substr($w, 0, 1))->implode('')))
<div class="lp-store" data-live="{{ $live ? '1' : '0' }}" @if (count($shops) > 1) x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" @endif>
    @if (count($shops) > 1)
        <button type="button" class="lp-store-in" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="menu">
    @else
        <div class="lp-store-in">
    @endif
        <span class="lp-store-logo" aria-hidden="true">{{ $initials !== '' ? $initials : 'L' }}</span>
        <span class="lp-store-text">
            <span class="lp-store-name" title="{{ $shop->name }}">{{ $shop->name }}</span>
            <span class="lp-store-live" data-tone="{{ $live ? 'live' : 'off' }}"><i></i><span>{{ $t($live ? 'search_live' : 'preview') }}</span></span>
        </span>
    @if (count($shops) > 1)
            <svg class="lp-store-more" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="lp-menu lp-menu-start" x-show="open" x-cloak x-transition.opacity role="menu">
            <div class="lp-menu-head">{{ __('admin::panels.account.switch') }}</div>
            @foreach ($shops as $other)
                <a role="menuitem" href="{{ $other['url'] }}" @if ($other['current']) aria-current="true" @endif>{{ $other['name'] }}</a>
            @endforeach
        </div>
    @else
        </div>
    @endif
</div>
