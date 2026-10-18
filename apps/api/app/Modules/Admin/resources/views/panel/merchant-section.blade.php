{{--
    The pages of one screen, as the design shows them: tabs under the heading (notices, knowledge centre,
    analytics, scan summary), or a side menu (settings, like Shopify's). The current page is marked.
--}}
<nav @class(['lp-section', 'lp-section-side' => $side, 'lp-section-tabs' => ! $side]) aria-label="{{ $label }}" data-section="{{ $key }}">
    @foreach ($pages as $page)
        <a href="{{ $page['url'] }}" @class(['is-on' => $page['current']]) @if ($page['current']) aria-current="page" @endif>
            <span>{{ $page['label'] }}</span>
            @if ($page['badge'] !== null && $page['badge'] !== '' && $page['badge'] !== 0 && $page['badge'] !== '0')<span class="lp-count">{{ $page['badge'] }}</span>@endif
        </a>
    @endforeach
</nav>
