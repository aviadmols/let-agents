{{-- One label and its value; what was wrong with it at the last save under it. --}}
@props(['label', 'tip' => null, 'error' => null])
<dt>{{ $label }}@if ($tip)<span class="lp-tip" tabindex="0" data-tip="{{ $tip }}" aria-label="{{ $tip }}">i</span>@endif</dt>
<dd>{{ $slot }}@if ($error)<span class="lp-set-error" role="alert">{{ $error }}</span>@endif</dd>
