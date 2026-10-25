{{-- A switch bound to a form field ($wire, saved with the page). Off and out of reach when the shop's plan holds it ($wire.held, EditsShopSettings). --}}
@props(['model', 'label' => null])
@php($field = \Illuminate\Support\Str::after($model, 'data.'))
<button type="button" role="switch" class="lp-toggle" x-data="{ on: $wire.entangle(@js($model)), held: ($wire.held || []).includes(@js($field)) }" x-bind:class="{ 'is-on': on }" x-bind:aria-checked="on ? 'true' : 'false'" x-bind:disabled="held" x-bind:title="held ? @js(__('admin::shop_settings.held')) : null" x-on:click="if (! held) on = ! on" @if ($label) aria-label="{{ $label }}" @endif {{ $attributes }}></button>
