{{-- The one save of a settings page, and what it means for the shoppers. --}}
@props(['label' => null, 'note' => null, 'action' => 'save'])
<div class="lp-set-save">
    <button type="button" class="lp-btn lp-btn-primary" wire:click="{{ $action }}" wire:loading.attr="disabled" wire:target="{{ $action }}">{{ $label ?? __('admin::shop_settings.save') }}</button>
    @if ($note !== false)<span class="lp-set-note">{{ $note ?? __('admin::shop_settings.save_note') }}</span>@endif
</div>
