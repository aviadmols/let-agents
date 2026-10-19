{{--
    The top of a screen, under its heading, as the design shows it: a short hint the first time the
    screen opens (closed for good in this browser), then the screen's pages as tabs (notices, analytics)
    or as a side menu (settings, like Shopify's). The current page is marked.
--}}
<div class="lp-hint" role="note" data-hint="{{ $key }}" x-data="{ shown: false, seen() { try { return JSON.parse(localStorage.getItem('lp-hints') || '{}') } catch (e) { return {} } }, close() { this.shown = false; try { const seen = this.seen(); seen['{{ $key }}'] = 1; localStorage.setItem('lp-hints', JSON.stringify(seen)) } catch (e) {} } }" x-init="shown = ! seen()['{{ $key }}']" x-show="shown" x-cloak x-transition.opacity>
    <span class="lp-mark lp-mark-sm" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 3h14a7 7 0 0 1-14 0zM5 21h14a7 7 0 0 0-14 0z" fill="currentColor"/></svg></span>
    <span>{{ $hint }}</span>
    <button type="button" aria-label="{{ __('admin::panels.close') }}" @click="close()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
    </button>
</div>
@if ($tabs)
    <nav @class(['lp-section', 'lp-section-side' => $side, 'lp-section-tabs' => ! $side]) aria-label="{{ $label }}" data-section="{{ $key }}">
        @php($group = null)
        @foreach ($pages as $page)
            @if ($side && $page['group'] !== null && $page['group'] !== $group)
                @php($group = $page['group'])
                <div class="lp-side-g">{{ $group }}</div>
            @endif
            <a href="{{ $page['url'] }}" @class(['is-on' => $page['current']]) @if ($page['current']) aria-current="page" @endif>
                <span>{{ $page['label'] }}</span>
                @if ($page['badge'] !== null && $page['badge'] !== '' && $page['badge'] !== 0 && $page['badge'] !== '0')<span class="lp-count">{{ $page['badge'] }}</span>@endif
            </a>
        @endforeach
    </nav>
@endif