<x-filament-panels::page>
    {{-- The weekly email, drawn with the store's real week (WeeklyEmailPreview), in a frame so its styles stay its own. --}}
    <div class="lp-page" data-weekly-preview>
        <iframe class="lp-d-mail-frame" title="{{ __('admin::weekly.preview.title') }}" srcdoc="{{ $this->html() }}" sandbox=""></iframe>
    </div>
</x-filament-panels::page>
