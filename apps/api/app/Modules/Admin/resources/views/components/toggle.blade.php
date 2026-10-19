{{-- A switch bound to a form field ($wire, saved with the page). --}}
@props(['model', 'label' => null])
<button type="button" role="switch" class="lp-toggle" x-data="{ on: $wire.entangle(@js($model)) }" x-bind:class="{ 'is-on': on }" x-bind:aria-checked="on ? 'true' : 'false'" x-on:click="on = ! on" @if ($label) aria-label="{{ $label }}" @endif {{ $attributes }}></button>
