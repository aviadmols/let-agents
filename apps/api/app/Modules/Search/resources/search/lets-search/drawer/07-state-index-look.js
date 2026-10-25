  // ---------------------------------------------------------------- state
  // the shopper's own recent searches kept (remember); the list before ls_searches also kept chips and questions, so it is dropped
  var RECENT_MAX = 6;
  store('local', 'ls_recent', null);
  var S = {
    open: false, tab: 'chat', wide: null, index: null, records: {}, built: null, engine: win.LA_ENGINE || null,
    config: {}, facets: [], turns: store('session', 'ls_turns') || lastTurns(), saved: store('local', 'ls_saved') || [],
    recent: [].concat(store('local', 'ls_searches') || []).filter(function (x) { return typeof x === 'string' && x.trim(); }).slice(0, RECENT_MAX), products: {}, ctx: CFG.productId ? String(CFG.productId) : null,
    phone: WISH.phoneShown, pick: [], onlyDiff: false, typing: false, active: -1,
    expanded: !!store('local', 'ls_expanded'), live: null, menu: ''
  };
  // at: for each filter read from the words, which of the typed words named it (their places in wordsOf(q))
  function blankC() { return { f: {}, sort: '', stock: false, sale: false, no: {}, auto: {}, at: {} }; }
  if (WISH.wish.length) { S.saved = WISH.wish.slice(); } else if (S.saved.length) { WISH.wish = S.saved.slice(); WISH.pending = S.saved.slice(); wishSave(); }
  // what was asked on earlier pages is kept, but hidden on this one until asked for (the dashed line at the bottom)
  S.turns.forEach(function (x) { x.prev = true; });
  var fresh = {}; // turn ids that animate in this render only
  var BOOTED = false; // the page is hooked: a look that changes after this re-hooks and redraws what is on it
  var OOS = { host: null, watching: false, state: null, text: null }; // the product page's row for a product sold out

  // ---------------------------------------------------------------- index (everything instant comes from here)
  function loadIndex() {
    if (S.indexing) { return S.indexing; }
    S.indexing = json(API + '/search/' + SITE + '/index?locale=' + LANG, { wait: 60000 }).then(function (r) {
      var d = r.body || {};
      S.index = d; S.config = d.config || {}; S.facets = d.facets || [];
      (d.items || []).forEach(function (it) { S.records[it.id] = it; });
      if (S.engine && S.engine.build) {
        try { S.built = S.engine.build((d.items || []).map(function (i) { return { id: i.id, title: i.title, keywords: i.kw || '', tags: i.tg || null }; })); } catch (e) { S.built = null; }
      }
      // the index carries the shop's look too (and is all a preview reads): the same choices as /look
      if (S.config.theme) { applyLook(S.config); }
      if (!CFG.assistant) { CFG.assistant = (S.config.shopName || 'Lets') + ' AI'; }
      shopSettings();
      render();
      return d;
    }, function (e) {
      // not kept failed: the next search asks for the index again
      S.indexing = null;
      throw e;
    });
    return S.indexing;
  }
  // what the shop turned on (the index config): photo search, questions, similar items, the cart, prices,
  // "ask my AI" and sharing. A setting the server did not send stays on.
  function off(key) { return S.config && S.config[key] === false; }
  // the welcome line offers only what the shop has on: a photo only where photo search is on, a question only where the assistant is
  function welcomeSub() { return off('photos') ? (off('ask') ? 'welcomeSubSearch' : 'welcomeSubNoPhoto') : (off('ask') ? 'welcomeSubNoAsk' : 'welcomeSub'); }
  function shopSettings() {
    R.classList.toggle('no-photo', off('photos'));
    R.classList.toggle('no-similar', off('similar'));
    // product pictures shown whole in a light frame, for a shop whose pictures sit on a white ground (search.image_fit)
    R.classList.toggle('is-frame', S.config.images === 'frame');
    doc.querySelectorAll('.lets-inline').forEach(function (h) { var b = h.shadowRoot && h.shadowRoot.querySelector('[data-photo]'); if (b) { b.hidden = off('photos'); b.previousElementSibling.hidden = off('photos'); } });
    if (S.config.selector && S.config.selector !== CFG.fieldSelector) { CFG.fieldSelector = CFG.fieldSelector + ', ' + S.config.selector; hookFields(); }
  }

  // ---------------------------------------------------------------- the shop's look (settings · how it looks, search window)
  // Read once a page, before the index is needed (small, cached by the browser for minutes), and kept for the
  // next page. Each choice is applied where it shows: the language of every word, Lets' look or the site's,
  // the assistant's name, the theme's own search box, the product page's row for a product sold out.
  // resolves true once this page read the fresh look (the invitation waits for it: a first visit has no look kept)
  function fetchLook() {
    if (!CFG.api || !CFG.site) { return Promise.resolve(false); }
    return json(API + '/search/' + SITE + '/look').then(function (r) { if (r.ok && r.body && r.body.theme) { applyLook(r.body); return true; } return false; }).catch(function () { return false; /* the last look stays */ });
  }
  function applyLook(l) {
    var keep = { theme: l.theme, lang: l.lang || null, assistant: l.assistant || '', takeOver: l.takeOver !== false, rate: l.rate !== false, oosSimilar: l.oosSimilar === true, nudge: l.nudge === true, nudgeAt: String(l.nudgeAt || ''), enabled: l.enabled !== false,
      float: l.float !== false, floatSide: l.floatSide === 'left' || l.floatSide === 'right' ? l.floatSide : 'auto', floatBottom: floatY(l.floatBottom) };
    if (!CFG.preview) { try { win.localStorage.setItem(LOOK_KEY, JSON.stringify(keep)); } catch (e) { /* storage blocked: read again next page */ } }
    LOOK = keep;
    if (keep.assistant && lookWins('assistant')) { CFG.assistant = keep.assistant; }
    if (lookWins('theme') && !URLQ.lsTheme) { setTheme(keep.theme === 'store' ? 'store' : 'lets'); }
    if (lookWins('locale') && !URLQ.lsLang) { setLang(/^en/i.test(String(keep.lang || SITE_LOCALE || '')) ? 'en' : 'he'); }
    if (CFG.takeOver !== keep.takeOver) { CFG.takeOver = keep.takeOver; rehook(); }
    if (BOOTED) { placeFloat(); outOfStockRow(); }
    if (D) { render(); }
  }
  // every host of ours carries the look: the drawer, the launchers, the product page's rows
  function ourHosts() { return [host].concat([].slice.call(doc.querySelectorAll('.lets-inline, .lets-oos, [data-lets-launcher]'))); }
  function setTheme(theme) {
    if (CFG.theme === theme && host.getAttribute('data-ls-theme') === theme) { return; }
    CFG.theme = theme;
    ourHosts().forEach(function (h) {
      h.setAttribute('data-ls-theme', theme);
      if (theme !== 'store') { ['--store-font', '--store-fg', '--store-bg', '--store-fg-muted', '--store-accent', '--store-radius'].forEach(function (p) { h.style.removeProperty(p); }); }
    });
    if (theme === 'store' && BOOTED) { applyStoreTheme(); }
  }
  // the widget in another language than the page: every word of ours, the drawer's frame and the rows on the page
  function setLang(lang) {
    if (lang === LANG) { return; }
    LANG = lang; T = WORDS[lang]; DIR = lang === 'he' ? 'rtl' : 'ltr';
    R.setAttribute('dir', DIR); R.setAttribute('lang', LANG);
    D.setAttribute('aria-label', t('open'));
    var label = function (sel, key) { var n = D.querySelector(sel); if (n) { n.setAttribute('aria-label', t(key)); } };
    label('[data-menu]', 'more'); label('button[data-close]', 'close'); label('[data-clear]', 'clear'); label('[data-photo]', 'photo'); label('[data-submit]', 'send');
    SUG.setAttribute('aria-label', t('placeholder')); FLOW.setAttribute('aria-label', t('open'));
    INPUT.placeholder = ''; renderCtx();
    var saved = D.querySelector('[data-tab="saved"]'); if (saved && saved.firstChild && saved.firstChild.nodeType === 3) { saved.firstChild.nodeValue = t('saved'); }
    var pv = D.querySelector('.ls-preview'); if (pv) { pv.innerHTML = icon('info', 'ls-icon--sm') + esc(t('preview')); }
    var cr = D.querySelector('.ls-credit a'); if (cr) { cr.textContent = t('powered'); }
    doc.querySelectorAll('.lets-inline').forEach(function (h) {
      var box = h.shadowRoot && h.shadowRoot.querySelector('.ls'), input = box && box.querySelector('input');
      if (box) { box.setAttribute('dir', DIR); box.setAttribute('lang', LANG); }
      if (input) { input.placeholder = t('placeholderProduct'); input.setAttribute('aria-label', t('placeholderProduct')); }
    });
    LAUNCHERS.forEach(function (b) { b.setAttribute('aria-label', t('open')); });
    refreshLauncher();
    // what the server words in the shop's language comes again in this one: the index, the product's bank
    if (S.indexing) { S.indexing = null; loadIndex(); }
    if (PAGE) { delete BANKS[String(PAGE.id)]; delete BANK_READY[String(PAGE.id)]; bankFor(PAGE.id); }
    if (OOS.host) { OOS.text = null; outOfStockRow(); }
  }
  function rec(id) { id = String(id); return S.records[id.indexOf(':') > 0 ? id : 'p:' + id]; }
  function pid(id) { return String(id).replace(/^p:/, ''); }
  function hits(q) {
    if (!q) { return []; }
    if (S.built && S.engine) {
      try { return S.engine.search(S.built, q).map(function (h) { return S.records[h.id || h]; }).filter(Boolean); } catch (e) { /* fall through */ }
    }
    var n = q.toLowerCase();
    return (S.index && S.index.items || []).filter(function (i) { return (i.title + ' ' + (i.kw || '')).toLowerCase().indexOf(n) >= 0; });
  }
  function isQuestion(q) {
    if (!q || off('ask')) { return false; }
    // "תבנה לי הזמנה לבניית פרגולה": a request to put an order together is asked, not searched (the shared
    // engine reads questions alike on the server; this is the drawer's own)
    if (buildAsk(q)) { return true; }
    if (S.engine && S.engine.isQuestion) { try { if (S.engine.isQuestion(q)) { return true; } } catch (e) { /* none */ } }
    // A long text is a question only when it asks for something ("תמליצו לי…", "מה ההבדל…"): a long
    // description of a product ("טבעת זהב צהוב עם שתי רצועות דקות…") is searched, by its words and its meaning.
    // "תן לי את כל ספרי החטופים", "תציג לי את ספריית שיבולת": a set asked to see, which the assistant shows whole
    return /\?\s*$/.test(q) || /^(איזה|איזו|אילו|איך|כמה|האם|מה|למה|מתי|אפשר|יש לכם|יש לך|which|what|how|can|does|is|do|why)\b/i.test(q.trim())
      || /^(תן|תני|תנו|תציג|תציגי|תציגו|הצג|תראה|תראי|תראו|הראה|הראו)\s+(לי|לנו)\s+\S|^(show|list)\s+(me\s+)?(all\s+)?\S/i.test(q.trim())
      || (q.trim().split(/\s+/).length >= 5 && ASKS_FOR.test(q));
  }
  var ASKS_FOR = /(תמליצ|תמליץ|המליצ|ממליצ|המלצה|תסביר|תסבירו|הסבר|ספר(ו)? לי|תגיד(ו)? לי|השוו|ההבדל|הבדל בין|כדאי לי|מה עדיף|עדיף|צריך לדעת|אני מתלבט|מתלבטת|recommend|explain|compare|difference|should i|which is better)/i;

  // store products (regular price, variations) for the cards on screen
  // live from the store, once a page view: the price and stock of today, not of last night's index
  function products(ids) {
    var need = ids.map(pid).filter(function (id) { return !Object.prototype.hasOwnProperty.call(S.products, id); });
    if (!need.length) { return Promise.resolve(); }
    if (SHOPIFY) { return shopifyProducts(need); }
    if (!STORE) { return Promise.resolve(); }
    return json(STORE + '/products?include=' + need.map(encodeURIComponent).join(',') + '&per_page=' + need.length, { credentials: 'same-origin' }).then(function (r) {
      need.forEach(function (id) { S.products[id] = null; });
      (Array.isArray(r.body) ? r.body : []).forEach(function (p) {
        S.products[String(p.id)] = p;
        if (!S.cur && p.prices) { S.cur = { prefix: p.prices.currency_prefix || '', suffix: p.prices.currency_suffix || '' }; }
      });
    }).catch(function () { /* cards keep the index price */ });
  }
  // Shopify has no "these products by id" for a storefront: each one's public JSON, four at a time, no token
  var SHOP_INFLIGHT = {};
  function handleOf(id) { var e = win.LA_ENGINE, r = rec(id); return e && e.handleFromUrl && r ? e.handleFromUrl(r.url) : null; }
  function shopifyJson(id) {
    var h = handleOf(id);
    if (!h) { return Promise.resolve(null); }
    return win.fetch(ROOT + 'products/' + encodeURIComponent(h) + '.js', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (res) { return res.ok ? res.json() : null; }).catch(function () { return null; });
  }
  // What a Shopify product page asks for besides its variants: a ring size its theme or an app keeps in a line
  // property (properties[Size], required) that the product's JSON does not have. Read from the product page
  // itself, once, when it is added: each field with its values as the page offers them (Rocks and Gold: a
  // ring that can be resized offers the sizes around its own). A value the page sets and does not let change
  // (a ring that cannot be resized keeps its size) goes with the line as it is (fixed). A required field
  // with nothing to choose from here (an engraving to type) sends the shopper to the page (free).
  var PAGE_FIELDS = {};
  function pageFields(id) {
    var h = handleOf(id);
    if (!h || !win.DOMParser) { return Promise.resolve(null); }
    if (PAGE_FIELDS[h]) { return PAGE_FIELDS[h]; }
    PAGE_FIELDS[h] = win.fetch(ROOT + 'products/' + encodeURIComponent(h), { credentials: 'same-origin', headers: { Accept: 'text/html' } }).then(function (res) { return res.ok ? res.text() : ''; }).then(function (html) {
      return html ? readPageFields(new win.DOMParser().parseFromString(html, 'text/html')) : null;
    }).catch(function () { return null; });
    return PAGE_FIELDS[h];
  }
  // WooCommerce: what the product's own form requires before the cart that its feed does not carry (an add-on's
  // length at Gueta, a gift wrap): read from the page as the shopper sees it, whatever plugin wrote the form.
  // Only a choice from a list can be made here; a required field to type in (an engraving) sends to the page.
  var WOO_FIELDS = {};
  function wooPageFields(id) {
    var r = rec(id), url = r && r.url;
    if (!url || !win.DOMParser) { return Promise.resolve(null); }
    if (WOO_FIELDS[url]) { return WOO_FIELDS[url]; }
    WOO_FIELDS[url] = win.fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } }).then(function (res) { return res.ok ? res.text() : ''; }).then(function (html) {
      if (!html) { return null; }
      var page = new win.DOMParser().parseFromString(html, 'text/html');
      var forms = [].slice.call(page.querySelectorAll('form.cart')).filter(function (f) { return !f.closest('.related, .upsells, .cross-sells, [class*="related"]'); });
      var main = forms.sort(function (a, b) { return b.querySelectorAll('select, input, textarea').length - a.querySelectorAll('select, input, textarea').length; })[0];
      if (!main) { return null; }
      var own = /^(quantity|add-to-cart|product_id|variation_id|attribute_.*|_.*|wc_.*nonce.*)$/;
      var named = {}, out = { fields: [], free: false, url: main.getAttribute('action') || url };
      [].slice.call(main.querySelectorAll('select[name], input[name], textarea[name]')).forEach(function (el) {
        if (own.test(el.name) || /^(hidden|submit|button)$/.test(el.type)) { return; }
        (named[el.name] = named[el.name] || []).push(el);
      });
      var labelOf = function (el) { var box = el.closest('.wc-pao-addon-container, .form-row, .wapf-field-container, .ppom-field-wrapper, .tm-extra-product-options-field, p, div'), l = (el.id && page.querySelector('label[for="' + el.id + '"]')) || (box && box.querySelector('label, .wc-pao-addon-name, .wapf-field-label')); return l ? l.textContent.replace(/\s+/g, ' ').replace(/[*:]\s*$/, '').trim() : ''; };
      Object.keys(named).forEach(function (name) {
        var els = named[name], el = els[0];
        var required = els.some(function (x) { return x.required; }) || !!el.closest('.required, .wc-pao-required-addon, [data-required="1"], [data-required="true"]');
        if (!required) { return; }
        var values = [];
        if (el.tagName === 'SELECT') {
          [].slice.call(el.options).forEach(function (o) { if (o.value.trim()) { values.push({ value: o.value, label: o.textContent.replace(/\s+/g, ' ').trim() }); } });
        } else if (el.type === 'radio') {
          els.forEach(function (x) { if (x.value.trim()) { values.push({ value: x.value, label: (x.id && page.querySelector('label[for="' + x.id + '"]') || {}).textContent || x.value }); } });
        }
        if (!values.length) { out.free = true; return; }
        var seen = {};
        values = values.filter(function (v) { var k = v.label.toLowerCase(); if (seen[k]) { return false; } seen[k] = 1; return true; });
        out.fields.push({ name: name, label: labelOf(el) || t('options'), values: values.slice(0, 60) });
      });
      return out.fields.length || out.free ? out : null;
    }).catch(function () { return null; });
    return WOO_FIELDS[url];
  }
  // WooCommerce: added as its product form posts it, to the product's own page, then read back from the cart
  function addByForm(id, fields, url) {
    var form = new URLSearchParams(), origin = win.location.origin, target;
    try { var at = new URL(url, origin); target = origin + at.pathname + at.search; } catch (e) { target = url; }
    Object.keys(fields).forEach(function (name) { form.append(name, fields[name]); });
    return win.fetch(target, { method: 'POST', credentials: 'same-origin', body: form, redirect: 'follow' }).then(function () {
      return win.fetch(STORE + '/cart', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (res) { return res.ok ? res.json() : null; });
    }).then(function (cart) {
      return !!(cart && (cart.items || []).some(function (item) { return String(item.id) === String(fields.variation_id || fields['add-to-cart']) || String(item.parent || '') === String(fields['add-to-cart']); }));
    });
  }
  function readPageFields(page) {
    var forms = [].slice.call(page.querySelectorAll('form[action*="/cart/add"]'));
    // the product's own form, not a quick-add of another product on the page
    // the attribute, not .id: a form holding <input name="id"> (every Shopify one) answers .id with that input
    var idOf = function (f) { return f.getAttribute('id') || ''; };
    // the one the page's fields belong to (a theme puts them outside it, form="…"); a payments widget's form beside it has none
    var score = function (f) { var i = idOf(f); return f.querySelectorAll('input, select, textarea').length + (i ? page.querySelectorAll('[form="' + i + '"]').length * 2 : 0) + (f.closest('main') ? 1 : 0); };
    var main = forms.slice().sort(function (a, b) { return score(b) - score(a); })[0];
    if (!main) { return null; }
    var mine = function (el) { var f = el.getAttribute('form'); return f ? f === idOf(main) : main.contains(el); };
    var named = {}, out = { fields: [], fixed: {}, free: false };
    [].slice.call(page.querySelectorAll('[name^="properties["]')).forEach(function (el) {
      // "properties[_x]" is the store's own, never the shopper's
      if (/^properties\[_/.test(el.name) || !mine(el)) { return; }
      (named[el.name] = named[el.name] || []).push(el);
    });
    var labelOf = function (el) { var l = el.id && page.querySelector('label[for="' + el.id + '"]'); return l ? l.textContent.replace(/\s+/g, ' ').replace(/\s*\*\s*$/, '').trim() : ''; };
    Object.keys(named).forEach(function (name) {
      var els = named[name], el = els[0], key = name.replace(/^properties\[|\]$/g, ''), values = [];
      if (el.tagName === 'SELECT') {
        [].slice.call(el.options).forEach(function (o) { if (o.value.trim()) { values.push({ value: o.value, label: o.textContent.replace(/\s+/g, ' ').trim() }); } });
      } else if (el.type === 'radio') {
        els.forEach(function (r) { if (r.value.trim()) { values.push({ value: r.value, label: labelOf(r) || r.value }); } });
      } else if (el.type === 'hidden') {
        // a picker of the theme writes into it (Rocks and Gold's ring sizes): its own radios are the values
        if (!el.required) { return; }
        var box = el.closest('variant-picker, fieldset, .product-form__input, [class*="picker"], [data-block-type]') || el.parentNode, seen = {};
        [].slice.call(box.querySelectorAll('input[type="radio"]')).forEach(function (r) {
          var v = (r.getAttribute('data-original-value') || r.value).trim();
          if (v && !seen[v]) { seen[v] = 1; values.push({ value: v, label: labelOf(r) || v }); }
        });
        // nothing to choose, but the page set it (a ring that cannot be resized): it goes as it is
        if (!values.length && el.value.trim()) { out.fixed[key] = el.value.trim(); return; }
      } else if (el.required) {
        out.free = true;
        return;
      }
      if (values.length) {
        out.fields.push({ name: key, label: labelOf(el) || (/^size$/i.test(key) ? t('size') : key), values: values.slice(0, 60) });
      } else if (el.required) {
        out.free = true;
      }
    });
    return out;
  }
  function shopifyProducts(need) {
    var waits = [], queue = [];
    need.forEach(function (id) { if (SHOP_INFLIGHT[id]) { waits.push(SHOP_INFLIGHT[id]); } else { queue.push(id); } });
    var at = 0;
    var next = function () {
      if (at >= queue.length) { return null; }
      var id = queue[at++];
      // not known yet as anything with a page (a search restored before the index came): asked again later, never "none"
      if (!handleOf(id)) { return next(); }
      return shopifyJson(id).then(function (j) { var e = win.LA_ENGINE; S.products[id] = j && e && e.fromShopifyProduct ? e.fromShopifyProduct(j) : null; if (j) { S.products[id + ':json'] = j; } }).then(next);
    };
    if (queue.length) {
      var workers = [];
      for (var w = 0; w < Math.min(4, queue.length); w++) { workers.push(next()); }
      var batch = Promise.all(workers).then(function () { queue.forEach(function (id) { delete SHOP_INFLIGHT[id]; }); });
      queue.forEach(function (id) { SHOP_INFLIGHT[id] = batch; });
      waits.push(batch);
    }
    return Promise.all(waits);
  }
  function livePrice(id) {
    var p = S.products[pid(id)];
    if (!p || !p.prices || p.prices.price == null) { return null; }
    return Number(p.prices.price) / Math.pow(10, p.prices.currency_minor_unit || 0);
  }
  function regular(id) {
    var p = S.products[pid(id)];
    if (!p || !p.prices) { return null; }
    var unit = Math.pow(10, p.prices.currency_minor_unit || 0);
    var reg = Number(p.prices.regular_price) / unit;
    var now = Number(p.prices.price) / unit;
    return reg > now ? reg : null;
  }

  // opening hours, as the store set them
  function hours() {
    var h = S.config.hours || {};
    var days = h.days || [];
    if (!days.length) { return { open: true }; }
    var now = new Date();
    try { now = new Date(now.toLocaleString('en-US', { timeZone: h.timezone || 'Asia/Jerusalem' })); } catch (e) { /* local time */ }
    var forced = (S.config.forceHours || '');
    var mins = now.getHours() * 60 + now.getMinutes();
    function span(d) { var m = /^(\d\d):(\d\d)-(\d\d):(\d\d)$/.exec(days[d] || ''); return m ? [m[1] * 60 + +m[2], m[3] * 60 + +m[4], m[3] + ':' + m[4], m[1] + ':' + m[2]] : null; }
    var today = span(now.getDay());
    var open = forced ? forced === 'open' : !!(today && mins >= today[0] && mins < today[1]);
    if (open) { return { open: true, until: today ? today[2] : '' }; }
    for (var k = 0; k < 8; k++) {
      var d = (now.getDay() + k) % 7, s = span(d);
      if (s && (k > 0 || mins < s[0])) {
        var when = k === 0 ? t('today') : k === 1 ? t('tomorrow') : T.days[d];
        return { open: false, when: when + ' ' + t('at') + s[3] };
      }
    }
    return { open: false, when: '' };
  }
  function wa(text) { var n = S.config.whatsapp; return n ? 'https://wa.me/' + n + '?text=' + encodeURIComponent(text) : null; }

