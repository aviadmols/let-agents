{{-- A text field bound to the form. --}}
@props(['model', 'ltr' => false, 'placeholder' => null, 'width' => null, 'type' => 'text'])
<div class="lp-set-field" @if ($width) style="max-width: {{ $width }}px" @endif>
    <input type="{{ $type }}" wire:model="{{ $model }}" @if ($ltr) dir="ltr" @endif @if ($placeholder) placeholder="{{ $placeholder }}" @endif {{ $attributes }}>
</div>
