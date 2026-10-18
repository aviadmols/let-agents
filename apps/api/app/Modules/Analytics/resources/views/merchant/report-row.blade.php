{{-- One row of an analytics list (ReportRow): a rank or a picture, what it is, the line under it, a pill, its number, its button. --}}
<div class="lp-row" data-row="{{ $list }}" @if (in_array('synonym', $r->actions, true)) x-data="{ asking: false, means: '' }" @endif>
    @if ($rank !== null)<span class="lp-rank">{{ $rank }}</span>@endif
    @if ($r->thumb !== null || in_array($list, ['adds', 'wish'], true))
        <span class="lp-thumb" aria-hidden="true">@if ($r->thumb)<img src="{{ $r->thumb }}" alt="" loading="lazy">@else<svg><use href="#lp-i-doc"/></svg>@endif</span>
    @endif
    <div class="lp-grow">
        <span class="lp-row-t" @if ($r->ltr) dir="ltr" style="text-align: end" @endif>{{ $r->title }}</span>
        @if ($r->sub !== '' || ($r->pill !== null && $list !== 'questions'))
            <span class="lp-row-s">{{ $r->sub }}@if ($r->pill !== null && $list !== 'questions' && ! in_array('block', $r->actions, true) && ! in_array('unblock', $r->actions, true))@if ($r->sub !== '') · @endif<span class="lp-pill" data-tone="{{ $r->tone }}">{{ $r->pill }}</span>@endif</span>
        @endif
        @if ($list === 'questions' && $r->pill !== null)
            <span class="lp-row-s"><span class="lp-pill" data-tone="{{ $r->tone }}"><i></i>{{ $r->pill }}</span></span>
        @endif
        @if (in_array('synonym', $r->actions, true))
            <form class="lp-inline" x-show="asking" x-cloak x-on:submit.prevent="$wire.act(@js($list), @js($r->key), 'synonym', means); asking = false; means = ''">
                <input class="lp-input lp-input-sm" x-model="means" x-ref="means" placeholder="{{ $t('synonym_placeholder', ['term' => $r->title]) }}" aria-label="{{ $t('synonym_label') }}">
                <button type="submit" class="lp-btn lp-btn-sm lp-btn-primary">{{ $t('save') }}</button>
                <button type="button" class="lp-link" x-on:click="asking = false">{{ $t('cancel') }}</button>
            </form>
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
    @elseif ($list !== 'ips')
        <span class="lp-v">{{ number_format($r->value) }}</span>
    @endif
</div>
