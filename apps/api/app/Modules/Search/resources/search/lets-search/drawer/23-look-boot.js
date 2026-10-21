  // keyboard shortcut: "/" or ⌘K / Ctrl+K
  doc.addEventListener('keydown', function (e) {
    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((doc.activeElement || {}).tagName || '') || (doc.activeElement && doc.activeElement.isContentEditable);
    if (((e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey)) || (e.key === '/' && !typing && !S.open)) { e.preventDefault(); open(); }
  });

  // store look: read the page's font, colour and radius; failing values fall back to ours
  function applyStoreTheme() {
    if (CFG.theme !== 'store') { return; }
    var cs = getComputedStyle(doc.body);
    // the store's own "add to cart" is its truest primary button: look for it first, in this order
    var btn = null;
    (CFG.accentSelector ? [CFG.accentSelector] : ['.single_add_to_cart_button', 'button[name="add-to-cart"]', 'form[action*="/cart/add"] [type="submit"]', '.product-form__submit', '.add_to_cart_button', '.button.alt']).some(function (sel) {
      var el = doc.querySelector(sel);
      if (el && !/rgba\(0, 0, 0, 0\)|transparent/.test(getComputedStyle(el).backgroundColor)) { btn = el; return true; }
      return false;
    });
    var bcs = btn ? getComputedStyle(btn) : null;
    function rgb(c) { var m = String(c).match(/-?\d*\.?\d+/g); if (!m) { return null; } var v = m.slice(0, 3).map(Number); if (/^color\(/.test(c)) { v = v.map(function (x) { return x * 255; }); } return v; }
    function lum(v) { v = v.map(function (x) { x /= 255; return x <= .03928 ? x / 12.92 : Math.pow((x + .055) / 1.055, 2.4); }); return .2126 * v[0] + .7152 * v[1] + .0722 * v[2]; }
    function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); }
    function hex(v) { return '#' + v.map(function (x) { return ('0' + Math.round(x).toString(16)).slice(-2); }).join(''); }
    // on every host of ours (the drawer, the launchers, the product page's rows): each has its own shadow root
    var hosts = ourHosts();
    function put(name, value) { hosts.forEach(function (h) { h.style.setProperty(name, value); }); }
    put('--store-font', cs.fontFamily);
    var fg = rgb(cs.color), bg = rgb(cs.backgroundColor);
    if (fg && bg && !/rgba\(0, 0, 0, 0\)|transparent/.test(cs.backgroundColor) && ratio(fg, bg) >= 4.5) {
      put('--store-fg', hex(fg)); put('--store-bg', hex(bg));
      // the muted text is the text mixed into the ground (tokens.css): darker until it still reads (AA)
      var k = .66, mix = function () { return fg.map(function (x, i) { return x * k + bg[i] * (1 - k); }); };
      while (ratio(mix(), bg) < 4.5 && k < 1) { k = Math.min(1, k + .06); }
      if (k > .66) { put('--store-fg-muted', hex(mix())); }
    }
    if (bcs) {
      var a = rgb(bcs.backgroundColor), n = 0;
      if (a && !/rgba\(0, 0, 0, 0\)/.test(bcs.backgroundColor)) {
        // the theme's main colour under white words: darkened step by step until the pair passes AA
        var white = [255, 255, 255];
        while (ratio(a, white) < 4.5 && n < 20) { a = a.map(function (x) { return x * .94; }); n++; }
        if (ratio(a, white) >= 4.5) { put('--store-accent', hex(a)); }
      }
      var r = parseFloat(bcs.borderRadius);
      if (!isNaN(r)) { put('--store-radius', Math.min(r, 24) + 'px'); }
    }
  }
  // a host made after the look was read: the same look as the drawer's
  function dressHost(h) {
    if (CFG.theme !== 'store') { return; }
    h.setAttribute('data-ls-theme', 'store');
    ['--store-font', '--store-fg', '--store-bg', '--store-fg-muted', '--store-accent', '--store-radius'].forEach(function (p) { var v = host.style.getPropertyValue(p); if (v) { h.style.setProperty(p, v); } });
  }

  // An AI agent working in the shopper's browser (WebMCP) finds the store's search on the page itself, no link
  // needed: two read-only tools, the same search and products the drawer shows. Only where the browser offers
  // it (document.modelContext, or navigator.modelContext in its first form); a search an agent runs is not
  // counted as a shopper's. Named lets_* so they never take the place of a platform's own (Shopify registers its own).
  function agentTools() {
    var context = doc.modelContext || (win.navigator && win.navigator.modelContext);
    if (!context || typeof context.registerTool !== 'function' || !SITE) { return; }
    var text = function (data) { return { content: [{ type: 'text', text: JSON.stringify(data) }] }; };
    var brief = function (r) { return { id: pid(r.id), title: decode(r.title), url: r.url, price: r.pr == null ? null : r.pr, in_stock: r.s === 1, image: r.img || null }; };
    var tools = [
      {
        name: 'lets_search_products',
        description: 'Search this store\'s products, in the shopper\'s own words (any language; prices like "up to 300" are read too). Returns up to 12 products with their page, price and stock.',
        inputSchema: { type: 'object', properties: { query: { type: 'string', description: 'What the shopper is looking for' } }, required: ['query'] },
        annotations: { readOnlyHint: true },
        execute: function (input) {
          var q = String((input && input.query) || '').trim();
          if (!q) { return Promise.resolve(text({ products: [] })); }
          return loadIndex().then(function () { return json(API + '/search/' + SITE + '?q=' + encodeURIComponent(q) + '&counted=1'); }).then(function (r) {
            var d = r.body || {};
            var ids = (d.ids || []).filter(function (id) { return /^p:/.test(id); });
            if (!ids.length) { ids = ((d.groups && d.groups.product) || []).map(function (p) { return p.id; }); }
            return text({ store: S.config.shopName || '', total: d.total || ids.length, products: ids.slice(0, 12).map(rec).filter(Boolean).map(brief) });
          });
        }
      },
      {
        name: 'lets_get_product',
        description: 'One product of this store by its id (from lets_search_products): its page, price, stock and picture.',
        inputSchema: { type: 'object', properties: { id: { type: 'string' } }, required: ['id'] },
        annotations: { readOnlyHint: true },
        execute: function (input) {
          return loadIndex().then(function () { var r = rec(String((input && input.id) || '')); return text(r ? brief(r) : { error: 'unknown_product' }); });
        }
      }
    ];
    tools.forEach(function (tool) { try { context.registerTool(tool); } catch (e) { /* a name taken, or an older form of the API */ } });
  }
  function boot() {
    // silent (a shopper while the store is in preview): nothing is shown, the page still counts, as the
    // product page module counted it before: the page view, the store's own add to cart, the search's attribution
    if (CFG.silent) {
      if (!S.ctx) {
        var smeta = win.ShopifyAnalytics && win.ShopifyAnalytics.meta && win.ShopifyAnalytics.meta.product, scls = String(doc.body.className || ''), spost = scls.match(/(?:^|\s)postid-([0-9]+)(?:\s|$)/);
        var sfound = smeta && smeta.id ? String(smeta.id) : (spost && /(?:^|\s)single-product(?:\s|$)/.test(scls) ? spost[1] : null);
        if (sfound && CFG.pageType !== 'content') { S.ctx = sfound; }
      }
      PAGE = CFG.pageId && CFG.pageType === 'content' ? { type: 'content', id: String(CFG.pageId) } : S.ctx ? { type: 'product', id: String(pid(S.ctx)) } : null;
      keepVisitor(); watchStoreAdds(); watchFoundProduct(); visitPage();
      if (PAGE) { bankFor(PAGE.id); }
      return;
    }
    if (!doc.getElementById('ls-font') && !/Google Sans/.test(getComputedStyle(doc.body).fontFamily)) {
      var link = doc.createElement('link'); link.id = 'ls-font'; link.rel = 'stylesheet'; link.href = 'https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600&display=swap'; doc.head.appendChild(link);
    }
    doc.body.appendChild(host);
    hook();
    inlineField();
    applyStoreTheme();
    agentTools();
    BOOTED = true;
    nudge(fetchLook());
    // the page: a product (from the config, else the store's own markers) or a content page, for its learning
    if (!S.ctx) {
      var meta = win.ShopifyAnalytics && win.ShopifyAnalytics.meta && win.ShopifyAnalytics.meta.product, cls = String(doc.body.className || ''), post = cls.match(/(?:^|\s)postid-([0-9]+)(?:\s|$)/);
      var found = meta && meta.id ? String(meta.id) : (post && /(?:^|\s)single-product(?:\s|$)/.test(cls) ? post[1] : null);
      if (found && !(CFG.pageType === 'content')) { S.ctx = found; CFG.productId = found; }
    }
    PAGE = CFG.pageId && CFG.pageType === 'content' ? { type: 'content', id: String(CFG.pageId) } : S.ctx ? { type: 'product', id: String(pid(S.ctx)) } : null;
    keepVisitor();
    watchStoreAdds();
    watchFoundProduct();
    visitPage();
    if (PAGE) { bankFor(PAGE.id); }
    sizeHelp();
    outOfStockRow();
    // the shop's own way in to the list: any link to "#wishlist", or its shortcode's [data-let-agents-wishlist]
    doc.addEventListener('click', function (e) { var l = e.target && e.target.closest ? e.target.closest('a[href$="#wishlist"], [data-let-agents-wishlist]') : null; if (l) { e.preventDefault(); S.tab = 'saved'; open({ focus: false }); } }, true);
    if (win.location.hash === '#wishlist') { S.tab = 'saved'; open({ focus: false }); }
    var shortStart = function () {
      json(API + '/shortlist/' + SITE + '/config?locale=' + LANG).then(function (r) {
        SHORT = r.ok ? r.body || {} : {};
        R.classList.toggle('no-wish', SHORT.wishlist === false); R.classList.toggle('no-compare', SHORT.compare === false);
        saveHearts();
        var synced = false;
        try { synced = win.sessionStorage.getItem(WISH_KEY + '_synced') === '1'; win.sessionStorage.setItem(WISH_KEY + '_synced', '1'); } catch (e) { synced = false; }
        if (SHORT.wishlist !== false && !synced && (WISH.wish.length || WISH.phone || CFG.customer || CFG.loggedIn)) { wishSync(); }
      }).catch(function () { SHORT = {}; });
    };
    if (win.requestIdleCallback) { win.requestIdleCallback(shortStart, { timeout: 3000 }); } else { setTimeout(shortStart, 1200); }
    inlineField();
    // ?la_order=…: the order a shopper's AI wrote, completed into this store's cart
    var laOrder = String(URLQ.la_order || '').trim().slice(0, 2000);
    if (laOrder) { loadIndex().then(function () { open({ focus: false }); orderTurn(laOrder); }); }
    else if (String(URLQ.la_q || '').trim()) {
      // a shared search: kept as a turn, with the filters it was shared with, by spelling only
      var laQ = String(URLQ.la_q).trim().slice(0, 120);
      loadIndex().then(function () { S.pendingC = cFromLink(URLQ.la_f || URLQ.lsF); S.spellingNext = true; open({ focus: false }); submit(laQ, 'search'); });
    }
    else if (/^[A-Za-z0-9]{8,16}$/.test(String(URLQ.la_list || ''))) {
      loadIndex().then(function () {
        open({ focus: false });
        var turn = addTurn({ kind: 'shared', loading: true });
        json(API + '/shortlist/' + SITE + '/shared/' + encodeURIComponent(URLQ.la_list)).then(function (r) {
          var list = ((r.body && r.body.products) || []).map(function (p) { return 'p:' + pid(p.external_id || p.id); }).filter(function (x) { return rec(x); });
          return products(list.map(pid)).then(function () { update(turn, { loading: false, ids: list }); });
        }).catch(function () { update(turn, { loading: false, ids: [] }); });
      });
    }
    else if (URLQ.lsShared) { S.saved = URLQ.lsShared.split(',').filter(Boolean); store('local', 'ls_saved', S.saved); S.tab = 'saved'; open({ focus: false }); }
    // a shared search: it opens kept, with the filters it was shared with
    else if (URLQ.lsOpen) { open({ focus: !URLQ.lsQ }); if (URLQ.lsQ) { loadIndex().then(function () { S.pendingC = URLQ.lsF ? cFromLink(URLQ.lsF) : null; submit(URLQ.lsQ, 'search'); }); } }
    else {
      // the store's own search page (?s= on WordPress, /search?q= on Shopify): its results open here, when the shop chose so
      var pq = null;
      try { var sp = new URLSearchParams(win.location.search); pq = SHOPIFY ? (/\/search\/?$/.test(win.location.pathname) ? sp.get('q') : null) : sp.get('s'); } catch (e) { pq = null; }
      pq = pq ? String(pq).trim() : '';
      if (pq && pq.length <= 120) { loadIndex().then(function () { if (S.config.resultsPage) { S.spellingNext = true; open({ focus: false }); submit(pq, 'search', false, true); } }); }
    }
  }
  win.LetsSearch = { open: open, close: close, search: function (q) { open({ focus: false }); submit(q, 'search'); }, ask: function (q) { open({ focus: false }); submit(q, 'ask'); }, similar: function (id) { open({ focus: false }); similarTurn(String(id)); }, photo: function () { open({ focus: false }); photoPick(); }, state: S, _photoFile: photoFile, _root: root, _learn: LEARN };
  if (!win.LetAgentsSearch) {
    win.LetAgentsSearch = {
      addToCart: function (externalId, button, onAdded) { loadIndex().then(function () { addToCart(button || null, String(externalId).replace(/^p:/, ''), null, null, null, null, onAdded); }); },
      canSimilar: function () { return S.index ? S.config.similar !== false : true; },
      similar: function (item) { var id = String(item && (item.external_id || item.id) || '').replace(/^p:/, ''); if (id) { open({ focus: false }); loadIndex().then(function () { similarTurn(id); }); } },
      photo: function () { open({ focus: false }); photoPick(); },
      ask: function (question, productIds, about) { var q = String(question || '').trim(); open({ focus: !q }); if (q) { loadIndex().then(function () { submit(q, 'ask', !!about); }); } },
      search: function (query) { var q = String(query || '').trim(); open({ focus: !q }); if (q) { loadIndex().then(function () { submit(q, 'search'); }); } }
    };
  }
  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', boot); } else { boot(); }
