{{--
    The shop's top bar (the redesign, 11.2026): light, at the end of the row above every screen. The store, whether
    search is live in it and the language moved to the sidebar (merchant-store, merchant-foot); here are the store
    itself and the person with their menu. An operator who opened someone else's store is told so here, with the way
    back. Inside a platform's admin (HostedPanel), no sign-out.
--}}
@php($t = fn (string $key, array $params = []) => __('admin::panels.top.'.$key, $params))
<div class="lp-topbar" data-merchant-topbar data-shop-banner="{{ $shop->slug }}">
    <div class="lp-top-shop">
        @if ($operator)
            <a class="lp-pill" data-tone="bad" href="{{ $operatorUrl }}" title="{{ __('admin::panels.account.as_operator', ['shop' => $shop->name]) }}" data-operator-shortcut><i></i>{{ $t('as_operator') }}</a>
        @endif
    </div>

    <div class="lp-top-end">
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
                            <button type="button" :class="{ 'is-on': theme === '{{ $mode }}' }" @click="theme = @js($mode); $dispatch('theme-changed', theme); fetch(@js(route('admin.theme', ['theme' => $mode])), { method: 'POST', headers: { 'X-CSRF-TOKEN': @js(csrf_token()), 'Accept': 'application/json' } }).catch(() => {})">{{ $t('themes.'.$mode) }}</button>
                        @endforeach
                    </span>
                </div>
                <hr>
                @if ($operator)
                    <a role="menuitem" href="{{ $operatorUrl }}">{{ __('admin::panels.switch.operator') }}</a>
                @endif
                @if ($logoutUrl)
                    <form method="post" action="{{ $logoutUrl }}" data-logout>
                        @csrf
                        <button type="submit" role="menuitem" class="lp-menu-item">{{ $t('logout') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
