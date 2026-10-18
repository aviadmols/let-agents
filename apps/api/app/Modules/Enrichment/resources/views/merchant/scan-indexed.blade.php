{{-- What the search knows of a product or post: the very text its meaning vectors were made from, piece by piece. --}}
@php($t = fn (string $key, array $params = []) => __('enrichment::scan.'.$key, $params))
<div class="lp-card-t lp-sub-t">
    <h2>{{ $t('indexed') }}</h2>
    <span class="lp-note">{{ $indexed['pieces'] === [] ? $t('indexed_none') : collect([
        trans_choice('enrichment::scan.indexed_pieces', count($indexed['pieces']), ['n' => count($indexed['pieces'])]),
        $t('indexed_chars', ['n' => number_format($indexed['chars'])]),
        $indexed['at'] ? $t('indexed_at', ['date' => $indexed['at']]) : null,
    ])->filter()->implode(' · ') }}</span>
</div>
@if ($indexed['pieces'] === [])
    <p class="lp-empty">{{ $t('indexed_empty') }}</p>
@else
    @if (($store ?? null) !== null && $store > 0 && $indexed['chars'] < $store * 0.5)
        <p class="lp-note" data-tone="warn">{{ $t('indexed_short', ['store' => number_format($store)]) }}</p>
    @endif
    @if ($indexed['waiting'] > 0)<p class="lp-note">{{ $t('indexed_waiting', ['n' => $indexed['waiting']]) }}</p>@endif
    <div class="lp-indexed" x-data="{ all: false }">
        @foreach ($indexed['pieces'] as $i => $piece)
            <div class="lp-ask-answer" @if ($i > 0) x-show="all" x-cloak @endif><span class="lp-note">{{ $t('indexed_piece', ['n' => $i + 1]) }}</span><p>{{ $piece }}</p></div>
        @endforeach
        @if (count($indexed['pieces']) > 1)
            <button type="button" class="lp-link" style="margin-top: 8px" x-on:click="all = ! all" x-text="all ? @js($t('indexed_less')) : @js($t('indexed_more', ['n' => count($indexed['pieces']) - 1]))"></button>
        @endif
    </div>
@endif
