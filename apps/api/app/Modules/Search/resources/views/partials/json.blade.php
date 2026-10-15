{{-- The whole page as one JSON object, in the page itself: an AI that reads the page has it all, instructions first. --}}
<section data-ai-json>
    <h2>{{ $t('json_title') }}</h2>
    <p class="muted">{{ $t('json_link') }} <a href="{{ $json }}" dir="ltr">{{ $json }}</a></p>
    <pre dir="ltr" class="json">{{ json_encode($ai, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</pre>
</section>
