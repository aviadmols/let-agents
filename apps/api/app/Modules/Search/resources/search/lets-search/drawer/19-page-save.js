  // ---------------------------------------------------------------- saving from the store's own pages
  // A "save" heart beside the add-to-cart button of the product page, and on every product card of a
  // category page (shortlist.heart_on_product / heart_on_cards / heart_place, read with the shortlist config).
  // A save keeps the product in the drawer's Saved list (toggleSaved) and shows where it went, as Aviad's
  // lets-save.js demo does (2026-10-18): the heart fills, the picture flies to the launcher, the launcher's
  // mark turns over into a heart for a moment, and a dot stays on it. No number in the header: the cart stays
  // the only counter. The first save shows a hint under the launcher. A theme can place its own button:
  // <button data-lets-save="ID" data-lets-image="#img">; on Shopify a card is known by its handle, and the
  // id comes from /products/{handle}.js on the first save.
  var LAUNCHERS = [], SAVE_HINT_KEY = 'ls_save_hint_seen', REDUCED = false;
  try { REDUCED = win.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { /* none */ }
  var SAVE_CSS = '.ls-save{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 16px;border:1.5px solid currentColor;border-radius:999px;background:transparent;color:inherit;font:inherit;font-weight:500;line-height:1;cursor:pointer;margin:8px 8px 0 0;vertical-align:middle}' +
    '.ls-save svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linejoin:round;transition:fill .16s ease;flex:none}' +
    '.ls-save[aria-pressed="true"] svg{fill:currentColor;animation:ls-pop .42s cubic-bezier(.23,1,.32,1)}' +
    '.ls-save--card{position:absolute;top:8px;inset-inline-end:8px;z-index:3;width:36px;height:36px;min-height:0;padding:0;margin:0;border:0;border-radius:50%;background:#fff;color:#0b0b0c;box-shadow:0 1px 4px rgb(0 0 0/.18)}' +
    '.ls-save--card svg{width:18px;height:18px}' +
    '.ls-save--inline{position:static;margin-inline-start:8px;vertical-align:middle;box-shadow:inset 0 0 0 1.5px currentColor;background:transparent;color:inherit}' +
    '.ls-save-host{position:relative!important}' +
    '.ls-fly{position:fixed;z-index:2147483646;pointer-events:none;object-fit:cover;border-radius:12px;box-shadow:0 10px 28px -10px rgb(11 11 12/.3)}' +
    '.ls-save-hint{position:fixed;z-index:2147483647;display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:14px;background:#0b0b0c;color:#fff;font:500 13px/1.3 "Google Sans",system-ui,sans-serif;box-shadow:0 10px 28px -10px rgb(11 11 12/.4);opacity:0;transform:translateY(-6px) scale(.98);transition:opacity .22s ease,transform .26s cubic-bezier(.23,1,.32,1);pointer-events:none}' +
    '.ls-save-hint.is-on{opacity:1;transform:none;pointer-events:auto}' +
    '.ls-save-hint button{all:unset;cursor:pointer;font-weight:600;text-decoration:underline;text-underline-offset:3px}' +
    '.ls-save-hint button:focus-visible{outline:2px solid #fff;outline-offset:2px;border-radius:4px}' +
    '@keyframes ls-pop{0%{transform:scale(1)}35%{transform:scale(.82)}70%{transform:scale(1.12)}100%{transform:scale(1)}}' +
    '@media (prefers-reduced-motion:reduce){.ls-save svg{animation:none!important}.ls-save-hint{transition:none}}';
  var SAVE_HEART = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19.5s-7.5-4.6-7.5-10.2A4.1 4.1 0 0 1 12 7.1a4.1 4.1 0 0 1 7.5 2.2c0 5.6-7.5 10.2-7.5 10.2z"/></svg>';
  var CARD_SEL = 'li.product, .wc-block-grid__product, .wc-block-components-product, .products .product, .card-wrapper, .product-card, .product-item, .grid__item .card, [data-lets-card]';
  // the cards the shop pointed at (shortlist.card_selector, found on its category page), with the known ones;
  // a selector the browser cannot read is left out
  function cardSel() {
    var own = SHORT && SHORT.cardSelector ? String(SHORT.cardSelector).trim() : '';
    if (own) { try { doc.querySelector(own); return CARD_SEL + ', ' + own; } catch (e) { /* not a selector */ } }
    return CARD_SEL;
  }
  function saveStyles() {
    if (doc.getElementById('let-agents-save')) { return; }
    var s = doc.createElement('style'); s.id = 'let-agents-save'; s.textContent = SAVE_CSS;
    (doc.head || doc.documentElement).appendChild(s);
  }
  function launcherEl() { return LAUNCHERS.filter(function (b) { return b.isConnected; })[0] || null; }
  // the dot on every launcher while something is saved, and the launcher's label with the count
  function refreshLauncher() {
    var n = S.saved.length, label = n ? t('launcherSaved', { n: n }) : t('open');
    LAUNCHERS.forEach(function (b) { var badge = b.querySelector('.ls-launch__badge'); if (badge) { badge.hidden = n === 0; } b.classList.toggle('has-saved', n > 0); b.setAttribute('aria-label', label); b.setAttribute('title', label); });
  }
  function receiveOnLauncher(b) {
    if (!b || REDUCED) { return; }
    b.classList.remove('is-settle', 'is-catch'); void b.offsetWidth;
    b.classList.add('is-receiving', 'is-catch');
    setTimeout(function () { b.classList.remove('is-receiving'); b.classList.add('is-settle'); }, 650);
    setTimeout(function () { b.classList.remove('is-settle', 'is-catch'); }, 1250);
    if (win.navigator.vibrate) { try { win.navigator.vibrate(12); } catch (e) { /* none */ } }
  }
  // the picture flies to the launcher; done() once, on the animation's end or after 700ms if it never runs (a background tab)
  function flyTo(img, target, done) {
    var src = img && (img.currentSrc || img.src);
    if (REDUCED || !src || !target || !win.Element.prototype.animate) { done(); return; }
    var a = img.getBoundingClientRect(), b = target.getBoundingClientRect();
    if (!a.width || !b.width) { done(); return; }
    var size = 72, f = doc.createElement('img');
    f.src = src; f.alt = ''; f.className = 'ls-fly';
    var sx = a.left + a.width / 2 - size / 2, sy = a.top + a.height / 2 - size / 2;
    f.style.cssText = 'width:' + size + 'px;height:' + size + 'px;left:' + sx + 'px;top:' + sy + 'px';
    doc.body.appendChild(f);
    var dx = b.left + b.width / 2 - size / 2 - sx, dy = b.top + b.height / 2 - size / 2 - sy;
    var anim = f.animate([
      { transform: 'translate(0,0) scale(1)', opacity: 1, borderRadius: '12px' },
      { transform: 'translate(' + dx * 0.55 + 'px,' + (dy * 0.55 - 60) + 'px) scale(.6)', opacity: 1, offset: 0.55 },
      { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(.18)', opacity: 0.2, borderRadius: '50%' }
    ], { duration: 520, easing: 'cubic-bezier(.32,.72,0,1)' });
    var finished = false, end = function () { if (finished) { return; } finished = true; f.remove(); done(); };
    anim.onfinish = end; anim.oncancel = end; setTimeout(end, 700);
  }
  var hintEl = null, hintTimer = null;
  function saveHint(b) {
    if (!b || store('local', SAVE_HINT_KEY) === 1) { return; }
    store('local', SAVE_HINT_KEY, 1);
    if (!hintEl) {
      hintEl = doc.createElement('div'); hintEl.className = 'ls-save-hint'; hintEl.setAttribute('role', 'status');
      hintEl.innerHTML = '<span></span> · <button type="button"></button>';
      hintEl.querySelector('button').addEventListener('click', function () { hintEl.classList.remove('is-on'); S.tab = 'saved'; open({ focus: false }); });
      doc.body.appendChild(hintEl);
    }
    hintEl.querySelector('span').textContent = t('saveHint'); hintEl.querySelector('button').textContent = t('saveView');
    var r = b.getBoundingClientRect(), below = r.bottom + 60 < win.innerHeight;
    hintEl.style.top = below ? (r.bottom + 10) + 'px' : (r.top - 54) + 'px';
    // toward the middle of the screen, whichever side the launcher is on (the floating one may be on either, search.float_side)
    if (r.left + r.width / 2 < win.innerWidth / 2) { hintEl.style.left = Math.max(12, r.left - 8) + 'px'; hintEl.style.right = 'auto'; } else { hintEl.style.right = Math.max(12, win.innerWidth - r.right - 8) + 'px'; hintEl.style.left = 'auto'; }
    requestAnimationFrame(function () { hintEl.classList.add('is-on'); });
    clearTimeout(hintTimer); hintTimer = setTimeout(function () { hintEl.classList.remove('is-on'); }, 3200);
  }
  function paintSave(id, on) {
    doc.querySelectorAll('[data-lets-save="' + id + '"]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', String(on));
      var l = btn.querySelector('[data-lets-label]'); if (l) { l.textContent = t(on ? 'savedBtn' : 'saveBtn'); }
    });
  }
  var HANDLES = {};
  function resolveHandle(handle) {
    if (!handle) { return Promise.resolve(null); }
    if (HANDLES[handle]) { return Promise.resolve(HANDLES[handle]); }
    return win.fetch(ROOT + 'products/' + handle + '.js', { credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (p) { HANDLES[handle] = String(p.id); return HANDLES[handle]; }).catch(function () { return null; });
  }
  function saveFromPage(btn) {
    (btn.dataset.letsSave ? Promise.resolve(String(btn.dataset.letsSave)) : btn.dataset.letsSlug ? resolveSlug(btn.dataset.letsSlug) : resolveHandle(btn.dataset.letsHandle)).then(function (id) {
      if (!id) { return; }
      btn.dataset.letsSave = id;
      var on = S.saved.indexOf(id) < 0;
      toggleSaved(id);
      announce(t(on ? 'savedToast' : 'removedToast'));
      if (!on) { return; }
      // the shop chose that a save opens the list (shortlist.heart_click): the Saved tab, instead of the flight
      if (SHORT && SHORT.heartClick === 'open') { S.tab = 'saved'; S.wide = null; open({ focus: false }); return; }
      // the picture that flies: the one the theme named, else the card's, else the product page's gallery
      var img = btn.dataset.letsImage ? doc.querySelector(btn.dataset.letsImage) : null;
      if (!img) { var box = btn.closest('[data-lets-product], ' + cardSel() + ', article'); img = box && box.querySelector('img'); }
      if (!img) { img = doc.querySelector('.woocommerce-product-gallery img, .product-gallery img, .product__media img, .product-single__media img, [data-lets-product] img, .product img, main img'); }
      var b = launcherEl();
      flyTo(img, b, function () { receiveOnLauncher(b); saveHint(b); });
    });
  }
  function wireSave(btn, id, iconOnly) {
    if (btn.__ls) { return; }
    btn.__ls = true;
    if (!btn.querySelector('svg')) { btn.insertAdjacentHTML('afterbegin', SAVE_HEART); }
    if (!iconOnly && !btn.querySelector('[data-lets-label]')) {
      var txt = btn.textContent.trim();
      [].slice.call(btn.childNodes).forEach(function (n) { if (n.nodeType === 3) { n.remove(); } });
      var l = doc.createElement('span'); l.setAttribute('data-lets-label', ''); l.textContent = txt || t('saveBtn'); btn.appendChild(l);
    }
    if (iconOnly) { btn.setAttribute('aria-label', t('saveBtn')); }
    if (id) { btn.dataset.letsSave = id; }
    btn.setAttribute('aria-pressed', String(!!id && S.saved.indexOf(id) >= 0));
    btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); saveFromPage(btn); });
  }
  // the product page: a "save" button right after the add-to-cart button, unless the theme placed its own
  function productSaveButton() {
    if (SHORT.heartProduct === false || !S.ctx || doc.querySelector('[data-lets-save]')) { return; }
    var at = null;
    (CFG.accentSelector ? [CFG.accentSelector] : ['.single_add_to_cart_button', 'button[name="add-to-cart"]', 'form[action*="/cart/add"] [type="submit"]', '.product-form__submit', '.button.alt']).some(function (sel) { at = doc.querySelector(sel); return !!at; });
    if (!at) { return; }
    var btn = doc.createElement('button'); btn.type = 'button'; btn.className = 'ls-save';
    at.insertAdjacentElement('afterend', btn);
    wireSave(btn, String(pid(S.ctx)), false);
  }
  // a card names its product: by a data attribute or a post-ID class (WooCommerce themes), else by its link,
  // whose Shopify handle or WooCommerce slug becomes the id on the first save (resolveHandle / resolveSlug)
  function cardIdOf(card) {
    var a = card.matches('[data-product_id], [data-product-id], [data-product]') ? card : card.querySelector('[data-product_id], [data-product-id], [data-product]');
    var v = a && (a.getAttribute('data-product_id') || a.getAttribute('data-product-id') || a.getAttribute('data-product'));
    if (v && /^\d+$/.test(String(v))) { return { id: String(v) }; }
    var m = String(card.className).match(/(?:^|\s)post-(\d+)(?:\s|$)/);
    if (m) { return { id: m[1] }; }
    // by the link, only on a card that sells (a price or an add-to-cart control): a category tile or a
    // menu picture links to a product list and gets no heart. Shopify: /products/{handle}; WooCommerce:
    // /product/{slug} (its /products/ is often the shop's own base for categories).
    if (!card.querySelector('.price, [class*="price"], .money, .woocommerce-Price-amount, .add_to_cart_button, .ajax_add_to_cart, form[action*="/cart/add"], [name="add-to-cart"]')) { return null; }
    var link = card.querySelector(SHOPIFY ? 'a[href*="/products/"]' : 'a[href*="/product/"]');
    if (!link) { return null; }
    var path = String(link.getAttribute('href') || '').split('?')[0].split('#')[0];
    var m2 = path.match(SHOPIFY ? /\/products\/([^\/]+)\/?$/ : /\/product\/([^\/]+)\/?$/);
    if (!m2) { return null; }
    return SHOPIFY ? { handle: decodeURIComponent(m2[1]) } : { slug: decodeURIComponent(m2[1]) };
  }
  // WooCommerce names a product by its slug in a card's link; the id comes from the Store API, once, on the first save
  var SLUGS = {};
  function resolveSlug(slug) {
    if (!slug) { return Promise.resolve(null); }
    if (SLUGS[slug]) { return Promise.resolve(SLUGS[slug]); }
    var base = STORE || (win.location.origin + '/wp-json/wc/store/v1');
    return win.fetch(base + '/products?slug=' + encodeURIComponent(slug), { credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (list) { var p = (list || [])[0]; if (!p || !p.id) { return null; } SLUGS[slug] = String(p.id); return SLUGS[slug]; }).catch(function () { return null; });
  }
  // every product card on the page: a known card class, or any link to a product that holds a picture
  function cards() {
    var out = [], seen = [];
    var add = function (card) { if (card && seen.indexOf(card) < 0) { seen.push(card); out.push(card); } };
    doc.querySelectorAll(cardSel()).forEach(add);
    doc.querySelectorAll(SHOPIFY ? 'a[href*="/products/"] img' : 'a[href*="/product/"] img').forEach(function (img) {
      var link = img.closest('a');
      if (/\/products?\/[^\/]+\/.+/.test(String(link.getAttribute('href') || '').split('?')[0].replace(/\/$/, ''))) { return; }   // a deeper path: a category, not a product
      add(link.closest(cardSel() + ', article, li, [data-product], [data-product-id], [data-product_id], product-card') || (link.parentElement && link.parentElement.parentElement) || link);
    });
    return out;
  }
  function cardHearts() {
    if (SHORT.heartCards === false) { return; }
    cards().forEach(function (card) {
      if (card.__lsCard || card.querySelector('[data-lets-save], .ls-save') || card.closest('[data-lets-save], .ls-save-host')) { return; }
      var found = cardIdOf(card), img = card.querySelector('img');
      if (!found || !img) { return; }
      card.__lsCard = true;
      var btn = doc.createElement('button'); btn.type = 'button'; btn.className = 'ls-save ls-save--card';
      if (found.handle) { btn.dataset.letsHandle = found.handle; }
      if (found.slug) { btn.dataset.letsSlug = found.slug; }
      var place = SHORT.heartPlace || 'corner';
      if (place === 'corner') {
        var host = img.closest('a, figure, div') || card; host.classList.add('ls-save-host'); host.appendChild(btn);
      } else {
        var anchor = place === 'price' ? card.querySelector('.price, .price__container, .product-price, [class*="price"]') : card.querySelector('.add_to_cart_button, .ajax_add_to_cart, form [type="submit"], .product-form__submit, .button');
        btn.classList.add('ls-save--inline');
        if (anchor) { anchor.insertAdjacentElement('afterend', btn); } else { card.appendChild(btn); }
      }
      wireSave(btn, found.id || null, true);
    });
  }
  function saveHearts() {
    if (SHORT.wishlist === false || CFG.silent || !doc.body) { return; }
    saveStyles();
    doc.querySelectorAll('[data-lets-save]').forEach(function (b) { wireSave(b, String(b.dataset.letsSave), b.hasAttribute('data-lets-icon-only')); });
    productSaveButton(); cardHearts(); refreshLauncher();
    // cards arrive later too (filters, infinite scroll, quick views)
    var queued = false;
    new MutationObserver(function () {
      if (queued) { return; }
      queued = true;
      requestAnimationFrame(function () { queued = false; cardHearts(); doc.querySelectorAll('[data-lets-save]').forEach(function (b) { if (!b.__ls) { wireSave(b, String(b.dataset.letsSave), b.hasAttribute('data-lets-icon-only')); } }); });
    }).observe(doc.body, { childList: true, subtree: true });
    // another tab saved or removed: the same list here
    win.addEventListener('storage', function (e) {
      if (e.key !== 'ls_saved') { return; }
      S.saved = [].concat(store('local', 'ls_saved') || []);
      refreshLauncher();
      doc.querySelectorAll('[data-lets-save]').forEach(function (b) { b.setAttribute('aria-pressed', String(S.saved.indexOf(String(b.dataset.letsSave)) >= 0)); });
    });
  }

