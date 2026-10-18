{{-- The home's heading row, as the designer's panel draws it: the greeting, and the period beside it. --}}
@php($t = fn (string $key, array $params = []) => __('admin::home.'.$key, $params))
<header class="lp-head" data-home-head>
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $subheading }}</p>
    </div>
    @include('admin::merchant.period')
</header>
