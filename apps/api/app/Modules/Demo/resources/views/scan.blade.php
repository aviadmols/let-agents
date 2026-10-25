{{-- The demo store's scan as a real store's first run shows it (the Admin module's onboarding, step 3): the products,
     their pictures, the pages, the tags and the knowledge as they grow, and what was learned. Polls DemoController::progress. --}}
@extends('demo::layout')
@php($t = fn (string $key, array $params = []) => __('demo::page.'.$key, $params))
@section('title', $t('scan.title_short'))
@push('head')
    <style>
        .scan-head { display: flex; align-items: center; gap: 16px; }
        .sand { width: 52px; height: 52px; border-radius: 50%; background: var(--ink); color: var(--lime); display: grid; place-items: center; flex: none; }
        .mark { --s: 22px; width: var(--s); height: var(--s); display: inline-grid; grid-template-rows: 1fr 1fr; }
        .mark i { display: block; background: currentColor; }
        .mark i:first-child { border-radius: 0 0 50% 50% / 0 0 100% 100%; transform-origin: 50% 100%; }
        .mark i:last-child { border-radius: 50% 50% 0 0 / 100% 100% 0 0; transform-origin: 50% 0%; }
        .mark.is-think { animation: hourglass 1.8s cubic-bezier(.77, 0, .175, 1) infinite; }
        .mark.is-think i { animation: sand 1.8s cubic-bezier(.77, 0, .175, 1) infinite; }
        @keyframes hourglass { 0%, 30% { transform: rotate(0deg); } 70%, 100% { transform: rotate(180deg); } }
        @keyframes sand { 0%, 30% { transform: scaleX(1); } 50% { transform: scaleX(.62); } 70%, 100% { transform: scaleX(1); } }
        .rows { display: flex; flex-direction: column; gap: 10px; margin-top: 24px; }
        .row { display: grid; grid-template-columns: 44px minmax(0, 1fr) 110px; gap: 14px; align-items: center; padding: 12px 14px; border-radius: 18px; background: var(--ground); transition: opacity .24s; }
        .row.is-wait { opacity: .5; }
        .ic { width: 44px; height: 44px; border-radius: 12px; background: #fff; display: grid; place-items: center; }
        .ic svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .row.is-done .ic { background: var(--ink); color: var(--lime); }
        .row.is-running .ic { box-shadow: inset 0 0 0 2px var(--ink); }
        .rt { font-weight: 500; }
        .rs { font-size: 13px; color: var(--muted); }
        .rn { font-weight: 600; font-variant-numeric: tabular-nums; text-align: end; }
        .rn .rs { font-weight: 400; }
        @media (max-width: 520px) { .row { grid-template-columns: 44px minmax(0, 1fr); } .rn { grid-column: 2; text-align: start; } }
        .learned { display: flex; flex-direction: column; gap: 8px; margin-top: 14px; }
        .fact { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border-radius: 18px; background: var(--ground); font-size: 14px; animation: in .36s cubic-bezier(.23, 1, .32, 1) backwards; }
        .fact svg { width: 26px; height: 26px; padding: 5px; border-radius: 50%; background: var(--lime); color: var(--ink); flex: none; fill: none; stroke: currentColor; stroke-width: 3; }
        .fact b { display: block; font-weight: 600; margin-bottom: 2px; }
        @keyframes in { from { opacity: 0; transform: translateY(6px); } }
        @media (prefers-reduced-motion: reduce) { .mark.is-think, .mark.is-think i, .fact { animation: none; } }
    </style>
@endpush
@section('body')
    <section class="hero" style="padding-bottom: 130px">
        <span class="hero__glow" aria-hidden="true"></span>
        <span class="brand"><i aria-hidden="true"></i>Lets</span>
        <h1>{{ $t('scan.hero', ['site' => $site->title ?? $site->host]) }}</h1>
        <p class="lede">{{ $t('scan.lede') }}</p>
    </section>

    <section class="card" data-demo-scan data-progress="{{ route('demo.progress', $site->token) }}">
        <div class="scan-head">
            <span class="sand"><span class="mark is-think" aria-hidden="true" data-mark><i></i><i></i></span></span>
            <div>
                <h2 data-title>{{ $t('scan.title') }}</h2>
                <p class="lead">{{ $t('scan.lead') }}</p>
            </div>
        </div>
        <div class="rows" data-rows aria-live="polite">
            @foreach (['products', 'images', 'pages', 'tags', 'knowledge'] as $part)
                <div class="row is-wait" data-row="{{ $part }}">
                    <span class="ic" aria-hidden="true">{!! $t('scan.icons.'.$part) !!}</span>
                    <div><div class="rt">{{ $t('scan.rows.'.$part.'.title') }}</div><div class="rs">{{ $t('scan.rows.'.$part.'.sub') }}</div></div>
                    <div class="rn"><span data-n>0</span><div class="rs" data-unit>{{ $t('scan.rows.'.$part.'.unit') }}</div></div>
                </div>
            @endforeach
        </div>
        <div class="learned" data-learned></div>
        <p class="note">{{ $t('scan.wait', ['email' => $site->email]) }}</p>
    </section>

    <script>
        (function () {
            var box = document.querySelector('[data-demo-scan]'), url = box.getAttribute('data-progress');
            var of = @json($t('scan.of')), titles = @json(__('demo::page.scan.learned')), seen = {};
            var check = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5L19 7"/></svg>';
            function draw(d) {
                if (d.status === 'ready' || d.status === 'failed' || d.status === 'expired') { location.reload(); return; }
                (d.rows || []).forEach(function (r) {
                    var row = box.querySelector('[data-row="' + r.key + '"]');
                    if (!row) { return; }
                    row.className = 'row is-' + (r.state === 'done' ? 'done' : r.state === 'running' ? 'running' : 'wait');
                    row.querySelector('[data-n]').textContent = Number(r.n).toLocaleString();
                    if (r.of) { row.querySelector('[data-unit]').textContent = of.replace(':count', Number(r.of).toLocaleString()); }
                });
                (d.learned || []).forEach(function (l) {
                    if (seen[l.part]) { return; }
                    seen[l.part] = true;
                    var el = document.createElement('div');
                    el.className = 'fact';
                    el.innerHTML = check + '<span><b></b><span></span></span>';
                    el.querySelector('b').textContent = titles[l.part] || '';
                    el.querySelector('span span').textContent = l.text;
                    box.querySelector('[data-learned]').appendChild(el);
                });
            }
            function poll() { fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(draw).catch(function () {}).then(function () { setTimeout(poll, 4000); }); }
            poll();
        })();
    </script>
@endsection
