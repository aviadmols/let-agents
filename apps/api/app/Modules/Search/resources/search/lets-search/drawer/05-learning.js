  // ---------------------------------------------------------------- the product page's learning (as let-agents.js reported it)
  // The events the page bank's learned order, popularity, attribution, lift and reports are built on:
  // /widget/{site}/events, validated against packages/event-spec (one bad event loses its whole batch,
  // so every field here is exactly what the schema allows). A held-out visitor sees the order the
  // learning never touched, and what they do is kept out of it.
  var ABC64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  function rid(n) { var b = new Uint8Array(n), o = ''; (win.crypto || win.msCrypto).getRandomValues(b); for (var i = 0; i < n; i++) { o += ABC64.charAt(b[i] & 63); } return o; }
  var PAGE = null;     // { type: 'product'|'content', id }: set at boot
  var LEARN = { queue: [], shop: null, bank: null, holdout: false, preview: false, exposed: {}, answered: 0, offered: false, recent: null, searched: null };
  var previewKey = (function () {
    var fromUrl = null;
    try { fromUrl = new URLSearchParams(win.location.search).get('let_agents_preview'); } catch (e) { /* old browser */ }
    if (fromUrl && /^[a-f0-9]{32}$/.test(fromUrl)) { writeCookie('let_agents_preview', fromUrl, 30 * 86400); }
    var m = doc.cookie.match(/(?:^|; )let_agents_preview=([^;]*)/), key = fromUrl || (m ? decodeURIComponent(m[1]) : null);
    return /^[a-f0-9]{32}$/.test(key || '') ? key : null;
  })();
  function writeCookie(name, value, maxAge) {
    try { doc.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; SameSite=Lax' + (win.location.protocol === 'https:' ? '; Secure' : ''); } catch (e) { /* none */ }
  }
  // the store reads let_agents_vid at checkout to link an order to what was used, so it lives in a cookie too
  function keepVisitor() { writeCookie('let_agents_vid', visitor(), 31536000); }
  // which side of the measurement this visitor is on, from their own id alone: the same side on every page
  function heldOut(percent) {
    if (!percent) { return false; }
    var v = visitor(), n = 0;
    for (var i = 0; i < v.length; i++) { n = (n * 31 + v.charCodeAt(i)) % 100000; }
    return (n % 100) < percent;
  }
  function pageCtx() {
    var p = { type: PAGE.type, path: (win.location.pathname || '/').slice(0, 512) };
    if (p.path.charAt(0) !== '/') { p.path = '/'; }
    p[PAGE.type === 'product' ? 'product_id' : 'content_id'] = String(PAGE.id);
    return p;
  }
  function eligible() {
    var ids = (LEARN.bank.sections || []).map(function (s) { return s.candidate; });
    return ids.filter(function (id, i) { return ids.indexOf(id) === i; }).slice(0, 12);
  }
  function sectionOf(candidate) { return LEARN.bank && (LEARN.bank.sections || []).filter(function (s) { return s.candidate === candidate; })[0]; }
  function learn(type, section, slot, data) {
    if (!PAGE || !LEARN.bank) { return; }
    var ev = { id: rid(22), type: type, ts: Date.now(), page: pageCtx() };
    if (section) {
      if (!eligible().length) { return; }   // the schema needs at least one: an event without would lose its batch
      ev.candidate = { id: section.candidate, version: LEARN.bank.bank_version, model: section.model, slot: slot || 'panel' };
      ev.bank_version = LEARN.bank.bank_version;
      ev.eligible = eligible();
      ev.bucket = section.candidate === 'compare' ? 'same_family_seen' : 'none';
    }
    if (data) { ev.data = data; }
    LEARN.queue.push(ev);
    if (LEARN.queue.length >= 20) { learnFlush(false); }
  }
  function learnFlush(leaving) {
    if (!LEARN.shop || !LEARN.queue.length) { return; }
    var url = API + '/widget/' + SITE + '/events', sid = visit().sid || rid(22);
    while (LEARN.queue.length) {
      var body = JSON.stringify({ v: 1, shop: LEARN.shop, vid: visitor(), session: sid, sent_at: Date.now(), holdout: LEARN.holdout, preview: LEARN.preview, events: LEARN.queue.splice(0, 50) });
      var sent = false;
      if (leaving && win.navigator.sendBeacon) { try { sent = win.navigator.sendBeacon(url, new Blob([body], { type: 'text/plain' })); } catch (e) { sent = false; } }
      if (!sent && win.fetch) { win.fetch(url, { method: 'POST', body: body, keepalive: true, mode: 'no-cors', credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }).catch(function () {}); }
    }
  }
  setInterval(function () { learnFlush(false); visitFlush(false); }, 4000);
  doc.addEventListener('visibilitychange', function () { if (doc.visibilityState === 'hidden') { learnFlush(true); visitFlush(true); } });
  win.addEventListener('pagehide', function () { learnFlush(true); visitFlush(true); });
  // ---------------------------------------------------------------- the visit, for the shop's sessions screen
  // A step for each thing that happened: the page, a search and its results, a photo search, a question, the
  // window opened, a save, an add to cart, details left. Of the address the visit came in on, only the named
  // campaign parameters go, once, with the visit's first page (RecordVisit keeps nothing else).
  var VISIT = { queue: [], first: null };
  function visitFirst() {
    try {
      if (win.sessionStorage.getItem('let_agents_landed')) { return null; }
      win.sessionStorage.setItem('let_agents_landed', '1');
    } catch (e) { return null; }
    var params = {};
    try {
      var q = new URLSearchParams(win.location.search);
      ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'ref'].forEach(function (k) { var v = q.get(k); if (v) { params[k] = v.slice(0, 120); } });
    } catch (e) { /* old browser */ }
    return { landing: (win.location.pathname || '/').slice(0, 300), ref: visit().ref, params: params };
  }
  function pageKind() {
    var path = win.location.pathname || '/', cls = String(doc.body && doc.body.className || '');
    if (PAGE) { return PAGE.type; }
    if (path === '/' || /(?:^|\s)home(?:\s|$)/.test(cls)) { return 'home'; }
    if (/\/(cart|checkout)(\/|$)/.test(path)) { return /checkout/.test(path) ? 'checkout' : 'cart'; }
    if (/\/(search)(\/|$)/.test(path) || /[?&]s=/.test(win.location.search) || /(?:^|\s)search-results(?:\s|$)/.test(cls)) { return 'search'; }
    if (/\/(collections|product-category|category)\//.test(path) || /(?:^|\s)(tax-product_cat|archive)(?:\s|$)/.test(cls)) { return 'category'; }
    if (/(?:^|\s)single-post(?:\s|$)/.test(cls) || /\/(blogs|blog)\//.test(path)) { return 'content'; }
    return 'other';
  }
  function visitStep(kind, extra) {
    if (!CFG.api || !CFG.site) { return; }
    var s = { id: rid(16), kind: kind, ts: Date.now(), path: (win.location.pathname || '/').slice(0, 300) };
    Object.keys(extra || {}).forEach(function (k) { var v = extra[k]; if (v !== undefined && v !== null && v !== '') { s[k] = v; } });
    VISIT.queue.push(s);
    if (VISIT.queue.length >= 20) { visitFlush(false); }
  }
  function visitFlush(leaving) {
    if (!VISIT.queue.length || !CFG.api || !CFG.site) { return; }
    var v = visit();
    if (!v.sid) { VISIT.queue = []; return; }
    while (VISIT.queue.length) {
      var payload = { v: 1, sid: v.sid, vid: visitor(), preview: !!CFG.preview, steps: VISIT.queue.splice(0, 30) };
      if (VISIT.first) { payload.first = VISIT.first; VISIT.first = null; }
      var body = JSON.stringify(payload), url = API + '/visit/' + SITE, sent = false;
      if (leaving && win.navigator.sendBeacon) { try { sent = win.navigator.sendBeacon(url, new Blob([body], { type: 'text/plain' })); } catch (e) { sent = false; } }
      if (!sent && win.fetch) { win.fetch(url, { method: 'POST', body: body, keepalive: true, mode: 'no-cors', credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }).catch(function () {}); }
    }
  }
  function visitPage() {
    VISIT.first = visitFirst();
    visitStep('view', { type: pageKind(), item: PAGE && PAGE.type === 'product' ? String(PAGE.id) : undefined });
    setTimeout(function () { visitFlush(false); }, 1200);
  }
  // the store's own add to cart on a product page, counted once whichever of its signals fired
  var lastStoreAdd = 0;
  function storeAdded(quantity) {
    var now = Date.now();
    if (!PAGE || PAGE.type !== 'product' || now - lastStoreAdd < 1500) { return; }
    lastStoreAdd = now;
    learn('add_to_cart', null, null, { source: 'page', product_id: String(PAGE.id), quantity: quantity, result: 'added' });
    visitStep('cart', { item: String(PAGE.id), n: quantity });
    learnFlush(true);
  }
  function watchStoreAdds() {
    if (!PAGE || PAGE.type !== 'product') { return; }
    doc.addEventListener('submit', function (event) {
      var form = event.target;
      if (form && form.matches && form.matches('form.cart')) { var i = form.querySelector('input[name="quantity"]'), q = i ? parseInt(i.value, 10) : 1; storeAdded(q >= 1 && q <= 999 ? q : 1); }
    }, true);
    doc.body.addEventListener('wc-blocks_added_to_cart', function () { storeAdded(1); });
    try { if (win.jQuery) { win.jQuery(doc.body).on('added_to_cart', function () { storeAdded(1); }); } } catch (e) { /* no jQuery */ }
  }
  // the bank arrived for this page: which side of the measurement, the order to show, and the page view
  function learnFrom(b) {
    if (!b || !b.shop || LEARN.bank) { return; }
    LEARN.bank = b; LEARN.shop = b.shop;
    LEARN.preview = !!CFG.preview && b.preview === true;
    LEARN.holdout = heldOut(b.holdout_percent || 0);
    if (LEARN.holdout && b.baseline_order && b.sections) {
      var order = b.baseline_order;
      b.sections = b.sections.slice().sort(function (x, y) { var i = order.indexOf(x.candidate), j = order.indexOf(y.candidate); return (i < 0 ? 99 : i) - (j < 0 ? 99 : j); });
      b.teaser = null;
    }
    learn('page_view');
    setTimeout(function () { learnFlush(false); }, 1500);
    if (PAGE.type === 'product') { rememberCurrent(b); viewedProducts(b); }
  }
  // a section counts as seen once at least half of it was on screen for a second, once a page
  function watchExposure() {
    if (!LEARN.bank || !('IntersectionObserver' in win)) { return; }
    FLOW.querySelectorAll('[data-cand]').forEach(function (node) {
      var cand = node.getAttribute('data-cand');
      if (LEARN.exposed[cand] || node.__watched) { return; }
      node.__watched = true;
      var timer = null, since = 0, ratio = 0;
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          ratio = entry.intersectionRatio;
          if (ratio >= 0.5 && !timer) {
            since = Date.now();
            timer = setTimeout(function () {
              io.disconnect();
              if (LEARN.exposed[cand]) { return; }
              LEARN.exposed[cand] = true;
              var s = sectionOf(cand);
              if (s) { learn('exposure', s, 'panel', { visible_ms: Math.min(3600000, Date.now() - since), ratio: Math.min(1, Math.round(ratio * 100) / 100) }); }
            }, 1000);
          } else if (ratio < 0.5 && timer) { clearTimeout(timer); timer = null; }
        });
      }, { threshold: [0, 0.5, 1] });
      io.observe(node);
    });
  }
  // the product viewed before this one, of the same kind, within 30 days: "compare with what you viewed"
  var SEEN_KEY = 'let_agents_seen';
  function seenProducts() { try { var l = JSON.parse(win.localStorage.getItem(SEEN_KEY) || '[]'); return Array.isArray(l) ? l : []; } catch (e) { return []; } }
  function rememberCurrent(b) {
    var r = rec(PAGE.id), heading = doc.querySelector('h1'), og = doc.querySelector('meta[property="og:image"]');
    var entry = { id: String(PAGE.id), key: b.compare ? b.compare.key : null, title: r ? decode(r.title) : (heading ? heading.textContent.trim().slice(0, 120) : ''), url: win.location.href.split('?')[0],
      rows: b.compare ? b.compare.rows : null, kinds: b.assistant && b.assistant.product ? b.assistant.product.kinds || [] : [], image: og ? String(og.getAttribute('content') || '') : null, at: Date.now() };
    try { var list = seenProducts().filter(function (x) { return x && x.id !== entry.id; }); list.unshift(entry); win.localStorage.setItem(SEEN_KEY, JSON.stringify(list.slice(0, 8))); } catch (e) { /* private mode */ }
  }
  function viewedBefore() {
    var b = LEARN.bank, kinds = b && b.assistant && b.assistant.product ? b.assistant.product.kinds || [] : [];
    if (!kinds.length) { return null; }
    var limit = Date.now() - 30 * 864e5;
    return seenProducts().filter(function (x) { return x && x.id && x.id !== String(PAGE.id) && x.title && x.at > limit && (x.kinds || []).some(function (k) { return kinds.indexOf(k) !== -1; }); })[0] || null;
  }
  // the products this visitor looked at, and more from the search that brought them here (both asked for apart from the bank)
  function viewedProducts(b) {
    var body = function (o) { return { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(o) }; };
    if (b.recent) {
      json(API + '/widget/' + SITE + '/recent', body({ vid: visitor(), id: String(PAGE.id), locale: LANG })).then(function (r) {
        var d = r.ok && r.body && r.body.data;
        if (d && d.products && d.products.length) { LEARN.recent = d; return products(d.products.map(function (p) { return pid(p.id); }).slice(0, 8)).then(render); }
      }).catch(function () { /* the page works without it */ });
    }
    if (b.searched) {
      var last = null;
      try { var l = JSON.parse(win.localStorage.getItem('let_agents_searches') || '[]'); last = Array.isArray(l) && l.length ? l[l.length - 1] : null; } catch (e) { last = null; }
      var q = last && typeof last.q === 'string' ? last.q.trim() : '';
      if (q.length >= 2 && Date.parse(last.at) > Date.now() - 30 * 60000) {
        json(API + '/widget/' + SITE + '/searched', body({ q: q.slice(0, 120), id: String(PAGE.id), locale: LANG })).then(function (r) {
          var d = r.ok && r.body && r.body.data;
          if (d && d.products && d.products.length) { LEARN.searched = d; return products(d.products.map(function (p) { return pid(p.id); }).slice(0, 8)).then(render); }
        }).catch(function () { /* the page works without it */ });
      }
    }
  }
  // the bank's own wording first (the shop's), ours when it has none
  function bankLabel(key, fallback, vars) {
    var s = LEARN.bank && LEARN.bank.labels && LEARN.bank.labels[key] ? LEARN.bank.labels[key] : fallback;
    Object.keys(vars || {}).forEach(function (k) { s = String(s).split(':' + k).join(vars[k]); });
    return s;
  }

