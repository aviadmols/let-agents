  // ---------------------------------------------------------------- saved + compare
  function savedView() {
    if (!S.saved.length) {
      return '<div class="ls-saved-empty"><span class="ls-ibtn">' + icon('heart') + '</span><b>' + esc(t('savedEmptyT')) + '</b>' + esc(t('savedEmpty')) + '</div>';
    }
    var list = S.saved.map(rec).filter(Boolean);
    var html = '<div class="ls-saved-tools" style="padding-top:8px">' +
      (SHORT && SHORT.wishlistWhatsapp === false ? '' : '<button class="ls-btn ls-btn--secondary ls-btn--sm" data-share-wa>' + icon('whatsapp', 'ls-icon--sm') + esc(t('shareWa')) + '</button>') +
      '<button class="ls-btn ls-btn--secondary ls-btn--sm" data-copy>' + icon('link', 'ls-icon--sm') + esc(t('copyLink')) + '</button></div>';
    html += '<div>' + list.map(function (r) {
      var id = pid(r.id), on = S.pick.indexOf(id) >= 0;
      return '<div class="ls-saved-item"><label class="ls-check-row" title="' + esc(t('pickToCompare')) + '"><input type="checkbox" data-pick="' + esc(id) + '"' + (on ? ' checked' : '') + ' aria-label="' + esc(t('pickToCompare')) + '"></label>' +
        '<a class="ls-saved-item__media" href="' + esc(r.url) + '" tabindex="-1"><img src="' + esc(r.img) + '" alt="">' + pic2(r) + '</a>' +
        '<div class="ls-saved-item__body"><a class="ls-saved-item__t" dir="auto" href="' + esc(r.url) + '">' + esc(decode(r.title)) + '</a><div class="ls-saved-item__price">' + priceHtml(r) + (r.s === 0 ? ' <span class="ls-stock">' + esc(t('outStock')) + '</span>' : '') + '</div>' +
          '<div class="ls-saved-item__actions">' + buyBtn(r, 'ls-btn--sm') + (r.s === 0 ? '' : '<button class="ls-btn ls-btn--ghost ls-btn--sm ls-saved-item__sim" data-similar="' + esc(id) + '">' + icon('grid', 'ls-icon--sm') + esc(t('similar')) + '</button>') + '</div><div data-after="' + esc(id) + '"></div></div>' +
        '<button class="ls-ibtn ls-ibtn--sm ls-saved-item__x" aria-label="' + esc(t('remove')) + '" data-heart="' + esc(id) + '">' + icon('x', 'ls-icon--sm') + '</button></div>';
    }).join('') + '</div>';
    var n = S.pick.length;
    html += '<div class="ls-compare-bar"><button class="ls-btn ls-btn--primary" data-compare' + (n < 2 ? ' disabled' : '') + '>' + icon('compare', 'ls-icon--sm') + esc(n < 2 ? t('compareNeed') : t('compareN', { n: n + '/5' })) + '</button></div>';
    html += '<p class="pg-note" style="font-size:12px;color:var(--ls-fg-muted);margin:12px 0 0">' + esc(S.phone ? t('phoneKept', { p: S.phone }) : t('savedNote')) + (S.phone && WISH.phone ? ' · <button class="ls-btn ls-btn--link" style="font-size:12px" data-phone-out>' + esc(shortWord('phone_out', t('phoneOut'))) + '</button>' : '') + '</p>';
    if (!S.phone && !(SHORT && SHORT.phoneLogin === false)) { html += phoneBox(); }
    return html;
  }
  function phoneBox() {
    var st = S.phoneStep || '';
    if (!st) { return '<button class="ls-btn ls-btn--link" style="margin-top:8px" data-phone-start>' + esc(t('savedPhone')) + '</button>'; }
    return '<form class="ls-form" style="margin-top:12px;padding:14px;border-radius:16px;background:var(--ls-bg-sunken)" data-phone-form><div style="font-weight:500;font-size:14px">' + esc(t('savedPhone')) + '</div><div style="font-size:13px;color:var(--ls-fg-muted)">' + esc(t('savedPhoneHelp')) + '</div>' +
      (st === 'num' ? '<div class="ls-input"><label for="lsph">' + esc(t('phone')) + '</label><input id="lsph" name="phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" style="background:var(--ls-bg)"' + (S.phoneErr ? ' aria-invalid="true"' : '') + '>' + (S.phoneErr ? '<span class="ls-input__err">' + esc(S.phoneErr) + '</span>' : '') + '</div><button class="ls-btn ls-btn--primary ls-btn--sm">' + esc(t('sendCode')) + '</button>'
        : '<div class="ls-input"><label for="lscd">' + esc(t('code')) + '</label><input id="lscd" name="code" inputmode="numeric" autocomplete="one-time-code" dir="ltr" style="background:var(--ls-bg)"' + (S.phoneErr ? ' aria-invalid="true"' : '') + '>' + (S.phoneErr ? '<span class="ls-input__err">' + esc(S.phoneErr) + '</span>' : '') + '</div><label class="ls-check-row"><input type="checkbox" name="consent"><span>' + esc(shortWord('phone_consent', t('phoneConsent'))) + '</span></label><button class="ls-btn ls-btn--primary ls-btn--sm">' + esc(t('verifyCode')) + '</button>') + '</form>';
  }
  function compareHtml(turn) {
    if (turn.loading) { return '<div style="padding-top:12px">' + thinking() + '</div>'; }
    var d = turn.data || { products: [], rows: [] };
    var byLabel = {}, merged = [];
    (d.rows || []).forEach(function (r) {
      var m = byLabel[r.label];
      if (!m) { m = byLabel[r.label] = { label: r.label, values: r.values.slice() }; merged.push(m); return; }
      r.values.forEach(function (v, i) { if (m.values[i] == null) { m.values[i] = v; } });
    });
    merged.forEach(function (r) { var vals = r.values.map(function (v) { return v == null ? '' : String(v).toLowerCase(); }); r.differs = vals.some(function (v) { return v !== vals[0]; }); });
    var rows = merged.filter(function (r) { return !S.onlyDiff || r.differs; });
    return '<div class="ls-wide-head"><button class="ls-btn ls-btn--ghost ls-btn--sm" data-narrow style="margin-inline-start:-12px">' + icon('back', 'ls-icon--sm') + esc(t('saved')) + '</button>' +
      '<div class="ls-summary" style="margin-top:8px"><div class="ls-summary__count" style="font-size:20px;font-weight:500">' + esc(t('compareTitle')) + '</div><label class="ls-toggle"><input type="checkbox" data-onlydiff' + (S.onlyDiff ? ' checked' : '') + '>' + esc(t('onlyDiff')) + '</label></div></div>' +
      '<div class="ls-cmp-wrap ls-scroll"><table class="ls-cmp"><thead><tr><th></th>' + d.products.map(function (p) {
        var r = rec(p.id) || { id: p.id, buy: 0, s: p.in_stock ? 1 : 0 };
        return '<td><img class="ls-cmp__img" src="' + esc(p.image) + '" alt=""><a class="ls-cmp__t" dir="auto" href="' + esc(p.url) + '">' + esc(decode(p.title)) + '</a><div style="margin-top:8px">' + buyBtn(Object.assign({}, r, { id: p.id }), 'ls-btn--sm') + '</div></td>';
      }).join('') + '</tr></thead><tbody>' +
      '<tr class="is-diff"><th>' + esc(t('price')) + '</th>' + d.products.map(function (p) { return '<td class="ls-num">' + money(p.price) + (p.regular_price > p.price ? ' <s style="color:var(--ls-fg-muted);font-weight:400">' + money(p.regular_price) + '</s>' : '') + '</td>'; }).join('') + '</tr>' +
      '<tr><th>' + esc(t('stock')) + '</th>' + d.products.map(function (p) { return '<td>' + esc(p.in_stock ? t('inStock') : t('outStock')) + '</td>'; }).join('') + '</tr>' +
      rows.map(function (row) { return '<tr' + (row.differs ? ' class="is-diff"' : '') + '><th>' + esc(row.label) + '</th>' + row.values.map(function (v) { return '<td>' + esc(v == null ? '—' : v) + '</td>'; }).join('') + '</tr>'; }).join('') +
      '</tbody></table></div>';
  }
  function compare() {
    var ids = S.pick.slice(0, 5);
    var turn = { kind: 'compare', id: uid(), loading: true };
    S.turns.push(turn); S.wide = turn.id; S.tab = 'chat'; render();
    json(API + '/shortlist/' + SITE + '/compare?ids=' + ids.join(',')).then(function (r) { turn.loading = false; turn.data = r.body || {}; render(); })
      .catch(function () { turn.loading = false; turn.data = null; render(); });
  }
  function toggleSaved(id) {
    var at = S.saved.indexOf(id);
    if (at >= 0) { S.saved.splice(at, 1); S.pick = S.pick.filter(function (x) { return x !== id; }); } else { S.saved.unshift(id); }
    store('local', 'ls_saved', S.saved);
    var on = at < 0;
    if (on) { visitStep('heart', { item: String(pid(id)) }); }
    WISH.wish = S.saved.slice();
    WISH.pending = on ? [id].concat(WISH.pending.filter(function (w) { return w !== id; })) : WISH.pending.filter(function (w) { return w !== id; });
    wishSave();
    wishSend(on ? { add: [id] } : { remove: [id] }).then(wishTake).catch(function () { /* kept here; sent with the next change */ });
    root.querySelectorAll('[data-heart="' + id + '"]').forEach(function (b) { b.setAttribute('aria-pressed', String(on)); });
    paintSave(id, on); refreshLauncher();
    var c = D.querySelector('[data-count]'); c.textContent = S.saved.length; c.classList.remove('is-bump'); void c.offsetWidth; c.classList.add('is-bump');
    toast(on ? t('savedToast') : t('removedToast'), function () { toggleSaved(id); if (S.tab === 'saved') { render(); } });
    if (S.tab === 'saved') { render(); }
  }

  // ---------------------------------------------------------------- cart
  // q: the search or question it was added from, counted as an add to cart once the store's cart says yes
  // variant: Shopify's variant id, chosen in the options sheet. A cart that does not take it (a variant to
  // choose, a quantity rule, out of stock) sends the shopper on to the product's page, where the store adds it
  // its own way; from the options sheet, the store's own reason is shown instead.
  // properties: what the page asks besides the variant (a ring size), chosen in the same sheet (pageFields).
  function addToCart(btn, id, variation, q, parent, variant, onAdded, properties) {
    if (btn) { btn.dataset.state = 'busy'; btn.setAttribute('aria-busy', 'true'); }
    var done = function (sections) {
      if (btn) { btn.dataset.state = 'done'; btn.removeAttribute('aria-busy'); }
      announce(t('announceAdded'));
      cartChanged(id, sections);
      if (q) { countAdd(q, itemOf(parent || id)); }
      visitStep('cart', { item: String(pid(parent || id)), n: 1 });
      if (typeof onAdded === 'function') { try { onAdded(); } catch (e) { /* theirs */ } }
      afterAdd(parent || id, btn);
    };
    var failed = function (message) {
      if (btn) { btn.dataset.state = ''; btn.removeAttribute('aria-busy'); }
      var page = (rec(parent || id) || {}).url;
      if (!variation && !variant && page && /^https?:/.test(page)) { win.location.href = page; return; }
      var box = afterBox(parent || id, btn) || LAYER.querySelector('.ls-layer__foot');
      if (box) { box.insertAdjacentHTML('beforeend', '<div class="ls-input__err" role="alert" style="margin-top:8px;width:100%">' + esc(message || t('addFailed')) + '</div>'); }
    };
    if (SHOPIFY) {
      var add = function (vid) {
        var item = { id: Number(vid), quantity: 1 };
        if (properties && Object.keys(properties).length) { item.properties = properties; }
        return win.fetch(ROOT + 'cart/add.js', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          // the cart icon and drawer sections come back with the answer, the way Dawn and its relatives ask for them
          body: JSON.stringify({ items: [item], sections: 'cart-icon-bubble,cart-drawer', sections_url: win.location.pathname }) })
          .then(function (res) { if (!res.ok) { throw new Error('add'); } return res.json().catch(function () { return null; }); })
          .then(function (j) { done(j && j.sections); }, function () { failed(); });
      };
      if (variant) { return add(variant); }
      var choose = function () { if (btn) { btn.dataset.state = ''; btn.removeAttribute('aria-busy'); } optionsLayer(id, q); return null; };
      // a product read as none before (the page did not answer, or a search restored before the index): read once more
      // before the shopper is sent to its page instead of adding it
      if (S.products[pid(id)] === null) { delete S.products[pid(id)]; }
      return products([id]).then(function () {
        var p = S.products[pid(id)];
        if (p && p.variants > 1) { return choose(); }
        if (!p || !p.variant || !p.is_in_stock) { failed(); return null; }
        // one variant, but the page may still ask for something (a ring size): chosen first, never left out
        return pageFields(id).then(function (f) {
          if (f && f.free) { failed(); return null; }
          if (f && f.fields.length) { return choose(); }
          if (f && Object.keys(f.fixed).length) { properties = Object.assign({}, f.fixed, properties || {}); }
          return add(p.variant);
        });
      });
    }
    if (!STORE) { failed(); return Promise.resolve(); }
    var body = { id: Number(id), quantity: 1 }, headers = { 'Content-Type': 'application/json' };
    if (variation) { body.variation = variation; }
    if (nonce) { headers.Nonce = nonce; }
    return win.fetch(STORE + '/cart/add-item', { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify(body) }).then(function (res) {
      var fresh = res.headers.get('Nonce');
      if (fresh) { nonce = fresh; }
      return res.json().catch(function () { return {}; }).then(function (b) {
        if (res.ok) { done(); return; }
        // refused for a choice the product's page asks (Gueta's lengths): the choices, here, instead of its page
        if (!variation && res.status === 400) {
          return wooPageFields(parent || id).then(function (page) {
            if (page && page.fields.length && !page.free) { if (btn) { btn.dataset.state = ''; btn.removeAttribute('aria-busy'); } optionsLayer(parent || id, q); return; }
            failed(b && b.message);
          });
        }
        failed(b && b.message);
      });
    }, function () { failed(); });
  }
  function formToCart(btn, id, variationId, variation, properties, url, q) {
    if (btn) { btn.dataset.state = 'busy'; btn.setAttribute('aria-busy', 'true'); }
    var fields = Object.assign({ 'add-to-cart': String(id), quantity: '1' }, properties);
    if (variationId) {
      fields.variation_id = String(variationId);
      (variation || []).forEach(function (v) { fields['attribute_' + String(v.attribute).toLowerCase()] = v.value; });
    }
    return addByForm(id, fields, url || (rec(id) || {}).url).then(function (ok) {
      if (btn) { btn.dataset.state = ok ? 'done' : ''; btn.removeAttribute('aria-busy'); }
      if (!ok) { var box = LAYER.querySelector('.ls-layer__foot'); if (box) { box.insertAdjacentHTML('beforeend', '<div class="ls-input__err" role="alert" style="margin-top:8px;width:100%">' + esc(t('addFailed')) + '</div>'); } return; }
      announce(t('announceAdded'));
      cartChanged(id);
      if (q) { countAdd(q, itemOf(id)); }
      visitStep('cart', { item: String(pid(id)), n: 1 });
      afterAdd(id, btn);
    }, function () { if (btn) { btn.dataset.state = ''; btn.removeAttribute('aria-busy'); } });
  }
  // the confirmation goes under the button that was pressed (the same product can be in the live results and a turn)
  // the store's own cart shows what went in: WooCommerce's fragments, and the events a theme listens to
  function cartChanged(id, sections) {
    if (SHOPIFY) { shopifyCartChanged(sections); }
    try { if (!SHOPIFY && win.jQuery) { win.jQuery(doc.body).trigger('wc_fragment_refresh'); } } catch (e) { /* a theme without jQuery */ }
    try {
      doc.body.dispatchEvent(new CustomEvent('let-agents:added_to_cart', { bubbles: true, detail: { id: id } }));
      doc.body.dispatchEvent(new CustomEvent('added_to_cart', { bubbles: true }));
      win.dispatchEvent(new CustomEvent('lets:added', { detail: { id: id } }));
    } catch (e) { /* old browser */ }
  }
  // Shopify themes show the cart in many ways and none is standard: Dawn's cart icon section, counters fed
  // from cart.js, and the events several themes listen to for redrawing their cart drawer
  function shopifyCartChanged(sections) {
    var bubble = doc.getElementById('cart-icon-bubble'), html = sections && sections['cart-icon-bubble'];
    if (bubble && typeof html === 'string' && win.DOMParser) {
      var section = new win.DOMParser().parseFromString(html, 'text/html').querySelector('.shopify-section');
      if (section) { bubble.innerHTML = section.innerHTML; }
    }
    var tell = function (cart) {
      ['cart:refresh', 'cart:updated'].forEach(function (name) {
        try { doc.documentElement.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: { cart: cart } })); doc.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: { cart: cart } })); } catch (e) { /* old */ }
      });
    };
    win.fetch(ROOT + 'cart.js', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (res) { return res.ok ? res.json() : null; }).then(function (cart) {
      if (cart && cart.item_count != null) { doc.querySelectorAll('[data-cart-count], .cart-count-bubble span[aria-hidden="true"]').forEach(function (n) { n.textContent = String(cart.item_count); }); }
      tell(cart);
    }).catch(function () { tell(null); });
  }
  function afterBox(id, btn) {
    var holder = btn && btn.closest && btn.closest('.ls-card, .ls-pick, .ls-exact, .ls-of, .ls-saved-item');
    return (holder && holder.querySelector('[data-after="' + id + '"]')) || BODY.querySelector('[data-after="' + id + '"]');
  }
  function afterAdd(id, btn) {
    var box = afterBox(id, btn);
    if (!box) { return; }
    box.innerHTML = '<div class="ls-added ls-focus-in" style="justify-content:space-between">' + icon('cart', 'ls-icon--sm') + '<span style="flex:1">' + esc(LANG === 'he' ? 'בעגלה שלכם' : 'In your cart') + '</span><a class="ls-btn ls-btn--link" href="' + esc(CFG.cartUrl) + '">' + esc(t('toCart')) + '</a></div>';
    json(API + '/widget/' + SITE + '/page?id=' + encodeURIComponent(id)).then(function (r) {
      var b = (r.body && (r.body.data || r.body)) || {}, comp = null;
      (b.sections || []).forEach(function (s) { if (s.candidate === 'complement' && s.products && s.products.length) { comp = s; } });
      if (!comp) { return; }
      return products(comp.products.map(function (p) { return pid(p.id); })).then(function () {
        box.insertAdjacentHTML('beforeend', '<div class="ls-block ls-focus-in" style="margin-top:12px"><div class="ls-block__title"><span>' + esc(t('goesWith')) + '</span><button class="ls-why" data-why>' + esc(t('whyShown')) + '</button></div>' + miniRow(comp.products) + '</div>');
        requestAnimationFrame(sliders);
      });
    }).catch(function () { /* nothing to add */ });
  }
  function optionsLayer(id, q) {
    showLayer(t('chooseTitle'), '<div style="padding:24px 0">' + thinking() + '</div>', '');
    var source = SHOPIFY ? Promise.all([shopifyJson(id), pageFields(id)]).then(function (both) {
      // Shopify's options and variants in the shape the sheet reads (an option with one value is no choice),
      // and what the page asks besides them (a ring size), each as one more choice the variants do not hold
      var j = both[0], page = both[1];
      if (!j || !j.variants) { return null; }
      var names = (j.options || []).map(function (o) { return typeof o === 'string' ? o : o.name; });
      var props = ((page && page.fields) || []).map(function (f) { return { name: f.label, prop: f.name, has_variations: true, terms: f.values.map(function (v) { return { slug: v.value, name: v.label }; }) }; });
      return { name: j.title, fixed: (page && page.fixed) || {}, images: j.featured_image ? [{ src: String(j.featured_image).replace(/^\/\//, 'https://') }] : [],
        attributes: names.map(function (n, at) { var vals = []; j.variants.forEach(function (v) { var x = v['option' + (at + 1)]; if (x != null && vals.indexOf(x) === -1) { vals.push(x); } }); return { name: n, has_variations: vals.length > 1 || (names.length === 1 && j.variants.length > 1), terms: vals.map(function (x) { return { slug: x, name: x }; }) }; }).concat(props),
        variations: j.variants.map(function (v) { return { id: v.id, available: v.available !== false, attributes: names.map(function (n, at) { return { name: n, value: v['option' + (at + 1)] }; }) }; }) };
    }) : Promise.all([json(STORE + '/products/' + encodeURIComponent(id), { credentials: 'same-origin' }), wooPageFields(id)]).then(function (both) {
      var p = both[0].body || null, page = both[1];
      // a required field to type in can be filled only on the product's page
      if (page && page.free) { var r0 = rec(id); if (r0 && r0.url) { win.location.href = r0.url; } return null; }
      if (p && page && page.fields.length) {
        p.formUrl = page.url;
        // the page asks it, not the variations: a simple product has none, and an attribute the page asks by the
        // same name (Gueta's "אורך") is the page's, with the page's values
        var asked = page.fields.map(function (f) { return f.label; });
        p.attributes = (p.type === 'simple' ? [] : (p.attributes || []).filter(function (a) { return asked.indexOf(a.name) === -1; })).concat(page.fields.map(function (f) { return { name: f.label, prop: f.name, has_variations: true, terms: f.values.map(function (v) { return { slug: v.value, name: v.label }; }) }; }));
      }
      return p;
    });
    source.then(function (p) {
      if (!p) { throw new Error('none'); }
      var attrs = (p.attributes || []).filter(function (a) { return a.has_variations; }), vars = (p.variations || []).filter(function (v) { return v.available !== false; }), chosen = {};
      var rr = rec(id) || {};
      function slugOf(a, term) { return term.slug; }
      function match() {
        return vars.filter(function (v) { return attrs.every(function (a) { var want = chosen[a.name]; if (!want) { return true; } var x = (v.attributes || []).filter(function (y) { return y.name === a.name; })[0]; return !x || !x.value || x.value === want.slug || decode(x.value) === want.name; }); });
      }
      function draw() {
        var missing = attrs.filter(function (a) { return !chosen[a.name]; });
        var head = '<div class="ls-variant-head"><img class="ls-about__img" style="width:72px;height:72px" src="' + esc(rr.img || (p.images && p.images[0] && p.images[0].src) || '') + '" alt=""><div><div class="ls-pick__title" dir="auto">' + esc(decode(p.name || rr.title)) + '</div><div style="margin-top:4px">' + priceHtml(rr) + '</div></div></div>';
        var groups = attrs.map(function (a) {
          return '<div class="ls-opt-group"><div class="ls-opt-group__t">' + esc(a.name) + (chosen[a.name] ? ' <span>· ' + esc(chosen[a.name].name) + '</span>' : '') + '</div><div class="ls-chips" style="flex-wrap:wrap;overflow:visible;mask-image:none;-webkit-mask-image:none">' +
            a.terms.map(function (term) {
              var test = Object.assign({}, chosen); test[a.name] = term;
              // only the variations are checked: a choice the page asks (a length) is there for any of them, also with none
              var ok = !attrs.some(function (z) { return !z.prop; }) || vars.some(function (v) { return attrs.every(function (b2) { var want = test[b2.name]; if (!want) { return true; } var x = (v.attributes || []).filter(function (y) { return y.name === b2.name; })[0]; return !x || !x.value || x.value === want.slug || decode(x.value) === want.name; }); });
              var on = chosen[a.name] && chosen[a.name].slug === term.slug;
              return '<button class="ls-chip" aria-pressed="' + !!on + '"' + (ok ? '' : ' disabled style="opacity:.4;text-decoration:line-through"') + ' data-var="' + esc(a.name) + '|' + esc(term.slug) + '"><span class="ls-chip__check">' + icon('check', 'ls-icon--sm') + '</span>' + esc(term.name) + '</button>';
            }).join('') + '</div></div>';
        }).join('');
        var foot = '<button class="ls-btn ls-btn--primary ls-btn--lg ls-add" data-var-add' + (missing.length ? ' disabled' : '') + '><span class="ls-add__label">' + esc(missing.length ? t('choose', { a: missing[0].name }) : t('add')) + '</span><span class="ls-add__busy">' + mark(true) + '</span><span class="ls-add__done"><svg class="ls-icon ls-icon--sm ls-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg>' + esc(t('added')) + '</span></button>';
        LAYER.querySelector('.ls-layer__body').innerHTML = head + groups;
        LAYER.querySelector('.ls-layer__foot').innerHTML = foot;
      }
      LAYER.onclick = function (e) {
        var v = e.target.closest('[data-var]');
        if (v && !v.disabled) { var parts = v.dataset.var.split('|'); var a = attrs.filter(function (x) { return x.name === parts[0]; })[0]; var term = a.terms.filter(function (x) { return x.slug === parts[1]; })[0]; chosen[a.name] = chosen[a.name] && chosen[a.name].slug === term.slug ? null : term; if (!chosen[a.name]) { delete chosen[a.name]; } draw(); return; }
        var add = e.target.closest('[data-var-add]');
        if (add && !add.disabled) {
          var m = match()[0];
          // WooCommerce: each attribute by its taxonomy when it has one (pa_color), as its add-item reads it
          var variation = attrs.filter(function (a) { return !a.prop; }).map(function (a) { return { attribute: a.taxonomy || a.name, value: chosen[a.name].slug }; });
          // the page's own fields go as the line's properties ("Size": "49"), as its product form posts them
          var properties = Object.assign({}, p.fixed || {});
          attrs.forEach(function (a) { if (a.prop) { properties[a.prop] = chosen[a.name].slug; } });
          var adding = SHOPIFY ? addToCart(add, id, null, q, id, m ? m.id : null, null, properties)
            : Object.keys(properties).length ? formToCart(add, id, m && attrs.some(function (a) { return !a.prop; }) ? m.id : null, variation, properties, p.formUrl, q)
            : addToCart(add, m ? m.id : id, variation.length ? variation : null, q, id);
          Promise.resolve(adding).then(function () { if (add.dataset.state === 'done') { setTimeout(hideLayer, 900); } });
        }
      };
      draw();
    }).catch(function () { LAYER.querySelector('.ls-layer__body').innerHTML = '<p class="ls-noanswer__text">' + esc(t('addFailed')) + '</p>'; });
  }

