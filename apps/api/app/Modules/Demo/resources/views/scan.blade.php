{{-- The demo store's scan as a real store's first run shows it (the Admin module's onboarding, step 3): first its
     preparation a step at a time (reading the site, starting the scan, the home page's picture), then the products,
     their pictures, the pages, the tags and the knowledge as they grow, and what was learned. A problem is shown here,
     where it happened, with what to do: the address asked again when it may be wrong, and a button to try again.
     Polls DemoController::progress. --}}
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
        .row.is-running .ic { box-shadow: inset 0 0 0 2px var(--ink); animation: pulse 1.4s ease-in-out infinite; }
        .row.is-failed .ic { background: #fdecec; color: #8a1c1c; }
        .row.is-failed .rn { color: #8a1c1c; }
        @keyframes pulse { 50% { opacity: .45; } }
        .group { margin: 24px 0 -14px; font-size: 12.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
        .steps .row { padding-block: 8px; }
        .steps .rn { font-size: 13px; font-weight: 500; }
        .problem { margin-top: 22px; padding: 20px; border-radius: 22px; background: #fdecec; color: #5c1414; }
        .problem h3 { margin: 0; font-size: 18px; font-weight: 600; letter-spacing: -.01em; }
        .problem p { margin: 6px 0 0; }
        .problem .input { background: #fff; }
        .problem .btn { margin-top: 14px; }
        .problem .alert { background: #fff; }
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
        @media (prefers-reduced-motion: reduce) { .mark.is-think, .mark.is-think i, .fact, .row.is-running .ic { animation: none; } }
    </style>
@endpush
@section('body')
    @php($failed = $site->status === \App\Modules\Demo\Models\DemoSite::FAILED)
    <section class="hero" style="padding-bottom: 130px">
        <span class="hero__glow" aria-hidden="true"></span>
        <span class="brand"><i aria-hidden="true"></i>Lets</span>
        <h1>{{ $failed ? $t('ended.failed_title') : $t('scan.hero', ['site' => $site->title ?? $site->host]) }}</h1>
        @unless ($failed)
            <p class="lede">{{ $t('scan.lede') }}</p>
        @endunless
    </section>

    @php($problem = $failed ? ($site->error ?? 'other') : null)
    @php($problem = $problem !== null && \Illuminate\Support\Facades\Lang::has('demo::page.scan.problem.'.$problem) ? $problem : ($failed ? 'other' : null))
    <section class="card" data-demo-scan data-status="{{ $site->status }}" data-progress="{{ route('demo.progress', $site->token) }}">
        <div class="scan-head">
            <span class="sand"><span class="mark {{ $failed ? '' : 'is-think' }}" aria-hidden="true" data-mark><i></i><i></i></span></span>
            <div>
                <h2 data-title>{{ $t('scan.title') }}</h2>
                <p class="lead">{{ $t('scan.lead') }}</p>
            </div>
        </div>

        @if ($problem !== null)
            <div class="problem" role="alert" data-problem="{{ $problem }}">
                <h3>{{ $t('scan.problem.'.$problem.'.title') }}</h3>
                <p>{{ $t('scan.problem.'.$problem.'.text') }}</p>
                @if ($retry)
                    <div class="alert" data-retry-error="{{ $retry }}">{{ $retry === 'too_many' ? $t('scan.again_errors.too_many') : $t('errors.'.$retry) }}</div>
                @endif
                <form method="post" action="{{ route('demo.again', $site->token) }}">
                    @csrf
                    @if (\Illuminate\Support\Facades\Lang::has('demo::page.scan.problem.'.$problem.'.ask'))
                        <label class="field" for="demo-url">{{ $t('scan.problem.'.$problem.'.ask') }}</label>
                        <input class="input" id="demo-url" name="url" type="text" inputmode="url" dir="ltr" required value="{{ $site->url }}" autocomplete="url">
                    @endif
                    <button class="btn" type="submit">{{ $t('scan.again') }}</button>
                </form>
            </div>
        @endif

        <div class="alert alert--ok" data-slow @if (! ($site->status === \App\Modules\Demo\Models\DemoSite::SCANNING && $site->scan_at === null && $site->verified_at?->lt(now()->subMinutes(2)))) hidden @endif>{{ $t('scan.slow') }}</div>

        <p class="group">{{ $t('scan.getting_ready') }}</p>
        <div class="rows steps" data-steps aria-live="polite">
            @foreach ($steps as $step)
                <div class="row is-{{ $step['state'] === 'skipped' ? 'done' : $step['state'] }}" data-step="{{ $step['key'] }}">
                    <span class="ic" aria-hidden="true">{!! $t('scan.icons.'.$step['key']) !!}</span>
                    <div><div class="rt">{{ $t('scan.steps.'.$step['key'].'.title') }}</div><div class="rs">{{ $t('scan.steps.'.$step['key'].'.sub') }}</div></div>
                    <div class="rn" data-state>{{ $t('scan.states.'.$step['state']) }}</div>
                </div>
            @endforeach
        </div>

        <p class="group">{{ $t('scan.scanning') }}</p>
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
            var of = @json($t('scan.of')), titles = @json(__('demo::page.scan.learned')), states = @json(__('demo::page.scan.states')), seen = {};
            var check = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5L19 7"/></svg>';
            if (box.getAttribute('data-status') === 'failed') { return; }
            function draw(d) {
                // ready shows the demo; a problem is drawn by the page itself, with what to do about it
                if (d.status === 'ready' || d.status === 'failed' || d.status === 'expired') { location.reload(); return false; }
                (d.steps || []).forEach(function (s) {
                    var row = box.querySelector('[data-step="' + s.key + '"]');
                    if (!row) { return; }
                    row.className = 'row is-' + (s.state === 'skipped' ? 'done' : s.state);
                    row.querySelector('[data-state]').textContent = states[s.state] || '';
                });
                box.querySelector('[data-slow]').hidden = !d.slow;
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
            function poll() {
                fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(draw)
                    .catch(function () {}).then(function (more) { if (more !== false) { setTimeout(poll, 4000); } });
            }
            poll();
        })();
    </script>
@endsection
