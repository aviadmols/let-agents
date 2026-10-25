<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Core\Localization\Locales::direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The plans a store chooses from. Shopify's approval screen must open in the full window, so each choice leaves the admin's frame. --}}
    <meta name="shopify-api-key" content="{{ $key }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>{{ __('shopify::app.plans_title') }}</title>
    <style>
        :root { --ink: #111; --muted: #5f6368; --line: #e4e4e7; --bg: #f7f7f8; --card: #fff; }
        @media (prefers-color-scheme: dark) { :root { --ink: #f4f4f5; --muted: #a1a1aa; --line: #2e2e33; --bg: #111113; --card: #1b1b1f; } }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.55 system-ui, Heebo, sans-serif; background: var(--bg); color: var(--ink); }
        main { max-width: 1080px; margin: 0 auto; padding: 32px 16px 48px; }
        h1 { margin: 0 0 4px; font-size: 24px; }
        .sub { margin: 0 0 24px; color: var(--muted); }
        .trial { display: inline-block; margin: -12px 0 24px; padding: 6px 14px; border: 1px solid var(--line); border-radius: 999px; background: var(--card); font-weight: 600; font-size: 14px; }
        .plans { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .plan { display: flex; flex-direction: column; padding: 20px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; }
        .plan.is-current { border-color: var(--ink); }
        .name { margin: 0; font-size: 18px; font-weight: 700; }
        .price { margin: 4px 0 14px; font-size: 26px; font-weight: 700; }
        .price small { font-size: 14px; font-weight: 400; color: var(--muted); }
        ul { margin: 0 0 18px; padding: 0; list-style: none; flex: 1; }
        li { padding: 4px 0; border-top: 1px solid var(--line); font-size: 14px; }
        li:first-child { border-top: 0; }
        .go { display: block; padding: 10px 14px; border-radius: 999px; background: var(--ink); color: var(--bg); text-align: center; text-decoration: none; font-weight: 600; }
        .here { display: block; padding: 10px 14px; border-radius: 999px; border: 1px solid var(--line); text-align: center; color: var(--muted); }
        .note { margin: 20px 0 0; color: var(--muted); font-size: 14px; }
        .fit { margin: 0 0 24px; padding: 14px 16px; border: 1px solid var(--line); border-radius: 12px; background: var(--card); font-size: 14px; }
        .fit p { margin: 0; }
        .fit p + p { margin-top: 4px; }
        .plan.is-fit { border: 2px solid var(--ink); }
        .badge { align-self: flex-start; margin: 0 0 8px; padding: 2px 10px; border-radius: 999px; background: var(--ink); color: var(--bg); font-size: 12px; font-weight: 600; }
        .short { margin: 0 0 12px; padding: 8px 10px; border-radius: 8px; background: var(--bg); color: var(--muted); font-size: 13px; }
        .short span { display: block; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('shopify::app.plans_title') }}</h1>
    <p class="sub">{{ __('shopify::app.plans_sub') }}</p>
    @if ($trial ?? null)
        <p class="trial" data-trial>{{ $trial }}</p>
    @endif
    @php($size = $store ?? [])
    @if (($size['products'] ?? null) !== null)
        {{-- What the store is, and the plan that fits it (PlanFit): the same reading for every store. --}}
        <div class="fit" data-fit>
            <p>{{ ($size['pages'] ?? null) !== null
                ? __('shopify::app.plans_store', ['products' => number_format($size['products']), 'pages' => number_format($size['pages'])])
                : __('shopify::app.plans_store_products', ['products' => number_format($size['products'])]) }}</p>
            @if (($size['answers'] ?? 0) > 0 || ($size['photos'] ?? 0) > 0)
                <p>{{ __('shopify::app.plans_used', ['answers' => number_format($size['answers'] ?? 0), 'photos' => number_format($size['photos'] ?? 0)]) }}</p>
            @endif
            <p><strong>{{ ($fits ?? null) !== null ? __('shopify::app.plans_fits', ['plan' => $fits]) : __('shopify::app.plans_none_fits') }}</strong></p>
        </div>
    @endif
    <div class="plans">
        @foreach ($plans as $row)
            @php($plan = $row['plan'])
            <section class="plan {{ $row['current'] ? 'is-current' : '' }} {{ ($row['fits'] ?? false) ? 'is-fit' : '' }}">
                @if ($row['fits'] ?? false)
                    <span class="badge" data-fits>{{ __('shopify::app.plans_fit_badge') }}</span>
                @endif
                <p class="name">{{ $plan->name }}</p>
                <p class="price"><span dir="ltr">${{ number_format((float) $plan->price()) }}</span> <small>{{ __('shopify::app.plans_month') }}</small></p>
                <ul>
                    @foreach ($row['lines'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
                @if (($row['short'] ?? []) !== [])
                    <p class="short" data-short>
                        @foreach ($row['short'] as $over)
                            <span>{{ __('shopify::app.plans_short_'.($over['what'] === 'photos' && $over['limit'] === 0 ? 'no_photos' : $over['what']), ['limit' => number_format($over['limit']), 'has' => number_format($over['has'])]) }}</span>
                        @endforeach
                    </p>
                @endif
                @if ($row['current'])
                    <span class="here">{{ __('shopify::app.plans_current') }}</span>
                @else
                    <a class="go" href="{{ $row['url'] }}" target="_top" onclick="event.preventDefault(); window.open(this.href, '_top');">{{ __('shopify::app.plans_choose', ['plan' => $plan->name]) }}</a>
                @endif
            </section>
        @endforeach
    </div>
    <p class="note">{{ __('shopify::app.plans_note') }}</p>
</main>
</body>
</html>
