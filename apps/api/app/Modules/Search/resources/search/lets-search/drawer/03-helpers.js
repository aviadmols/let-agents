  // ---------------------------------------------------------------- tiny helpers
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c]; }); }
  function decode(s) { var d = doc.createElement('textarea'); d.innerHTML = String(s || ''); return d.value; }
  function icon(n, cls) { if (n === 'fwd' || n === 'back') { cls = (cls || '') + ' ls-icon--dir'; } return '<svg class="ls-icon ' + (cls || '') + '" aria-hidden="true"><use href="#ls-i-' + n + '"/></svg>'; }
  function mark(think) { return '<span class="ls-mark' + (think ? ' ls-mark--think' : '') + '" aria-hidden="true"><i></i><i></i></span>'; }
  // a price as the store writes it: Shopify's money format (or, browsing in another currency, the browser's),
  // WooCommerce's prefix and suffix from its Store API; ₪ until the store has said
  function money(n) {
    if (n == null || isNaN(n)) { return ''; }
    var e = win.LA_ENGINE;
    if (SHOPIFY && e && e.formatMoney && (CFG.moneyFormat || CFG.currency)) {
      var foreign = CFG.currency && CFG.shopCurrency && CFG.currency !== CFG.shopCurrency;
      try { return e.formatMoney(Math.round(Number(n) * 100), foreign ? '' : CFG.moneyFormat, CFG.currency || 'ILS', LANG); } catch (x) { /* plain */ }
    }
    var text = Number(n).toLocaleString(LANG === 'he' ? 'he-IL' : 'en-US', { maximumFractionDigits: 2 }), cur = S && (S.cur || storeCurrency());
    return cur ? (cur.prefix || '') + text + (cur.suffix || '') : '₪' + text;
  }
  // the store's own currency, as the index says it (a site with no Store API: a content site, a demo), its symbol
  // where the language puts it; null for shekels or none, so ₪ stays as it was
  var CUR_AFFIX = {};
  // "13 products", or on a site that tells about what it offers ("Read more": tours, courses) "13 items"
  function countText(n) {
    var items = S.config && S.config.cardAction === 'read_more';
    return n === 1 ? t(items ? 'oneItem' : 'oneProduct') : t(items ? 'itemsCount' : 'productsCount', { n: n });
  }
  function storeCurrency() {
    var code = S && S.config && S.config.currency;
    if (!code || code === 'ILS') { return null; }
    if (CUR_AFFIX[code] !== undefined) { return CUR_AFFIX[code]; }
    try {
      var parts = new Intl.NumberFormat(LANG === 'he' ? 'he-IL' : 'en-US', { style: 'currency', currency: code, currencyDisplay: 'narrowSymbol' }).formatToParts(1);
      var at = parts.findIndex(function (p) { return p.type === 'currency'; }), num = parts.findIndex(function (p) { return p.type === 'integer'; });
      var sym = at >= 0 ? parts[at].value : code;
      CUR_AFFIX[code] = at >= 0 && at > num ? { prefix: '', suffix: ' ' + sym } : { prefix: sym, suffix: '' };
    } catch (x) { CUR_AFFIX[code] = { prefix: code + ' ', suffix: '' }; }
    return CUR_AFFIX[code];
  }
  function store(kind, key, val) {
    try {
      var s = kind === 'local' ? win.localStorage : win.sessionStorage;
      if (val === undefined) { var v = s.getItem(key); return v ? JSON.parse(v) : null; }
      if (val === null) { s.removeItem(key); } else { s.setItem(key, JSON.stringify(val)); }
    } catch (e) { return null; }
    return null;
  }
  function uid() { return Math.random().toString(36).slice(2, 10); }
  // A request that never ends (a connection dropped while the server switched to a new version, Story 25.10: "מחפש
  // בחנות…" for good) is given up after a while (opts.wait, ms): the turn shows its error and "try again", and again()
  // asks once more first. A question to the AI waits longer than a search.
  function json(url, opts) {
    opts = Object.assign({ credentials: 'omit' }, opts || {});
    var wait = opts.wait || 30000, ctl = win.AbortController ? new win.AbortController() : null, timer = null;
    delete opts.wait;
    if (ctl) { opts.signal = ctl.signal; timer = setTimeout(function () { ctl.abort(); }, wait); }
    return win.fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (body) { clearTimeout(timer); return { ok: r.ok, status: r.status, body: body }; });
    }, function (e) { clearTimeout(timer); throw e; });
  }
  // A question's request cut off on the way (a network blip, the server switching to a new version): asked once
  // more a moment later. The answer, if it was written meanwhile, is kept there and comes back at once.
  // what the server said when it is not "not found": the limit of the day, a question not the store's, unavailable
  function saidOf(d) { return d && d.answer && d.outcome !== 'no_info' && d.outcome !== 'no_match' ? String(d.answer) : null; }
  function again(send) {
    return send().catch(function () { return new Promise(function (go) { setTimeout(go, 1500); }).then(send); });
  }
  function post(url, body) { return json(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), wait: 90000 }); }
  var API = String(CFG.api).replace(/\/$/, '');
  var SITE = encodeURIComponent(CFG.site);
  function words(text, from) { var i = from || 0; return esc(text).split(' ').map(function (w) { return '<span class="ls-w" style="--i:' + (i++) + '">' + w + ' </span>'; }).join(''); }
  // An answer as it reads best: its own lines kept (a line that starts with "-", "•" or "1." is a list item);
  // a long answer in one block, a sentence a line, and a sentence of parts joined by ";" a list of them
  function answerBlocks(text) {
    var item = /^(?:[-•*]|\d{1,2}[.)])\s+/;
    // a writer's markdown is no markup here: "**חיזוק הגוף**" reads as its words (answers kept from before the server
    // took it off too)
    text = String(text || '').replace(/(\*\*|__)(?=\S)([\s\S]*?\S)\1/g, '$2').replace(/\*\*/g, '');
    var lines = String(text || '').split(/\n+/).map(function (l) { return l.trim(); }).filter(Boolean);
    if (lines.length === 1 && lines[0].length > 200) {
      lines = lines[0].replace(/([.!?])\s+(?=\S)/g, '$1\n').split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
    }
    var blocks = [];
    lines.forEach(function (line) {
      var parts = line.split(/;\s+/);
      if (item.test(line)) { var last = blocks[blocks.length - 1]; if (last && last.list) { last.list.push(line.replace(item, '')); } else { blocks.push({ list: [line.replace(item, '')] }); } return; }
      if (parts.length >= 2 && line.length > 120) { blocks.push({ text: parts[0] }); blocks.push({ list: parts.slice(1) }); return; }
      blocks.push({ text: line });
    });
    return blocks;
  }
  // "לפי העמוד שלה", "according to its page": the words lead to that page (as Aviad asked, 2026-10-25). The page is
  // the one the answer names just before them, else the first it rests on.
  var PAGE_SAID = /(?:לפי|על[ -]פי|כפי שכתוב ב|כמו שכתוב ב)\s?(?:ה)?(?:עמודים|עמוד|דף)(?:\s(?:המוצר|שלה|שלו|שלנו|שלהם|באתר))*|according to (?:its|the|our) (?:product )?pages?/i;
  // an answer that stops in the middle of a sentence ("…local travel professio", kept in this browser from before) ends at its
  // last whole sentence, where that keeps half of it or more: as the server ends it (AnswerSiteQuestion::endsWhole)
  function endsWhole(s) {
    var t = String(s || '').replace(/\s+$/, ''), re = /[.!?…](?=\s|$)/g, m, last = -1;
    if (t.length < 300 || /[.!?…:)"״'׳”»\]]$/.test(t)) { return s; }
    while ((m = re.exec(t))) { last = m.index + 1; }
    return last >= t.length * 0.5 ? t.slice(0, last) : s;
  }
  function answerText(text, anim, pages) {
    var n = 0, said = '', plain = function (s) { var h = anim ? words(s, n) : esc(s); n += String(s).split(' ').length; return h; };
    var out = function (s) {
      var m = pages && pages.length ? PAGE_SAID.exec(s) : null, before = m ? s.slice(0, m.index) : s;
      if (!m) { said += s + ' '; return plain(s); }
      var near = (said + before).slice(-160).toLowerCase(), page = pages.filter(function (p) { return near.indexOf(String(p.title).toLowerCase()) >= 0; })[0] || pages[0];
      said += before + m[0];
      return plain(before) + '<a class="ls-answer__page-link" href="' + esc(page.url) + '">' + plain(m[0]) + '</a>' + out(s.slice(m.index + m[0].length));
    };
    return answerBlocks(endsWhole(text)).map(function (b) { return b.list ? '<ul class="ls-answer__list">' + b.list.map(function (s) { return '<li>' + out(s) + '</li>'; }).join('') + '</ul>' : '<p>' + out(b.text) + '</p>'; }).join('');
  }

