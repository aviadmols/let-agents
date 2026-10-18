{{--
    The shop's top bar, as the designer's panel draws it, at the top of every screen: the store and whether
    Lets is live in it, then the language, the store itself, and the person with their menu. An operator
    who opened someone else's store is told so here, with the way back.
--}}
@php($t = fn (string $key, array $params = []) => __('admin::panels.top.'.$key, $params))
<div class="lp-topbar" data-merchant-topbar data-shop-banner="{{ $shop->slug }}">
    <div class="lp-top-shop">
        <span class="lp-top-av" aria-hidden="true">{{ mb_substr($shop->name, 0, 1) }}</span>
        @if (count($shops) > 1)
            <div class="lp-drop" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button type="button" class="lp-top-name" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="menu">
                    <b>{{ $shop->name }}</b>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </button>
                <div class="lp-menu lp-menu-start" x-show="open" x-cloak x-transition.opacity role="menu">
                    <div class="lp-menu-head">{{ __('admin::panels.account.switch') }}</div>
                    @foreach ($shops as $other)
                        <a role="menuitem" href="{{ $other['url'] }}" @if ($other['current']) aria-current="true" @endif>{{ $other['name'] }}</a>
                    @endforeach
                </div>
            </div>
        @else
            <b class="lp-top-name">{{ $shop->name }}</b>
        @endif
        <span class="lp-pill" data-tone="{{ $live ? 'good' : 'warn' }}" data-live="{{ $live ? '1' : '0' }}"><i></i>{{ $t($live ? 'live' : 'preview') }}</span>
        @if ($operator)
            <a class="lp-pill" data-tone="bad" href="{{ $operatorUrl }}" title="{{ __('admin::panels.account.as_operator', ['shop' => $shop->name]) }}" data-operator-shortcut><i></i>{{ $t('as_operator') }}</a>
        @endif
    </div>

    <div class="lp-top-end">
        <nav class="lp-lang" aria-label="{{ __('admin::panels.locale.label') }}">
            @foreach ($locales as $locale)
                @if ($locale === $current)
                    <span aria-current="true" lang="{{ $locale }}">{{ __("admin::panels.locale.names.{$locale}", [], $locale) }}</span>
                @else
                    <a href="{{ route('admin.locale', ['locale' => $locale]) }}" lang="{{ $locale }}">{{ __("admin::panels.locale.names.{$locale}", [], $locale) }}</a>
                @endif
            @endforeach
        </nav>

        @if ($storeUrl)
            <a class="lp-btn lp-btn-sm lp-top-store" href="{{ $storeUrl }}" target="_blank" rel="noopener" aria-label="{{ $t('open_store') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M19 14v6H4V5h6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>{{ $t('open_store') }}</span>
            </a>
        @endif

        <div class="lp-drop lp-user" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
            <button type="button" class="lp-user-btn" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="menu" data-user-menu>
                <span class="lp-top-av lp-top-av-ink" aria-hidden="true">{{ mb_substr($name !== '' ? $name : $email, 0, 1) }}</span>
                <span class="lp-user-who"><b>{{ $name !== '' ? $name : $email }}</b><span>{{ $role }}</span></span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            <div class="lp-menu" x-show="open" x-cloak x-transition.opacity role="menu">
                <div class="lp-menu-head"><b>{{ $name !== '' ? $name : $email }}</b>{{ $email }}</div>
                @foreach ($links as $link)
                    <a role="menuitem" href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                @endforeach
                <hr>
                <div class="lp-menu-row" x-data="{ theme: localStorage.getItem('theme') || 'system' }">
                    <span>{{ $t('theme') }}</span>
                    <span class="lp-tabs lp-tabs-sm" role="group" aria-label="{{ $t('theme') }}">
                        @foreach (['light', 'dark', 'system'] as $mode)
                            <button type="button" :class="{ 'is-on': theme === '{{ $mode }}' }" @click="theme = '{{ $mode }}'; $dispatch('theme-changed', theme)">{{ $t('themes.'.$mode) }}</button>
                        @endforeach
                    </span>
                </div>
                <hr>
                @if ($operator)
                    <a role="menuitem" href="{{ $operatorUrl }}">{{ __('admin::panels.switch.operator') }}</a>
                @endif
                <form method="post" action="{{ $logoutUrl }}">
                    @csrf
                    <button type="submit" role="menuitem" class="lp-menu-item">{{ $t('logout') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>
