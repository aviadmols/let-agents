{{-- Where everything is, as links an AI's browser can open: it opens only addresses it has seen, never one it would make. --}}
@if (! empty($browse))
    <section data-ai-browse>
        <h2>{{ $t('browse_title') }}</h2>
        @if (! empty($browse['all_products']))
            <p>{{ $t('browse_all') }}
                @foreach ($browse['all_products'] as $i => $url)<a href="{{ $url }}">{{ $t('browse_page', ['n' => $i + 1]) }}</a>@if (! $loop->last) · @endif @endforeach
            </p>
        @endif
        @if (! empty($browse['categories']))
            <p class="muted">{{ $t('browse_categories') }}</p>
            <ul class="options">
                @foreach ($browse['categories'] as $category)
                    <li><a href="{{ $category['url'] }}">{{ $category['name'] }}</a> <span class="muted">({{ $category['products'] }})</span></li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
