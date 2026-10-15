<style>
    :root { --fg: #111; --muted: #666; --line: #e5e5e5; --bg: #fff; --soft: #f6f6f4; }
    @media (prefers-color-scheme: dark) { :root { --fg: #eee; --muted: #9a9a9a; --line: #2c2c2c; --bg: #141414; --soft: #1d1d1d; } }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Heebo, Arial, sans-serif; }
    main { max-width: 860px; margin: 0 auto; padding: 32px 16px 64px; }
    h1 { font-size: 26px; line-height: 1.25; margin: 0 0 8px; }
    h2 { font-size: 19px; margin: 36px 0 12px; }
    h3 { font-size: 17px; margin: 0 0 6px; }
    p { margin: 0 0 10px; }
    .muted { color: var(--muted); font-size: 14px; }
    .ai { background: var(--soft); border-radius: 10px; padding: 14px 16px; font-size: 14px; }
    .ai h2 { margin: 0 0 8px; font-size: 16px; }
    .ai ol { margin: 0; padding-inline-start: 20px; }
    .ai li { margin: 0 0 6px; }
    .ai code { direction: ltr; unicode-bidi: embed; font-size: 13px; word-break: break-all; }
    .chips { display: flex; flex-wrap: wrap; gap: 8px; list-style: none; padding: 0; margin: 0; }
    .chips li { border: 1px solid var(--line); border-radius: 999px; padding: 4px 12px; font-size: 14px; }
    article { display: grid; grid-template-columns: 110px 1fr; gap: 16px; padding: 18px 0; border-top: 1px solid var(--line); }
    article img { width: 110px; height: 110px; object-fit: cover; border-radius: 8px; background: var(--soft); }
    .price { font-weight: 700; }
    .was { text-decoration: line-through; color: var(--muted); font-weight: 400; margin-inline-start: 6px; }
    dl { display: grid; grid-template-columns: max-content 1fr; gap: 2px 12px; margin: 8px 0; font-size: 14px; }
    dt { color: var(--muted); }
    dd { margin: 0; }
    .options { margin: 6px 0 10px; padding-inline-start: 18px; font-size: 14px; }
    a { color: inherit; }
    .buy { font-weight: 700; }
    pre.json { background: var(--soft); border-radius: 10px; padding: 12px 14px; font-size: 12.5px; line-height: 1.5; overflow-x: auto; max-height: 480px; white-space: pre-wrap; word-break: break-word; }
    form { display: flex; gap: 8px; margin: 16px 0 4px; }
    input[type=search] { flex: 1; font: inherit; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: var(--fg); }
    button { font: inherit; font-weight: 600; padding: 10px 18px; border: 0; border-radius: 8px; background: var(--fg); color: var(--bg); cursor: pointer; }
    .pages { display: flex; gap: 16px; justify-content: space-between; margin-top: 24px; }
    footer { margin-top: 48px; font-size: 13px; color: var(--muted); }
    .go { display: inline-block; background: var(--fg); color: var(--bg); text-decoration: none; font-weight: 600; padding: 10px 18px; border-radius: 8px; margin: 4px 0 8px; }
    @media (max-width: 520px) { article { grid-template-columns: 1fr; } article img { width: 100%; height: auto; aspect-ratio: 1; } }
</style>
