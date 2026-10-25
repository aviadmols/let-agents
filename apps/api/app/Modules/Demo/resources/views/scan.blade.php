{{-- The demo store's scan as a real store's first run shows it (the Admin module's onboarding, step 3): first its
     preparation a step at a time (reading the site, starting the scan), then the products,
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
        .eta { margin-top: 20px; padding: 14px 16px; border-radius: 18px; background: var(--lime); color: var(--ink); font-size: 15px; line-height: 1.45; }
        .eta b { font-weight: 500; }
        .live { position: relative; display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; margin-top: 20px; padding: 14px 16px 18px; border-radius: 18px; background: var(--ink); color: #fff; overflow: hidden; }
        .live b { font-weight: 500; }
        .live__dot { width: 10px; height: 10px; border-radius: 50%; background: var(--lime); box-shadow: 0 0 0 0 rgb(226 255 120 / .6); animation: beat 1.6s ease-out infinite; flex: none; }
        .live__meta { margin-inline-start: auto; color: var(--on-ink); font-size: 13px; font-variant-numeric: tabular-nums; }
        .live__bar { position: absolute; inset-inline: 0; bottom: 0; height: 3px; background: rgb(255 255 255 / .12); }
        .live__bar i { position: absolute; inset-block: 0; width: 30%; background: var(--lime); animation: sweep 1.6s ease-in-out infinite; }
        @keyframes beat { 70% { box-shadow: 0 0 0 9px rgb(226 255 120 / 0); } 100% { box-shadow: 0 0 0 0 rgb(226 255 120 / 0); } }
        /* a bar a row: how far by count when the whole is known, else a sweep while it runs */
        .meter { position: relative; height: 4px; margin-top: 8px; border-radius: 999px; background: var(--line); overflow: hidden; }
        .meter i { position: absolute; inset-block: 0; inset-inline-start: 0; width: 0; border-radius: inherit; background: var(--ink); transition: width .6s cubic-bezier(.23, 1, .32, 1); }
        .row.is-done .meter i { width: 100%; }
        .row.is-running .meter.is-open i { width: 32%; animation: sweep 1.6s ease-in-out infinite; }
        @keyframes sweep { 0% { inset-inline-start: -32%; } 100% { inset-inline-start: 100%; } }
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
        @media (prefers-reduced-motion: reduce) { .mark.is-think, .mark.is-think i, .fact, .row.is-running .ic, .row.is-running .meter.is-open i, .live__dot, .live__bar i { animation: none; } }
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

        @unless ($failed)
            {{-- how long it takes, from what the last demos took, and that they need not wait for it --}}
            <div class="eta" data-eta><b>{{ $t('scan.wait', ['minutes' => \App\Modules\Demo\Models\DemoSite::typicalMinutes(), 'email' => $site->email]) }}</b></div>
            {{-- the live line: what is at work now, for how long, and when the screen last heard from the scan --}}
            <div class="live" data-live data-since="{{ $site->verified_at?->toIso8601String() }}" aria-live="polite">
                <span class="live__dot" aria-hidden="true"></span>
                <b data-now>{{ $t('scan.now', ['what' => $t('scan.steps.read.title')]) }}</b>
                <span class="live__meta"><span data-clock></span> · <span data-beat>{{ $t('scan.updated_now') }}</span></span>
                <span class="live__bar" aria-hidden="true"><i></i></span>
            </div>
        @endunless

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
                <div class="row is-{{ $step['state'] }}" data-step="{{ $step['key'] }}">
                    <span class="ic" aria-hidden="true">{!! $t('scan.icons.'.$step['key']) !!}</span>
                    <div><div class="rt">{{ $t('scan.steps.'.$step['key'].'.title') }}</div><div class="rs">{{ $t('scan.steps.'.$step['key'].'.sub') }}</div><div class="meter is-open" aria-hidden="true"><i></i></div></div>
                    <div class="rn" data-state>{{ $t('scan.states.'.$step['state']) }}</div>
                </div>
            @endforeach
        </div>

        <p class="group">{{ $t('scan.scanning') }}</p>
        <div class="rows" data-rows aria-live="polite">
            {{-- the pictures only when the demo reads them; the knowledge centre by where it stands, not by a count --}}
            @foreach (array_values(array_filter(['products', 'images', 'pages', 'tags', 'knowledge'], fn (string $p): bool => $p !== 'images' || $site->readsPictures())) as $part)
                <div class="row is-wait" data-row="{{ $part }}">
                    <span class="ic" aria-hidden="true">{!! $t('scan.icons.'.$part) !!}</span>
                    <div><div class="rt">{{ $t('scan.rows.'.$part.'.title') }}</div><div class="rs">{{ $t('scan.rows.'.$part.'.sub') }}</div><div class="meter is-open" aria-hidden="true" data-meter><i></i></div></div>
                    @if ($part === 'knowledge')
                        <div class="rn" data-state>{{ $t('scan.states.waiting') }}</div>
                    @else
                        <div class="rn"><span data-n>0</span><div class="rs" data-unit>{{ $t('scan.rows.'.$part.'.unit') }}</div></div>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="learned" data-learned></div>
    </section>

    <script>
        (function () {
            var box = document.querySelector('[data-demo-scan]'), url = box.getAttribute('data-progress');
            var of = @json($t('scan.of')), titles = @json(__('demo::page.scan.learned')), states = @json(__('demo::page.scan.states')), seen = {};
            var check = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5L19 7"/></svg>';
            if (box.getAttribute('data-status') === 'failed') { return; }
            // the live line: what is at work, the time since the code, and the seconds since the scan was last heard from
            var live = box.querySelector('[data-live]'), heard = Date.now();
            var doing = Object.assign({}, @json(collect(__('demo::page.scan.rows'))->map(fn ($r) => $r['title'])), @json(collect(__('demo::page.scan.steps'))->map(fn ($s) => $s['title'])));
            var nowText = @json($t('scan.now', ['what' => '%s'])), finishing = @json($t('scan.finishing')), beatNow = @json($t('scan.updated_now')), beatAgo = @json($t('scan.updated_ago', ['seconds' => '%s']));
            var since = Date.parse(live.getAttribute('data-since')) || Date.now();
            function tick() {
                var s = Math.max(0, Math.floor((Date.now() - since) / 1000)), ago = Math.floor((Date.now() - heard) / 1000);
                live.querySelector('[data-clock]').textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
                live.querySelector('[data-beat]').textContent = ago < 3 ? beatNow : beatAgo.replace('%s', ago);
            }
            tick();
            setInterval(tick, 1000);
            function draw(d) {
                // ready shows the demo; a problem is drawn by the page itself, with what to do about it
                if (d.status === 'ready' || d.status === 'failed' || d.status === 'expired') { location.reload(); return false; }
                heard = Date.now();
                if (d.since) { since = Date.parse(d.since) || since; }
                live.querySelector('[data-now]').textContent = d.now && doing[d.now] ? nowText.replace('%s', doing[d.now]) : finishing;
                (d.steps || []).forEach(function (s) {
                    var row = box.querySelector('[data-step="' + s.key + '"]');
                    if (!row) { return; }
                    row.className = 'row is-' + s.state;
                    row.querySelector('[data-state]').textContent = states[s.state] || '';
                });
                box.querySelector('[data-slow]').hidden = !d.slow;
                (d.rows || []).forEach(function (r) {
                    var row = box.querySelector('[data-row="' + r.key + '"]');
                    if (!row) { return; }
                    var state = r.state === 'done' ? 'done' : r.state === 'running' ? 'running' : 'wait';
                    row.className = 'row is-' + state;
                    // the bar: by count when the whole is known, a sweep while it runs when not
                    var meter = row.querySelector('[data-meter]');
                    // all counted but still at work (a further pass over the same pictures): a sweep, not a full bar standing still
                    var counted = r.of && !(state === 'running' && r.n >= r.of);
                    meter.classList.toggle('is-open', !counted);
                    meter.querySelector('i').style.width = counted && state !== 'done' ? Math.min(100, Math.round(100 * r.n / r.of)) + '%' : '';
                    if (r.key === 'knowledge') { row.querySelector('[data-state]').textContent = states[state === 'wait' ? 'waiting' : state] || ''; return; }
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
