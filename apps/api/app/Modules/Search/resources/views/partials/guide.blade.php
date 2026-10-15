{{-- The prompt at the top of every page an AI reads, the same lines as its JSON (AgentCatalog::instructions), in English: the shopper's language, the tone, where every product is, and to end every answer with the link that buys. --}}
@php($en = \App\Modules\Search\Support\AgentCatalog::AI_LOCALE)
<section class="ai" data-ai-guide lang="{{ $en }}" dir="ltr">
    <h2>{{ __('search::share.guide_title', ['store' => $store['name']], $en) }}</h2>
    <ol>
        @foreach ($lines as $line)<li>{{ $line }}</li>@endforeach
    </ol>
</section>
