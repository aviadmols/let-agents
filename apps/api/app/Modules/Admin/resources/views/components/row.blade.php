{{-- A row: what it is, the line under it, and its control at the end. --}}
@props(['title', 'sub' => null, 'tip' => null])
<div {{ $attributes->class('lp-set-row') }}>
    <div class="lp-grow">
        <div class="lp-set-t">{{ $title }}@if ($tip)<span class="lp-tip" tabindex="0" data-tip="{{ $tip }}" aria-label="{{ $tip }}">i</span>@endif</div>
        @if ($sub !== null || isset($below))<div class="lp-set-s">{{ $sub }}{{ $below ?? '' }}</div>@endif
    </div>
    <div class="lp-set-control">{{ $slot }}</div>
</div>
