{{-- The sessions screen's heading row, as the design draws it: the title and what it is, the period beside it. --}}
<header class="lp-head" data-sessions-head>
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $subheading }}</p>
    </div>
    @include('admin::merchant.period')
</header>
