  // ---------------------------------------------------------------- filters + sort layers
  function filtersLayer(turn) {
    function draw() {
      var fc = facetChips(turn, 0), c = C(turn), groups = {}, so = sortOf(c);
      fc.codes.forEach(function (code) { var g = Math.floor(code / 1000); (groups[g] = groups[g] || []).push(code); });
      var html = activeRow(turn) ? '<div class="ls-fgroup ls-fgroup--active"><div class="ls-fgroup__t">' + esc(t('activeFilters')) + '</div>' + activeRow(turn) + '</div>' : '';
      html += '<div class="ls-fgroup"><div class="ls-fgroup__t">' + esc(t('sort')) + '</div><div class="ls-chips">' + ['', 'asc', 'desc', 'new'].map(function (s) { return '<button class="ls-chip" aria-pressed="' + (so === s) + '" data-fsort="' + s + '"><span class="ls-chip__check">' + icon('check', 'ls-icon--sm') + '</span>' + esc(sortLabel(s)) + '</button>'; }).join('') + '</div></div>';
      if (fc.stock || fc.sale || onStock(c) || onSale(c)) {
        html += '<div class="ls-fgroup"><div class="ls-fgroup__t">' + esc(LANG === 'he' ? 'זמינות' : 'Availability') + '</div><div class="ls-chips">' + ((fc.stock || onStock(c)) ? chip(t('inStock'), onStock(c), 'stock', turn.id, c.autoStock && !c.stock && !!c.saidStock) : '') + ((fc.sale || onSale(c)) ? chip(t('onSale'), onSale(c), 'sale', turn.id, c.autoSale && !c.sale) : '') + '</div></div>';
      }
      Object.keys(groups).forEach(function (g) {
        var facet = S.facets[g], colours = groups[g].some(function (code) { return swatch(facet, facet.v[code % 1000]); });
        html += '<div class="ls-fgroup' + (colours ? ' ls-fgroup--colour' : '') + '"><div class="ls-fgroup__t">' + esc(facet.l) + '</div><div class="ls-chips">' + groups[g].map(function (code) {
          var v = facet.v[code % 1000];
          return chip(v.l, onCode(c, code), 'f:' + code, turn.id, c.auto[code] && !c.f[code], swatch(facet, v), fc.counts[code]);
        }).join('') + '</div></div>';
      });
      LAYER.querySelector('.ls-layer__body').innerHTML = html;
      var n = filtered(turn).length;
      LAYER.querySelector('.ls-layer__foot').innerHTML = '<button class="ls-btn ls-btn--secondary" data-fclear>' + esc(t('clearAll')) + '</button><button class="ls-btn ls-btn--primary" data-layer-close' + (n ? '' : ' disabled') + '>' + esc(n ? t('showN', { n: n }) : t('noFilterMatch').split('.')[0]) + '</button>';
    }
    showLayer(t('filter'), '', '');
    LAYER.onclick = function (e) {
      var s = e.target.closest('[data-fsort]');
      // handled here, so the drawer's own click handler doesn't toggle it a second time
      if (s) { e.stopPropagation(); setSort(turn, s.dataset.fsort); draw(); refresh(turn); return; }
      if (e.target.closest('[data-fclear]')) { e.stopPropagation(); clearC(turn, true); draw(); refresh(turn); return; }
      var cl = e.target.closest('[data-clear-filters]');
      if (cl) { e.stopPropagation(); clearC(turn, false); draw(); refresh(turn); return; }
      var ch = e.target.closest('[data-chip]');
      // a filter that took its words with it starts the search again: the sheet closes on the new results
      if (ch) { e.stopPropagation(); if (applyChip(turn, ch.dataset.chip)) { hideLayer(); } else { draw(); } }
    };
    draw();
  }
  function setSort(turn, s) { var c = C(turn); c.sort = s; c.autoSort = ''; c.no.sort = true; }
  // clearing takes off what was read from the words too, and keeps it off
  function clearC(turn, withSort) {
    var c = C(turn), n = blankC();
    Object.keys(c.no).forEach(function (k) { n.no[k] = true; });
    Object.keys(c.auto).forEach(function (k) { n.no[k] = true; });
    Object.keys(c.words || {}).forEach(function (k) { n.no['w:' + k] = true; });
    n.suggest = c.suggest || {};
    if (c.autoStock) { n.no.stock = true; }
    if (c.autoSale) { n.no.sale = true; }
    if (withSort) { n.no.sort = true; } else { n.sort = c.sort; n.autoSort = c.autoSort; }
    turn.c = n;
  }
  // true when the filter took its words with it and the search runs again (dropWords)
  function applyChip(turn, key) {
    var c = C(turn), words = null;
    if (key === 'stock') {
      if (c.autoStock) { c.autoStock = false; c.no.stock = true; if (c.saidStock) { words = saidAt(turn.q, /^במלאי$/, ['in', 'stock']); } } else { c.stock = !c.stock; }
    } else if (key === 'sale') {
      if (c.autoSale) { c.autoSale = false; c.no.sale = true; words = saidAt(turn.q, /^ב?מבצע$/, ['on', 'sale']); } else { c.sale = !c.sale; }
    } else if (key.indexOf('g:') === 0) {
      // a store's word offered beside the typed one, chosen or let go: a filter of its own, the search's words untouched
      var g = key.slice(2);
      c.picked = c.picked || {};
      if (c.picked[g]) { delete c.picked[g]; } else if (c.suggest && c.suggest[g]) { c.picked[g] = c.suggest[g]; signalEvent('chip_on', turn.q, c.suggest[g].label); }
    } else if (key.indexOf('w:') === 0) {
      // a word read as a chip, taken off: out of the search too, as a filter read from the words is
      var wd = c.words && c.words[key.slice(2)];
      if (wd) { delete c.words[key.slice(2)]; c.no[key] = true; words = wd.at.slice(); signalEvent('chip_off', turn.q, wd.label); }
    } else {
      var code = Number(key.split(':')[1]);
      if (c.auto[code] && !c.f[code] && c.at && c.at[code] && c.at[code].length) { words = c.at[code]; }
      var named = valueOf(code), label = named && named.value ? named.value.l : '';
      // read from the words and taken off, or put on by hand: what the store learns of its chips
      if (c.auto[code] && !c.f[code]) { signalEvent('chip_off', turn.q, label); } else if (!c.f[code]) { signalEvent('chip_on', turn.q, label); }
      if (c.auto[code] || c.f[code]) { delete c.auto[code]; delete c.f[code]; c.no[code] = true; } else { c.f[code] = true; delete c.no[code]; }
    }
    if (words && words.length && turn.q && (turn === S.live || turn.kind === 'results')) { dropWords(turn, words); return true; }
    refresh(turn);
    return false;
  }
  // where the words that asked for stock or a sale were typed: one Hebrew word, or an English pair
  function saidAt(q, one, pair) {
    var w = wordsOf(q), out = [];
    w.forEach(function (x, i) {
      if (one.test(x)) { out.push(i); }
      if (x === pair[0] && w[i + 1] === pair[1]) { out.push(i, i + 1); }
    });
    return out;
  }
  function sortLayer(turn) {
    var so = sortOf(C(turn));
    showLayer(t('sort'), '<div class="ls-stack">' + ['', 'asc', 'desc', 'new'].map(function (s) { return '<button class="ls-row" data-fsort="' + s + '" aria-pressed="' + (so === s) + '">' + (so === s ? icon('check') : '<span style="width:20px"></span>') + '<span class="ls-row__text">' + esc(sortLabel(s)) + '</span></button>'; }).join('') + '</div>', null);
    LAYER.onclick = function (e) { var s = e.target.closest('[data-fsort]'); if (s) { e.stopPropagation(); setSort(turn, s.dataset.fsort); hideLayer(); refresh(turn); } };
  }

  // ---------------------------------------------------------------- the menu: new chat, share, ask my AI, large view
  // Share and "Ask my AI" send what's on screen: the live results, the wide grid, or the newest search,
  // as filtered. With no search yet they send the store itself, as the product scripts do.
  var AI_OPEN = { chatgpt: 'https://chatgpt.com/?q={q}', claude: 'https://claude.ai/new?q={q}', gemini: 'https://gemini.google.com/app?q={q}' };
  var AIS = [['chatgpt', 'ChatGPT'], ['claude', 'Claude'], ['gemini', 'Gemini']];
  function shareCtx() {
    var turn = (S.live && SUG.classList.contains('is-on') && S.live) || (S.wide && turnById(S.wide)) ||
      S.turns.slice().reverse().filter(function (x) { return (x.kind === 'results' || x.kind === 'similar') && x.ids && x.ids.length; })[0] || null;
    if (turn && (turn.kind === 'compare' || !turn.ids)) { turn = null; }
    var q = !turn ? '' : turn.kind === 'similar' ? decode((rec(turn.of) || {}).title || '').split('|')[0].trim() : turn.q;
    return { q: q || '', ids: turn ? filtered(turn).slice(0, 24).map(function (r) { return pid(r.id); }) : [], turn: turn };
  }
  // the store itself, opening this search with the filters chosen: the link format the product scripts
  // made, so links already shared (and the AI connector's) open the same way (?la_q=…&la_f=…)
  var SORT_LINK = { asc: 'price:asc', desc: 'price:desc' };
  function shareLink(cx) {
    var home = CFG.homeUrl || (win.location.origin + '/');
    if (!cx.q) { return home; }
    var c = cx.turn && cx.turn.kind === 'results' ? C(cx.turn) : null, f = [];
    if (c) {
      Object.keys(c.f).forEach(function (code) { var x = valueOf(Number(code)); if (x && x.value) { f.push(x.facet.k + '.' + x.value.k); } });
      if (c.sale) { f.push('sale'); }
      if (c.stock) { f.push('stock'); }
      if (SORT_LINK[c.sort]) { f.push('sort.' + SORT_LINK[c.sort]); }
    }
    return home + (home.indexOf('?') >= 0 ? '&' : '?') + 'la_q=' + encodeURIComponent(cx.q) + (f.length ? '&la_f=' + encodeURIComponent(f.join(',')) : '');
  }
  // the filters a shared link names, chosen before its results show (facets by key, as the index names them)
  function cFromLink(list) {
    var c = blankC();
    String(list || '').split(',').map(function (k) { return k.trim(); }).filter(Boolean).forEach(function (k) {
      if (k === 'stock' || k === 'sale') { c[k] = true; return; }
      if (k.indexOf('sort.') === 0) { var v = k.slice(5); c.sort = v === 'price:asc' ? 'asc' : v === 'price:desc' ? 'desc' : v === 'new' ? 'new' : ''; return; }
      if (/^\d+$/.test(k)) { c.f[k] = true; return; }
      var dot = k.indexOf('.'), key = k.slice(0, dot), val = k.slice(dot + 1);
      (S.facets || []).forEach(function (facet, at) { if (facet.k === key) { (facet.v || []).forEach(function (v, i) { if (v.k === val) { c.f[at * 1000 + i] = true; } }); } });
    });
    return c;
  }
  // the AI's link: from the server (a snapshot it reads), or, when that fails, the store's own link
  var SHARES = {};
  function shareFor(cx) {
    var key = cx.q + '|' + cx.ids.join(',') + (cx.asked ? '|asked' : '');
    if (!SHARES[key]) {
      // text/plain, so the request needs no preflight; the body is JSON either way
      SHARES[key] = json(API + '/search/' + SITE + '/share', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ q: cx.q, ids: cx.ids, locale: LANG, asked: !!cx.asked }) }).then(function (r) {
        var d = r.ok && r.body && (r.body.data || r.body);
        if (d && d.prompt && d.open) { return d; }
        throw new Error('share');
      }).catch(function () {
        delete SHARES[key];
        var url = shareLink(cx), words = cx.asked ? t('aiPromptQ', { q: cx.q, store: S.config.shopName || '', url: url }) : t('aiPrompt', { store: S.config.shopName || '', url: url }), open = {};
        Object.keys(AI_OPEN).forEach(function (k) { open[k] = AI_OPEN[k].replace('{q}', encodeURIComponent(words)); });
        return { url: url, prompt: words, open: open };
      });
    }
    return SHARES[key];
  }
  function copyText(text, done) {
    var p = win.navigator.clipboard && win.navigator.clipboard.writeText ? win.navigator.clipboard.writeText(text) : Promise.reject();
    p.then(done, function () { win.prompt(t('aiCopy'), text); });
  }
  function menuRow(attrs, ic, label, end) { return '<button class="ls-row" role="menuitem" ' + attrs + '>' + ic + '<span class="ls-row__text">' + esc(label) + '</span>' + (end || '') + '</button>'; }
  function menuHtml() {
    var cx = shareCtx(), whole = !cx.q, chev = icon('fwd', 'ls-icon--sm ls-menu__chev');
    var back = function (title) { return '<button class="ls-menu__back" data-menu-view="">' + icon('back', 'ls-icon--sm') + '<span>' + esc(title) + '</span></button>'; };
    var about = whole ? '' : '<span class="ls-menu__for" dir="auto">' + esc(t('aiFor', { q: cx.q, n: cx.ids.length })) + '</span>';
    if (S.menu === 'ai') {
      return back(t('aiAsk')) + '<p class="ls-menu__help">' + esc(whole ? t('aiStoreHelp') : t('aiHelp')) + about + '</p>' +
        AIS.map(function (a) { return menuRow('data-ai="' + a[0] + '"', '<span class="ls-menu__logo">' + logo(a[0]) + '</span>', a[1], chev); }).join('') +
        menuRow('data-ai="copy"', '<span class="ls-menu__logo">' + icon('link', 'ls-icon--sm') + '</span>', t('aiCopy')) + '<div class="ls-menu__note" role="status" data-menu-note></div>';
    }
    if (S.menu === 'share') {
      return back(t(whole ? 'shareStore' : 'shareSearch')) + '<p class="ls-menu__help">' + esc(t(whole ? 'shareStoreHelp' : 'shareHelp')) + (whole ? '' : '<span class="ls-menu__for" dir="auto">"' + esc(cx.q) + '"</span>') + '</p>' +
        menuRow('data-share="whatsapp"', '<span class="ls-menu__logo ls-menu__logo--wa">' + icon('whatsapp', 'ls-icon--sm') + '</span>', 'WhatsApp') +
        menuRow('data-share="link"', '<span class="ls-menu__logo">' + icon('link', 'ls-icon--sm') + '</span>', t('aiCopy')) +
        (typeof win.navigator.share === 'function' ? menuRow('data-share="more"', '<span class="ls-menu__logo">' + icon('share', 'ls-icon--sm') + '</span>', t('shareMore')) : '') + '<div class="ls-menu__note" role="status" data-menu-note></div>';
    }
    var aiOn = !off(whole ? 'aiAsk' : 'aiShare'), shareOn = !off('shareResults');
    return menuRow('data-new', icon('history'), t('newChat')) + (aiOn || shareOn ? '<div class="ls-menu__sep"></div>' : '') +
      (shareOn ? menuRow('data-menu-view="share"', icon('share'), t(whole ? 'shareStore' : 'shareSearch'), chev) : '') +
      (aiOn ? menuRow('data-menu-view="ai"', icon('spark'), t('aiAsk'), '<span class="ls-menu__logos">' + AIS.map(function (a) { return logo(a[0]); }).join('') + '</span>' + chev) : '') +
      (isPhone() ? '' : '<div class="ls-menu__sep"></div>' + menuRow('data-expand role="menuitemcheckbox" aria-checked="' + S.expanded + '"', icon('expand'), t('bigView'), S.expanded ? icon('check', 'ls-icon--sm') : ''));
  }
  function showMenu(view) {
    S.menu = view || '';
    MENU.innerHTML = menuHtml();
    MENU.hidden = false; MENU.classList.toggle('is-sub', !!S.menu);
    D.querySelector('[data-menu]').setAttribute('aria-expanded', 'true');
    var first = MENU.querySelector(S.menu ? '[data-ai], [data-share]' : 'button');
    if (first) { first.focus({ preventScroll: true }); }
  }
  function hideMenu() { if (MENU.hidden) { return; } MENU.hidden = true; S.menu = ''; D.querySelector('[data-menu]').setAttribute('aria-expanded', 'false'); }
  function menuNote(text) { var n = MENU.querySelector('[data-menu-note]'); if (n) { n.textContent = text; } }
  // A question that asks for planning, quantities or a comparison: long, or in those words. Offered, besides
  // our answer, to the shopper's own AI with the store's whole information, to come back as an order here.
  function complexAsk(q) {
    return wordsOf(q).length >= 6 || buildAsk(q) || /(כמה צריך|כמה יחידות|כמה מטר|לפרויקט|פרויקט|מה ההבדל|להשוות|השוואה|מה עדיף|עדיף|לתכנן|תכנון|רשימת קניות|רשימה|כמות|how many|how much|compare|difference|which is better|project|plan)/i.test(q);
  }
  // "תבנה לי הזמנה לבניית פרגולה": a thing to build or assemble, or an order put together for it
  var BUILD = /(^|\s)(תבנה|תבני|תרכיב|תרכיבי|בנה|בני|לבנות|בניית|לבניית|לבנייה|להרכיב|הרכבה|להרכבת|build|assemble|put together)(\s|$)|הזמנה (ל|עבור)|order for/i;
  function buildAsk(q) { return BUILD.test(String(q || '')); }
  // The store's own ready-made kit of what is to be built: a product named kit / set / ערכה with every word that
  // names the thing ("קיט פרגולה" for "הזמנה לבניית פרגולה"). Words of the store's catalogue only, so any shop.
  var KIT = /(^|[^\p{L}])(קיט|ערכה|ערכת|סט|kit|set)([^\p{L}]|$)/iu;
  var PLAN_WORDS = /^(תבנה|תבני|תרכיב|תרכיבי|בנה|בני|לבנות|בניית|לבניית|לבנייה|להרכיב|הרכבה|להרכבת|הזמנה|להזמין|לי|לנו|עבור|בשביל|צריך|רוצה|רוצים|build|assemble|put|together|order|me|us|need|want|kit|set|קיט|ערכה|סט)$/i;
  function kitsFor(q) {
    if (!buildAsk(q)) { return []; }
    var thing = wordsOf(q).filter(function (w) { return w.length >= 2 && !STOP.test(w) && !PLAN_WORDS.test(w) && !PLAN_WORDS.test(stem(w)); });
    if (!thing.length) { return []; }
    var found = Object.keys(S.records).map(function (k) { return S.records[k]; }).filter(function (r) {
      return r && r.t === 'product' && KIT.test(r.title || '') && thing.every(function (w) { return says(r.title + ' ' + (r.kw || ''), w) || says(r.title + ' ' + (r.kw || ''), stem(w)); });
    });
    found.sort(function (a, b) { return (b.s === 0 ? 0 : 1) - (a.s === 0 ? 0 : 1); });
    return found.slice(0, 3).map(function (r) { return r.id; });
  }
  function kitsHtml(turn) {
    if (turn.kits === undefined) { turn.kits = kitsFor(turn.q); }
    var list = (turn.kits || []).map(rec).filter(Boolean);
    return list.length ? '<div class="ls-kit"><div class="ls-block__title" style="margin-bottom:6px">' + esc(t('kitTitle')) + '</div>' + list.map(prow).join('') + '</div>' : '';
  }
  function myAi(turn) {
    var ask = S.config.aiAsk;
    if (!turn || !turn.q || !ask || !ask.open || off('aiShare') || !(turn.advice || turn.aiOpen || complexAsk(turn.q))) { return ''; }
    return '<div class="ls-myai"><div class="ls-myai__t">' + esc(t('myAiTitle')) + '</div><div class="ls-myai__s">' + esc(t('myAiSub')) + '</div><div class="ls-myai__btns">' +
      ['chatgpt', 'claude', 'gemini'].filter(function (k) { return ask.open[k]; }).map(function (k) { return '<button class="ls-btn ls-btn--secondary ls-btn--sm" data-ai-ask="' + k + '" data-turn="' + turn.id + '">' + logo(k) + esc({ chatgpt: 'ChatGPT', claude: 'Claude', gemini: 'Gemini' }[k]) + '</button>'; }).join('') +
      '</div>' + geminiHtml(turn.id) + '</div>';
  }
  // the question and the products of its answer, handed to the shopper's own AI
  // "Not sure what fits?" under a search's results, as the shop set it (search.not_sure): its AI recommends among
  // them, by the search, with the outside AIs under the answer (inside); or straight to the outside AIs (outside)
  function notSureMax() { return Number(S.config.notSureMax) || 6; }
  // Inside, a step first: what to ask about these results (as Aviad asked, 2026-10-21): about the one product found, by
  // its name (what it is, who it is for, what to know before buying), or among several (the difference, which suits,
  // which is worth it), or the shopper's own question, never always "which is best". In any shop: a book, a ring, a shirt.
  function notSure(rt) {
    if (!rt) { return; }
    if (S.config.notSure === 'outside' && S.config.aiAsk && S.config.aiAsk.open) { rt.aiOpen = !rt.aiOpen; render(); return; }
    rt.askOpen = !rt.askOpen;
    refresh(rt);
    if (rt.askOpen && !isPhone()) { var input = BODY.querySelector('[data-ask-about-form="' + rt.id + '"] input') || SUGIN.querySelector('[data-ask-about-form="' + rt.id + '"] input'); if (input) { input.focus({ preventScroll: true }); } }
  }
  function aboutResults(rt) { return filtered(rt).slice(0, notSureMax()); }
  // a product's name, short: the part before its code or its details ("ביבי: סיפור חיי", not "… - מק"ט 52220, זהב 14K")
  function shortName(r) { return decode(r.title || '').split(/\s[-–|]\s|,/)[0].trim().slice(0, 60); }
  function askAboutHtml(rt) {
    var list = aboutResults(rt), one = list.length === 1 ? shortName(list[0]) : '';
    var asks = one ? [t('askOneBrief', { t: one }), t('askOneWho', { t: one }), t('askOneKnow', { t: one })] : [t('askManyDiff'), t('askManyBest'), t('askManyWorth')];
    return '<div class="ls-askabout ls-focus-in"><div class="ls-follow">' + asks.map(function (q) {
      return '<button class="ls-chip ls-chip--quiet" data-ask-about="' + esc(rt.id) + '" data-question="' + esc(q) + '">' + esc(q) + '</button>';
    }).join('') + '</div><form class="ls-askabout__form" data-ask-about-form="' + esc(rt.id) + '"><label class="ls-field ls-field--sm">' +
      '<input type="text" enterkeyhint="send" maxlength="300" dir="' + DIR + '" placeholder="' + esc(t('askOwn')) + '" aria-label="' + esc(t('askOwn')) + '">' +
      '<button class="ls-ibtn ls-ibtn--solid ls-ibtn--sm" aria-label="' + esc(t('send')) + '">' + icon('send', 'ls-icon--sm') + '</button></label></form></div>';
  }
  // the question goes to the store's AI with the products it is about
  function askAbout(rt, question) {
    var q = String(question || '').trim();
    if (!rt || !q) { return; }
    var ids = aboutResults(rt).map(function (r) { return pid(r.id); });
    rt.askOpen = false;
    askTurn(q, { products: ids }, false, { advice: ids });
  }
  // Gemini opens an empty chat whatever its address says, and a page that has just opened another tab may no longer
  // write to the clipboard. So Gemini takes two presses: the first readies the words and says what comes next; the
  // second copies them while this page still has the focus, then opens Gemini to paste them into.
  var GEMINI = null;
  function geminiReady(ready, where) {
    ready.then(function (d) {
      GEMINI = { prompt: d.prompt, url: d.open.gemini, where: where, copied: false };
      if (where === 'menu') { var n = MENU.querySelector('[data-menu-note]'); if (n) { n.innerHTML = geminiHtml('menu'); } } else { render(); }
    }, function () { if (where === 'menu') { menuNote(t('aiFailed')); } else { toast(t('aiFailed')); } });
  }
  function geminiHtml(where) {
    if (!GEMINI || GEMINI.where !== where) { return ''; }
    // copied, and the browser kept the tab from opening: a plain link opens it, no clipboard needed
    var go = GEMINI.copied ? '<a class="ls-btn ls-btn--primary ls-btn--sm" href="' + esc(GEMINI.url) + '" target="_blank" rel="noopener">' + logo('gemini') + esc(t('geminiOpen')) + '</a>'
      : '<button class="ls-btn ls-btn--primary ls-btn--sm" data-gemini-go>' + logo('gemini') + esc(t('geminiGo')) + '</button>';
    return '<div class="ls-gemini"><p class="ls-gemini__how">' + esc(t(GEMINI.copied ? 'aiPaste' : 'geminiHow')) + '</p>' + go + '</div>';
  }
  function geminiGo() {
    var g = GEMINI;
    if (!g) { return; }
    var open = function () {
      var w = win.open(g.url, '_blank');
      if (w) { try { w.opener = null; } catch (e) { /* fine */ } toast(t('aiPaste')); return; }
      g.copied = true;
      if (g.where === 'menu') { var n = MENU.querySelector('[data-menu-note]'); if (n) { n.innerHTML = geminiHtml('menu'); } } else { render(); }
    };
    var p = win.navigator.clipboard && win.navigator.clipboard.writeText ? win.navigator.clipboard.writeText(g.prompt) : Promise.reject();
    p.then(open, function () { win.prompt(t('geminiCopy'), g.prompt); open(); });
  }  function askAiAbout(turn, choice) {
    if (!turn) { return; }
    // what the answer picked; else the results it was asked about (advice), or the results themselves
    var ids = (turn.picks && turn.picks.length ? turn.picks.map(function (p) { return pid(p.id); }) : turn.advice || (turn.kind === 'results' ? filtered(turn).map(function (r) { return pid(r.id); }) : [])).slice(0, 24);
    var ready = shareFor({ q: turn.q, ids: ids, turn: null, asked: true });
    if (choice === 'gemini') { geminiReady(ready, turn.id); return; }
    var tab = win.open('about:blank', '_blank');
    ready.then(function (data) {
      if (tab) { try { tab.opener = null; } catch (e) { /* fine */ } tab.location.href = data.open[choice]; } else { win.location.href = data.open[choice]; }
    }, function () { if (tab) { tab.close(); } toast(t('aiFailed')); });
  }
  function askAi(choice) {
    var cx = shareCtx();
    // the tab opens inside the press, so no popup blocker stops it, and goes where it should once the link is ready
    var ready = shareFor(cx);
    if (choice === 'gemini') { geminiReady(ready, 'menu'); return; }
    var tab = choice === 'copy' ? null : win.open('about:blank', '_blank');
    ready.then(function (data) {
      if (choice === 'copy') { copyText(data.prompt, function () { menuNote(t('aiCopied')); }); return; }
      if (tab) { try { tab.opener = null; } catch (e) { /* fine */ } tab.location.href = data.open[choice]; } else { win.location.href = data.open[choice]; }
    }, function () { if (tab) { tab.close(); } menuNote(t('aiFailed')); });
  }
  function shareGo(choice) {
    var cx = shareCtx(), link = shareLink(cx), shop = S.config.shopName || '';
    var said = cx.q ? t('shareText', { store: shop, q: cx.q }) : t('shareStoreText', { store: shop });
    if (choice === 'whatsapp') { var w = win.open('https://wa.me/?text=' + encodeURIComponent(said + ' ' + link), '_blank'); if (w) { try { w.opener = null; } catch (e) { /* fine */ } } return; }
    if (choice === 'more') { win.navigator.share({ title: t(cx.q ? 'shareSearch' : 'shareStore'), text: said, url: link }).catch(function () { /* closed */ }); return; }
    copyText(link, function () { menuNote(t('shareCopied')); });
  }
  // large view on a desktop: the side of the drawer, or the menu, makes it wide; again makes it narrow
  function toggleExpand() {
    if (S.wide) { narrow(); S.expanded = false; } else { S.expanded = !S.expanded; }
    store('local', 'ls_expanded', S.expanded || null);
    render();
    if (S.live) { renderLive(false); }
  }
  function narrow() {
    var was = turnById(S.wide);
    S.wide = null;
    if (was && was.kind === 'compare') { S.turns = S.turns.filter(function (x) { return x !== was; }); S.tab = 'saved'; }
  }

  // ---------------------------------------------------------------- layer + toast
  var layerReturn = null;
  function showLayer(title, body, foot) {
    layerReturn = root.activeElement;
    LAYER.hidden = false; LSCRIM.hidden = false;
    LAYER.innerHTML = '<div class="ls-sheet__handle" style="display:block"></div><div class="ls-layer__head"><div class="ls-layer__title">' + esc(title) + '</div><button class="ls-ibtn" aria-label="' + esc(t('close')) + '" data-layer-close>' + icon('x') + '</button></div><div class="ls-layer__body ls-scroll">' + body + '</div>' + (foot === null ? '' : '<div class="ls-layer__foot">' + (foot || '') + '</div>');
    LAYER.setAttribute('aria-label', title);
    LAYER.setAttribute('tabindex', '-1');
    requestAnimationFrame(function () { LAYER.classList.add('is-on'); LSCRIM.classList.add('is-on'); LAYER.focus({ preventScroll: true }); });
  }
  function hideLayer() {
    if (LAYER.hidden) { return; }
    LAYER.classList.remove('is-on'); LSCRIM.classList.remove('is-on'); LAYER.onclick = null;
    setTimeout(function () { if (!LAYER.classList.contains('is-on')) { LAYER.hidden = true; LSCRIM.hidden = true; } }, 380);
    if (layerReturn && layerReturn.focus) { try { layerReturn.focus({ preventScroll: true }); } catch (e) { /* gone */ } }
  }
  var toastTimer = null;
  function toast(text, undo) {
    TOAST.hidden = false;
    TOAST.innerHTML = '<span>' + esc(text) + '</span>' + (undo ? '<button data-undo>' + esc(t('undo')) + '</button>' : '');
    TOAST.onclick = function (e) { if (e.target.closest('[data-undo]')) { TOAST.hidden = true; undo(); } };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { TOAST.hidden = true; }, 4200);
  }

