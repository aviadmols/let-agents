<x-filament-panels::page>
    {{-- Improvements, as the designer's redesign draws it (Improvements page; prototype improvements.html, 11.2026). --}}
    @php($t = fn (string $key, array $params = []) => __('admin::improvements.'.$key, $params))
    @php($n = fn (string $key, int $count, array $params = []) => trans_choice('admin::improvements.'.$key, $count, ['n' => number_format($count)] + $params))
    @php($mark = '<svg viewBox="0 0 225 225" fill="currentColor" aria-hidden="true"><path d="m0,225c0-62.13,50.37-112.5,112.5-112.5s112.5,50.37,112.5,112.5H0Z"/><path d="m225,0c0,62.13-50.37,112.5-112.5,112.5S0,62.13,0,0h225Z"/></svg>')
    @php($arrow = '<svg class="lp-d-flip" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>')
    @php($icons = [
        'nothing' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2M8.8 8.8l4.4 4.4M13.2 8.8l-4.4 4.4"/>',
        'noclick' => '<path d="M5 3.5l13 6.2-5.6 1.9-1.9 5.6z"/><path d="m12.4 11.6 6.1 6.1"/>',
        'question' => '<path d="M12 4a8 8 0 0 0-6.8 12.2L4 20l3.9-1.2A8 8 0 1 0 12 4z"/>',
        'info' => '<path d="M3.5 12.6V4.5a1 1 0 0 1 1-1h8.1l8 8-9.1 9.1z"/><circle cx="8.5" cy="8.5" r="1.4"/>',
        'idea' => '<path d="M12 3.5l1.9 4.6 4.6 1.9-4.6 1.9L12 16.5l-1.9-4.6L5.5 10l4.6-1.9z"/><path d="M18.5 15.5l.8 1.9 1.9.8-1.9.8-.8 1.9-.8-1.9-1.9-.8 1.9-.8z"/>',
    ])
    @php($chips = ['required' => 'sun', 'quick' => 'lime', 'asked' => 'sun', 'nice' => 'quiet', 'bigger' => 'ink'])
    @php($approved = $this->approved())
    @php($tally = $this->tally($approved))
    @php($fixes = $this->fixes())
    @php($auto = $this->auto())
    @php($goal = $this->goal())

    <div class="lp-page lp-d" data-improvements>
        <div class="lp-d-imp">
            {{-- since the owner started --}}
            <section class="lp-d-tally" aria-label="{{ $t('tally.label') }}">
                <div><span class="lp-d-tally-n lp-d-num"><em>{{ number_format($tally['approved']) }}</em></span><span class="lp-d-tally-l">{{ $n('tally.approved', $tally['approved']) }}</span></div>
                <div><span class="lp-d-tally-n lp-d-num">{{ number_format($tally['found']) }}</span><span class="lp-d-tally-l">{{ $t('tally.found') }}</span></div>
                <div><span class="lp-d-tally-n lp-d-num"><bdi>{{ $tally['sales'] }}</bdi></span><span class="lp-d-tally-l">{{ $t('tally.sales') }}</span></div>
                <span class="lp-d-tally-mark">{!! $mark !!}</span>
            </section>

            <div class="lp-d-imp-main">
                <div class="lp-d-tabs" role="tablist" aria-label="{{ $t('title') }}">
                    @foreach (['waiting' => count($fixes), 'auto' => $auto['total'], 'done' => count($approved)] as $tab => $count)
                        <button type="button" role="tab" wire:click="show('{{ $tab }}')" aria-selected="{{ $this->tab === $tab ? 'true' : 'false' }}" data-tab="{{ $tab }}">
                            <span>{{ $t('tabs.'.$tab) }}</span><span class="lp-d-tabs-n lp-d-num">{{ number_format($count) }}</span>
                        </button>
                    @endforeach
                </div>

                @if ($this->tab === 'waiting')
                    @if ($fixes === [])
                        <section class="lp-d-card"><div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('waiting.none') }}</b><span>{{ $t('waiting.none_body') }}</span></div></section>
                    @else
                        @php($kinds = $this->kinds())
                        @if (count($kinds) > 1)
                            <div class="lp-d-kinds" role="group" aria-label="{{ $t('kinds.label') }}">
                                <button type="button" class="lp-d-kind" wire:click="filter('all')" aria-pressed="{{ $this->kind === 'all' ? 'true' : 'false' }}"><span>{{ $t('kinds.all') }}</span></button>
                                @foreach ($kinds as $k => $count)
                                    <button type="button" class="lp-d-kind" wire:click="filter('{{ $k }}')" aria-pressed="{{ $this->kind === $k ? 'true' : 'false' }}"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons[$k] !!}</svg><span>{{ $t('kinds.'.$k) }}</span></button>
                                @endforeach
                            </div>
                        @endif

                        <div class="lp-d-fixes">
                            @foreach ($this->shown() as $fix)
                                @php($s = $fix->suggestion)
                                @php($dk = \App\Modules\Admin\Filament\Merchant\Pages\Improvements::draftKey($fix->key))
                                <article class="lp-d-fix" wire:key="imp-{{ $dk }}" data-fix="{{ $fix->key }}" data-kind="{{ $fix->kind }}">
                                    <div class="lp-d-fix-top">
                                        <span class="lp-d-fix-icon"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $icons[$fix->kind] ?? $icons['nothing'] !!}</svg></span>
                                        <div>
                                            <div class="lp-d-fix-tags">
                                                <span class="lp-d-chip lp-d-chip-{{ $chips[$fix->chip] ?? 'quiet' }}">{{ __('admin::today.chips.'.$fix->chip) }}</span>
                                                @if ($fix->oneTap)<span class="lp-d-chip lp-d-chip-quiet">{{ $t('one_tap') }}</span>@endif
                                            </div>
                                            @php($title = e($fix->title))
                                            @if ($fix->struck)@php($title = preg_replace('/'.preg_quote(e($fix->struck), '/').'/u', '<s dir="auto">'.e($fix->struck).'</s>', $title, 1))@endif
                                            <h2 class="lp-d-fix-title" dir="auto" title="{{ $fix->title }}">{!! $title !!}</h2>
                                            <p class="lp-d-fix-why">{{ $fix->text }}</p>
                                        </div>
                                        @if ($fix->count > 0)
                                            <div class="lp-d-fix-impact"><b class="lp-d-num">{{ number_format($fix->count) }}</b>{{ trans_choice('admin::improvements.units.'.$fix->unit, $fix->count) }}</div>
                                        @endif
                                    </div>

                                    @if (($s['type'] ?? null) === 'rule')
                                        <div class="lp-d-sugg">
                                            <span class="lp-d-sugg-label">{!! $mark !!}<span>{{ $t('sugg.ours') }}</span></span>
                                            <div class="lp-d-sugg-rule"><span class="lp-d-word" dir="auto">{{ $s['from'] }}</span>{!! $arrow !!}<span class="lp-d-word lp-d-word-to" dir="auto">{{ $s['to'] }}</span>@if (($s['products'] ?? []) !== [])<span class="lp-d-fix-why">{{ $t('sugg.they_see') }}</span>@endif</div>
                                            @if (($s['products'] ?? []) !== [])
                                                <div class="lp-d-preview">
                                                    @foreach ($s['products'] as $p)
                                                        <div class="lp-d-pv">@if ($p['picture'])<img src="{{ $p['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif<b dir="auto">{{ $p['title'] }}</b>@if ($p['price'])<span class="lp-d-num"><bdi>{{ $p['price'] }}</bdi></span>@endif</div>
                                                    @endforeach
                                                    @if (($s['more'] ?? 0) > 0)<div class="lp-d-pv lp-d-pv-more"><b>{{ $n('sugg.more', (int) $s['more']) }}</b></div>@endif
                                                </div>
                                            @endif
                                        </div>
                                    @elseif (($s['type'] ?? null) === 'words')
                                        <div class="lp-d-sugg">
                                            <span class="lp-d-sugg-label">{!! $mark !!}<span>{{ $t('sugg.as_one') }}</span></span>
                                            <div class="lp-d-sugg-rule">@foreach ($s['words'] as $i => $w)@if ($i > 0)<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 9h14M5 15h14"/></svg>@endif<span class="lp-d-word" dir="auto">{{ $w }}</span>@endforeach</div>
                                        </div>
                                    @elseif (($s['type'] ?? null) === 'answer')
                                        <div class="lp-d-sugg">
                                            <span class="lp-d-sugg-label">{!! $mark !!}<span>{{ $t('sugg.draft') }}</span></span>
                                            <textarea class="lp-d-answer" rows="3" dir="auto" wire:model="drafts.{{ $dk }}" aria-label="{{ $t('sugg.draft') }}" x-data x-init="if (! $el.value) { $el.value = @js($s['answer']); $el.dispatchEvent(new Event('input')) }">{{ $s['answer'] }}</textarea>
                                            <div class="lp-d-answer-src">{{ $t('sugg.where_shown') }}</div>
                                        </div>
                                    @elseif (($s['type'] ?? null) === 'facts')
                                        <div class="lp-d-sugg">
                                            <span class="lp-d-sugg-label">{!! $mark !!}<span>{{ $t('sugg.photos') }}</span></span>
                                            <div class="lp-d-facts">
                                                @foreach ($s['facts'] as $f)
                                                    <div class="lp-d-factrow">@if ($f['picture'])<img src="{{ $f['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif<span dir="auto">{{ $f['title'] }}<small>{{ $f['label'] }}</small></span>@if ($f['value'] !== '')<span class="lp-d-chip lp-d-chip-lime" dir="auto">{{ $f['value'] }}</span>@endif</div>
                                                @endforeach
                                                @if (($s['more'] ?? 0) > 0)<p class="lp-d-fix-why">{{ $n('sugg.more_products', (int) $s['more']) }}</p>@endif
                                            </div>
                                        </div>
                                    @endif

                                    <div class="lp-d-fix-do">
                                        @if ($fix->oneTap)
                                            <button type="button" class="lp-d-btn lp-d-btn-ink" wire:click="approve(@js($fix->key))" wire:loading.attr="disabled" wire:target="approve"><span>{{ $fix->action }}</span></button>
                                        @elseif ($fix->url)
                                            {{-- more than a tap: it opens in the side panel, the list stays behind it (as Aviad asked, 16.11) --}}
                                            <button type="button" class="lp-d-btn lp-d-btn-ink" wire:click="openPanel(@js($fix->key))" data-open-panel="{{ $fix->key }}"><span>{{ $fix->action }}</span></button>
                                        @endif
                                        @if ($fix->secondary)<a class="lp-d-btn lp-d-btn-line lp-d-btn-sm" href="{{ $fix->secondary['url'] }}">{{ $fix->secondary['label'] }}</a>@endif
                                        <span class="lp-d-spacer"></span>
                                        @if ($fix->typed !== [])
                                            <details class="lp-d-why-more"><summary><span>{{ $fix->kind === 'question' ? $t('typed.asked') : $t('typed.typed') }}</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></summary>
                                                <div class="lp-d-typed">@foreach ($fix->typed as $ty)<span dir="auto">{{ $ty['text'] }} <b class="lp-d-num">{{ number_format($ty['n']) }}</b></span>@endforeach</div>
                                            </details>
                                        @endif
                                        @if ($fix->kind === 'idea')
                                            <button type="button" class="lp-d-ghost" wire:click="later(@js($fix->key))">{{ __('admin::today.todo.later') }}</button>
                                        @else
                                            <button type="button" class="lp-d-ghost" wire:click="dismiss(@js($fix->key))">{{ $t('not_relevant') }}</button>
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                @elseif ($this->tab === 'auto')
                    <p class="lp-d-card-sub lp-d-tab-sub">{{ $t('auto.sub') }}</p>
                    @if ($auto['rows'] === [])
                        <section class="lp-d-card"><div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ $t('auto.none') }}</b><span>{{ $t('auto.none_body') }}</span></div></section>
                    @else
                        <div class="lp-d-rows">
                            @foreach ($auto['rows'] as $r)
                                <div class="lp-d-row" wire:key="auto-{{ $r['key'] }}">
                                    @if ($r['picture'])<img src="{{ $r['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif
                                    <span><span class="lp-d-row-what">@if ($r['kind'] === 'typo')<s dir="auto">{{ $r['from'] }}</s>{!! $arrow !!}@else<span dir="auto">{{ $r['from'] }}</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 9h14M5 15h14"/></svg>@endif<span dir="auto">{{ $r['to'] }}</span></span><span class="lp-d-row-when">{{ $t('auto.kinds.'.$r['kind']) }} · {{ $r['at'] }}</span></span>
                                    <span class="lp-d-row-since"><b class="lp-d-num">{{ number_format($r['found']) }}</b>{{ $t('auto.found_since') }}</span>
                                    <button type="button" class="lp-d-ghost" wire:click="undo(@js($r['key']))">{{ $t('auto.undo') }}</button>
                                </div>
                            @endforeach
                        </div>
                        @if ($auto['total'] > count($auto['rows']))<p class="lp-d-note">{{ $n('auto.earlier', $auto['total'] - count($auto['rows'])) }}</p>@endif
                    @endif
                @else
                    <p class="lp-d-card-sub lp-d-tab-sub">{{ $t('done.sub') }}</p>
                    @if ($approved === [])
                        <section class="lp-d-card"><div class="lp-d-zero"><span class="lp-d-zero-mark">{!! $mark !!}</span><b>{{ __('admin::today.proof.none') }}</b><span>{{ __('admin::today.proof.none_body') }}</span></div></section>
                    @else
                        <div class="lp-d-rows">
                            @foreach ($approved as $a)
                                <div class="lp-d-row">
                                    @if ($a['picture'])<img src="{{ $a['picture'] }}" alt="" loading="lazy" onerror="this.classList.add('is-missing')">@else<span class="lp-d-tile">{!! $mark !!}</span>@endif
                                    <span><span class="lp-d-row-what"><span dir="auto">{{ $a['searched'] }}</span>{!! $arrow !!}<span dir="auto">{{ $a['finds'] }}</span></span><span class="lp-d-row-when">{{ __('admin::today.proof.approved', ['date' => $a['approved']]) }}</span></span>
                                    <span class="lp-d-row-since">@if ($a['money'])<b class="lp-d-num"><bdi>{{ $a['money'] }}</bdi></b>@endif{{ $n('done.found', $a['found']) }} · {{ $n('done.adds', $a['adds']) }}</span>
                                    <span></span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>

            <aside class="lp-d-imp-aside">
                <section class="lp-d-goal" aria-labelledby="goal-title">
                    <h2 class="lp-d-card-title" id="goal-title">{{ $t('goal.title') }}</h2>
                    @php($pct = $goal['pct'] ?? 0)
                    <div class="lp-d-ring">
                        <svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="50" fill="none" stroke="var(--lp-d-card)" stroke-width="12"/><circle cx="60" cy="60" r="50" fill="none" stroke="var(--lp-d-ink)" stroke-width="12" stroke-linecap="round" stroke-dasharray="314.16" stroke-dashoffset="{{ round(314.16 * (1 - $pct / 100), 2) }}" transform="rotate(-90 60 60)"/></svg>
                        <div class="lp-d-ring-n"><b class="lp-d-num">{{ $goal['pct'] === null ? '—' : $goal['pct'].'%' }}</b><span>{{ $t('goal.goal', ['pct' => \App\Modules\Admin\Filament\Merchant\Pages\Improvements::GOAL]) }}</span></div>
                    </div>
                    @if ($goal['pct'] !== null && $goal['waiting'] > 0 && $goal['after'] > $goal['pct'])
                        <p>{!! trans_choice('admin::improvements.goal.after', $goal['waiting'], ['n' => '<b>'.$goal['waiting'].'</b>', 'pct' => '<b>'.$goal['after'].'%</b>']) !!}</p>
                    @elseif ($goal['pct'] === null)
                        <p>{{ $t('goal.none') }}</p>
                    @endif
                </section>
                <section class="lp-d-tip">
                    <b>{!! $mark !!}<span>{{ $t('tip.title') }}</span></b>
                    <span>{{ $t('tip.body') }}</span>
                </section>
            </aside>
        </div>

        {{-- The side panel a card opens in: all of it, and what to do there, the list behind it as it was --}}
        @if ($open = $this->panelFix())
            @php($s = $open->suggestion)
            @php($dk = \App\Modules\Admin\Filament\Merchant\Pages\Improvements::draftKey($open->key))
            @php($writes = $open->kind === 'question' && in_array($s['type'] ?? 'write', ['write', 'answer'], true))
            <div class="lp-imp-panel" wire:key="panel-{{ $dk }}" data-panel="{{ $open->key }}" x-data x-on:keydown.escape.window="$wire.closePanel()">
                <button type="button" class="lp-imp-panel-scrim" wire:click="closePanel" aria-label="{{ $t('panel.close') }}" tabindex="-1"></button>
                <aside class="lp-imp-panel-box" role="dialog" aria-modal="true" aria-labelledby="lp-imp-panel-title" x-init="$nextTick(() => $el.focus())" tabindex="-1">
                    <header class="lp-imp-panel-head">
                        <span class="lp-d-chip lp-d-chip-{{ $chips[$open->chip] ?? 'quiet' }}">{{ __('admin::today.chips.'.$open->chip) }}</span>
                        <button type="button" class="lp-imp-panel-x" wire:click="closePanel" aria-label="{{ $t('panel.close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
                    </header>
                    <div class="lp-imp-panel-body">
                        <h2 class="lp-imp-panel-title" id="lp-imp-panel-title" dir="auto">{{ $open->title }}</h2>
                        @if ($open->count > 0)
                            <div class="lp-imp-panel-count"><b class="lp-d-num">{{ number_format($open->count) }}</b> {{ trans_choice('admin::improvements.units.'.$open->unit, $open->count) }}</div>
                        @endif
                        <section class="lp-imp-panel-sec">
                            <h3>{{ $t('panel.why') }}</h3>
                            <p>{{ $open->text }}</p>
                        </section>
                        @if ($open->pictures !== [])
                            <div class="lp-imp-panel-pics">@foreach ($open->pictures as $pic)<img src="{{ $pic }}" alt="" loading="lazy" onerror="this.remove()">@endforeach</div>
                        @endif
                        @if ($open->typed !== [])
                            <section class="lp-imp-panel-sec">
                                <h3>{{ $open->kind === 'question' ? $t('typed.asked') : $t('typed.typed') }}</h3>
                                <div class="lp-d-typed">@foreach ($open->typed as $ty)<span dir="auto">{{ $ty['text'] }} <b class="lp-d-num">{{ number_format($ty['n']) }}</b></span>@endforeach</div>
                            </section>
                        @endif

                        @if ($writes)
                            {{-- the answer written here, published as written: every next shopper who asks gets it --}}
                            <section class="lp-imp-panel-sec" x-data="{ text: $wire.entangle('drafts.{{ $dk }}') }">
                                <h3>{{ $t('panel.answer') }}</h3>
                                <textarea class="lp-d-answer" rows="6" dir="auto" x-model="text" aria-label="{{ $t('panel.answer') }}"></textarea>
                                <p class="lp-imp-panel-hint">{{ $t('panel.answer_hint') }}</p>
                                <div class="lp-imp-panel-do">
                                    <button type="button" class="lp-d-btn lp-d-btn-ink" wire:click="approve(@js($open->key))" wire:loading.attr="disabled" wire:target="approve" x-bind:disabled="! (text || '').trim()"><span>{{ $t('panel.publish') }}</span></button>
                                </div>
                            </section>
                        @elseif (($s['type'] ?? null) === 'idea' && ($s['write'] ?? '') !== '')
                            <div class="lp-imp-panel-do">
                                <button type="button" class="lp-d-btn lp-d-btn-ink" wire:click="approve(@js($open->key))" wire:loading.attr="disabled" wire:target="approve"><span>{{ $s['write'] }}</span></button>
                            </div>
                        @endif
                    </div>
                    <footer class="lp-imp-panel-foot">
                        @if ($open->url)<a class="lp-d-btn lp-d-btn-line lp-d-btn-sm" href="{{ $open->url }}">{{ $t('panel.full') }}</a>@endif
                        <span class="lp-d-spacer"></span>
                        @if ($open->kind === 'idea')
                            <button type="button" class="lp-d-ghost" wire:click="later(@js($open->key))">{{ __('admin::today.todo.later') }}</button>
                        @else
                            <button type="button" class="lp-d-ghost" wire:click="dismiss(@js($open->key))">{{ $t('not_relevant') }}</button>
                        @endif
                    </footer>
                </aside>
            </div>
        @endif
    </div>

    {{-- the side panel's own look, here with it (the merchant stylesheet stays as it is) --}}
    <style>
        .fi-panel-merchant .lp-imp-panel { position: fixed; inset: 0; z-index: 60; }
        .fi-panel-merchant .lp-imp-panel-scrim { position: absolute; inset: 0; width: 100%; border: 0; background: rgb(11 11 12 / .32); animation: lp-imp-fade .24s ease both; cursor: default; }
        .fi-panel-merchant .lp-imp-panel-box { position: absolute; inset-block: 0; inset-inline-end: 0; width: min(520px, 100%); display: flex; flex-direction: column; background: var(--lp-d-ground, #f6f6f4); color: var(--lp-d-ink, #111); box-shadow: -18px 0 48px -16px rgb(0 0 0 / .28); outline: none; animation: lp-imp-in-ltr .34s var(--lp-d-ease, cubic-bezier(.32,.72,0,1)) both; }
        [dir="rtl"] .fi-panel-merchant .lp-imp-panel-box, .fi-panel-merchant[dir="rtl"] .lp-imp-panel-box { animation-name: lp-imp-in-rtl; box-shadow: 18px 0 48px -16px rgb(0 0 0 / .28); }
        .fi-panel-merchant .lp-imp-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px 8px; }
        .fi-panel-merchant .lp-imp-panel-x { width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; background: var(--lp-d-card, #fff); border: 0; cursor: pointer; }
        .fi-panel-merchant .lp-imp-panel-x svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; }
        .fi-panel-merchant .lp-imp-panel-body { flex: 1; min-height: 0; overflow: auto; padding: 6px 22px 22px; display: grid; gap: 18px; align-content: start; }
        .fi-panel-merchant .lp-imp-panel-title { font-size: 24px; line-height: 1.3; font-weight: 600; margin: 0; }
        .fi-panel-merchant .lp-imp-panel-count { font-size: 14px; color: var(--lp-d-muted, #666); }
        .fi-panel-merchant .lp-imp-panel-count b { font-size: 22px; color: var(--lp-d-ink, #111); margin-inline-end: 4px; }
        .fi-panel-merchant .lp-imp-panel-sec { display: grid; gap: 8px; background: var(--lp-d-card, #fff); border-radius: 18px; padding: 16px 18px; }
        .fi-panel-merchant .lp-imp-panel-sec h3 { margin: 0; font-size: 13px; font-weight: 600; color: var(--lp-d-muted, #666); }
        .fi-panel-merchant .lp-imp-panel-sec p { margin: 0; font-size: 15px; line-height: 1.6; }
        .fi-panel-merchant .lp-imp-panel-hint { font-size: 13px !important; color: var(--lp-d-muted, #666); }
        .fi-panel-merchant .lp-imp-panel-pics { display: flex; gap: 8px; flex-wrap: wrap; }
        .fi-panel-merchant .lp-imp-panel-pics img { width: 72px; height: 72px; object-fit: cover; border-radius: 12px; background: var(--lp-d-card, #fff); }
        .fi-panel-merchant .lp-imp-panel-do { display: flex; gap: 10px; flex-wrap: wrap; }
        .fi-panel-merchant .lp-imp-panel-do .lp-d-btn[disabled] { opacity: .45; cursor: default; }
        .fi-panel-merchant .lp-imp-panel-foot { display: flex; align-items: center; gap: 10px; padding: 14px 22px calc(14px + env(safe-area-inset-bottom)); border-top: 1px solid var(--lp-d-line, #e5e5e5); }
        @keyframes lp-imp-fade { from { opacity: 0; } }
        @keyframes lp-imp-in-ltr { from { transform: translateX(104%); } }
        @keyframes lp-imp-in-rtl { from { transform: translateX(-104%); } }
        @media (prefers-reduced-motion: reduce) { .fi-panel-merchant .lp-imp-panel-box, .fi-panel-merchant .lp-imp-panel-scrim { animation: none; } }
    </style>
</x-filament-panels::page>
