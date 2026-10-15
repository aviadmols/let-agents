@if (($policies['promises'] ?? []) !== [] || ($policies['pages'] ?? []) !== [])
    <h2>{{ $t('policies') }}</h2>
    @if (($policies['promises'] ?? []) !== [])
        <dl>
            @foreach ($policies['promises'] as $promise)
                <dt>{{ $promise['name'] }}</dt><dd>{{ $promise['detail'] }}</dd>
            @endforeach
        </dl>
    @endif
    @if (($policies['pages'] ?? []) !== [])
        <p class="muted">{{ $t('policy_pages') }}:
            @foreach ($policies['pages'] as $page)<a href="{{ $page['url'] }}" rel="noopener">{{ $page['title'] }}</a>@if (! $loop->last) · @endif @endforeach
        </p>
    @endif
@endif
