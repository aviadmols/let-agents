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
    var text = Number(n).toLocaleString(LANG === 'he' ? 'he-IL' : 'en-US', { maximumFractionDigits: 2 }), cur = S && S.cur;
    return cur ? (cur.prefix || '') + text + (cur.suffix || '') : '₪' + text;
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
  function json(url, opts) {
    return win.fetch(url, Object.assign({ credentials: 'omit' }, opts || {})).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
    });
  }
  // A question's request cut off on the way (a network blip, the server switching to a new version): asked once
  // more a moment later. The answer, if it was written meanwhile, is kept there and comes back at once.
  // what the server said when it is not "not found": the limit of the day, a question not the store's, unavailable
  function saidOf(d) { return d && d.answer && d.outcome !== 'no_info' && d.outcome !== 'no_match' ? String(d.answer) : null; }
  function again(send) {
    return send().catch(function () { return new Promise(function (go) { setTimeout(go, 1500); }).then(send); });
  }
  function post(url, body) { return json(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }); }
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
  function answerText(text, anim, pages) {
    var n = 0, said = '', plain = function (s) { var h = anim ? words(s, n) : esc(s); n += String(s).split(' ').length; return h; };
    var out = function (s) {
      var m = pages && pages.length ? PAGE_SAID.exec(s) : null, before = m ? s.slice(0, m.index) : s;
      if (!m) { said += s + ' '; return plain(s); }
      var near = (said + before).slice(-160).toLowerCase(), page = pages.filter(function (p) { return near.indexOf(String(p.title).toLowerCase()) >= 0; })[0] || pages[0];
      said += before + m[0];
      return plain(before) + '<a class="ls-answer__page-link" href="' + esc(page.url) + '">' + plain(m[0]) + '</a>' + out(s.slice(m.index + m[0].length));
    };
    return answerBlocks(text).map(function (b) { return b.list ? '<ul class="ls-answer__list">' + b.list.map(function (s) { return '<li>' + out(s) + '</li>'; }).join('') + '</ul>' : '<p>' + out(b.text) + '</p>'; }).join('');
  }

