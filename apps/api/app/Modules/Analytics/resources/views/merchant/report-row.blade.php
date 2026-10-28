{{-- One row of an analytics list (ReportRow): a rank or a picture, what it is (a searched word opens its sessions),
     the line under it, a pill, the answer a question got, its number, its buttons. --}}
@php($link ??= null)
@php($full ??= false)
{{-- a question's answer and what can be done with it: in its whole list only --}}
@php($answers = $full && in_array('answer', $r->actions, true))
@php($inline = in_array('synonym', $r->actions, true) || $answers)
<div class="lp-row" data-row="{{ $list }}" @if ($inline) x-data="{ asking: false, means: '' }" @endif>
    @if ($rank !== null)<span class="lp-rank">{{ $rank }}</span>@endif
    @if ($r->thumb !== null || in_array($list, ['adds', 'wish'], true))
        <span class="lp-thumb" aria-hidden="true">@if ($r->thumb)<img src="{{ $r->thumb }}" alt="" loading="lazy">@else<svg><use href="#lp-i-doc"/></svg>@endif</span>
    @endif
    <div class="lp-grow">
        @if ($link !== null)
            <a class="lp-row-t lp-row-link" href="{{ $link }}" title="{{ $t('sessions_of', ['term' => $r->title]) }}" @if ($r->ltr) dir="ltr" style="text-align: end" @endif>{{ $r->title }}</a>
        @else
            <span class="lp-row-t" @if ($r->ltr) dir="ltr" style="text-align: end" @endif>{{ $r->title }}</span>
        @endif
        @php($asks = in_array($list, ['questions', 'asked'], true))
        @if ($r->sub !== '' || ($r->pill !== null && ! $asks))
            <span class="lp-row-s">{{ $r->sub }}@if ($r->pill !== null && ! $asks && ! in_array('block', $r->actions, true) && ! in_array('unblock', $r->actions, true))@if ($r->sub !== '') · @endif<span class="lp-pill" data-tone="{{ $r->tone }}">{{ $r->pill }}</span>@endif</span>
        @endif
        @if ($asks && $r->pill !== null)
            <span class="lp-row-s"><span class="lp-pill" data-tone="{{ $r->tone }}"><i></i>{{ $r->pill }}</span></span>
        @endif
        @if ($full && $r->detail !== '')
            <div class="lp-ask-answer">@if ($r->detailLabel !== '')<span class="lp-note">{{ $r->detailLabel }}</span>@endif<p>{{ $r->detail }}</p></div>
        @endif
        {{-- what came with it: the products the shopper was shown, those they opened or added marked --}}
        @if ($full && $r->items !== [])
            <div style="display: grid; gap: 6px; margin-top: 8px" data-row-items>
                <span class="lp-note">{{ $t('items_shown') }}</span>
                <div class="lp-chips">
                    @foreach ($r->items as $item)
                        <span class="lp-chip" style="cursor: default; min-height: 30px; font-size: 13px; {{ isset($item['note']) ? 'box-shadow: inset 0 0 0 1.5px var(--la-ink);' : '' }}">{{ $item['title'] }}@isset($item['note'])<b style="font-weight: 600">· {{ $item['note'] }}</b>@endisset</span>
                    @endforeach
                </div>
            </div>
        @endif
        @if (in_array('synonym', $r->actions, true))
            <form class="lp-inline" x-show="asking" x-cloak x-on:submit.prevent="$wire.act(@js($list), @js($r->key), 'synonym', means); asking = false; means = ''">
                <input class="lp-input lp-input-sm" x-model="means" x-ref="means" placeholder="{{ $t('synonym_placeholder', ['term' => $r->title]) }}" aria-label="{{ $t('synonym_label') }}">
                <button type="submit" class="lp-btn lp-btn-sm lp-btn-primary">{{ $t('save') }}</button>
                <button type="button" class="lp-link" x-on:click="asking = false">{{ $t('cancel') }}</button>
            </form>
        @elseif ($answers)
            {{-- the team's own answer: every next shopper gets it, and the question leaves the list --}}
            <form class="lp-stack" style="margin-top: 8px" x-show="asking" x-cloak x-on:submit.prevent="if (means.trim().length > 1) { $wire.act(@js($list), @js($r->key), 'answer', means); asking = false; means = '' }">
                <textarea class="lp-textarea" rows="3" maxlength="1200" x-model="means" x-ref="means" placeholder="{{ $t('answer_placeholder') }}" aria-label="{{ $t('answer_label', ['question' => $r->title]) }}"></textarea>
                <div class="lp-actions">
                    <button type="submit" class="lp-btn lp-btn-sm lp-btn-primary">{{ $t('answer_publish') }}</button>
                    <button type="button" class="lp-link" x-on:click="asking = false">{{ $t('cancel') }}</button>
                </div>
            </form>
            <div class="lp-actions" style="margin-top: 8px" x-show="! asking">
                <button type="button" class="lp-btn lp-btn-sm" x-on:click="asking = true; $nextTick(() => $refs.means.focus())">{{ $r->detail !== '' && $r->tone === 'good' ? $t('answer_instead') : $t('answer') }}</button>
                @if (in_array('aside', $r->actions, true))
                    <button type="button" class="lp-link" wire:click="act(@js($list), @js($r->key), 'aside')" wire:confirm="{{ $t('aside_confirm') }}">{{ $t('aside') }}</button>
                @endif
            </div>
        @endif
    </div>
    @if ((in_array('block', $r->actions, true) || in_array('unblock', $r->actions, true)) && $r->pill !== null)
        <span class="lp-pill" data-tone="{{ $r->tone }}"><i></i>{{ $r->pill }}</span>
    @endif
    @if (in_array('synonym', $r->actions, true))
        <button type="button" class="lp-btn lp-btn-sm" x-show="! asking" x-on:click="asking = true; $nextTick(() => $refs.means.focus())">{{ $t('synonym') }}</button>
    @elseif (in_array('block', $r->actions, true))
        <button type="button" class="lp-btn lp-btn-sm" wire:click="act(@js($list), @js($r->key), 'block')" wire:confirm="{{ $t('block_confirm', ['ip' => $r->title]) }}">{{ $t('block') }}</button>
    @elseif (in_array('unblock', $r->actions, true))
        <button type="button" class="lp-link" wire:click="act(@js($list), @js($r->key), 'unblock')">{{ $t('unblock') }}</button>
    @elseif (! in_array($list, ['ips', 'asked'], true))
        <span class="lp-v">{{ number_format($r->value) }}</span>
    @endif
</div>
