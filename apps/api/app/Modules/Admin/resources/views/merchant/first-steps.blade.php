{{-- The shop's first steps, drawn as the designer's panel draws them (the home, and the last first-run screen).
     The page that includes it answers looked(): the step "see it as a shopper" opened from here. --}}
@php($t = fn (string $key, array $params = []) => __('admin::home.'.$key, $params))
@php($done = collect($steps)->where('done', true)->count())
@php($next = collect($steps)->firstWhere('done', false)?->key)
<div class="lp-card-t" style="margin-block-end: 8px">
    <h2>{{ $t('steps.title', ['n' => count($steps)]) }}</h2>
    <span class="lp-note">{{ $t('steps.progress', ['done' => $done, 'all' => count($steps)]) }}</span>
</div>
<div class="lp-bar"><i style="width: {{ (int) round(100 * $done / max(1, count($steps))) }}%"></i></div>
<ol class="lp-wins">
    @foreach ($steps as $i => $step)
        <li @class(['lp-win', 'is-done' => $step->done, 'is-next' => $step->key === $next]) data-step="{{ $step->key }}" data-done="{{ $step->done ? '1' : '0' }}">
            <span class="lp-win-n">@if ($step->done)<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="sr-only">{{ $t('steps.done') }}</span>@else{{ $i + 1 }}@endif</span>
            <div>
                <div class="lp-win-t">{{ $step->title }}</div>
                <div class="lp-win-s">{{ $step->body }}</div>
            </div>
            @if (! $step->done && $step->url)
                <a href="{{ $step->url }}" @class(['lp-btn lp-btn-sm', 'lp-btn-primary' => $step->key === $next])
                   @if ($step->external) target="_blank" rel="noopener" @if ($step->key === 'look') wire:click="looked" @endif @endif>{{ $step->button }}</a>
            @endif
        </li>
    @endforeach
</ol>
