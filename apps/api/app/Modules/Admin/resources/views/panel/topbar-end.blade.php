{{-- The end of the top bar: the language switch, then the person's name beside their menu. --}}
<div class="la-topbar-end" data-topbar-end>
    <nav class="la-seg la-seg-sm" aria-label="{{ __('admin::panels.locale.label') }}">
        @foreach ($locales as $locale)
            @if ($locale === $current)
                <span aria-current="true" lang="{{ $locale }}">{{ __("admin::panels.locale.short.{$locale}", [], $locale) }}</span>
            @else
                <a href="{{ route('admin.locale', ['locale' => $locale]) }}" lang="{{ $locale }}">{{ __("admin::panels.locale.short.{$locale}", [], $locale) }}</a>
            @endif
        @endforeach
    </nav>
    @if ($name !== '')
        <span class="la-topbar-name">{{ $name }}</span>
    @endif
</div>
