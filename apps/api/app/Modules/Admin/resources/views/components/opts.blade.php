{{-- Option cards, one of them: a title, a line, and a small sketch of how it looks (mini: lets, site, cover, frame, or none). --}}
@props(['model', 'options' => [], 'columns' => 2])
<div {{ $attributes->class('lp-opts') }} style="--lp-opts: {{ $columns }}" x-data="{ v: $wire.entangle(@js($model)) }" role="radiogroup">
    @foreach ($options as $value => $option)
        @php($value = (string) $value)
        <button type="button" role="radio" class="lp-opt" x-bind:class="{ 'is-on': String(v) === @js($value) }" x-bind:aria-checked="String(v) === @js($value) ? 'true' : 'false'" x-on:click="v = @js($value)">
            <b>{{ $option['title'] }}</b>
            @if (! empty($option['text']))<span>{{ $option['text'] }}</span>@endif
            @if (! empty($option['mini']))
                <span class="lp-mini" data-mini="{{ $option['mini'] }}" aria-hidden="true"><span class="lp-mini-pic"></span><span class="lp-mini-ln"><i></i><i></i></span><span class="lp-mini-b"></span></span>
            @endif
        </button>
    @endforeach
</div>
