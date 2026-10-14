/*!
 * Let Agents — Try-On on the storefront (docs/try-on.md §10). Loaded by the widget (let-agents.js)
 * only on a product page whose bank says the product can be tried, so a shop that has not turned
 * Try-On on never loads it.
 *
 * A pill in the widget's own row (or among the block's tags) opens the window: upload or take a photo
 * (after the shopper agrees), the server checks it (refused: a reason and one click to try another),
 * asks what the photo leaves open (which finger, which hand, where in the room), makes the picture in
 * the background and shows its real stages; the window can be closed and a chip says when it is
 * ready, on this product's page. The result carries the AI line as live text, never in the picture.
 *
 * Plain ES5-ish, no build step. The style lives in this file, inside a shadow root, with the same
 * tokens as the widget (--let-agents-accent, --let-agents-glow-1/2/3).
 */
(function (win, doc) {
  'use strict';

  var ctx = win.LetAgentsTryOnContext;
  if (!ctx || !ctx.api || !ctx.site || !ctx.product || win.__letAgentsTryOn) {
    return;
  }
  win.__letAgentsTryOn = true;

  var API = String(ctx.api).replace(/\/+$/, '') + '/try-on/' + encodeURIComponent(ctx.site);
  var LOCALE = String(ctx.locale || 'he').slice(0, 2);
  var KEY = 'let_agents_tryon_' + ctx.site + '_' + ctx.product;
  var POLL_MS = 2500;
  // The store team's test (let_agents_tryon in the page's address, with the preview key): sent with
  // every request; the server checks the key. Empty for shoppers.
  var TEST = ctx.preview ? { preview: String(ctx.preview), 'class': String(ctx.testClass || '') } : null;
  // In the test, the page's own name and picture, for a product the scan did not mark or the catalog
  // has not read yet; the same values the search script sent, so the config comes from the cache.
  function testPage() {
    var h1 = doc.querySelector('h1');
    return {
      title: String(ctx.title || meta('og:title') || (h1 ? h1.textContent.replace(/\s+/g, ' ') : '') || '').trim().slice(0, 200),
      image: String(ctx.image || meta('og:image:secure_url') || meta('og:image') || '').trim()
    };
  }
  function testQuery() {
    if (!TEST) { return ''; }
    var page = testPage();
    return '&preview=' + encodeURIComponent(TEST.preview) + (TEST['class'] ? '&class=' + encodeURIComponent(TEST['class']) : '') +
      '&title=' + encodeURIComponent(page.title) + '&image=' + encodeURIComponent(page.image);
  }

  var CSS = [
    ':host{all:initial;font-family:inherit;color:#111827;font-size:15px;line-height:1.5;--accent:var(--let-agents-accent,#1f2933);--surface:var(--let-agents-surface,#fff);--line:rgba(17,24,39,.12);--muted:rgba(17,24,39,.62);--g1:var(--let-agents-glow-1,66,133,244);--g2:var(--let-agents-glow-2,168,85,247);--g3:var(--let-agents-glow-3,236,72,153);--hairline:linear-gradient(135deg,rgba(var(--g1),.55),rgba(var(--g2),.4) 50%,rgba(var(--g3),.35));--wash:radial-gradient(120% 90% at 100% 0%,rgba(var(--g1),.08),transparent 55%),radial-gradient(90% 70% at 0% 100%,rgba(var(--g3),.06),transparent 55%);--grad:linear-gradient(135deg,rgb(var(--g1)),rgb(var(--g2)) 60%,rgb(var(--g3)))}',
    '*{box-sizing:border-box}[hidden]{display:none!important}button{font:inherit;color:inherit}',
    '@keyframes to-in{from{opacity:0;transform:translateY(8px) scale(.985)}to{opacity:1;transform:none}}@keyframes to-spin{to{transform:rotate(360deg)}}@keyframes to-alive{0%,100%{box-shadow:0 0 0 0 rgba(var(--g2),.45)}50%{box-shadow:0 0 0 6px rgba(var(--g2),0)}}',
    '@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}',
    '.veil{position:fixed;inset:0;z-index:2147483300;background:rgba(17,24,39,.42);display:flex;align-items:center;justify-content:center;padding:16px}',
    '.box{position:relative;width:100%;max-width:480px;height:min(94vh,860px);display:flex;flex-direction:column;padding:22px 20px 16px;border:1px solid transparent;border-radius:28px;background:var(--wash) padding-box,linear-gradient(var(--surface),var(--surface)) padding-box,var(--hairline) border-box;box-shadow:0 30px 70px rgba(17,24,39,.22);animation:to-in .28s cubic-bezier(.2,.8,.2,1)}',
    '.top{margin:0 0 14px;padding-inline-end:40px}.who{display:flex;align-items:center;gap:6px;font-size:12px;color:#9ca3af}.title{margin:2px 0 0;font-size:16px;font-weight:600;line-height:1.35}.price{color:rgb(var(--g2));font-weight:700;white-space:nowrap}',
    '.x{all:unset;position:absolute;top:16px;inset-inline-end:16px;width:36px;height:36px;border-radius:50%;display:grid;place-items:center;cursor:pointer;color:var(--muted)}.x:hover,.x:focus-visible{background:rgba(17,24,39,.06);color:#111827}.x svg{width:22px;height:22px}',
    '.stage{position:relative;flex:1;min-height:0;border-radius:20px;overflow:hidden;background:#f4f4f5}',
    '.pane{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;padding:22px;text-align:center;background:var(--wash),#fafafa}',
    '.pane h3{margin:4px 0 0;font-size:18px;font-weight:700}.hint{margin:0;max-width:34ch;font-size:13.5px;color:var(--muted)}',
    '.icon{width:84px;height:84px;border-radius:22px;overflow:hidden;background:#fff;box-shadow:0 8px 22px rgba(var(--g2),.16)}.icon img{width:100%;height:100%;object-fit:cover}',
    '.buttons{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:4px}',
    '.btn{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:44px;padding:0 18px;border-radius:999px;cursor:pointer;font-size:14px;font-weight:600;background:var(--accent);color:#fff;text-align:center}',
    '.btn.ghost{background:linear-gradient(#fff,#fff) padding-box,var(--hairline) border-box;border:1px solid transparent;color:#111827}.btn[aria-disabled="true"]{opacity:.45;cursor:default}.btn:focus-visible{outline:2px solid rgba(var(--g2),.5);outline-offset:2px}.btn svg{width:18px;height:18px}',
    '.small{font-size:11.5px;color:#8a8f98}.needed{font-size:12px;font-weight:600;color:rgb(var(--g2))}',
    '.consent{display:flex;gap:8px;align-items:flex-start;max-width:40ch;margin-top:6px;font-size:12px;color:#4b5563;text-align:start;cursor:pointer}.consent input{margin:2px 0 0;flex:none;accent-color:rgb(var(--g2));width:15px;height:15px}',
    '.notice{display:flex;gap:8px;align-items:flex-start;max-width:36ch;padding:10px 12px;border-radius:14px;background:#fef2f2;color:#991b1b;font-size:14px;font-weight:600;text-align:start}',
    '.ring{flex:none;width:22px;height:22px;border-radius:50%;border:2.5px solid rgba(var(--g2),.18);border-top-color:rgb(var(--g2));animation:to-spin .9s linear infinite}',
    '.card{width:min(300px,100%);padding:16px;border:1px solid transparent;border-radius:18px;background:linear-gradient(rgba(255,255,255,.96),rgba(255,255,255,.96)) padding-box,var(--hairline) border-box;box-shadow:0 14px 40px rgba(var(--g2),.14);text-align:start}',
    '.lead{display:flex;align-items:center;gap:10px;font-weight:600;font-size:14.5px;margin-bottom:10px}',
    '.steps{list-style:none;margin:0;padding:0;display:grid;gap:8px}.steps li{display:flex;align-items:center;gap:10px;font-size:13.5px;color:#9ca3af}.dot{flex:none;width:20px;height:20px;border-radius:50%;background:#f1f1f3}',
    '.steps li.is-done{color:#111827}.steps li.is-done .dot{background:var(--grad)}.steps li.is-now{color:#111827;font-weight:600}.steps li.is-now .dot{background:#fff;box-shadow:inset 0 0 0 2px rgb(var(--g2));animation:to-alive 1.6s ease-in-out infinite}',
    '.later{max-width:30ch;font-size:12.5px;color:#4b5563}',
    '.options{display:grid;gap:8px;width:min(320px,100%)}',
    '.result{position:absolute;inset:0;transition:transform .35s ease;transform-origin:center;background:#ebe9ee}.result img{width:100%;height:100%;object-fit:cover}.stage.is-zoomed .result{transform:scale(2.1)}.stage.is-zoomed{cursor:zoom-out}',
    '.zoom{all:unset;position:absolute;top:12px;inset-inline-end:12px;z-index:2;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;cursor:pointer;background:rgba(255,255,255,.92);box-shadow:0 4px 14px rgba(0,0,0,.12)}.zoom svg{width:18px;height:18px}',
    '.ai{position:absolute;bottom:12px;inset-inline-start:12px;z-index:2;display:flex;align-items:center;gap:8px;max-width:calc(100% - 24px);padding:6px 10px 6px 12px;border-radius:999px;background:rgba(255,255,255,.88);font-size:12px;line-height:1.35;color:#374151}.ai b{flex:none;padding:1px 7px;border-radius:999px;background:var(--grad);color:#fff;font-size:10.5px}',
    '.actions{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:12px}.foot{margin-top:10px;text-align:center;font-size:11.5px;color:#a1a1aa}',
    '.chip{all:unset;box-sizing:border-box;position:fixed;bottom:20px;inset-inline-start:20px;z-index:2147483200;display:flex;align-items:center;gap:10px;max-width:min(320px,calc(100vw - 40px));padding:8px 14px 8px 8px;border:1px solid transparent;border-radius:999px;cursor:pointer;background:linear-gradient(#fff,#fff) padding-box,var(--hairline) border-box;box-shadow:0 12px 34px rgba(var(--g2),.18);animation:to-in .3s ease}',
    '.chip .ico{flex:none;width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:rgba(var(--g2),.1);overflow:hidden}.chip .ico img{width:100%;height:100%;object-fit:cover}.chip.is-ready .ico{box-shadow:0 0 0 2px rgb(var(--g2))}.chip .t{display:grid;line-height:1.3}.chip .t b{font-size:13.5px}.chip .t span{font-size:11.5px;color:var(--muted)}',
    '@media (max-width:560px){.veil{padding:0;align-items:flex-end}.box{max-width:none;height:96vh;border-radius:24px 24px 0 0;padding:18px 14px 12px}.chip{bottom:84px}}'
  ].join('');

  var ICON = {
    spark: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="rgb(168,85,247)" d="M12 2l1.9 6.1L20 10l-6.1 1.9L12 18l-1.9-6.1L4 10l6.1-1.9z"/></svg>',
    close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    upload: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>',
    camera: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 9.5V8a3 3 0 0 1 3-3h1.8l1.2-1.6h5l1.2 1.6h1.8a3 3 0 0 1 3 3v9a3 3 0 0 1-3 3h-11a3 3 0 0 1-3-3z"/><circle cx="12" cy="12.5" r="3.4"/></svg>',
    zoomIn: '<svg viewBox="0 0 24 24" fill="none" stroke="#222" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7.5"/><path d="M21 21l-4.4-4.4M11 8v6M8 11h6"/></svg>',
    zoomOut: '<svg viewBox="0 0 24 24" fill="none" stroke="#222" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7.5"/><path d="M21 21l-4.4-4.4M8 11h6"/></svg>'
  };

  var S = { cfg: null, open: false, view: 'upload', consent: false, photo: null, questions: [], answers: {}, message: '', job: null, stage: null, image: null, zoom: false, added: false, poll: null };
  var root = null;
  var host = null;

  // ------------------------------------------------------------------ helpers

  function el(tag, cls, text) {
    var n = doc.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }
  function L(key) {
    var labels = (S.cfg && S.cfg.labels) || {};
    return labels[key] != null ? labels[key] : '';
  }
  function visitor() {
    var vid = null;
    try { vid = win.localStorage.getItem('let_agents_vid'); } catch (e) { /* private */ }
    if (!/^anon-[A-Za-z0-9_-]{16,64}$/.test(vid || '')) {
      vid = 'anon-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2) + 'xxxxxxxx';
      vid = vid.slice(0, 32);
      try { win.localStorage.setItem('let_agents_vid', vid); } catch (e) { /* private */ }
    }
    return vid;
  }
  function remember(value) {
    try { value ? win.localStorage.setItem(KEY, JSON.stringify(value)) : win.localStorage.removeItem(KEY); } catch (e) { /* private */ }
  }
  function remembered() {
    try { return JSON.parse(win.localStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
  }
  function json(response) { return response.json().then(function (body) { return { status: response.status, body: body || {} }; }); }
  function meta(name) {
    var n = doc.querySelector('meta[property="' + name + '"]') || doc.querySelector('meta[name="' + name + '"]');
    return n ? String(n.getAttribute('content') || '').trim() : '';
  }
  function product() {
    var h1 = doc.querySelector('h1');
    return {
      title: (ctx.title || (h1 && h1.textContent) || meta('og:title') || '').trim(),
      price: ctx.price || meta('product:price:amount') || '',
      image: ctx.image || meta('og:image') || ''
    };
  }

  // ------------------------------------------------------------------ entry: a pill in the widget's row

  // Where the button goes when there is no widget row: the store's own setting (try_on.anchor_selector),
  // else next to the add-to-cart form, which every theme has.
  var FALLBACK_ANCHORS = ['form[action*="/cart/add"]', 'form.cart', '.product-form'];
  var POSITIONS = { before: 'beforebegin', after: 'afterend', start: 'afterbegin', end: 'beforeend' };

  function anchorElement() {
    var wanted = S.cfg && S.cfg.anchor && S.cfg.anchor.selector ? [S.cfg.anchor.selector] : FALLBACK_ANCHORS;
    for (var i = 0; i < wanted.length; i++) {
      try {
        var found = doc.querySelector(wanted[i]);
        if (found) { return found; }
      } catch (e) { /* a selector the browser does not accept */ }
    }
    return null;
  }

  /** The button on the store's page itself, in a shadow root of its own so the theme cannot touch it. */
  function placeOnPage() {
    if (doc.querySelector('[data-la-tryon-anchor]')) { return true; }
    var anchor = anchorElement();
    if (!anchor) { return false; }
    var wrap = doc.createElement('div');
    wrap.setAttribute('data-la-tryon-anchor', '');
    wrap.style.margin = '10px 0';
    var shadow = wrap.attachShadow({ mode: 'open' });
    var style = doc.createElement('style');
    style.textContent = ':host{all:initial;display:block;font-family:inherit}button{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:46px;padding:0 18px;border:1px solid transparent;border-radius:999px;cursor:pointer;font:600 15px/1.2 inherit;color:#111827;background:linear-gradient(#fff,#fff) padding-box,linear-gradient(135deg,rgba(66,133,244,.7),rgba(168,85,247,.6) 50%,rgba(236,72,153,.55)) border-box;box-shadow:0 6px 18px rgba(168,85,247,.14)}button:hover{box-shadow:0 8px 24px rgba(168,85,247,.24)}button:focus-visible{outline:2px solid rgba(168,85,247,.5);outline-offset:2px}';
    shadow.appendChild(style);
    var b = doc.createElement('button');
    b.type = 'button';
    b.setAttribute('data-la-tryon', '');
    b.innerHTML = ICON.spark;
    b.appendChild(el('span', null, S.cfg.group === 'furniture' ? L('button_furniture') : L('button')));
    b.addEventListener('click', function () { open(); });
    shadow.appendChild(b);
    wrap.style.fontFamily = win.getComputedStyle(doc.body).fontFamily;
    anchor.insertAdjacentElement(POSITIONS[(S.cfg.anchor && S.cfg.anchor.position) || 'after'] || 'afterend', wrap);
    return true;
  }

  function placePill() {
    if (!S.cfg) { return false; }
    if (doc.querySelector('[data-la-tryon-anchor]')) { return true; }
    // Loaded without a widget (the search script on a product page), or the shop chose a place: on the page.
    if (ctx.standalone || (S.cfg.anchor && S.cfg.anchor.selector)) { return placeOnPage(); }
    var widget = doc.querySelector('.let-agents-widget');
    var wroot = widget && widget.shadowRoot;
    if (!wroot) { return false; }
    if (wroot.querySelector('[data-la-tryon]')) { return true; }
    var row = wroot.querySelector('.as-tags') || wroot.querySelector('.chips') || wroot.querySelector('.quick') || wroot.querySelector('.more');
    if (!row) { return false; }
    var assistant = row.classList.contains('as-tags');
    var b = doc.createElement('button');
    b.type = 'button';
    b.className = assistant ? 'as-tag' : 'pill';
    b.setAttribute('data-la-tryon', '');
    b.innerHTML = ICON.spark;
    b.setAttribute('style', 'display:inline-flex;align-items:center;gap:5px');
    b.appendChild(el('span', null, S.cfg.group === 'furniture' ? L('button_furniture') : L('button')));
    b.addEventListener('click', function () { open(); });
    row.insertBefore(b, assistant ? null : row.firstChild);
    return true;
  }

  // ------------------------------------------------------------------ the window

  function ensureHost() {
    if (root) { return; }
    host = doc.createElement('div');
    host.setAttribute('data-la-tryon-host', '');
    host.style.fontFamily = win.getComputedStyle(doc.body).fontFamily;
    root = host.attachShadow({ mode: 'open' });
    doc.body.appendChild(host);
  }

  function render() {
    ensureHost();
    root.innerHTML = '';
    var style = doc.createElement('style');
    style.textContent = CSS;
    root.appendChild(style);
    if (!S.open) {
      if (S.job && (S.view === 'working' || S.view === 'result')) { root.appendChild(chip()); }
      return;
    }
    var p = product();
    var veil = el('div', 'veil');
    veil.addEventListener('click', function (e) { if (e.target === veil) { close(); } });
    var box = el('div', 'box');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('dir', LOCALE === 'he' ? 'rtl' : 'ltr');
    var x = el('button', 'x');
    x.type = 'button';
    x.innerHTML = ICON.close;
    x.setAttribute('aria-label', L('close'));
    x.addEventListener('click', close);
    box.appendChild(x);
    var top = el('div', 'top');
    var who = el('div', 'who');
    who.innerHTML = ICON.spark;
    who.appendChild(el('span', null, S.cfg.group === 'furniture' ? L('button_furniture') : L('button')));
    top.appendChild(who);
    var title = el('h2', 'title', p.title);
    if (p.price) { title.appendChild(doc.createTextNode(' · ')); title.appendChild(el('span', 'price', p.price)); }
    top.appendChild(title);
    box.appendChild(top);
    var stage = el('div', 'stage' + (S.zoom ? ' is-zoomed' : ''));
    stage.setAttribute('aria-live', 'polite');
    box.appendChild(stage);
    if (S.view === 'upload') { stage.appendChild(uploadPane(p)); }
    if (S.view === 'checking') { stage.appendChild(waitPane(L('checking_photo'), 0)); }
    if (S.view === 'rejected') { stage.appendChild(rejectPane()); }
    if (S.view === 'question') { stage.appendChild(questionPane()); }
    if (S.view === 'working') { stage.appendChild(waitPane(S.cfg.group === 'furniture' ? L('generating_space') : L('generating'), stageIndex())); }
    if (S.view === 'result') { resultPane(stage); box.appendChild(actions()); }
    box.appendChild(el('div', 'foot', S.cfg.group === 'furniture' ? L('own_space') : L('own_photo')));
    veil.appendChild(box);
    root.appendChild(veil);
  }

  function uploadPane(p) {
    var pane = el('div', 'pane');
    if (p.image) {
      var icon = el('div', 'icon');
      var i = el('img');
      i.src = p.image;
      i.alt = '';
      icon.appendChild(i);
      pane.appendChild(icon);
    }
    pane.appendChild(el('h3', null, (L('upload_title') || {})[S.cfg.group] || ''));
    pane.appendChild(el('p', 'hint', (L('upload_hint') || {})[S.cfg.group] || ''));
    var buttons = el('div', 'buttons');
    var file = el('input');
    file.type = 'file';
    file.accept = 'image/jpeg,image/png,image/webp';
    file.hidden = true;
    var cam = el('input');
    cam.type = 'file';
    cam.accept = 'image/*';
    cam.setAttribute('capture', S.cfg.group === 'furniture' ? 'environment' : 'user');
    cam.hidden = true;
    var up = el('button', 'btn');
    up.type = 'button';
    up.innerHTML = ICON.upload;
    up.appendChild(el('span', null, L('upload')));
    var shoot = el('button', 'btn ghost');
    shoot.type = 'button';
    shoot.innerHTML = ICON.camera;
    shoot.appendChild(el('span', null, L('camera')));
    buttons.appendChild(up);
    buttons.appendChild(shoot);
    pane.appendChild(buttons);
    var needed = el('span', 'needed', L('consent_needed'));
    pane.appendChild(needed);
    pane.appendChild(el('span', 'small', L('formats')));
    var agree = el('label', 'consent');
    var box = el('input');
    box.type = 'checkbox';
    box.checked = S.consent;
    agree.appendChild(box);
    agree.appendChild(el('span', null, L('consent')));
    pane.appendChild(agree);
    function paint() {
      up.setAttribute('aria-disabled', S.consent ? 'false' : 'true');
      shoot.setAttribute('aria-disabled', S.consent ? 'false' : 'true');
      needed.hidden = S.consent;
    }
    box.addEventListener('change', function () { S.consent = box.checked; paint(); });
    paint();
    up.addEventListener('click', function () { if (S.consent) { file.click(); } else { box.focus(); } });
    shoot.addEventListener('click', function () { if (S.consent) { cam.click(); } else { box.focus(); } });
    [file, cam].forEach(function (input) {
      input.addEventListener('change', function () { if (input.files && input.files[0]) { send(input.files[0]); } });
      pane.appendChild(input);
    });
    return pane;
  }

  function waitPane(lead, at) {
    var pane = el('div', 'pane');
    var card = el('div', 'card');
    var head = el('div', 'lead');
    head.appendChild(el('span', 'ring'));
    head.appendChild(el('span', null, lead));
    card.appendChild(head);
    var list = el('ol', 'steps');
    var steps = L('steps') || {};
    ['photo', 'prompt', 'generate', 'check'].forEach(function (key, i) {
      var li = el('li', i < at ? 'is-done' : (i === at ? 'is-now' : ''));
      li.appendChild(el('span', 'dot'));
      li.appendChild(el('span', null, steps[key] || key));
      list.appendChild(li);
    });
    card.appendChild(list);
    pane.appendChild(card);
    if (S.view === 'working') { pane.appendChild(el('div', 'later', L('generating_note'))); }
    return pane;
  }

  function stageIndex() {
    return { prompt: 1, generate: 2, check: 3 }[S.stage] || 1;
  }

  function rejectPane() {
    var pane = el('div', 'pane');
    pane.appendChild(el('div', 'notice', S.message || L('failed')));
    var again = el('button', 'btn');
    again.type = 'button';
    again.innerHTML = ICON.upload;
    again.appendChild(el('span', null, L('try_again')));
    again.addEventListener('click', function () { S.view = 'upload'; S.photo = null; render(); });
    pane.appendChild(again);
    return pane;
  }

  /** What the photo leaves open, one question at a time: which finger, which hand, where in the room. */
  function questionPane() {
    var q = S.questions[0];
    var pane = el('div', 'pane');
    pane.appendChild(el('h3', null, q.question));
    var list = el('div', 'options');
    (q.options || []).forEach(function (option) {
      var b = el('button', 'btn ghost', option.label);
      b.type = 'button';
      b.addEventListener('click', function () {
        S.answers[q.key] = option.key;
        S.questions.shift();
        if (S.questions.length) { render(); } else { start(); }
      });
      list.appendChild(b);
    });
    pane.appendChild(list);
    return pane;
  }

  function resultPane(stage) {
    var res = el('div', 'result');
    var i = el('img');
    i.src = S.image;
    i.alt = '';
    res.appendChild(i);
    stage.appendChild(res);
    var z = el('button', 'zoom');
    z.type = 'button';
    z.innerHTML = S.zoom ? ICON.zoomOut : ICON.zoomIn;
    z.setAttribute('aria-label', S.zoom ? L('zoom_out') : L('zoom'));
    z.addEventListener('click', function (e) { e.stopPropagation(); S.zoom = !S.zoom; render(); });
    stage.appendChild(z);
    // The AI line is live text, never baked into the picture.
    var ai = el('div', 'ai');
    ai.appendChild(el('b', null, L('ai_badge')));
    ai.appendChild(el('span', null, S.cfg.note || ''));
    stage.appendChild(ai);
    stage.addEventListener('mousemove', function (e) {
      if (!S.zoom) { return; }
      var r = stage.getBoundingClientRect();
      res.style.transformOrigin = ((e.clientX - r.left) / r.width * 100) + '% ' + ((e.clientY - r.top) / r.height * 100) + '%';
    });
  }

  function actions() {
    var row = el('div', 'actions');
    var cart = el('button', 'btn');
    cart.type = 'button';
    cart.appendChild(el('span', null, S.added ? L('added') : L('add_to_cart')));
    cart.addEventListener('click', function () {
      // The store's own add-to-cart: its options, its cart, its counter.
      var own = doc.querySelector('form.cart [type="submit"], form[action*="/cart/add"] [type="submit"], [name="add-to-cart"]');
      if (own) { own.click(); }
      S.added = true;
      render();
    });
    var save = el('a', 'btn ghost', L('save'));
    save.href = S.image;
    save.setAttribute('download', 'try-on.jpg');
    save.setAttribute('target', '_blank');
    save.setAttribute('rel', 'noopener');
    row.appendChild(cart);
    row.appendChild(save);
    return row;
  }

  function chip() {
    var ready = S.view === 'result';
    var c = el('button', 'chip' + (ready ? ' is-ready' : ''));
    c.type = 'button';
    c.setAttribute('dir', LOCALE === 'he' ? 'rtl' : 'ltr');
    var ico = el('span', 'ico');
    if (ready && S.image) {
      var i = el('img');
      i.src = S.image;
      i.alt = '';
      ico.appendChild(i);
    } else {
      ico.appendChild(el('span', 'ring'));
    }
    c.appendChild(ico);
    var t = el('span', 't');
    t.appendChild(el('b', null, ready ? L('chip_ready') : L('chip_working')));
    t.appendChild(el('span', null, L('chip_open')));
    c.appendChild(t);
    c.addEventListener('click', function () { S.open = true; render(); });
    return c;
  }

  // ------------------------------------------------------------------ the requests

  function send(file) {
    S.view = 'checking';
    render();
    var body = new FormData();
    body.append('photo', file);
    body.append('vid', visitor());
    body.append('consent', S.consent ? '1' : '0');
    body.append('locale', LOCALE);
    if (TEST) { body.append('preview', TEST.preview); }
    fetch(API + '/photos', { method: 'POST', body: body, credentials: 'omit' })
      .then(json)
      .then(function (r) {
        var d = r.body.data || {};
        if (d.status !== 'ok') {
          S.view = 'rejected';
          S.message = d.message || L('failed');
          render();
          return;
        }
        S.photo = d.photo;
        S.answers = {};
        start();
      })
      .catch(function () { S.view = 'rejected'; S.message = L('failed'); render(); });
  }

  function start() {
    S.view = 'working';
    S.stage = 'prompt';
    render();
    fetch(API + '/tries', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ vid: visitor(), photo: S.photo, product: String(ctx.product), answers: S.answers, locale: LOCALE, preview: TEST ? TEST.preview : undefined, 'class': TEST ? TEST['class'] : undefined, title: TEST ? testPage().title : undefined, image: TEST ? testPage().image : undefined }),
      credentials: 'omit'
    })
      .then(json)
      .then(function (r) {
        var d = r.body.data || {};
        if (d.status === 'needs_answer') {
          S.questions = (d.questions || []).slice();
          S.view = 'question';
          render();
          return;
        }
        if (d.status === 'refused' || !d.id) {
          S.view = 'rejected';
          S.message = d.message || L('failed');
          render();
          return;
        }
        follow(d);
      })
      .catch(function () { S.view = 'rejected'; S.message = L('failed'); render(); });
  }

  /** Where the try is, until it is ready or failed; the chip follows it while the window is closed. */
  function follow(d) {
    clearTimeout(S.poll);
    S.job = d.id;
    S.stage = d.stage;
    remember({ job: d.id });
    if (d.status === 'ready') {
      S.view = 'result';
      S.image = d.image;
      render();
      return;
    }
    if (d.status === 'failed') {
      S.view = 'rejected';
      S.message = L('failed');
      S.job = null;
      remember(null);
      render();
      return;
    }
    S.view = 'working';
    render();
    S.poll = setTimeout(function () {
      fetch(API + '/tries/' + encodeURIComponent(d.id) + '?vid=' + encodeURIComponent(visitor()) + testQuery(), { credentials: 'omit' })
        .then(json)
        .then(function (r) { if (r.status === 200 && r.body.data) { follow(r.body.data); } else { S.job = null; remember(null); render(); } })
        .catch(function () { S.poll = setTimeout(function () { follow(d); }, POLL_MS); });
    }, POLL_MS);
  }

  function open() {
    S.open = true;
    if (S.view !== 'working' && S.view !== 'result' && S.view !== 'question') { S.view = 'upload'; }
    render();
  }

  function close() {
    S.open = false;
    S.zoom = false;
    render();
  }

  // ------------------------------------------------------------------ start

  fetch(API + '/config?product=' + encodeURIComponent(ctx.product) + '&locale=' + LOCALE + testQuery(), { credentials: 'omit' })
    .then(json)
    .then(function (r) {
      var d = r.body.data;
      if (!d || !d.tryable) { return; }
      S.cfg = d;
      var tries = 0;
      // The widget's row comes when the widget has drawn; without a row after a while, the page itself.
      (function wait() {
        if (placePill()) { return; }
        if (++tries === 25) { placeOnPage(); return; }
        setTimeout(wait, 200);
      })();
      // A layout that redraws (the chat opening, a panel) keeps the pill.
      setInterval(function () { placePill(); }, 1500);
      // A try started before, on this product's page: its chip again.
      var before = remembered();
      if (before && before.job) { S.job = before.job; follow({ id: before.job, status: 'working' }); }
    })
    .catch(function () { /* no Try-On today */ });

  win.LetAgentsTryOn = { open: open };
})(window, document);
