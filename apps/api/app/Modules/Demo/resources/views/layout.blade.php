{{-- The demo's pages in Lets' own look (lets.co.il, site.css): ink 60%, white 30%, lime 10%, lime only on ink; Google Sans. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · Lets</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600&family=Heebo:wght@400;500;600&display=swap">
    <style>
        :root { --ground: #f6f6f7; --ink: #0b0b0c; --ink-2: #141416; --ink-3: #1d1d21; --muted: #5f5f67; --on-ink: #a9a9b2; --lime: #e2ff78; --card: #ececef; --line: #e2e2e6; --font: "Google Sans", "Heebo", system-ui, sans-serif; color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--ground); color: var(--ink); font-family: var(--font); font-size: 15px; line-height: 1.55; -webkit-font-smoothing: antialiased; }
        a { color: inherit; }
        ::selection { background: var(--lime); color: var(--ink); }
        .hero { position: relative; isolation: isolate; overflow: hidden; background: var(--ink); color: #fff; padding: 72px 20px 140px; text-align: center; }
        .hero::before { content: ""; position: absolute; inset: 0; z-index: -1; pointer-events: none; background-image: linear-gradient(to right, rgb(255 255 255 / .1) 1px, transparent 1px), linear-gradient(to bottom, rgb(255 255 255 / .1) 1px, transparent 1px); background-size: 72px 72px; -webkit-mask: radial-gradient(ellipse 60% 70% at 50% 40%, #000 20%, transparent 72%); mask: radial-gradient(ellipse 60% 70% at 50% 40%, #000 20%, transparent 72%); }
        .hero__glow { position: absolute; left: 50%; bottom: -70%; width: min(1500px, 220%); aspect-ratio: 1.5; translate: -50% 0; border-radius: 50%; background: radial-gradient(closest-side, rgb(226 255 120 / .28), rgb(226 255 120 / .08) 50%, transparent); pointer-events: none; z-index: -1; }
        .brand { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 17px; letter-spacing: -.01em; color: #fff; text-decoration: none; }
        .brand i { width: 14px; height: 18px; display: inline-grid; grid-template-rows: 1fr 1fr; }
        .brand i::before, .brand i::after { content: ""; background: var(--lime); }
        .brand i::before { border-radius: 0 0 50% 50% / 0 0 100% 100%; }
        .brand i::after { border-radius: 50% 50% 0 0 / 100% 100% 0 0; }
        .hero h1 { margin: 34px auto 0; font-size: clamp(32px, 5vw, 58px); line-height: 1.04; letter-spacing: -.045em; font-weight: 500; max-width: 18ch; text-wrap: balance; }
        .hero h1 em { font-style: normal; color: var(--lime); display: inline-block; }
        .hero p.lede { margin: 22px auto 0; max-width: 52ch; color: var(--on-ink); font-size: clamp(15px, 1.2vw, 17px); text-wrap: balance; }
        .card { position: relative; max-width: 620px; margin: -96px auto 48px; background: #fff; border-radius: 28px; padding: 32px; box-shadow: 0 24px 60px -24px rgb(11 11 12 / .28); }
        @media (max-width: 640px) { .card { margin: -96px 16px 32px; padding: 22px; border-radius: 22px; } .hero { padding-top: 48px; } }
        .card h2 { margin: 0; font-size: 22px; font-weight: 500; letter-spacing: -.02em; }
        .card p.lead { margin: 6px 0 0; color: var(--muted); }
        label.field { display: block; margin-top: 20px; font-weight: 500; font-size: 14px; }
        .input { display: block; width: 100%; margin-top: 8px; height: 52px; padding: 0 18px; border: 1px solid var(--line); border-radius: 999px; background: var(--ground); font: inherit; font-size: 16px; color: var(--ink); outline: none; transition: border-color .2s, background .2s; }
        .input:focus { border-color: var(--ink); background: #fff; }
        fieldset { border: 0; padding: 0; margin: 22px 0 0; min-width: 0; }
        fieldset legend.field { margin: 0; padding: 0; }
        .choices { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-top: 8px; }
        @media (max-width: 520px) { .choices { grid-template-columns: 1fr; } }
        .choice { position: relative; display: block; cursor: pointer; }
        .choice input { position: absolute; opacity: 0; pointer-events: none; }
        .choice span { display: block; height: 100%; padding: 12px 14px; border: 1px solid var(--line); border-radius: 18px; background: var(--ground); transition: all .2s; }
        .choice b { display: block; font-weight: 500; }
        .choice small { display: block; color: var(--muted); font-size: 12.5px; line-height: 1.4; margin-top: 2px; }
        .choice input:checked + span { background: var(--ink); border-color: var(--ink); color: #fff; }
        .choice input:checked + span small { color: var(--on-ink); }
        .choice input:focus-visible + span { outline: 2px solid var(--ink); outline-offset: 2px; }
        .check { display: flex; gap: 10px; align-items: flex-start; margin-top: 18px; font-size: 13.5px; color: var(--muted); cursor: pointer; }
        .check input { margin-top: 3px; accent-color: var(--ink); width: 16px; height: 16px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 54px; padding: 0 28px; border: 0; border-radius: 999px; background: var(--ink); color: var(--lime); font: inherit; font-weight: 600; font-size: 16px; cursor: pointer; text-decoration: none; transition: transform .15s; }
        .btn:active { transform: scale(.97); }
        .btn--block { width: 100%; margin-top: 24px; }
        .btn--lime { background: var(--lime); color: var(--ink); }
        .btn--quiet { background: none; color: var(--ink); height: auto; padding: 0; font-weight: 500; text-decoration: underline; text-underline-offset: 4px; text-decoration-color: var(--line); }
        .note { margin-top: 14px; font-size: 13px; color: var(--muted); text-align: center; }
        .alert { margin-top: 18px; padding: 12px 16px; border-radius: 16px; background: #fdecec; color: #8a1c1c; font-size: 14px; }
        .alert--ok { background: var(--card); color: var(--ink); }
        .code { letter-spacing: .5em; text-align: center; font-size: 26px; font-weight: 600; direction: ltr; }
        .foot { text-align: center; color: var(--muted); font-size: 12.5px; padding-bottom: 32px; }
    </style>
    @stack('head')
</head>
<body>
@yield('body')
</body>
</html>
