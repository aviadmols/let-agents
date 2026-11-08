{{-- A redesigned screen's heading row (11.2026): its name, one sentence, and the period when the screen counts over one, or a
     short note on the side (aside: "Saves automatically"). --}}
<header class="lp-d-top" data-screen-head>
    <div>
        <h1 class="lp-d-hello">{{ $title }}</h1>
        @if (! empty($lede))<p class="lp-d-lede">{{ $lede }}</p>@endif
    </div>
    @if (method_exists($this, 'offeredPeriods'))
        <div class="lp-d-top-tools">@include('admin::merchant.period')</div>
    @elseif (! empty($aside))
        <div class="lp-d-top-tools"><span class="lp-d-saved-note"><i></i>{{ $aside }}</span></div>
    @endif
</header>
