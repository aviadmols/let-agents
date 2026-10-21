  // ---------------------------------------------------------------- size help on a product page (as before)
  // A size gone in the colour chosen is marked on the theme's own buttons, with a line above them; a tap on it
  // opens similar items that have that size in stock now. Only in a shop that turned it on (/sizes), in its words.
  function sizeOptionIndex(options) {
    for (var i = 0; options && i < options.length; i++) { var n = typeof options[i] === 'string' ? options[i] : (options[i] && options[i].name) || ''; if (/מידה|מידות|גודל|size/i.test(n)) { return i; } }
    return -1;
  }
  function sizeValue(v) { return v == null ? '' : String(v).replace(/\s+/g, ' ').trim().toLowerCase(); }
  var SIZE_WORDS = null;
  function sizeWord(key, vars) { var w = (SIZE_WORDS && SIZE_WORDS[key]) || t(key); Object.keys(vars || {}).forEach(function (k) { w = String(w).split(':' + k).join(vars[k]); }); return w; }
  function sizeHelp() {
    if (!PAGE || PAGE.type !== 'product') { return; }
    var form = doc.querySelector('form[action*="/cart/add"]') || doc.querySelector('form.variations_form') || doc.querySelector('form.cart');
    if (!form) { return; }
    var scope = form.closest('product-info, .product, [id*="MainProduct"], main, section') || doc.body, lastOpen = 0;
    var esc2 = function (v) { return win.CSS && win.CSS.escape ? win.CSS.escape(v) : String(v).replace(/["\\]/g, '\\$&'); };
    function readProduct() {
      if (SHOPIFY) {
        var handle = /\/products\/([^\/?#]+)/.exec(win.location.pathname);
        if (!handle) { return Promise.resolve(null); }
        return win.fetch(ROOT + 'products/' + handle[1] + '.js', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
          var at = j ? sizeOptionIndex(j.options) : -1;
          return at < 0 ? null : { at: 'option' + (at + 1), keys: ['option1', 'option2', 'option3'], variants: (j.variants || []).map(function (v) { return { id: String(v.id), values: { option1: v.option1, option2: v.option2, option3: v.option3 }, available: !!v.available }; }) };
        }).catch(function () { return null; });
      }
      var list = null;
      try { list = JSON.parse(form.getAttribute('data-product_variations') || 'null'); } catch (e) { list = null; }
      if (!list || !list.length) { return Promise.resolve(null); }
      var keys = Object.keys(list[0].attributes || {}), at = null;
      keys.forEach(function (k) {
        if (at) { return; }
        var select = form.querySelector('[name="' + k + '"]'), named = (select && select.getAttribute('data-attribute_name')) || k, cap = select && select.closest('tr') && select.closest('tr').querySelector('label');
        if (/מידה|מידות|גודל|size/i.test(named + ' ' + (cap ? cap.textContent : ''))) { at = k; }
      });
      return Promise.resolve(at ? { at: at, keys: keys, variants: list.map(function (v) { return { id: String(v.variation_id), values: v.attributes || {}, available: !!(v.is_in_stock && v.variation_is_active !== false && v.is_purchasable !== false) }; }) } : null);
    }
    // the other choices made now (the colour): a size gone in black but there in tan is not gone
    function chosen(product) {
      var others = {};
      if (SHOPIFY) {
        var idField = form.querySelector('[name="id"]'), current = product.variants.filter(function (v) { return idField && v.id === String(idField.value); })[0];
        if (!current) { return null; }
        product.keys.forEach(function (k) { if (k !== product.at && current.values[k] != null) { others[k] = current.values[k]; } });
        return others;
      }
      product.keys.forEach(function (k) { var field = form.querySelector('[name="' + k + '"]'); if (k !== product.at && field && field.value) { others[k] = field.value; } });
      return others;
    }
    function goneSizes(product) {
      var others = chosen(product) || {}, seen = {}, gone = [];
      product.variants.forEach(function (v) {
        var size = v.values[product.at];
        if (size == null || size === '') { return; }
        for (var k in others) { if (Object.prototype.hasOwnProperty.call(others, k) && v.values[k] !== '' && String(v.values[k]) !== String(others[k])) { return; } }
        var key = sizeValue(size);
        if (!seen[key]) { seen[key] = { value: String(size), available: false }; }
        seen[key].available = seen[key].available || v.available;
      });
      Object.keys(seen).forEach(function (k) { if (!seen[k].available) { gone.push(seen[k].value); } });
      return gone;
    }
    function sizeLabel(product, value) {
      var select = !SHOPIFY && form.querySelector('select[name="' + product.at + '"]'), option = select && select.querySelector('option[value="' + esc2(value) + '"]');
      return option ? option.textContent.trim() : value;
    }
    function swatches(value) {
      var found = [], v = esc2(value);
      scope.querySelectorAll('input[type="radio"][value="' + v + '"], [data-value="' + v + '"], [data-option-value="' + v + '"], button[value="' + v + '"]').forEach(function (node) {
        if (node.tagName === 'INPUT') {
          var tag = (node.id && scope.querySelector('label[for="' + esc2(node.id) + '"]')) || node.closest('label');
          if (tag && found.indexOf(tag) === -1) { found.push(tag); }
          node.setAttribute('data-la-size-gone', value);
          return;
        }
        if (found.indexOf(node) === -1 && !node.closest('[data-la-size-help]')) { found.push(node); }
      });
      return found;
    }
    function clear() {
      doc.querySelectorAll('[data-la-size-gone]').forEach(function (n) { n.removeAttribute('data-la-size-gone'); });
      doc.querySelectorAll('[data-la-size-badge], [data-la-size-help]').forEach(function (n) { n.parentNode.removeChild(n); });
    }
    // the line above the sizes, in our look; where the theme's sizes were not found, the gone sizes to tap in it
    function banner(product, gone, before, withChips) {
      var host = doc.createElement('div');
      host.setAttribute('data-la-size-help', '');
      host.style.margin = '0 0 10px';
      dressHost(host);
      var sr = host.attachShadow({ mode: 'open' });
      sr.innerHTML = styles(['tokens.css', 'components.css']) + '<style>:host{all:initial;display:block}.b{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px 14px;border-radius:14px;background:var(--ls-bg-sunken);color:var(--ls-fg);font:500 14px/1.45 var(--ls-font)}.b .ls-mark{--s:16px}.b .ls-chips{width:100%;flex-wrap:wrap;overflow:visible;mask-image:none;-webkit-mask-image:none}</style>' +
        '<div class="ls b" dir="' + DIR + '">' + mark() + '<span>' + esc(sizeWord(withChips ? 'size_gone_pick' : 'size_gone_banner')) + '</span>' +
        (withChips ? '<div class="ls-chips">' + gone.map(function (v) { return '<button class="ls-chip" type="button" data-size="' + esc(v) + '">' + esc(sizeLabel(product, v)) + '</button>'; }).join('') + '</div>' : '') + '</div>';
      sr.addEventListener('click', function (e) { var b = e.target.closest('[data-size]'); if (b) { openSize(b.getAttribute('data-size'), b.textContent.trim()); } });
      before.parentNode.insertBefore(host, before);
    }
    function markGone(product) {
      clear();
      var gone = goneSizes(product), first = null;
      if (!gone.length) { return; }
      gone.forEach(function (value) {
        swatches(value).forEach(function (node) {
          node.setAttribute('data-la-size-gone', value);
          node.setAttribute('title', sizeWord('size_gone_badge'));
          if (win.getComputedStyle(node).position === 'static') { node.style.position = 'relative'; }
          var badge = doc.createElement('span');
          badge.setAttribute('data-la-size-badge', ''); badge.setAttribute('aria-hidden', 'true');
          badge.setAttribute('style', 'position:absolute;top:3px;inset-inline-start:3px;width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.55;pointer-events:none');
          node.appendChild(badge);
          first = first || node;
        });
      });
      if (first) { banner(product, gone, first.closest('fieldset, variant-radios, variant-selects, .variant-picker, .product-form__input, .swatch, .variations') || first.parentNode, false); }
      else { banner(product, gone, form, true); }
    }
    function openSize(value, shown) {
      if (Date.now() - lastOpen < 600) { return; }
      lastOpen = Date.now();
      open({ focus: false });
      loadIndex().then(function () { similarTurn(String(PAGE.id), { value: value, shown: shown || value }); });
    }
    // a tap on a marked size: the theme still selects it (and shows its own "sold out"); the drawer opens
    doc.addEventListener('click', function (e) {
      var tg = e.target && e.target.closest ? e.target.closest('[data-la-size-gone]') : null;
      if (tg && SIZE_WORDS) { var v = tg.getAttribute('data-la-size-gone'); openSize(v, tg.textContent.trim() || v); }
    }, true);
    readProduct().then(function (product) {
      if (!product || !goneSizes(product).length) { return; }
      json(API + '/search/' + SITE + '/sizes?locale=' + LANG).then(function (r) {
        var d = r.ok && r.body;
        if (!d || !d.enabled) { return; }
        SIZE_WORDS = d.labels || {};
        markGone(product);
        // another colour chosen: the sizes gone change with it; themes redraw the picker, so a beat later
        form.addEventListener('change', function () { setTimeout(function () { markGone(product); }, 350); });
      }).catch(function () { /* the page stays as the theme drew it */ });
    });
  }
  // the size, among what the store has in stock now: Shopify's sizes, or a WooCommerce attribute's terms
  function hasSize(id, size) {
    var item = S.products[pid(id)], want = sizeValue(size.value), shown = sizeValue(size.shown);
    if (!item || item.is_in_stock === false) { return false; }
    if (SHOPIFY) { return !!item.sizes && (item.sizes.indexOf(want) !== -1 || item.sizes.indexOf(shown) !== -1); }
    return (item.attributes || []).some(function (a) { return (a.terms || []).some(function (term) { return sizeValue(term.slug) === want || sizeValue(term.name) === shown; }); });
  }

  // ---------------------------------------------------------------- a product or a variant sold out (search.out_of_stock_similar)
  // On a product page whose product is sold out, or whose chosen variant is (a size, a colour), a Lets row under
  // "add to cart", only while it is so: a press opens similar products that are in stock, in the same size.
  // What is sold out is read from the page as the store draws it: WooCommerce's own stock line and its
  // variation events; Shopify's product JSON and the variant the page has chosen.
  var SIZE_NAME = /מידה|מידות|גודל|size/i;
  function cartForm() {
    return doc.querySelector('form.variations_form') || doc.querySelector(SHOPIFY ? 'product-form form[action*="/cart/add"], form[action*="/cart/add"]' : 'form.cart') || null;
  }
  function outOfStockRow() {
    var on = LOOK.oosSimilar === true && LOOK.enabled !== false && !CFG.silent && !!PAGE && PAGE.type === 'product';
    if (!on) { if (OOS.host) { OOS.host.hidden = true; } return; }
    if (OOS.watching) { showOos(OOS.state); return; }
    OOS.watching = true;
    watchStock();
  }
  function showOos(state) {
    OOS.state = state || null;
    var on = !!state && LOOK.oosSimilar === true && LOOK.enabled !== false;
    if (!on) { if (OOS.host) { OOS.host.hidden = true; } return; }
    if (!OOS.host || !doc.body.contains(OOS.host)) { OOS.host = oosHost(); OOS.text = null; if (!OOS.host) { return; } }
    var text = state.whole ? t('oosProduct') : state.size ? t('oosSize', { v: state.size.shown }) : t('oosOption', { v: state.want.map(function (w) { return w.shown; }).join(' · ') });
    var box = OOS.host.shadowRoot.querySelector('.ls'), label = OOS.host.shadowRoot.querySelector('.ls-oos__text');
    box.setAttribute('dir', DIR); box.setAttribute('lang', LANG);
    if (OOS.text !== text) { OOS.text = text; label.textContent = text; }
    OOS.host.hidden = false;
  }
  // the row, in the look of the product page's search row (.lets-inline), right under the store's add-to-cart form
  function oosHost() {
    var at = cartForm() || doc.querySelector('.summary .stock.out-of-stock, .entry-summary .stock.out-of-stock, .product p.stock.out-of-stock');
    if (!at || !at.parentNode) { return null; }
    var h = doc.createElement('div');
    h.className = 'lets-oos';
    h.hidden = true;
    dressHost(h);
    var sr = h.attachShadow({ mode: 'open' });
    sr.innerHTML = styles(['tokens.css', 'components.css', 'drawer.css']) +
      '<style>:host{all:initial;display:block;margin:14px 0 4px}:host([hidden]){display:none}.ls-oos{width:100%;cursor:pointer;text-align:start;font:inherit;color:var(--ls-fg);gap:10px}.ls-oos__text{flex:1;min-width:0;font-size:14px;line-height:1.4}.ls-oos .ls-icon{flex:none;opacity:.7}</style>' + SPRITE +
      '<div class="ls ls-inline" dir="' + DIR + '" lang="' + LANG + '"><button type="button" class="ls-field ls-inline__field ls-oos"><span class="ls-ask__mark ls-inline__mark">' + mark() + '</span><span class="ls-oos__text"></span>' + icon('fwd', 'ls-icon--sm') + '</button></div>';
    sr.querySelector('button').addEventListener('click', function () {
      var st = OOS.state;
      if (!st || !PAGE) { return; }
      open({ focus: false });
      loadIndex().then(function () { similarTurn(String(PAGE.id), null, { whole: !!st.whole, want: st.want || [], size: st.size || null }); });
    });
    // right under the form: what is gone first, then our question row
    at.parentNode.insertBefore(h, at.nextSibling);
    return h;
  }
  // the state the row shows, from the options chosen ({ value, shown, size }): a size that is gone is what the
  // shopper looks for, with the colour they chose when there is one
  function oosState(options) {
    var want = options.filter(function (o) { return o.value !== '' && o.value != null; });
    if (!want.length) { return { whole: true, want: [], size: null }; }
    var size = want.filter(function (o) { return o.size; })[0] || null;
    return { whole: false, want: size ? want.filter(function (o) { return o === size || !o.size; }) : want, size: size ? { value: size.value, shown: size.shown } : null };
  }
  function watchStock() {
    if (SHOPIFY) { watchShopifyStock(); return; }
    var form = doc.querySelector('form.variations_form');
    // the product sold out as a whole: WooCommerce's own stock line in the summary (not a variation's)
    var whole = [].slice.call(doc.querySelectorAll('.summary .stock.out-of-stock, .entry-summary .stock.out-of-stock, .product p.stock.out-of-stock')).filter(function (n) { return !n.closest('.woocommerce-variation, .single_variation_wrap'); })[0];
    if (whole) { showOos({ whole: true, want: [], size: null }); return; }
    if (!form) { return; }
    var label = function (key, value) {
      var select = form.querySelector('[name="' + key + '"]'), option = select && [].slice.call(select.options || []).filter(function (o) { return o.value === value; })[0];
      var row = select && select.closest('tr'), cap = row && row.querySelector('label, th');
      return { name: ((select && select.getAttribute('data-attribute_name')) || key) + ' ' + (cap ? cap.textContent : ''), shown: option ? option.textContent.trim() : value };
    };
    var found = function (v) {
      if (!v || v.is_in_stock !== false) { showOos(null); return; }
      showOos(oosState(Object.keys(v.attributes || {}).map(function (k) {
        // an attribute the variation leaves open ("any size") is the one the shopper chose on the page
        var value = String(v.attributes[k] || (form.querySelector('[name="' + k + '"]') || {}).value || ''), l = label(k, value);
        return { value: value, shown: l.shown, size: SIZE_NAME.test(l.name) };
      })));
    };
    if (win.jQuery) {
      win.jQuery(form).on('found_variation.lets', function (e, v) { found(v); }).on('reset_data.lets hide_variation.lets', function () { showOos(null); });
      return;
    }
    // without jQuery: the variation's own stock line, as WooCommerce writes it
    new MutationObserver(function () {
      var gone = form.querySelector('.woocommerce-variation-availability .stock.out-of-stock');
      if (!gone) { showOos(null); return; }
      showOos(oosState([].slice.call(form.querySelectorAll('select[name^="attribute_"]')).map(function (s) {
        var l = label(s.name, s.value);
        return { value: s.value, shown: l.shown, size: SIZE_NAME.test(l.name) };
      })));
    }).observe(form, { childList: true, subtree: true });
  }
  function watchShopifyStock() {
    var handle = /\/products\/([^\/?#]+)/.exec(win.location.pathname);
    if (!handle) { return; }
    win.fetch(ROOT + 'products/' + handle[1] + '.js', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
      if (!j || !j.variants) { return; }
      if (j.available === false) { showOos({ whole: true, want: [], size: null }); return; }
      var names = (j.options || []).map(function (o) { return typeof o === 'string' ? o : (o && o.name) || ''; });
      var chosen = function () {
        var form = cartForm(), field = form && form.querySelector('[name="id"]'), id = field && field.value;
        if (!id) { try { id = new URLSearchParams(win.location.search).get('variant'); } catch (e) { id = null; } }
        return j.variants.filter(function (v) { return String(v.id) === String(id); })[0] || null;
      };
      var last = null;
      var check = function () {
        var v = chosen(), key = v ? String(v.id) : '';
        if (key === last) { return; }
        last = key;
        if (!v || v.available !== false) { showOos(null); return; }
        showOos(oosState(names.map(function (n, i) {
          var value = v.options ? v.options[i] : v['option' + (i + 1)];
          return { value: value == null || value === 'Default Title' ? '' : String(value), shown: value == null ? '' : String(value), size: SIZE_NAME.test(n) };
        })));
      };
      check();
      // themes change the variant in many ways: the form's own change, their events, the address
      var soon = function () { setTimeout(check, 200); };
      doc.addEventListener('change', soon, true);
      doc.addEventListener('click', function (e) { if (e.target && e.target.closest && e.target.closest('variant-radios, variant-selects, variant-picker, .product-form__input, [data-variant], [name^="options"]')) { soon(); } }, true);
      ['variant:change', 'variant:changed', 'variantChange', 'on:variant:change'].forEach(function (name) { doc.addEventListener(name, soon); });
      win.addEventListener('popstate', soon);
    }).catch(function () { /* the page stays as the theme drew it */ });
  }

