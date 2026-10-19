{{-- A number with − and +, within its bounds. --}}
@props(['model', 'min' => 0, 'max' => 9999, 'step' => 1, 'label' => null])
<span {{ $attributes->class('lp-num') }} x-data="{ v: $wire.entangle(@js($model)) }" role="group" @if ($label) aria-label="{{ $label }}" @endif>
    <button type="button" x-on:click="v = Math.max({{ $min }}, (+v || 0) - {{ $step }})" aria-label="−">−</button>
    <span x-text="v" dir="ltr"></span>
    <button type="button" x-on:click="v = Math.min({{ $max }}, (+v || 0) + {{ $step }})" aria-label="+">+</button>
</span>
