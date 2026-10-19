{{-- A choice of chips: one of them, or (multiple) any of them, each chosen one with a check. --}}
@props(['model', 'options' => [], 'multiple' => false, 'sm' => false, 'label' => null])
<div {{ $attributes->class('lp-chips lp-set-chips') }} x-data="{ v: $wire.entangle(@js($model)) }" role="{{ $multiple ? 'group' : 'radiogroup' }}" @if ($label) aria-label="{{ $label }}" @endif>
    @foreach ($options as $value => $text)
        @php($value = (string) $value)
        @if ($multiple)
            <button type="button" @class(['lp-chip', 'lp-chip-sm' => $sm]) x-bind:class="{ 'is-on': (v || []).includes(@js($value)) }" x-bind:aria-pressed="(v || []).includes(@js($value)) ? 'true' : 'false'"
                x-on:click="v = (v || []).includes(@js($value)) ? (v || []).filter((x) => x !== @js($value)) : [...(v || []), @js($value)]">
                <svg class="lp-chip-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>{{ $text }}
            </button>
        @else
            <button type="button" role="radio" @class(['lp-chip', 'lp-chip-sm' => $sm]) x-bind:class="{ 'is-on': String(v) === @js($value) }" x-bind:aria-checked="String(v) === @js($value) ? 'true' : 'false'" x-on:click="v = @js($value)">{{ $text }}</button>
        @endif
    @endforeach
</div>
