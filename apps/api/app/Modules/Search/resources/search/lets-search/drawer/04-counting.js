  // ---------------------------------------------------------------- counting (as the old search script counted)
  // The same events, keys and payloads the store's reports are built on: /search/{site}/events with
  // {events, ctx:{sid,page,ref}, vid?}. Nothing about the shopper: an anonymous id, only with an add to cart.
  var COUNTED_KEY = 'let_agents_search_counted', FOUND_KEY = 'let_agents_found', PAUSE_MS = 2000;
  function norm(q) {
    var e = win.LA_ENGINE;
    try { if (e && e.normalize) { return e.normalize(q); } } catch (x) { /* plain */ }
    return String(q || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }
  function countedSet() { try { return JSON.parse(win.sessionStorage.getItem(COUNTED_KEY) || '[]'); } catch (e) { return []; } }
  function isCounted(n) { return countedSet().indexOf(n) !== -1; }
  function markCounted(n) {
    try { var list = countedSet(); if (list.indexOf(n) === -1) { list.push(n); win.sessionStorage.setItem(COUNTED_KEY, JSON.stringify(list.slice(-50))); } } catch (e) { /* private mode: may count twice */ }
  }
  // the visit as the store manager reads it beside a search: this tab's session, the page, where the session came from
  function visit() {
    var sid = null, ref = '';
    try {
      sid = win.sessionStorage.getItem('let_agents_sid');
      if (!sid) {
        sid = 's' + Math.random().toString(36).slice(2, 12) + Date.now().toString(36);
        win.sessionStorage.setItem('let_agents_sid', sid);
        var from = doc.referrer || '', external = from && from.indexOf(win.location.protocol + '//' + win.location.host) !== 0;
        win.sessionStorage.setItem('let_agents_ref', external ? from : '');
      }
      ref = win.sessionStorage.getItem('let_agents_ref') || '';
    } catch (e) { /* storage blocked: no session */ }
    return { sid: sid, page: win.location.pathname, ref: ref };
  }
  function visitQuery() { var v = visit(); return (v.sid ? '&sid=' + encodeURIComponent(v.sid) : '') + '&page=' + encodeURIComponent(v.page) + (v.ref ? '&ref=' + encodeURIComponent(v.ref) : ''); }
  // the same anonymous id the product page module used (cookie, else this browser), so daily questions add up once
  var visitorId = null;
  function visitor() {
    if (visitorId) { return visitorId; }
    var ok = function (v) { return /^anon-[A-Za-z0-9_-]{16,64}$/.test(v || ''); };
    var m = doc.cookie.match(/(?:^|; )let_agents_vid=([^;]*)/) || doc.cookie.match(/(?:^|; )rega_vid=([^;]*)/), id = m ? decodeURIComponent(m[1]) : null;
    if (!ok(id)) { try { id = win.localStorage.getItem('let_agents_vid'); } catch (e) { id = null; } }
    if (!ok(id)) {
      var abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_', bytes = new Uint8Array(22);
      (win.crypto || win.msCrypto).getRandomValues(bytes);
      id = 'anon-'; for (var i = 0; i < bytes.length; i++) { id += abc.charAt(bytes[i] % abc.length); }
      try { win.localStorage.setItem('let_agents_vid', id); } catch (e) { /* the cookie still carries it */ }
    }
    visitorId = id;
    return id;
  }
  function sendEvents(events, withVisitor) {
    if (!CFG.api || !CFG.site) { return; }
    var payload = { events: events, ctx: visit() };
    if (withVisitor) { payload.vid = visitor(); }
    var body = JSON.stringify(payload), url = String(CFG.api).replace(/\/$/, '') + '/search/' + encodeURIComponent(CFG.site) + '/events';
    try { if (win.navigator.sendBeacon && win.navigator.sendBeacon(url, new Blob([body], { type: 'text/plain' }))) { return; } } catch (e) { /* fall through */ }
    try { win.fetch(url, { method: 'POST', body: body, keepalive: true, mode: 'cors', credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }); } catch (e) { /* best effort */ }
  }
  // the shopper's own last searches, in this browser only (read later, with consent, to explain a cart)
  function rememberSearch(query) {
    try {
      var key = 'let_agents_searches', list = JSON.parse(win.localStorage.getItem(key) || '[]'), cutoff = Date.now() - 14 * 864e5;
      list = list.filter(function (s) { return s && s.q && Date.parse(s.at) > cutoff && s.q !== query.trim(); });
      list.push({ q: String(query).trim().slice(0, 120), at: new Date().toISOString() });
      win.localStorage.setItem(key, JSON.stringify(list.slice(-20)));
    } catch (e) { /* storage blocked */ }
  }
  function countSearch(query, results) {
    var n = norm(query);
    if (n.length < 2 || isCounted(n)) { return; }
    markCounted(n);
    sendEvents([{ type: 'search', q: n, results: results }]);
    visitStep('search', { label: String(query).trim().slice(0, 200), n: results });
    rememberSearch(query);
  }
  function countClick(query, item, shown) {
    var n = norm(query);
    if (!n || !item) { return; }
    var events = [];
    if (!isCounted(n)) { markCounted(n); events.push({ type: 'search', q: n, results: shown || 0 }); }
    events.push({ type: 'click', q: n, id: item.id, title: item.title });
    // with the shopper's anonymous id: the store learns from what different shoppers open (LearnFromSearches)
    sendEvents(events, true);
    rememberFound(n, item);
  }
  // what a shopper did with a search, for the store to learn from: a chip taken off or put on, a looser search chosen,
  // the AI asked after it (the server counts each shopper once a day)
  function signalEvent(kind, query, value) {
    var n = norm(query);
    if (!n) { return; }
    sendEvents([{ type: kind, q: n, value: String(value == null ? '' : value).slice(0, 200) }], true);
  }
  function countAdd(query, item) {
    var n = norm(query);
    if (!n || !item) { return; }
    sendEvents([{ type: 'add_to_cart', q: n, id: item.id, title: item.title, path: win.location.pathname }], true);
  }
  function foundProducts() {
    try {
      var kept = JSON.parse(win.localStorage.getItem(FOUND_KEY) || '{}'), cutoff = Date.now() - 7 * 864e5, out = {};
      Object.keys(kept || {}).forEach(function (id) { if (kept[id] && kept[id].t > cutoff && kept[id].q) { out[id] = kept[id]; } });
      return out;
    } catch (e) { return {}; }
  }
  // a product opened from the search, kept a week: added to the cart later from its own page, it still came through the search
  function rememberFound(query, item) {
    var id = String(item && item.id || '');
    if (id.indexOf('p:') !== 0) { return; }
    try {
      var kept = foundProducts();
      kept[id.slice(2)] = { q: query, id: id, title: String(item.title || '').slice(0, 200), t: Date.now() };
      var ids = Object.keys(kept).sort(function (a, b) { return kept[b].t - kept[a].t; }).slice(0, 40), trimmed = {};
      ids.forEach(function (k) { trimmed[k] = kept[k]; });
      win.localStorage.setItem(FOUND_KEY, JSON.stringify(trimmed));
    } catch (e) { /* storage blocked */ }
  }
  // on the page of a product found by the search, the store's own add to cart counts as the search's, once
  function watchFoundProduct() {
    var meta = win.ShopifyAnalytics && win.ShopifyAnalytics.meta && win.ShopifyAnalytics.meta.product;
    var post = String(doc.body && doc.body.className || '').match(/(?:^|\s)postid-([0-9]+)(?:\s|$)/);
    var pageId = meta && meta.id ? String(meta.id) : (post && /(?:^|\s)single-product(?:\s|$)/.test(doc.body.className) ? post[1] : (CFG.productId ? String(CFG.productId) : null));
    var found = pageId ? foundProducts()[pageId] : null;
    if (!found) { return; }
    var counted = false;
    var count = function () { if (!counted) { counted = true; sendEvents([{ type: 'add_to_cart', q: found.q, id: found.id, title: found.title, path: win.location.pathname, via: 'page' }], true); } };
    var isAdd = function (url) { return /\/cart\/add(\.js)?(\?|$)|wc-ajax=add_to_cart|[?&]add-to-cart=/.test(String(url || '')); };
    win.addEventListener('submit', function (event) {
      var form = event.target, action = form && form.getAttribute ? String(form.getAttribute('action') || '') : '';
      if (!event.defaultPrevented && form && (/\/cart\/add/.test(action) || (form.classList && form.classList.contains('cart')))) { count(); }
    });
    if (win.fetch) {
      var fetchBefore = win.fetch;
      win.fetch = function (input) {
        var url = typeof input === 'string' ? input : (input && input.url), answer = fetchBefore.apply(this, arguments);
        if (isAdd(url)) { answer.then(function (r) { if (r && r.ok) { count(); } }, function () {}); }
        return answer;
      };
    }
    if (win.XMLHttpRequest) {
      var openBefore = win.XMLHttpRequest.prototype.open;
      win.XMLHttpRequest.prototype.open = function (method, url) {
        if (isAdd(url)) { this.addEventListener('load', function () { if (this.status >= 200 && this.status < 300) { count(); } }); }
        return openBefore.apply(this, arguments);
      };
    }
  }

