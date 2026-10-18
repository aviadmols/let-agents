{{-- The analytics heading row, as the design draws it: a way back from a whole list, the title, the period beside it. --}}
<header class="lp-head" data-analytics-head>
    <div>
        @if ($back)
            <button type="button" class="lp-back" wire:click="open('')"><svg aria-hidden="true"><use href="#lp-i-down"/></svg>{{ __('analytics::report.title') }}</button>
        @endif
        <h1>{{ $title }}</h1>
        <p>{{ $subheading }}</p>
    </div>
    @include('admin::merchant.period')
</header>
