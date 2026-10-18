{{-- The scan summary's heading row, as the design draws it: the title, the last scan, products or pages and posts. --}}
@php($n = $this->numbers())
@php($last = $this->lastScan())
<header class="lp-head" data-scan-head>
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $subheading }}</p>
    </div>
    <div class="lp-head-end">
        @if ($last)<span class="lp-pill" data-tone="good"><i></i>{{ __('enrichment::scan.last', ['when' => $last]) }}</span>@endif
        <button type="button" class="lp-btn lp-btn-sm" wire:click="scanChanges" wire:loading.attr="disabled" wire:target="scanChanges" @disabled(! $this->canScanChanges())>{{ __('enrichment::scan.scan_changes') }}</button>
        <div class="lp-tabs" role="tablist">
            <button type="button" role="tab" wire:click="show('products')" @class(['is-on' => $this->tab === 'products'])>{{ __('enrichment::scan.tabs.products') }} <span class="lp-count">{{ number_format($n['total']) }}</span></button>
            <button type="button" role="tab" wire:click="show('pages')" @class(['is-on' => $this->tab === 'pages'])>{{ __('enrichment::scan.tabs.pages') }} <span class="lp-count">{{ number_format($n['pages'] + $n['posts']) }}</span></button>
        </div>
    </div>
</header>
