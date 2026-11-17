  // ---------------------------------------------------------------- tiny helpers
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c]; }); }
  // A title with its HTML entities read ("&amp;" is "&"), once per title: the cards, the filters and the reading of the
  // words ask it of the same products on every key (Rocks, 1.11: a second of a phone's time over two searches).
  // memo(): a value worked out once per input, forgotten all at once past MEMO_MAX inputs.
  var MEMO_MAX = 5000;
  function memo(fn) {
    var kept = {}, n = 0;
    return function (s) {
      var k = String(s == null ? '' : s);
      if (Object.prototype.hasOwnProperty.call(kept, k)) { return kept[k]; }
      if (++n > MEMO_MAX) { kept = {}; n = 1; }
      return (kept[k] = fn(k));
    };
  }
  // A page's or a picture's address as the drawer uses it: a web address (http, https, protocol-relative) or a path on
  // this site; anything else ("javascript:…", "data:…") is no address and becomes '' (1.11: a demo of any site could put
  // a script behind a product's link).
  function safeUrl(u) {
    var s = String(u == null ? '' : u).trim();
    return /^(https?:)?\/\/[^\s]/i.test(s) || /^\/(?!\/)/.test(s) ? s : '';
  }
  var decode = memo(function (s) { var d = doc.createElement('textarea'); d.innerHTML = s; return d.value; });
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
    // two decimals unless whole ("202.46", "99.50", "250"), as the assistant writes a price (Money::price)
    var whole = Number(n) === Math.floor(Number(n));
    var text = Number(n).toLocaleString(LANG === 'he' ? 'he-IL' : 'en-US', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2 }), cur = S && (S.cur || storeCurrency());
    return cur ? (cur.prefix || '') + text + (cur.suffix || '') : '₪' + text;
  }
  // How the cards write a price, for the assistant to write its prices the same (Naot QA, 16.11: "AUD 202.46" over a card
  // of "$202"): the currency's code, and the sign money() puts beside a number, before it or after it
  function priceLike() {
    var code = String((SHOPIFY && CFG.currency) || (S && S.config && S.config.currency) || 'ILS').toUpperCase();
    var shown = String(money(1) || '').replace(/[\u200e\u200f\u00a0]/g, ' ').trim(), m = /^(.*?)\s*1(?:[.,]0+)?\s*(.*?)$/.exec(shown);
    if (!m || (!m[1] && !m[2]) || (m[1] && m[2])) { return { currency: code }; }
    return { currency: code, currency_sign: (m[1] || m[2]).trim(), sign_after: !m[1] };
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
  // Each site's own: every demo is on one address (agents.lets.co.il), and one's saved list, searches and conversation
  // were another's (Naot QA, 15.11: "Saved 3" over an empty list). A key already a site's (its look) stays as it is.
  function siteKey(key) { return key.indexOf('ls_look_') === 0 ? key : key + '@' + String(CFG.site || ''); }
  function store(kind, key, val) {
    try {
      var s = kind === 'local' ? win.localStorage : win.sessionStorage, k = siteKey(key);
      if (val === undefined) {
        var v = s.getItem(k);
        // what a store's shoppers kept before it was kept apart, carried over once: never on our demo address, where it
        // was another demo's
        if (v === null && !CFG.demo) { v = s.getItem(key); if (v !== null) { s.setItem(k, v); } }
        return v ? JSON.parse(v) : null;
      }
      if (val === null) { s.removeItem(k); } else { s.setItem(k, JSON.stringify(val)); }
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
      // too many requests from this address (a shop's visitors behind one network, a busy minute): once more a moment later,
      // never shown as "nothing found" (Rocks, 17.11: "0 similar items" was a refusal)
      if (r.status === 429 && !opts._again && (!opts.method || opts.method === 'GET')) {
        clearTimeout(timer);
        var after = Math.min(5, Math.max(1, parseInt(r.headers.get('Retry-After') || '2', 10) || 2));
        return new Promise(function (go) { setTimeout(go, after * 1000); }).then(function () { return json(url, Object.assign({}, opts, { _again: true, signal: undefined, wait: wait })); });
      }
      return r.json().catch(function () { return {}; }).then(function (body) { clearTimeout(timer); return { ok: r.ok, status: r.status, body: body }; });
    }, function (e) { clearTimeout(timer); throw e; });
  }
  // A question's request cut off on the way (a network blip, the server switching to a new version): asked once
  // more a moment later. The answer, if it was written meanwhile, is kept there and comes back at once.
  // what the server said when it is not "not found": the limit of the day, a question not the store's, unavailable
  function saidOf(d) { return d && d.answer && d.outcome !== 'no_info' && d.outcome !== 'no_match' && !teamFor(d) ? String(d.answer) : null; }
  // a question the topic check found not about the store ("אתם מחליפים סוללות?" before scope_site v10): its line, then
  // the team, as a "not found" shows it; never for harm or instructions, which get the line alone
  function teamFor(d) { return !!(d && d.outcome === 'out_of_scope' && d.reason === 'not_about_store'); }
  function notFoundOf(d) { return d ? (d.not_found || (teamFor(d) && d.answer ? String(d.answer) : null)) : null; }
  function again(send) {
    return send().catch(function () { return new Promise(function (go) { setTimeout(go, 1500); }).then(send); });
  }
  function post(url, body) { return json(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), wait: 90000 }); }
  var API = String(CFG.api).replace(/\/$/, '');
  var SITE = encodeURIComponent(CFG.site);
  function words(text, from) { var i = from || 0; return String(text).split(' ').map(function (w) { return '<span class="ls-w" style="--i:' + (i++) + '">' + ltrWord(w) + ' </span>'; }).join(''); }
  // A word of numbers, an email or a link keeps its own order inside a Hebrew line: "10:00–15:00" is not read
  // backwards, and the full stop after "info@store.co.il." still ends the Hebrew sentence (Rocks, 28.10). A short
  // one is never broken across two lines.
  var LTR_WORD = /^([("'״]*)([^\s֐-׿؀-ۿ]*?)([.,;:!?)"'״]*)$/;
  // an email address in an answer, with whatever is joined to it ("ל־info@store.co.il."): to press, as Aviad asked (15.11)
  var EMAIL_WORD = /^(.*?)([A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,})([.,;:!?)"'״]*)$/;
  // a phone number in an answer, with whatever is joined to it ("ל־054-4600563", "ל'054-3979035'."): to press, and never in
  // the quote marks a writer put around it (Rocks QA, 17.11). A number that opens with 0 or + and has nine to thirteen digits:
  // never a price, a code or a year.
  var PHONE_WORD = /^(.*?)['"׳״‘’“”]?((?:\+\d|0)[\d-]{7,14}\d)['"׳״‘’“”]?([.,;:!?)"'״]*)$/;
  function ltrWord(w) {
    var ph = PHONE_WORD.exec(w), digits = ph ? ph[2].replace(/\D/g, '') : '';
    if (ph && digits.length >= 9 && digits.length <= 13 && !/\d$/.test(ph[1])) { return esc(ph[1]) + '<bdi dir="ltr" class="ls-nb"><a class="ls-answer__page-link" href="tel:' + (ph[2].charAt(0) === '+' ? '+' : '') + digits + '">' + esc(ph[2]) + '</a></bdi>' + esc(ph[3]); }
    var e = EMAIL_WORD.exec(w);
    if (e) { return esc(e[1]) + '<bdi dir="ltr"><a class="ls-answer__page-link" href="mailto:' + esc(e[2]) + '">' + esc(e[2]) + '</a></bdi>' + esc(e[3]); }
    var m = LTR_WORD.exec(w);
    // a link a store's own word gave ("https://maps.app.goo.gl/…"): to press, by the name of its site
    if (m && /^https?:\/\/[^/]/i.test(m[2])) { return esc(m[1]) + '<bdi dir="ltr"><a class="ls-answer__page-link" href="' + esc(m[2]) + '" target="_blank" rel="noopener">' + esc(m[2].replace(/^https?:\/\/(?:www\.)?/i, '').split(/[/?#]/)[0]) + '</a></bdi>' + esc(m[3]); }
    return m && m[2] && /[0-9@]|:\/\//.test(m[2]) ? esc(m[1]) + '<bdi dir="ltr"' + (m[2].length <= 24 ? ' class="ls-nb"' : '') + '>' + esc(m[2]) + '</bdi>' + esc(m[3]) : esc(w);
  }
  function ltrWords(text) { return String(text).split(' ').map(ltrWord).join(' '); }
  // An answer as it reads best: its own lines kept (a line that starts with "-", "•" or "1." is a list item);
  // an answer of two sentences or more in one block, a sentence a line (but a short one, read at a glance), and a
  // sentence of parts joined by ";" a list of them
  function answerBlocks(text) {
    var item = /^(?:[-•*]|\d{1,2}[.)])\s+/;
    // a writer's markdown is no markup here: "**חיזוק הגוף**" reads as its words (answers kept from before the server
    // took it off too)
    text = String(text || '').replace(/(\*\*|__)(?=\S)([\s\S]*?\S)\1/g, '$2').replace(/\*\*/g, '');
    var lines = String(text || '').split(/\n+/).map(function (l) { return l.trim(); }).filter(Boolean);
    if (lines.length === 1 && lines[0].length > 110) {
      lines = lines[0].replace(/([.!?])\s+(?=\S)/g, '$1\n').split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
    }
    var blocks = [];
    lines.forEach(function (line) {
      var parts = line.split(/;\s+/);
      if (item.test(line)) { var last = blocks[blocks.length - 1]; if (last && last.list) { last.list.push(line.replace(item, '')); } else { blocks.push({ list: [line.replace(item, '')] }); } return; }
      // a list of two or more after its lead; one ";" is a sentence, never a lead cut short and a lone bullet ("…for full
      // credit" and "• exchanges are accepted within 60 days": Jean Rachel QA, 16.11)
      if (parts.length >= 3 && line.length > 120) { blocks.push({ text: parts[0] }); blocks.push({ list: parts.slice(1) }); return; }
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
    if (t.length < 300 || /[.!?…:)"״'׳”»\]]$/.test(t) || /https?:\/\/\S+$/i.test(t)) { return s; }
    while ((m = re.exec(t))) { last = m.index + 1; }
    return last >= t.length * 0.5 ? t.slice(0, last) : s;
  }
  // an address the answer gives ("https://rocksandgold.co.il/certificate/?id="): a link that opens, its closing
  // punctuation left out of it (as Aviad asked, 11.11)
  var ADDRESS = /https?:\/\/[^\s<>"'״׳”«»]+/i;
  function answerText(text, anim, pages) {
    var n = 0, said = '', plain = function (s) { var h = anim ? words(s, n) : ltrWords(s); n += String(s).split(' ').length; return h; };
    var out = function (s) {
      var a = ADDRESS.exec(s);
      if (a) {
        var url = a[0].replace(/[.,;:!?)\]}]+$/, ''), head = out(s.slice(0, a.index));
        said += url + ' ';
        return head + '<a class="ls-answer__page-link" href="' + esc(url) + '" target="_blank" rel="noopener" dir="ltr">' + esc(url) + '</a>' + out(s.slice(a.index + url.length));
      }
      var m = pages && pages.length ? PAGE_SAID.exec(s) : null, before = m ? s.slice(0, m.index) : s;
      if (!m) { said += s + ' '; return plain(s); }
      var near = (said + before).slice(-160).toLowerCase(), page = pages.filter(function (p) { return near.indexOf(String(p.title).toLowerCase()) >= 0; })[0] || pages[0];
      said += before + m[0];
      return plain(before) + '<a class="ls-answer__page-link" href="' + esc(page.url) + '">' + plain(m[0]) + '</a>' + out(s.slice(m.index + m[0].length));
    };
    return answerBlocks(endsWhole(text)).map(function (b) { return b.list ? '<ul class="ls-answer__list">' + b.list.map(function (s) { return '<li>' + out(s) + '</li>'; }).join('') + '</ul>' : '<p>' + out(b.text) + '</p>'; }).join('');
  }

