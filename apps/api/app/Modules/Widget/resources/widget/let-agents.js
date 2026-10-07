/*!
 * Let Agents storefront widget v1.
 *
 * Loaded by the Let Agents WordPress plugin, which sets window.LetAgentsContext:
 *   { site, api, script, mode: 'live'|'preview', preview, page: {type, id}, locale,
 *     storeApi, nonce, cartUrl }
 *
 * What it does, in order:
 *   1. Reads the page content from the Let Agents API (cached, public data).
 *   2. In preview mode, shows the widget only to the store team (a valid preview key).
 *   3. Replaces prices and stock with live values from the store's own Store API, and drops
 *      anything that is not in stock, not on sale where a sale is promised, or whose price
 *      superlative no longer holds.
 *   4. Shows a row of circles, one per section; each opens its own panel. When the shopper
 *      viewed a product of the same type before, one circle compares the two. What was viewed
 *      stays in this browser only.
 *   5. Places itself by the CSS selector set for the shop, or floats at the bottom.
 *   6. Reports anonymous events (packages/event-spec) with navigator.sendBeacon.
 *
 * Everything renders in a shadow root, so the theme's CSS does not leak in and ours does not
 * leak out. Stores can still set --let-agents-accent, --let-agents-radius and --let-agents-surface on .let-agents-widget.
 * Text is always set with textContent; links and images must be http(s).
 */
(function () {
  'use strict';

  var ctx = window.LetAgentsContext;
  if (!ctx || !ctx.site || !ctx.api || !ctx.page || window.__letAgentsWidget) {
    return;
  }
  window.__letAgentsWidget = true;

  var API = String(ctx.api).replace(/\/+$/, '');
  var STORE_API = ctx.storeApi ? String(ctx.storeApi).replace(/\/?$/, '/') : null;
  var PAGE_TYPE = ctx.page.type === 'content' ? 'content' : 'product';
  var PAGE_ID = String(ctx.page.id || '');
  var ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  var MAX_BATCH = 50;
  var SEEN_KEY = 'let_agents_seen';
  var MAX_SEEN = 8;
  var CHIP_SLOTS = ['teaser', 'chip_1', 'chip_2', 'chip_3', 'chip_4', 'chip_5', 'chip_6', 'chip_7', 'chip_8'];

  if (!/^[A-Za-z0-9_.:-]{1,64}$/.test(PAGE_ID)) {
    return;
  }

  // ---------------------------------------------------------------- identity

  function randomId(length) {
    var bytes = new Uint8Array(length);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    var out = '';
    for (var i = 0; i < length; i++) {
      out += ALPHABET[bytes[i] & 63];
    }
    return out;
  }

  function readCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function writeCookie(name, value, maxAge) {
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge +
      '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }

  function storage(kind, key, fallback) {
    try {
      var store = window[kind];
      var value = store.getItem(key);
      if (!value) {
        value = fallback();
        store.setItem(key, value);
      }
      return value;
    } catch (e) {
      return null;
    }
  }

  // The plugin reads let_agents_vid at checkout to link an order to widget use, so it lives in a cookie too.
  // A shopper first seen before the rename keeps the same anonymous id, so learning and
  // attribution carry over.
  var vid = readCookie('let_agents_vid') || readCookie('rega_vid');
  if (!/^anon-[A-Za-z0-9_-]{16,64}$/.test(vid || '')) {
    vid = storage('localStorage', 'let_agents_vid', function () { return 'anon-' + randomId(22); }) || 'anon-' + randomId(22);
  }
  writeCookie('let_agents_vid', vid, 31536000);

  var session = storage('sessionStorage', 'let_agents_sid', function () { return randomId(22); }) || randomId(22);

  /**
   * Which side of the measurement this visitor is on, decided from their own id alone.
   *
   * The same person is always on the same side, on every page and every visit, because a
   * measurement where people wander between the two groups measures nothing. A held-out visitor
   * is shown the arrangement the learning never touched, and what they do is kept out of it.
   */
  function heldOut(percent) {
    if (!percent) {
      return false;
    }
    var n = 0;
    for (var i = 0; i < vid.length; i++) {
      n = (n * 31 + vid.charCodeAt(i)) % 100000;
    }
    return (n % 100) < percent;
  }

  var holdout = false;

  // ---------------------------------------------------------------- preview

  var previewKey = null;
  (function () {
    var fromUrl = null;
    try {
      fromUrl = new URLSearchParams(location.search).get('let_agents_preview');
    } catch (e) { /* old browser */ }

    if (fromUrl && /^[a-f0-9]{32}$/.test(fromUrl)) {
      writeCookie('let_agents_preview', fromUrl, 30 * 86400);
    }

    var candidate = ctx.preview || fromUrl || readCookie('let_agents_preview');
    previewKey = /^[a-f0-9]{32}$/.test(candidate || '') ? candidate : null;
  })();

  var isPreviewMode = ctx.mode === 'preview';

  // ---------------------------------------------------------------- events

  var queue = [];
  var shop = null;
  var bank = null;
  // How many questions the assistant has answered on this page, and whether the offer has been
  // made. Once per page: being asked twice is how a helpful thing turns into a pop-up.
  var answered = 0;
  var offered = false;
  var teamPreview = false;
  var viewed = null;

  function pageContext() {
    var page = { type: PAGE_TYPE, path: (location.pathname || '/').slice(0, 512) };
    if (page.path.charAt(0) !== '/') {
      page.path = '/';
    }
    page[PAGE_TYPE === 'product' ? 'product_id' : 'content_id'] = PAGE_ID;
    return page;
  }

  function eligible() {
    var ids = bank.sections.map(function (s) { return s.candidate; });
    return ids.filter(function (id, i) { return ids.indexOf(id) === i; }).slice(0, 12);
  }

  function track(type, section, slot, data) {
    var event = { id: randomId(22), type: type, ts: Date.now(), page: pageContext() };

    if (section) {
      event.candidate = { id: section.candidate, version: bank.bank_version, model: section.model, slot: slot };
      event.bank_version = bank.bank_version;
      event.eligible = eligible();
      event.bucket = section.candidate === 'compare' ? 'same_family_seen' : 'none';
    }
    if (data) {
      event.data = data;
    }

    queue.push(event);
    if (queue.length >= 20) {
      flush(false);
    }
  }

  function flush(leaving) {
    if (!shop || queue.length === 0) {
      return;
    }

    var url = API + '/widget/' + ctx.site + '/events';

    while (queue.length) {
      var body = JSON.stringify({
        v: 1,
        shop: shop,
        vid: vid,
        session: session,
        sent_at: Date.now(),
        holdout: holdout,
        preview: teamPreview,
        events: queue.splice(0, MAX_BATCH)
      });

      // text/plain keeps it a simple request: no preflight.
      var sent = false;
      if (leaving && navigator.sendBeacon) {
        try {
          sent = navigator.sendBeacon(url, new Blob([body], { type: 'text/plain' }));
        } catch (e) { sent = false; }
      }
      if (!sent && window.fetch) {
        fetch(url, { method: 'POST', body: body, keepalive: true, mode: 'no-cors', credentials: 'omit', headers: { 'Content-Type': 'text/plain' } })
          .catch(function () {});
      }
    }
  }

  // ---------------------------------------------------------------- the store's own add to cart

  var lastAddAt = 0;

  /** Counts an add to the cart once, whichever of the store's signals fired for it. */
  function storeAdded(quantity) {
    var now = Date.now();
    if (PAGE_TYPE !== 'product' || now - lastAddAt < 1500) {
      return;
    }
    lastAddAt = now;
    track('add_to_cart', null, null, { source: 'page', product_id: PAGE_ID, quantity: quantity, result: 'added' });
    // The form may leave the page right now: a beacon survives that.
    flush(true);
  }

  function formQuantity(form) {
    var input = form.querySelector('input[name="quantity"]');
    var quantity = input ? parseInt(input.value, 10) : 1;
    return quantity >= 1 && quantity <= 999 ? quantity : 1;
  }

  /**
   * The store's own button on a product page: the product form's submit, or the theme's ajax add
   * (WooCommerce's added_to_cart on jQuery, the blocks' wc-blocks_added_to_cart). Nothing about the
   * shopper is read, only that the product on this page went into the cart.
   */
  function watchStoreAdds() {
    if (PAGE_TYPE !== 'product') {
      return;
    }
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (form && form.matches && form.matches('form.cart')) {
        storeAdded(formQuantity(form));
      }
    }, true);
    document.body.addEventListener('wc-blocks_added_to_cart', function () { storeAdded(1); });
    try {
      if (window.jQuery) {
        window.jQuery(document.body).on('added_to_cart', function () { storeAdded(1); });
      }
    } catch (e) { /* a theme without jQuery */ }
  }

  setInterval(function () { flush(false); }, 4000);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flush(true);
    }
  });
  window.addEventListener('pagehide', function () { flush(true); });

  function watchExposure(element, fire) {
    if (!('IntersectionObserver' in window)) {
      return;
    }

    var timer = null;
    var since = 0;
    var ratio = 0;
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        ratio = entry.intersectionRatio;
        if (ratio >= 0.5 && !timer) {
          since = Date.now();
          timer = setTimeout(function () {
            observer.disconnect();
            fire(Math.min(3600000, Date.now() - since), Math.min(1, Math.round(ratio * 100) / 100));
          }, 1000);
        } else if (ratio < 0.5 && timer) {
          clearTimeout(timer);
          timer = null;
        }
      });
    }, { threshold: [0, 0.5, 1] });

    observer.observe(element);
  }

  // ---------------------------------------------------------------- store data

  var nonce = ctx.nonce || null;
  var lastMessage = '';

  function safeUrl(value) {
    return typeof value === 'string' && /^https?:\/\//i.test(value) ? value : null;
  }

  function liveProducts(ids) {
    if (!STORE_API || ids.length === 0 || !window.fetch) {
      return Promise.resolve(null);
    }

    var unique = ids.filter(function (id, i) { return ids.indexOf(id) === i; }).slice(0, 100);
    var url = STORE_API + 'products?per_page=100&include=' + unique.map(encodeURIComponent).join(',');

    return fetch(url, { credentials: 'same-origin' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (products) {
        if (!Array.isArray(products)) {
          return null;
        }
        var byId = {};
        products.forEach(function (product) { byId[String(product.id)] = product; });
        return byId;
      })
      .catch(function () { return null; });
  }

  function money(prices, key) {
    if (!prices || prices[key] === undefined || prices[key] === null || prices[key] === '') {
      return '';
    }
    var minor = parseInt(prices.currency_minor_unit, 10) || 0;
    var amount = parseInt(prices[key], 10) / Math.pow(10, minor);
    if (isNaN(amount)) {
      return '';
    }
    var parts = amount.toFixed(minor).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, prices.currency_thousand_separator || ',');
    return (prices.currency_prefix || '') + parts.join(prices.currency_decimal_separator || '.') + (prices.currency_suffix || '');
  }

  function amount(prices, key) {
    var minor = parseInt(prices && prices.currency_minor_unit, 10) || 0;
    return parseInt(prices && prices[key], 10) / Math.pow(10, minor);
  }

  function addToCart(productId, retried) {
    var headers = { 'Content-Type': 'application/json' };
    var ready = nonce ? Promise.resolve(nonce) : fetch(STORE_API + 'cart', { credentials: 'same-origin' })
      .then(function (response) {
        nonce = response.headers.get('Nonce') || response.headers.get('X-WC-Store-API-Nonce');
        return nonce;
      });

    return ready.then(function (value) {
      if (value) {
        headers.Nonce = value;
        headers['X-WC-Store-API-Nonce'] = value;
      }
      return fetch(STORE_API + 'cart/add-item', {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify({ id: Number(productId), quantity: 1 })
      });
    }).then(function (response) {
      var fresh = response.headers.get('Nonce');
      if (fresh) {
        nonce = fresh;
      }
      return response.json().catch(function () { return {}; }).then(function (body) {
        if (response.ok) {
          return 'added';
        }
        var code = String((body && body.code) || '');
        if (!retried && /nonce/.test(code)) {
          nonce = null;
          return addToCart(productId, true);
        }
        if (/stock/.test(code)) {
          return 'out_of_stock';
        }
        // A store plugin that requires a choice (a length, a color) refuses with a general add-to-cart
        // error and a message for the shopper. Send them to the product page with that message.
        if (/variation|attribute|option|add_to_cart_error/.test(code)) {
          lastMessage = body && body.message ? decodeEntities(body.message) : '';
          return 'needs_options';
        }
        return 'error';
      });
    }).catch(function () { return 'error'; });
  }

  function refreshCartFragments() {
    try {
      if (window.jQuery) {
        window.jQuery(document.body).trigger('wc_fragment_refresh');
      }
      document.body.dispatchEvent(new Event('wc-blocks_added_to_cart'));
    } catch (e) { /* the theme has no mini cart */ }
  }

  // ---------------------------------------------------------------- memory of viewed products

  function seenProducts() {
    try {
      var list = JSON.parse(window.localStorage.getItem(SEEN_KEY) || '[]');
      return Array.isArray(list) ? list : [];
    } catch (e) {
      return [];
    }
  }

  function rememberProduct(entry) {
    try {
      var list = seenProducts().filter(function (item) { return item && item.id !== entry.id; });
      list.unshift(entry);
      window.localStorage.setItem(SEEN_KEY, JSON.stringify(list.slice(0, MAX_SEEN)));
    } catch (e) { /* private mode */ }
  }

  /** The most recent other product of the same type, viewed within 30 days. */
  function comparable() {
    if (!bank.compare) {
      return null;
    }
    var limit = Date.now() - 30 * 86400000;
    var list = seenProducts();
    for (var i = 0; i < list.length; i++) {
      var item = list[i];
      if (item && item.id !== PAGE_ID && item.key === bank.compare.key && item.at > limit && Array.isArray(item.rows)) {
        return item;
      }
    }
    return null;
  }

  // ---------------------------------------------------------------- rendering

  var CSS = [
    // inline-size containment: the widget takes its column's width and a long line never widens the column.
    ':host{all:initial;display:block;contain:inline-size;max-width:100%;margin:16px 0;font-family:inherit;color:inherit;font-size:15px;line-height:1.5;',
    '--accent:var(--let-agents-accent,#1f2933);--surface:var(--let-agents-surface,#fff);--radius:var(--let-agents-radius,14px);--line:rgba(17,24,39,.12);--muted:rgba(17,24,39,.62);',
    // The soft glow: three RGB triplets a store can retune (--let-agents-glow-1/2/3), a hairline gradient and a faint wash.
    '--g1:var(--let-agents-glow-1,66,133,244);--g2:var(--let-agents-glow-2,168,85,247);--g3:var(--let-agents-glow-3,236,72,153);',
    '--hairline:linear-gradient(135deg,rgba(var(--g1),.55),rgba(var(--g2),.4) 50%,rgba(var(--g3),.35));',
    '--wash:radial-gradient(120% 90% at 100% 0%,rgba(var(--g1),.08),transparent 55%),radial-gradient(90% 70% at 0% 100%,rgba(var(--g3),.06),transparent 55%);',
    '--grad:linear-gradient(135deg,rgb(var(--g1)),rgb(var(--g2)) 60%,rgb(var(--g3)))}',
    '@keyframes let-agents-drift{0%{background-position:0 0,0 0,0% 50%}100%{background-position:0 0,0 0,100% 50%}}',
    '@keyframes let-agents-dot{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}',
    '@keyframes let-agents-in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}',
    '@media (prefers-reduced-motion:reduce){.let-agents *{animation:none!important}}',
    ':host(.is-floating){contain:none;position:fixed;bottom:16px;inset-inline-start:16px;z-index:2147483000;margin:0;max-width:calc(100vw - 32px)}',
    '*{box-sizing:border-box}',
    '.let-agents{position:relative}',
    '.note{display:block;width:fit-content;margin:0 0 6px;padding:2px 8px;border-radius:999px;background:#fff4d6;color:#7a5200;font-size:12px}.note a{color:inherit;text-decoration:underline}',
    '.chips{display:flex;flex-wrap:wrap;gap:8px;align-items:center}',
    // The field above the module: search the page, ask on Enter.
    '.pill[hidden]{display:none}',
    '.find{display:flex;gap:6px;align-items:center;margin:0 0 10px;padding:4px;padding-inline-start:12px;border:1px solid var(--line);border-radius:999px;background:var(--surface)}',
    '.find:focus-within{border-color:var(--accent)}',
    '.find-input{all:unset;flex:1;min-width:0;padding:7px 4px;font:inherit;font-size:14px;color:inherit}',
    '.find-input::placeholder{color:var(--muted)}',
    '.find-send{all:unset;cursor:pointer;padding:7px 14px;border-radius:999px;background:var(--grad);color:#fff;font-size:13px;font-weight:600}',
    '.find-send:focus-visible{outline:2px solid var(--accent);outline-offset:2px}',
    '.find-answer{display:grid;gap:4px;margin:8px 0;padding:10px 12px;border-radius:12px;background:rgba(var(--g2),.06)}',
    '.find-answer p{margin:0;white-space:pre-line}',
    '.find-tag{font-size:11px;color:var(--muted)}',
    '.find-ask{all:unset;box-sizing:border-box;display:flex;justify-content:space-between;align-items:center;gap:10px;width:100%;margin:8px 0;padding:10px 12px;border:1.5px dashed var(--accent);border-radius:12px;cursor:pointer;font-size:14px}',
    '.find-ask b{color:var(--accent);white-space:nowrap}',
    '.find-none{margin:8px 0;color:var(--muted);font-size:14px}',
    // The tag bank: a quiet heading over soft tags, each opening its results in the same panel.
    '.tagbank-title{margin:0 0 8px;font-size:13px;font-weight:700;letter-spacing:.02em;color:var(--muted)}',
    '.tagbank{gap:6px}',
    '.tag-pill{padding:6px 12px;border-color:transparent;background:rgba(var(--g2),.07);box-shadow:none;font-size:13.5px}',
    '.tag-pill:hover,.tag-pill:focus-visible{background:rgba(var(--g2),.12);border-color:transparent;box-shadow:none}',
    ':host(.is-floating) .chips{flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none}',
    '.pill{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;gap:7px;max-width:100%;padding:8px 13px;border:1px solid var(--line);',
    'border-radius:999px;background:var(--surface);color:inherit;font:inherit;font-size:14px;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:box-shadow .2s,border-color .2s,background .2s;white-space:nowrap}',
    '.pill:hover,.pill:focus-visible{border-color:var(--accent);box-shadow:0 4px 14px rgba(0,0,0,.08)}',
    '.pill:focus-visible{outline:2px solid var(--accent);outline-offset:2px}',
    // The open circle is lit softly: a tinted face inside a gradient hairline, never a solid block.
    '.pill[aria-expanded="true"]{background:linear-gradient(#f5f6ff,#f5f6ff) padding-box,var(--hairline) border-box;border-color:transparent;color:inherit;box-shadow:0 8px 22px rgba(var(--g2),.10)}',
    '.pill[aria-expanded="true"] .spark{color:rgba(var(--g2),.9)}',
    '.spark{flex:none;width:17px;height:17px;color:var(--accent)}',
    '.quote{all:unset;box-sizing:border-box;display:flex;align-items:flex-start;gap:10px;width:100%;margin:0 0 10px;padding:10px 14px;',
    'border-inline-start:3px solid var(--accent);border-start-end-radius:10px;border-end-end-radius:10px;background:rgba(17,24,39,.04);cursor:pointer;font:inherit;font-size:15px;line-height:1.55;color:inherit}',
    '.quote:hover,.quote:focus-visible{background:rgba(17,24,39,.07)}.quote:focus-visible{outline:2px solid var(--accent);outline-offset:2px}',
    '.quote .mark{flex:none;font-family:Georgia,serif;font-size:30px;line-height:.9;color:var(--accent)}',
    '.quote strong,.highlights strong{font-weight:700}',
    '.quote{border-inline-start:2px solid rgba(var(--g2),.55);background:linear-gradient(90deg,rgba(var(--g2),.06),rgba(var(--g2),0))}',
    '.quote:hover,.quote:focus-visible{background:linear-gradient(90deg,rgba(var(--g2),.10),rgba(var(--g2),.02))}',
    '.quote .mark{color:rgba(var(--g2),.85)}',
    '.pop{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 10px;font-size:13px;color:var(--muted)}',
    '.pop .hot{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:999px;background:rgba(200,30,30,.09);color:#a51616;font-weight:600;font-size:12px}',
    '.pop .hot svg{width:12px;height:12px}',
    '.highlights li{position:relative;padding-inline-start:18px;margin:6px 0}',
    '.highlights li:before{content:"";position:absolute;inset-inline-start:2px;top:.6em;width:7px;height:7px;border-radius:50%;background:var(--accent)}',
    '.chip-label{display:block;max-width:22ch;overflow:hidden;text-overflow:ellipsis}',
    // How much waits inside a circle: "+4" products, "3" points. Quiet on a closed circle, lit on the open one.
    '.count{flex:none;display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:rgba(17,24,39,.07);color:var(--muted);font-size:11px;font-weight:700;line-height:1}',
    '.pill[aria-expanded="true"] .count{background:var(--grad);color:#fff}',
    '.panel{margin-top:8px;padding:6px 16px 14px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);color:#111827}',
    ':host(.is-floating) .panel{position:absolute;bottom:calc(100% + 8px);inset-inline-start:0;width:min(420px,calc(100vw - 32px));max-height:70vh;overflow:auto;box-shadow:0 12px 40px rgba(0,0,0,.18)}',
    '.panel[hidden]{display:none}',
    '.head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding-top:4px}',
    'h3{margin:0;font-size:15px;font-weight:600;color:#111827}',
    '.close{all:unset;cursor:pointer;width:28px;height:28px;display:grid;place-items:center;border-radius:50%;color:var(--muted);font-size:20px;line-height:1}',
    '.close:hover,.close:focus-visible{background:rgba(0,0,0,.06)}',
    '.body{margin-top:8px}',
    'ul{margin:0;padding:0;list-style:none}',
    '.lines li{position:relative;padding-inline-start:18px;margin:4px 0}',
    '.lines li:before{content:"";position:absolute;inset-inline-start:2px;top:.6em;width:7px;height:7px;border-radius:50%;background:var(--accent)}',
    '.specs{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;margin:0}',
    '.specs dt{color:var(--muted)}.specs dd{margin:0;font-weight:500}',
    '.tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}',
    '.tag{padding:3px 10px;border-radius:999px;background:rgba(17,24,39,.06);font-size:13px}',
    // Products: a slider by default (swipe, or the arrows on a mouse), or a list; the shopper's pick is kept in this browser.
    '.view{display:flex;justify-content:flex-end;gap:4px;margin:0 0 8px}',
    '.view button{all:unset;box-sizing:border-box;width:30px;height:28px;border-radius:8px;display:grid;place-items:center;color:var(--muted);cursor:pointer}',
    '.view button svg{width:16px;height:16px}.view button[aria-pressed="true"]{background:rgba(17,24,39,.08);color:#1f1f1f}',
    '.cards-wrap{position:relative}',
    '.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}',
    '.cards.is-slider{display:flex;gap:10px;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;padding:2px 2px 6px;margin:0 -2px;-webkit-overflow-scrolling:touch}',
    '.cards.is-slider::-webkit-scrollbar{display:none}',
    '.cards.is-slider .card{flex:0 0 clamp(150px,44%,190px);scroll-snap-align:start}',
    '.cards-nav{all:unset;box-sizing:border-box;position:absolute;top:34%;width:32px;height:32px;border-radius:50%;background:#fff;color:#1f1f1f;box-shadow:0 2px 10px rgba(0,0,0,.16);display:grid;place-items:center;cursor:pointer;z-index:1}',
    '.cards-nav svg{width:16px;height:16px}.cards-nav.prev{inset-inline-start:-8px}.cards-nav.next{inset-inline-end:-8px}.cards-nav[hidden]{display:none}',
    '.cards.is-list{display:flex;flex-direction:column;gap:8px}',
    '.cards.is-list .card{display:grid;grid-template-columns:64px minmax(0,1fr) auto;column-gap:10px;row-gap:2px;align-items:center}',
    '.cards.is-list .card>a{display:contents}',
    '.cards.is-list .card img{grid-column:1;grid-row:1/span 5;width:64px;height:64px}',
    '.cards.is-list .card .title,.cards.is-list .card .reason,.cards.is-list .card .price,.cards.is-list .card .note-price{grid-column:2}',
    '.cards.is-list .card .badge{position:static;grid-column:2;justify-self:start;width:fit-content}',
    '.cards.is-list .card .add,.cards.is-list .card .status{grid-column:3;grid-row:1/span 5;margin-top:0;align-self:center;white-space:nowrap}',
    '.card{position:relative;display:flex;flex-direction:column;gap:6px;padding:8px;border:1px solid var(--line);border-radius:10px;background:#fff}',
    '.card a{color:inherit;text-decoration:none}',
    '.card img{display:block;width:100%;aspect-ratio:1;object-fit:contain;background:#f6f6f7;border-radius:6px}',
    '.card .title{font-size:13px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
    '.card .reason{font-size:12px;color:var(--muted);line-height:1.35}',
    '.badge{position:absolute;top:12px;inset-inline-start:12px;padding:1px 8px;border-radius:999px;background:#c81e1e;color:#fff;font-size:11px}',
    '.price{font-weight:600;font-size:14px}.price del{display:inline-block;font-weight:400;color:var(--muted);margin-inline-start:8px;font-size:12px}',
    '.add{all:unset;box-sizing:border-box;margin-top:auto;text-align:center;padding:7px 8px;border-radius:8px;background:var(--accent);color:#fff;font-size:13px;cursor:pointer}',
    '.add[disabled]{opacity:.6;cursor:default}.add.secondary{background:transparent;color:var(--accent);border:1px solid var(--accent)}',
    '.note-price{font-size:12px;color:var(--muted);margin-top:-4px}.status{font-size:12px;color:var(--muted)}.status a{color:var(--accent)}',
    '.ask-suggested{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}',
    '.ask-chip{all:unset;box-sizing:border-box;cursor:pointer;padding:6px 12px;border-radius:999px;background:rgba(17,24,39,.06);font-size:13px;line-height:1.4}',
    '.ask-chip:hover,.ask-chip:focus-visible{background:rgba(17,24,39,.12)}',
    '.ask-form{display:flex;gap:8px}',
    '.ask-input{flex:1;min-width:0;box-sizing:border-box;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:#fff;color:inherit;font:inherit;font-size:16px}',
    '.ask-input:focus{outline:2px solid var(--accent);outline-offset:1px}',
    '.ask-send{all:unset;box-sizing:border-box;cursor:pointer;padding:10px 16px;border-radius:10px;background:var(--accent);color:#fff;font-size:14px}',
    '.ask-send[disabled]{opacity:.6;cursor:default}',
    '.ask-answer{margin-top:10px;padding:10px 12px;border-radius:10px;background:rgba(17,24,39,.04)}',
    // The sign-up: a white card inside a gradient hairline with a faint wash, a borderless field, a gradient-text button.
    '.signup{margin-top:12px;padding:12px;border:1px solid transparent;border-radius:16px;background:var(--wash) padding-box,linear-gradient(#fff,#fff) padding-box,var(--hairline) border-box}',
    '.signup-title{font-weight:600}.signup-sub{margin-top:2px;font-size:12px;color:var(--muted)}',
    '.signup-form{display:flex;gap:8px;margin-top:8px}',
    '.signup-input{flex:1;min-width:0;box-sizing:border-box;height:42px;padding:0 14px;border:0;border-radius:999px;background:#f4f4f5;color:inherit;font:inherit;font-size:16px}',
    '.signup-input:focus{outline:2px solid rgba(var(--g2),.5);outline-offset:1px}',
    '.signup-send{all:unset;box-sizing:border-box;cursor:pointer;height:42px;padding:0 16px;border:1px solid transparent;border-radius:999px;white-space:nowrap;background:linear-gradient(#fff,#fff) padding-box,var(--hairline) border-box}',
    '.signup-send span{font-size:14px;font-weight:700;background:var(--grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:rgb(var(--g2))}',
    '.signup-send[disabled]{opacity:.6;cursor:default}',
    '.signup-consent{display:flex;gap:7px;align-items:flex-start;margin-top:8px;font-size:11.5px;color:#8a8f98;line-height:1.5;cursor:pointer}',
    '.signup-consent input{margin:2px 0 0;flex:none;accent-color:rgb(var(--g2))}',
    '.signup-status{margin-top:8px;font-size:13px}',
    '.signup-note{font-size:13px;color:var(--muted)}',
    // The assistant layout: a banner that turns while it is closed, then a conversation card.
    // The small tags under it: the product lists and their counts, before anything opens.
    '.quick{display:flex;flex-wrap:wrap;gap:6px;margin:-2px 0 10px}.quick[hidden]{display:none}',
    '.quick .pill{height:30px;padding:0 10px;font-size:12px;gap:5px;color:var(--muted)}',
    '.quick .count{min-width:16px;height:16px;padding:0 4px;font-size:10px}',
    '@keyframes let-agents-caret{0%,50%{opacity:1}51%,100%{opacity:0}}',
    '.spark-g{flex:none;width:20px;height:20px}',
    // The banner: one card that changes what it holds, the way an ad board does. Compact on purpose.
    '.bn{margin:0 0 8px;padding:9px 11px;border:1px solid transparent;border-radius:16px;',
    'background:var(--wash) padding-box,linear-gradient(#fff,#fff) padding-box,var(--hairline) border-box;background-size:auto,auto,220% 220%;',
    'animation:let-agents-drift 9s ease-in-out infinite alternate;box-shadow:0 10px 30px rgba(var(--g2),.09)}',
    '.bn[hidden]{display:none}',
    '.bn-head{display:flex;align-items:center;gap:6px;margin-bottom:5px}',
    '.bn-head .spark-g{width:15px;height:15px}',
    '.bn-who{font-size:10.5px;font-weight:500;color:#9ca3af}',
    '.bn-stage{min-height:54px}',
    '.bn-frame{all:unset;box-sizing:border-box;display:block;width:100%;cursor:pointer;font:inherit;color:inherit;text-align:start;animation:let-agents-in .34s ease-out}',
    '.bn-frame[hidden]{display:none}',
    '.bn-frame:focus-visible{outline:2px solid rgba(var(--g2),.5);outline-offset:3px;border-radius:10px}',
    '.bn-row{display:flex;align-items:center;gap:9px}',
    '.bn-tile{flex:none;width:38px;height:38px;border-radius:11px;display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1}',
    '.bn-tile svg{width:19px;height:19px}',
    '.bn-tile.is-rank{background:var(--grad);color:#fff}',
    '.bn-tile.is-rank b{font-size:14px;font-weight:700}.bn-tile.is-rank i{font-style:normal;font-size:8px;opacity:.9;margin-top:1px}',
    '.bn-tile.is-hot{background:rgba(200,30,30,.08);color:#a51616}',
    '.bn-tile.is-promise{background:rgba(16,185,129,.10);color:#0f766e}',
    '.bn-tile.is-made{background:rgba(217,119,6,.10);color:#b45309}',
    '.bn-tile.is-compare{background:rgba(17,24,39,.06);color:#3f3f46}',
    '.bn-tile.is-ask{background:rgba(var(--g2),.10);color:rgb(var(--g2))}',
    '.bn-tile.is-points{background:rgba(66,133,244,.10);color:#1d4ed8}',
    '.bn-bubbles{display:flex;flex:none;padding-inline-start:8px}',
    '.bn-bubbles img,.bn-bubbles .bn-more{width:32px;height:32px;border-radius:50%;margin-inline-start:-8px;box-shadow:0 0 0 2px #fff,0 1px 4px rgba(0,0,0,.10)}',
    '.bn-bubbles img{object-fit:contain;background:#f6f6f7}',
    '.bn-bubbles .bn-more{display:flex;align-items:center;justify-content:center;background:#f4f4f5;color:#52525b;font-size:11px;font-weight:600;box-shadow:0 0 0 2px #fff}',
    '.bn-text{min-width:0}',
    '.bn-title{font-size:13px;font-weight:600;line-height:1.3}.bn-title.is-plain{font-weight:500}',
    '.bn-sub{margin-top:1px;font-size:11px;color:var(--muted);line-height:1.35}',
    '.bn-type{font-size:13px;line-height:1.45;min-height:1.45em}',
    '.bn-type.is-typing:after{content:"";display:inline-block;width:2px;height:1em;margin-inline-start:2px;background:currentColor;vertical-align:-2px;animation:let-agents-caret 1s steps(1) infinite}',
    '.bn-feet{display:flex;align-items:center;gap:6px;margin-top:6px}',
    '.bn-dot{all:unset;box-sizing:border-box;cursor:pointer;width:4px;height:4px;border-radius:999px;background:rgba(17,24,39,.16);transition:width .3s,background .3s}',
    '.bn-dot[aria-current="true"]{width:20px;background:var(--grad)}',
    '.bn-dot:focus-visible{outline:2px solid rgba(var(--g2),.5);outline-offset:3px}',
    '.bn-play{all:unset;box-sizing:border-box;margin-inline-start:auto;cursor:pointer;font-size:10.5px;color:#a1a1aa}',
    '.bn-play:focus-visible{outline:2px solid rgba(var(--g2),.5);outline-offset:2px}',
    // The offer stands on its own, under the banner, never inside what the assistant found.
    '.let-agents>.signup{margin:0 0 10px;padding:10px 11px}',
    '.chat{position:relative;padding:12px;border:1px solid transparent;border-radius:20px;background:var(--wash) padding-box,linear-gradient(#fff,#fff) padding-box,var(--hairline) border-box;',
    'background-size:auto,auto,220% 220%;animation:let-agents-drift 9s ease-in-out infinite alternate;box-shadow:0 14px 40px rgba(var(--g2),.10)}',
    '.chat[hidden]{display:none}',
    '.chat-head{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted)}.chat-head .who{font-weight:500}.chat-head .aside{margin-inline-start:auto;font-size:11px;color:#a1a1aa}',
    '.thread{display:flex;flex-direction:column;gap:8px;margin-top:10px;max-height:46vh;overflow-y:auto;scrollbar-width:thin;overscroll-behavior:contain}',
    '.bubble[hidden]{display:none}',
    '.earlier{all:unset;box-sizing:border-box;align-self:center;cursor:pointer;padding:4px 12px;border-radius:999px;background:rgba(17,24,39,.05);font-size:12px;color:var(--muted)}',
    '.earlier:hover,.earlier:focus-visible{background:rgba(17,24,39,.1)}',
    '.bubble{align-self:flex-start;max-width:94%;box-sizing:border-box;padding:9px 12px;border-radius:16px 16px 16px 4px;background:#fff;border:1px solid #ececee;font-size:14px;line-height:1.5;animation:let-agents-in .3s ease-out}',
    '.bubble.me{align-self:flex-end;max-width:78%;border-radius:16px 16px 4px 16px;background:#f4f4f5;border:0}',
    '.bubble .mark{font-family:Georgia,serif;font-size:22px;line-height:.5;color:rgba(var(--g2),.85);margin-inline-end:6px;vertical-align:-4px}',
    '.bubble-lead{font-weight:600;margin-bottom:6px}.bubble .body{margin-top:0}.bubble .ask-form,.bubble .ask-note{display:none}',
    '.dots{display:flex;gap:5px;padding:12px 14px}.dots span{width:6px;height:6px;border-radius:50%;background:rgb(var(--g2));animation:let-agents-dot 1.1s infinite ease-in-out}',
    '.dots span:nth-child(2){animation-delay:.15s}.dots span:nth-child(3){animation-delay:.3s}',
    '.more{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:8px}.more .lead{font-size:12px;color:#a1a1aa}',
    '.more .pill{height:32px;padding:0 11px;font-size:12.5px}.more .count{min-width:17px;height:17px;padding:0 4px;font-size:10px}',
    '.composer{display:flex;align-items:center;gap:8px;margin-top:10px}',
    '.composer input{flex:1;min-width:0;box-sizing:border-box;height:40px;padding:0 14px;border:0;border-radius:999px;background:#f4f4f5;color:inherit;font:inherit;font-size:16px}',
    '.composer input:focus{outline:2px solid rgba(var(--g2),.5);outline-offset:1px}',
    '.composer button{all:unset;box-sizing:border-box;flex:none;width:40px;height:40px;border-radius:50%;background:var(--grad);color:#fff;display:grid;place-items:center;cursor:pointer;box-shadow:0 6px 16px rgba(var(--g2),.25)}',
    '.composer button svg{width:18px;height:18px}.let-agents[dir="ltr"] .composer button svg{transform:scaleX(-1)}',
    '.chat .signup{margin-top:10px;padding:10px}',
    '.ask-q{font-weight:600;margin-bottom:3px}.ask-a{line-height:1.55}.ask-a.is-loading{color:var(--muted)}',
    '.ask-heading{margin:14px 0 4px;font-size:13px;font-weight:600;color:var(--muted)}',
    '.ask-item{padding:8px 0;border-top:1px solid var(--line)}',
    '.ask-note{margin-top:10px;font-size:11px;color:var(--muted)}',
    '.ask-general{margin-top:4px;font-size:12px;color:var(--muted)}',
    // When the assistant has nothing verified: the way to the store team, with the question in hand.
    '.handover{margin-top:10px;padding:12px;border:1px solid transparent;border-radius:14px;background:var(--wash) padding-box,linear-gradient(#fff,#fff) padding-box,var(--hairline) border-box}',
    '.handover-title{font-size:13.5px;line-height:1.45;margin-bottom:8px}',
    '.handover-when{margin-top:8px;font-size:12px;color:var(--muted)}',
    '.callback{margin-top:8px;padding-top:8px;border-top:1px solid #f0f0f2}',
    '.callback-open{all:unset;box-sizing:border-box;cursor:pointer;font-size:12px;color:rgb(var(--g2));text-decoration:underline}',
    '.callback-open[hidden]{display:none}',
    '.callback-form[hidden]{display:none}',
    '.callback-row{display:flex;gap:8px}',
    '.callback .signup-input{height:38px;font-size:14px}.callback .signup-send{height:38px}',
    '.lead{margin-top:10px;padding:11px 12px;border:1px solid transparent;border-radius:14px;background:var(--wash) padding-box,linear-gradient(#fff,#fff) padding-box,var(--hairline) border-box}',
    '.lead-said{font-size:13.5px;line-height:1.5}',
    '.lead-form{margin-top:8px}.lead-row{display:flex;gap:8px}',
    '.lead-done{font-size:13.5px;line-height:1.5;font-weight:600}',
    '.lead-no{all:unset;cursor:pointer;display:inline-block;margin-top:7px;font-size:12px;color:var(--muted);text-decoration:underline}',
    '.lead .signup-input{height:38px;font-size:14px}.lead .signup-send{height:38px}',
    // The badge is lifted out of the flow and hung above the card, so the card keeps its shape
    // whether or not anyone is answering.
    '.contact-wrap{position:relative;margin-top:10px;padding-top:22px}',
    '.contact-badge{position:absolute;top:0;inset-inline-start:8px;display:inline-flex;align-items:center;gap:6px;font-size:11.5px;line-height:1.4;color:var(--muted)}',
    '.contact-badge:before{content:"";flex:none;width:7px;height:7px;border-radius:50%;background:#c4c4c8}',
    '.contact-wrap.is-online .contact-badge{color:#146c43}',
    // A slow breath, not a blink: enough to read as live, never enough to nag.
    '.contact-wrap.is-online .contact-badge:before{background:#16a34a;animation:let-agents-alive 2.4s ease-in-out infinite}',
    '@keyframes let-agents-alive{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(22,163,74,.5)}50%{opacity:.7;box-shadow:0 0 0 5px rgba(22,163,74,0)}}',
    '.contact{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:12px 14px;border:1px solid var(--line);border-radius:16px;background:var(--surface)}',
    '.contact-title{flex:1 1 180px;min-width:0;font-size:14px;line-height:1.45}',
    '.contact-button{flex:none;display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:999px;background:#25d366;color:#fff;text-decoration:none;font-size:14px;font-weight:600}',
    '.contact-button svg{width:17px;height:17px;flex:none;fill:currentColor}',
    '.contact-button:hover,.contact-button:focus-visible{filter:brightness(.95)}',
    '.contact-note{margin:6px 2px 0;font-size:12px;color:var(--muted)}',
    '.browse{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}',
    '.browse a{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border:1px solid var(--line);border-radius:999px;color:inherit;text-decoration:none;font-size:13px}',
    '.browse a:after{content:"\\203A"}.let-agents[dir="rtl"] .browse a:after{content:"\\2039"}',
    '.browse a:hover,.browse a:focus-visible{border-color:var(--accent)}',
    '.guides a{display:flex;align-items:center;gap:10px;padding:6px 0;color:inherit;text-decoration:none}',
    '.guides img{width:56px;height:42px;object-fit:cover;border-radius:6px;flex:none;background:#f6f6f7}',
    '.guides span{font-size:14px}.guides a:hover span{text-decoration:underline}',
    '.cmp-cards{display:grid;grid-template-columns:1fr 1fr;gap:10px}',
    '.cmp-card{display:flex;flex-direction:column;align-items:flex-start;gap:4px;padding:10px;border:1px solid var(--line);border-radius:12px;background:#fff;color:inherit;text-decoration:none;min-width:0}',
    '.cmp-card.is-this{border-color:var(--accent);box-shadow:inset 0 0 0 1px var(--accent)}',
    'a.cmp-card:hover,a.cmp-card:focus-visible{border-color:var(--accent)}',
    '.cmp-card img{display:block;width:100%;aspect-ratio:4/3;object-fit:contain;background:#f6f6f7;border-radius:8px}',
    '.cmp-badge{padding:1px 8px;border-radius:999px;background:rgba(17,24,39,.07);font-size:11px;color:var(--muted)}',
    '.cmp-card.is-this .cmp-badge{background:var(--accent);color:#fff}',
    '.cmp-title{font-size:13px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
    '.cmp-price{font-weight:700;font-size:15px}',
    '.cmp-note{margin-top:10px;padding:6px 10px;border-radius:8px;background:#e8f6ee;color:#146c43;font-size:13px}',
    '.cmp-heading{margin:14px 0 4px;font-size:13px;font-weight:600;color:var(--muted)}',
    '.cmp-row{display:grid;grid-template-columns:1fr 1fr;gap:2px 10px;padding:8px 0;border-top:1px solid var(--line)}',
    '.cmp-label{grid-column:1/-1;font-size:12px;color:var(--muted)}',
    '.cmp-value{font-size:14px;font-weight:500}.cmp-value.is-this{font-weight:700}',
    '.cmp-same{margin-top:10px;padding-top:8px;border-top:1px solid var(--line);font-size:12px;color:var(--muted)}'
  ].join('');

  var SPARK = '<svg class="spark" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2l1.9 6.1L20 10l-6.1 1.9L12 18l-1.9-6.1L4 10l6.1-1.9zM19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/></svg>';

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined && text !== null) {
      node.textContent = String(text);
    }
    return node;
  }

  var decoder = document.createElement('textarea');
  function decodeEntities(value) {
    decoder.innerHTML = String(value || '');
    return decoder.value;
  }

  function place(host) {
    var placement = bank.placement || {};
    var target = null;

    try {
      target = placement.selector ? document.querySelector(placement.selector) : null;
    } catch (e) {
      target = null; // an invalid selector falls back like a missing element
    }

    if (target && target.parentNode) {
      switch (placement.position) {
        case 'before': target.parentNode.insertBefore(host, target); break;
        case 'prepend': target.insertBefore(host, target.firstChild); break;
        case 'append': target.appendChild(host); break;
        default: target.parentNode.insertBefore(host, target.nextSibling);
      }
      return true;
    }

    if (placement.floating) {
      host.classList.add('is-floating');
      document.body.appendChild(host);
      return true;
    }

    return false;
  }

  /**
   * A question rather than words to look up: it ends with a question mark, or it is two words or
   * more and opens like a question. The same rule as the store's search box.
   */
  var QUESTION_WORDS = ['איך', 'כמה', 'האם', 'למה', 'מדוע', 'מה', 'מהו', 'מהי', 'מהם', 'איפה', 'היכן', 'מתי', 'מי', 'איזה', 'איזו', 'אילו',
    'אפשר', 'ניתן', 'יש', 'צריך', 'כדאי', 'מותר', 'how', 'what', 'why', 'when', 'where', 'which', 'who', 'can', 'does', 'do', 'is', 'are', 'should'];

  function isQuestion(raw) {
    var text = String(raw || '').trim();
    if (/[?？]\s*$/.test(text)) {
      return text.replace(/[?？\s]/g, '').length >= 3;
    }
    var words = text.toLowerCase().split(/\s+/).filter(Boolean);
    return words.length >= 2 && QUESTION_WORDS.indexOf(words[0]) !== -1;
  }

  /** Lower case, Hebrew final letters as regular ones, no punctuation: for matching words, not for showing. */
  function findNormalize(text) {
    return String(text || '').toLowerCase()
      .replace(/ך/g, 'כ').replace(/ם/g, 'מ').replace(/ן/g, 'נ').replace(/ף/g, 'פ').replace(/ץ/g, 'צ')
      .replace(/[\u0591-\u05C7]/g, '')
      .replace(/[^\w\u05D0-\u05EA\s]/g, ' ')
      .replace(/\s+/g, ' ').trim();
  }

  var FIND_STOP = QUESTION_WORDS.map(findNormalize).concat(['של', 'על', 'עם', 'את', 'זה', 'זו', 'לי', 'הוא', 'היא', 'the', 'a', 'an', 'of', 'for', 'to', 'it']);

  function findWords(text) {
    return findNormalize(text).split(' ').filter(function (word) { return word.length > 1 && FIND_STOP.indexOf(word) === -1; });
  }

  /**
   * A word's skeleton: no plural ending, no vav or yod after the first letter. "ברגים" and "בורג"
   * are both "ברג", so full and defective spelling, singular and plural meet.
   */
  function findStem(word) {
    var w = word;
    if (w.length > 4 && /(ימ|ות)$/.test(w)) {
      w = w.slice(0, -2);
    }
    return w.charAt(0) + w.slice(1).replace(/[וי]/g, '');
  }

  /** The share of the typed words a text holds, each compared by its skeleton. */
  function findCovers(text, words) {
    if (!words.length) {
      return 0;
    }
    var hay = ' ' + findNormalize(text).split(' ').map(findStem).join(' ') + ' ';
    var hit = 0;
    words.forEach(function (word) {
      var stem = findStem(word);
      var probe = stem.length > 3 ? stem.slice(0, -1) : stem;
      if (hay.indexOf(probe.length >= 3 ? probe : ' ' + probe) !== -1) {
        hit++;
      }
    });
    return hit / words.length;
  }

  function productCard(section, product, live, labels) {
    var data = live && live[product.id];
    if (live && (!data || !data.is_in_stock)) {
      return null; // gone or out of stock right now
    }
    if (section.require_sale && !(data && data.on_sale)) {
      return null; // "on sale" is a promise: only what is on sale right now
    }

    var card = el('article', 'card');
    var url = safeUrl(data ? data.permalink : product.url);
    var link = el('a');
    if (url) {
      link.href = url;
    }
    link.addEventListener('click', function () { track('click', section, 'panel', { product_id: String(product.id) }); });

    var image = safeUrl(data && data.images && data.images[0] ? data.images[0].thumbnail : product.image);
    if (image) {
      var img = el('img');
      img.src = image;
      img.alt = '';
      img.loading = 'lazy';
      link.appendChild(img);
    }
    link.appendChild(el('span', 'title', data ? decodeEntities(data.name) : product.title));
    card.appendChild(link);

    if (data && data.on_sale) {
      card.appendChild(el('span', 'badge', labels.on_sale));
    }

    if (product.reason) {
      card.appendChild(el('div', 'reason', product.reason));
    }

    if (!data) {
      return card; // no live data: no price and no button, the link still works
    }

    var price = el('div', 'price', money(data.prices, 'price'));
    if (data.on_sale && data.prices && data.prices.regular_price !== data.prices.price) {
      price.appendChild(el('del', null, money(data.prices, 'regular_price')));
    }
    card.appendChild(price);
    if (product.price_note) {
      card.appendChild(el('div', 'note-price', product.price_note));
    }

    function chooseLink() {
      var choose = el('a', 'add secondary', labels.choose_options);
      if (url) {
        choose.href = url;
      }
      choose.addEventListener('click', function () { track('click', section, 'panel', { product_id: String(product.id) }); });
      return choose;
    }

    var simple = data.type === 'simple' && data.is_purchasable && !data.has_options && !product.needs_options;
    if (!simple) {
      card.appendChild(chooseLink());
      return card;
    }

    var button = el('button', 'add', labels.add_to_cart);
    button.type = 'button';
    var status = el('div', 'status');
    button.addEventListener('click', function () {
      button.disabled = true;
      button.textContent = labels.adding;
      addToCart(product.id).then(function (result) {
        track('add_to_cart', section, 'panel', { source: 'widget', product_id: String(product.id), quantity: 1, result: result });
        status.textContent = '';
        if (result === 'added') {
          button.textContent = labels.added;
          // The refresh below fires the store's own signals; this add is already counted.
          lastAddAt = Date.now();
          refreshCartFragments();
          var cart = safeUrl(ctx.cartUrl);
          if (cart) {
            var view = el('a', null, labels.view_cart);
            view.href = cart;
            status.appendChild(view);
          }
        } else if (result === 'needs_options') {
          card.replaceChild(chooseLink(), button);
          status.textContent = lastMessage;
        } else {
          button.disabled = false;
          button.textContent = labels.add_to_cart;
          status.textContent = result === 'out_of_stock' ? labels.out_of_stock : labels.error;
        }
      });
    });
    card.appendChild(button);
    card.appendChild(status);

    return card;
  }

  function guideList(section, guides) {
    var list = el('ul', 'guides');
    (guides || []).forEach(function (guide) {
      var url = safeUrl(guide.url);
      if (!url) {
        return;
      }
      var item = el('li');
      var link = el('a');
      link.href = url;
      var image = safeUrl(guide.image);
      if (image) {
        var img = el('img');
        img.src = image;
        img.alt = '';
        img.loading = 'lazy';
        link.appendChild(img);
      }
      link.appendChild(el('span', null, guide.title));
      link.addEventListener('click', function () { track('click', section, 'panel', guide.id ? { content_id: String(guide.id) } : null); });
      item.appendChild(link);
      list.appendChild(item);
    });
    return list.firstChild ? list : null;
  }

  /** An amount in the store's currency format, like money() but for any value. */
  function moneyValue(prices, value) {
    var minor = parseInt(prices && prices.currency_minor_unit, 10) || 0;
    var formatted = {};
    for (var key in prices) {
      if (Object.prototype.hasOwnProperty.call(prices, key)) {
        formatted[key] = prices[key];
      }
    }
    formatted.value = String(Math.round(value * Math.pow(10, minor)));
    return money(formatted, 'value');
  }

  /**
   * This product next to one of the same kind the shopper viewed before: two cards with photo and
   * price, how much cheaper one is, what differs, and what is the same in one line.
   */
  function compareView(section, previous, live, labels) {
    var current = live && live[PAGE_ID];
    var before = live && live[previous.id];
    var view = el('div', 'cmp');

    function card(data, title, url, badge, isThis) {
      var col = el(url ? 'a' : 'div', 'cmp-card' + (isThis ? ' is-this' : ''));
      if (url) {
        col.href = url;
        col.addEventListener('click', function () { track('click', section, 'panel', { product_id: String(previous.id) }); });
      }
      var image = safeUrl(data && data.images && data.images[0] ? data.images[0].thumbnail : '');
      if (image) {
        var img = el('img');
        img.src = image;
        img.alt = '';
        img.loading = 'lazy';
        col.appendChild(img);
      }
      col.appendChild(el('span', 'cmp-badge', badge));
      col.appendChild(el('span', 'cmp-title', title));
      if (data) {
        col.appendChild(el('span', 'cmp-price', money(data.prices, 'price')));
      }
      return col;
    }

    var heading = document.querySelector('h1');
    var thisTitle = current ? decodeEntities(current.name) : (heading ? heading.textContent.trim() : labels.this_product);
    var cards = el('div', 'cmp-cards');
    cards.appendChild(card(current, thisTitle, null, labels.this_product, true));
    cards.appendChild(card(before, before ? decodeEntities(before.name) : previous.title, safeUrl(previous.url), labels.viewed_before, false));
    view.appendChild(cards);

    if (current && before) {
      var difference = amount(current.prices, 'price') - amount(before.prices, 'price');
      if (Math.abs(difference) >= 1) {
        var note = difference < 0 ? labels.this_cheaper : labels.before_cheaper;
        view.appendChild(el('div', 'cmp-note', String(note || '').replace(':amount', moneyValue(current.prices, Math.abs(difference)))));
      }
    }

    var mine = {};
    bank.compare.rows.forEach(function (r) { mine[r[0]] = r; });
    var theirs = {};
    previous.rows.forEach(function (r) { theirs[r[0]] = r; });
    var keys = bank.compare.rows.map(function (r) { return r[0]; });
    previous.rows.forEach(function (r) { if (keys.indexOf(r[0]) === -1) { keys.push(r[0]); } });

    var rows = el('div', 'cmp-rows');
    var same = [];
    keys.forEach(function (key) {
      var label = (mine[key] || theirs[key])[1];
      var a = mine[key] ? mine[key][2] : '';
      var b = theirs[key] ? theirs[key][2] : '';
      if (a && a === b) {
        same.push(label + ': ' + a);
        return;
      }
      var row = el('div', 'cmp-row');
      row.appendChild(el('div', 'cmp-label', label));
      row.appendChild(el('div', 'cmp-value is-this', a || '—'));
      row.appendChild(el('div', 'cmp-value', b || '—'));
      rows.appendChild(row);
    });

    if (rows.firstChild) {
      view.appendChild(el('div', 'cmp-heading', labels.differences));
      view.appendChild(rows);
    }
    if (same.length) {
      view.appendChild(el('div', 'cmp-same', (labels.same_in_both || '') + ' ' + same.join(' · ')));
    }

    return view;
  }

  /** Whether the shop answers right now, by its own hours and time zone. */
  function shopIsOnline(contact) {
    var hours = contact.hours || [];
    var now = new Date();
    var day;
    var minutes;

    try {
      var parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: contact.timezone || 'UTC', weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false
      }).formatToParts(now);
      var read = {};
      parts.forEach(function (part) { read[part.type] = part.value; });
      day = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(read.weekday);
      minutes = parseInt(read.hour, 10) * 60 + parseInt(read.minute, 10);
    } catch (e) {
      day = now.getDay();
      minutes = now.getHours() * 60 + now.getMinutes();
    }

    // One entry per day, Sunday first. Empty means the shop is closed that day.
    var match = /^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/.exec(String(hours[day] || '').trim());

    if (!match) {
      return false;
    }

    var from = parseInt(match[1], 10) * 60 + parseInt(match[2], 10);
    var until = parseInt(match[3], 10) * 60 + parseInt(match[4], 10);

    // A day that runs past midnight ends on the day it started.
    return until > from ? (minutes >= from && minutes < until) : (minutes >= from || minutes < until);
  }

  /** The strip that opens WhatsApp with this product, when the shop asked for one. */
  function contactStrip(live) {
    var contact = bank.contact;

    if (!contact) {
      return null;
    }

    var online = shopIsOnline(contact);

    if (!online && contact.hide_when_offline) {
      return null;
    }

    var data = live && live[PAGE_ID];
    var heading = document.querySelector('h1');
    var title = data ? decodeEntities(data.name) : (heading ? heading.textContent.trim().slice(0, 120) : document.title);
    var link = safeUrl(data ? data.permalink : location.href.split('?')[0]) || location.href.split('?')[0];
    var message = String(contact.message || '').replace(':product', title).replace(':url', link);

    var strip = el('div', 'contact-wrap' + (online ? ' is-online' : ''));
    strip.appendChild(el('div', 'contact-badge', online ? contact.online_label : contact.offline_label));

    var card = el('div', 'contact');
    card.appendChild(el('span', 'contact-title', contact.title));
    strip.appendChild(card);

    var button = el('a', 'contact-button');
    button.innerHTML = WHATSAPP;
    button.appendChild(el('span', null, contact.button));
    button.href = 'https://wa.me/' + contact.number + '?text=' + encodeURIComponent(message);
    button.target = '_blank';
    button.rel = 'noopener';
    var section = { candidate: 'contact', model: 'contact' };
    button.addEventListener('click', function () {
      track('click', section, 'teaser', PAGE_TYPE === 'product' ? { product_id: PAGE_ID } : { content_id: PAGE_ID });
    });
    card.appendChild(button);

    if (!online && contact.offline_note) {
      strip.appendChild(el('div', 'contact-note', contact.offline_note));
    }

    return strip;
  }

  /**
   * The question box: questions to tap (asked most about this product, then common ones), a field
   * for a free question, the answer, and what earlier shoppers asked. Loaded on first open.
   */
  /** The outcomes where the assistant has nothing of its own to say. */
  // Every ending that leaves the shopper without an answer offers the team instead, a refused
  // question most of all: it is the one the team can answer and the assistant cannot.
  var NO_ANSWER = { no_info: true, limit: true, unavailable: true, out_of_scope: true };

  /**
   * The way out when the assistant cannot answer: one WhatsApp button that opens a chat with the
   * store, carrying the product, its page and the question the shopper just asked. Shown whether
   * the shop is open or closed — a message left at night is still a message — but it says which.
   */
  function askTheTeam(question, live, labels) {
    var contact = bank.contact;

    if (!contact) {
      return null;
    }

    var data = live && live[PAGE_ID];
    var heading = document.querySelector('h1');
    var title = data ? decodeEntities(data.name) : (heading ? heading.textContent.trim().slice(0, 120) : document.title);
    var link = safeUrl(data ? data.permalink : location.href.split('?')[0]) || location.href.split('?')[0];
    var online = shopIsOnline(contact);

    var box = el('div', 'handover');
    box.appendChild(el('div', 'handover-title', labels.ask_team));

    var button = el('a', 'contact-button');
    button.innerHTML = WHATSAPP;
    button.href = 'https://wa.me/' + contact.number + '?text=' + encodeURIComponent(
      String(labels.ask_team_message || '')
        .replace(':product', title)
        .replace(':question', question)
        .replace(':url', link)
    );
    button.target = '_blank';
    button.rel = 'noopener';
    button.appendChild(el('span', null, contact.button));
    box.appendChild(button);

    var when = el('div', 'handover-when', online ? contact.online_label : (contact.offline_note || contact.offline_label));
    box.appendChild(when);

    if (bank.callbacks) {
      box.appendChild(callbackBox(question, labels));
    }

    var section = { candidate: 'contact', model: 'contact' };
    button.addEventListener('click', function () {
      track('click', section, 'panel', PAGE_TYPE === 'product' ? { product_id: PAGE_ID } : { content_id: PAGE_ID });
    });

    return box;
  }



  /**
   * Fills the slot the plugin left inside the article.
   *
   * The widget itself sits beside the content, where the store's placement setting puts it. An
   * offer belongs in the middle of the piece, where a reader has had enough of it to want more —
   * so the plugin leaves an empty element there and this fills it. Only the first: a page with
   * two offers on it has none.
   *
   * It lives outside the shadow root, in the page's own DOM, so it inherits the site's typeface
   * and reads as part of the article rather than as something bolted on.
   */
  function fillCtaSlot(cta, labels, open) {
    var slot = document.querySelector('.let-agents-cta[data-let-agents-cta]');

    if (!slot || !cta || slot.dataset.letAgentsFilled) {
      return;
    }
    slot.dataset.letAgentsFilled = '1';

    var card = document.createElement('div');
    card.setAttribute('dir', document.documentElement.dir || 'rtl');
    card.style.cssText = 'margin:22px 0;padding:16px 18px;border-radius:14px;border:1px solid rgba(17,24,39,.1);'
      + 'background:linear-gradient(135deg,rgba(99,102,241,.06),rgba(236,72,153,.05));font-family:inherit';

    var head = document.createElement('div');
    head.textContent = cta.headline;
    head.style.cssText = 'font-size:17px;font-weight:700;line-height:1.35';

    var body = document.createElement('div');
    body.textContent = cta.body;
    body.style.cssText = 'margin-top:5px;font-size:14.5px;line-height:1.5;opacity:.85';

    var go = document.createElement('button');
    go.type = 'button';
    go.textContent = cta.button;
    go.style.cssText = 'margin-top:11px;padding:9px 20px;border:0;border-radius:999px;cursor:pointer;'
      + 'background:#6366f1;color:#fff;font:inherit;font-size:14px;font-weight:600';

    go.addEventListener('click', function () {
      track('click', { candidate: 'cta', model: cta.variant }, 'inline', { cta: cta.id });
      if (typeof open === 'function') {
        open();
      }
    });

    card.appendChild(head);
    card.appendChild(body);
    card.appendChild(go);
    slot.appendChild(card);

    track('exposure', { candidate: 'cta', model: cta.variant }, 'inline', { cta: cta.id });
  }
  /**
   * The conversation that is trying to get somewhere.
   *
   * One field at a time, because a form of six boxes in a chat bubble is a form, and people fill
   * in forms less often than they answer a question. The server decides what comes next and
   * whether what was typed is real — this only draws it — so the widget cannot be talked into
   * skipping the consent by anybody editing it in a console.
   *
   * Saying no ends it for this visit. Being asked twice is how a helpful thing becomes a pop-up.
   */
  function leadFlow(labels, asked, onDone) {
    var wrap = el('div', 'lead');
    var said = el('div', 'lead-said');
    var form = el('form', 'lead-form');
    var row = el('div', 'lead-row');
    var input = el('input', 'signup-input');
    var send = el('button', 'signup-send');
    var given = {};
    var declined = false;
    // How many fields this reader has been shown, so an abandoned flow says where it stopped.
    var step_ = 0;
    var busy = false;

    input.type = 'text';
    input.maxLength = 200;
    send.type = 'submit';
    send.appendChild(el('span', null, labels.lead_next));
    row.appendChild(input);
    row.appendChild(send);

    var agree = el('label', 'signup-consent');
    var tick = el('input');
    tick.type = 'checkbox';
    agree.hidden = true;
    agree.appendChild(tick);
    var agreeText = el('span');
    agree.appendChild(agreeText);

    var no = el('button', 'lead-no', labels.lead_no);
    no.type = 'button';

    wrap.appendChild(said);
    form.appendChild(row);
    form.appendChild(agree);
    wrap.appendChild(form);
    wrap.appendChild(no);

    var field = null;

    function step(body) {
      if (busy) {
        return;
      }
      busy = true;
      send.disabled = true;

      fetch(API + '/widget/' + ctx.site + '/lead', {
        method: 'POST',
        credentials: 'omit',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({
          id: PAGE_ID, type: PAGE_TYPE, vid: vid, asked: asked(),
          given: given, consent: body.consent === true, declined: body.declined === true
        })
      })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (json) {
          var data = json && json.data;
          if (!data) {
            said.textContent = labels.lead_error;

            return;
          }
          draw(data);
        })
        .catch(function () { said.textContent = labels.lead_error; })
        .then(function () { busy = false; send.disabled = false; });
    }

    function draw(data) {
      if (data.error) {
        said.textContent = labels['lead_error_' + data.error] || labels.lead_error;
      }

      if (data.state === 'declined') {
        declined = true;
        track('lead_declined', null, null, { step: step_ });
        wrap.textContent = '';
        wrap.appendChild(el('div', 'lead-said', labels.lead_declined));

        return;
      }

      if (data.state === 'done') {
        track('lead_done', null, null, { steps: step_ });
        wrap.textContent = '';
        wrap.appendChild(el('div', 'lead-done', data.promise || labels.lead_thanks));
        if (typeof onDone === 'function') {
          onDone();
        }

        return;
      }

      no.hidden = false;

      if (data.state === 'consent') {
        field = null;
        said.textContent = labels.lead_consent_ask;
        input.hidden = true;
        agree.hidden = false;
        agreeText.textContent = data.consent || '';
        send.firstChild.textContent = labels.lead_finish;

        return;
      }

      step_++;
      track('lead_step', null, null, { step: step_, field: data.field.type });
      field = data.field;
      input.hidden = false;
      agree.hidden = true;
      input.value = '';
      input.type = field.type === 'email' ? 'email' : (field.type === 'phone' ? 'tel' : 'text');
      input.placeholder = field.label || '';
      input.setAttribute('aria-label', field.label || '');
      if (!data.error) {
        said.textContent = (data.offer ? data.offer + ' — ' : '') + String(labels.lead_ask_field).replace(':field', field.label || '');
      }
      send.firstChild.textContent = labels.lead_next;
      input.focus();
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      if (declined) {
        return;
      }

      if (field) {
        given[field.key] = input.value;
        step({});

        return;
      }

      if (!tick.checked) {
        said.textContent = labels.signup_need_consent;

        return;
      }

      step({ consent: true });
    });

    no.addEventListener('click', function () {
      step({ declined: true });
    });

    // The first call asks the server what to open with.
    track('lead_start', null, null, {});
    step({});

    return wrap;
  }
  /**
   * A quieter way out than WhatsApp: leave a phone or an email and the team comes back with the
   * answer. Closed until it is asked for, so it never competes with the green button above it.
   */
  function callbackBox(question, labels) {
    var wrap = el('div', 'callback');

    var open = el('button', 'callback-open', labels.callback_open);
    open.type = 'button';
    wrap.appendChild(open);

    var form = el('form', 'callback-form');
    form.hidden = true;

    var row = el('div', 'callback-row');
    var input = el('input', 'signup-input');
    input.type = 'text';
    input.maxLength = 120;
    input.placeholder = labels.signup_placeholder || '';
    input.setAttribute('aria-label', labels.callback_open || '');
    var send = el('button', 'signup-send');
    send.type = 'submit';
    send.appendChild(el('span', null, labels.callback_send));
    row.appendChild(input);
    row.appendChild(send);
    form.appendChild(row);

    var agree = el('label', 'signup-consent');
    var tick = el('input');
    tick.type = 'checkbox';
    agree.appendChild(tick);
    agree.appendChild(el('span', null, labels.callback_consent));
    form.appendChild(agree);

    var said = el('div', 'signup-status');
    said.hidden = true;
    form.appendChild(said);

    open.addEventListener('click', function () {
      form.hidden = false;
      open.hidden = true;
      input.focus();
      track('open', { candidate: 'callback', model: 'contact' }, 'panel');
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var typed = input.value.trim();

      if (!typed) {
        return;
      }
      if (!tick.checked) {
        said.hidden = false;
        said.textContent = labels.signup_need_consent;

        return;
      }

      send.disabled = true;
      fetch(API + '/widget/' + ctx.site + '/signup', {
        method: 'POST',
        credentials: 'omit',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({
          vid: vid,
          contact: typed,
          consent: true,
          locale: String(ctx.locale || 'he').slice(0, 2),
          question: question,
          type: PAGE_TYPE,
          id: PAGE_ID
        })
      })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (json) {
          var status = json && json.data && json.data.status;
          said.hidden = false;
          said.textContent = status === 'invalid_contact' ? labels.signup_invalid : labels.callback_saved;

          if (status !== 'invalid_contact') {
            row.hidden = true;
            agree.hidden = true;
            track('submit', { candidate: 'callback', model: 'contact' }, 'panel');
          }
          send.disabled = false;
        })
        .catch(function () {
          said.hidden = false;
          said.textContent = labels.signup_error;
          send.disabled = false;
        });
    });

    wrap.appendChild(form);

    return wrap;
  }

  function askPanel(section, labels, live) {
    var node = el('div', 'body ask');
    var suggested = el('div', 'ask-suggested');
    var form = el('form', 'ask-form');
    var input = el('input', 'ask-input');
    input.type = 'text';
    input.maxLength = 200;
    input.placeholder = labels.ask_placeholder || '';
    input.setAttribute('aria-label', labels.ask_title || '');
    var send = el('button', 'ask-send', labels.ask_send);
    send.type = 'submit';
    form.appendChild(input);
    form.appendChild(send);
    var answer = el('div', 'ask-answer');
    answer.setAttribute('aria-live', 'polite');
    answer.hidden = true;
    var recent = el('div', 'ask-recent');
    node.appendChild(suggested);
    node.appendChild(form);
    node.appendChild(answer);
    node.appendChild(recent);
    node.appendChild(el('div', 'ask-note', labels.ask_note));

    var busy = false;
    function ask(question) {
      question = String(question || '').trim();
      if (!question || busy) {
        return;
      }
      busy = true;
      send.disabled = true;
      answer.hidden = false;
      answer.textContent = '';
      answer.appendChild(el('div', 'ask-q', question));
      var reply = el('div', 'ask-a is-loading', labels.ask_thinking);
      answer.appendChild(reply);

      fetch(API + '/widget/' + ctx.site + '/ask', {
        method: 'POST',
        credentials: 'omit',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({ id: PAGE_ID, type: PAGE_TYPE, question: question, vid: vid, locale: String(ctx.locale || 'he').slice(0, 2) })
      })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (json) {
          var data = json && json.data;
          reply.className = 'ask-a';
          reply.textContent = data ? data.answer : labels.ask_error;
          answered++;

          // A reader who got an answer is a reader worth asking. Once, and only where the shop
          // has said what it wants — otherwise this is a pop-up with extra steps.
          if (bank.lead && !offered && answered >= 1) {
            offered = true;
            answer.appendChild(leadFlow(labels, function () { return answered; }, null));
          }
          // Written into a bubble that was already on the screen, so nothing scrolls on its own.
          if (typeof reply.scrollIntoView === 'function') {
            try { reply.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) { /* older browsers manage */ }
          }
          if (data && data.source === 'general') {
            answer.appendChild(el('div', 'ask-general', labels.ask_general));
          }
          // Nothing verified to answer with: hand the shopper to the store team, with the
          // product and their own question already written into the message.
          if (data && NO_ANSWER[data.outcome]) {
            var handover = askTheTeam(question, live, labels);
            if (handover) {
              answer.appendChild(handover);
            }
          }
          track('chat_question', section, 'panel', {
            length: Math.min(2000, question.length),
            answered_from: data && data.from === 'bank' ? 'bank' : (data && data.outcome === 'answered' ? 'rag' : 'none')
          });
        })
        .catch(function () {
          reply.className = 'ask-a';
          reply.textContent = labels.ask_error;
        })
        .then(function () {
          busy = false;
          send.disabled = false;
        });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      ask(input.value);
      input.value = '';
    });

    node.load = function () {
      node.load = null;
      fetch(API + '/widget/' + ctx.site + '/questions?id=' + encodeURIComponent(PAGE_ID) + '&type=' + PAGE_TYPE + '&locale=' + encodeURIComponent(String(ctx.locale || 'he').slice(0, 2)), { credentials: 'omit' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (json) {
          var data = json && json.data;
          if (!data) {
            return;
          }
          (data.suggested || []).forEach(function (question) {
            var chip = el('button', 'ask-chip', question);
            chip.type = 'button';
            chip.addEventListener('click', function () { ask(question); });
            suggested.appendChild(chip);
          });
          if ((data.recent || []).length) {
            recent.appendChild(el('div', 'ask-heading', labels.ask_recent));
            data.recent.forEach(function (item) {
              var row = el('div', 'ask-item');
              row.appendChild(el('div', 'ask-q', item.question));
              row.appendChild(el('div', 'ask-a', item.answer));
              if (item.source === 'general') {
                row.appendChild(el('div', 'ask-general', labels.ask_general));
              }
              recent.appendChild(row);
            });
          }
        })
        .catch(function () { /* the field still works */ });
    };

    // The assistant layout types into its own field and hands the question here.
    node.ask = ask;

    return node;
  }

  /**
   * The invitation to leave a phone or an email so the list waits for them next time, shown under
   * whatever is open. Once they left one, the same place says so instead.
   */
  function signUpBox(section, labels) {
    var box = el('div', 'signup');

    if (section.signed_up) {
      box.appendChild(el('div', 'signup-note', String(labels.signed_up_as || '').replace(':contact', section.signed_up.masked)));
      return box;
    }

    var wording = section.signup;
    if (!wording) {
      return null;
    }

    box.appendChild(el('div', 'signup-title', wording.title));
    if (wording.note) {
      box.appendChild(el('div', 'signup-sub', wording.note));
    }

    var form = el('form', 'signup-form');
    var input = el('input', 'signup-input');
    input.type = 'text';
    input.maxLength = 190;
    input.placeholder = wording.placeholder || '';
    input.setAttribute('aria-label', wording.title);
    var send = el('button', 'signup-send');
    send.appendChild(el('span', null, wording.button));
    send.type = 'submit';
    form.appendChild(input);
    form.appendChild(send);
    box.appendChild(form);

    var consent = el('label', 'signup-consent');
    var agreed = el('input');
    agreed.type = 'checkbox';
    consent.appendChild(agreed);
    consent.appendChild(el('span', null, wording.consent));
    box.appendChild(consent);

    var status = el('div', 'signup-status');
    status.setAttribute('aria-live', 'polite');
    box.appendChild(status);

    function post(path, body) {
      return fetch(API + '/widget/' + ctx.site + '/' + path, {
        method: 'POST',
        credentials: 'omit',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify(body)
      })
        .then(function (response) { return response.json(); })
        .then(function (json) { return (json && json.data) || null; })
        .catch(function () { return null; });
    }

    /** The second step: the code that proves the contact is theirs. */
    function askForCode(masked) {
      form.parentNode.removeChild(form);
      consent.parentNode.removeChild(consent);
      status.textContent = String(labels.signup_code_sent || '').replace(':contact', masked || '');

      var codeForm = el('form', 'signup-form');
      var code = el('input', 'signup-input');
      code.type = 'text';
      code.inputMode = 'numeric';
      code.maxLength = 8;
      code.placeholder = labels.signup_code_placeholder || '';
      code.setAttribute('aria-label', labels.signup_code_placeholder || '');
      var confirm = el('button', 'signup-send');
      confirm.appendChild(el('span', null, labels.signup_confirm));
      confirm.type = 'submit';
      codeForm.appendChild(code);
      codeForm.appendChild(confirm);
      box.insertBefore(codeForm, status);

      codeForm.addEventListener('submit', function (event) {
        event.preventDefault();
        confirm.disabled = true;
        post('confirm', { vid: vid, code: code.value }).then(function (data) {
          confirm.disabled = false;
          var outcome = data && data.status;
          if (outcome === 'verified') {
            box.removeChild(codeForm);
            status.textContent = labels.signup_verified;
            return;
          }
          status.textContent = outcome === 'expired' ? labels.signup_expired
            : outcome === 'too_many' ? labels.signup_too_many
              : labels.signup_wrong_code;
        });
      });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      if (!agreed.checked) {
        status.textContent = labels.signup_need_consent;
        return;
      }

      send.disabled = true;
      status.textContent = '';

      post('signup', { vid: vid, contact: input.value, consent: true, locale: String(ctx.locale || 'he').slice(0, 2) })
        .then(function (data) {
          send.disabled = false;
          var outcome = data && data.status;

          if (outcome === 'code_sent') {
            track('click', section, 'panel');
            askForCode(data.masked);
            return;
          }
          if (outcome === 'saved') {
            track('click', section, 'panel');
            box.removeChild(form);
            box.removeChild(consent);
            status.textContent = labels.signup_saved;
            return;
          }
          status.textContent = outcome === 'invalid_contact' ? labels.signup_invalid
            : outcome === 'no_consent' ? labels.signup_need_consent
              : outcome === 'too_many' ? labels.signup_too_many
                : labels.signup_error;
        });
    });

    return box;
  }

  // ---------------------------------------------------------------- products as a slider or a list

  var VIEW_KEY = 'let_agents_view';
  var viewers = [];

  function savedView() {
    try {
      return window.localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'slider';
    } catch (e) {
      return 'slider';
    }
  }

  /** One choice for the whole page, kept in this browser: every product list follows it. */
  function chooseView(mode) {
    try {
      window.localStorage.setItem(VIEW_KEY, mode);
    } catch (e) { /* private mode */ }
    viewers.forEach(function (apply) { apply(mode); });
  }

  /**
   * Puts the cards into the section as a slider (the default: swipe, or arrows on a mouse) or a
   * list, with the two-button switch above them when there is more than one product. The arrows
   * are measured when the section is shown, since a hidden panel has no width.
   */
  function productViews(node, cards, labels) {
    var count = cards.children.length;
    var rtl = (bank.dir || 'rtl') === 'rtl';
    var wrapCards = el('div', 'cards-wrap');
    var prev = el('button', 'cards-nav prev');
    var next = el('button', 'cards-nav next');
    prev.type = 'button';
    next.type = 'button';
    prev.innerHTML = rtl ? CHEVRON_RIGHT : CHEVRON_LEFT;
    next.innerHTML = rtl ? CHEVRON_LEFT : CHEVRON_RIGHT;
    prev.setAttribute('aria-label', labels.view_prev || '');
    next.setAttribute('aria-label', labels.view_next || '');
    prev.hidden = true;
    next.hidden = true;

    function step() {
      return Math.max(120, Math.round(cards.clientWidth * 0.8));
    }
    prev.addEventListener('click', function () { cards.scrollBy({ left: (rtl ? 1 : -1) * step(), behavior: 'smooth' }); });
    next.addEventListener('click', function () { cards.scrollBy({ left: (rtl ? -1 : 1) * step(), behavior: 'smooth' }); });

    var buttons = {};
    var view = null;
    if (count > 1) {
      view = el('div', 'view');
      [['slider', ICON_SLIDER, labels.view_slider], ['list', ICON_LIST, labels.view_list]].forEach(function (option) {
        var button = el('button');
        button.type = 'button';
        button.innerHTML = option[1];
        button.setAttribute('aria-label', option[2] || option[0]);
        button.title = option[2] || option[0];
        button.addEventListener('click', function () { chooseView(option[0]); });
        buttons[option[0]] = button;
        view.appendChild(button);
      });
    }

    var current = 'slider';
    function apply(mode) {
      current = count > 1 ? mode : 'slider';
      cards.className = 'cards is-' + current;
      Object.keys(buttons).forEach(function (key) {
        buttons[key].setAttribute('aria-pressed', key === current ? 'true' : 'false');
      });
      var overflow = current === 'slider' && cards.scrollWidth > cards.clientWidth + 4;
      prev.hidden = !overflow;
      next.hidden = !overflow;
    }
    viewers.push(apply);
    apply(savedView());

    // Measured again each time the section is shown (a panel opens, a bubble appears), on the
    // next frame, once the browser has laid it out.
    node.load = function () {
      var measure = function () { apply(current); };
      if (window.requestAnimationFrame) {
        window.requestAnimationFrame(measure);
      } else {
        setTimeout(measure, 0);
      }
    };

    if (view) {
      node.appendChild(view);
    }
    wrapCards.appendChild(prev);
    wrapCards.appendChild(cards);
    wrapCards.appendChild(next);
    node.appendChild(wrapCards);
  }

  var ICON_SLIDER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="7" y="5" width="10" height="14" rx="2"/><path d="M3 8v8M21 8v8"/></svg>';
  var ICON_LIST = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1.2" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1.2" fill="currentColor" stroke="none"/></svg>';
  var CHEVRON_LEFT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>';
  var CHEVRON_RIGHT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';

  /** The body of one section, or null when nothing in it survives live data. */
  function renderBody(section, live, labels) {
    var node = el('div', 'body');

    if (section.candidate === 'compare') {
      node.appendChild(compareView(section, section.previous, live, labels));
      return node;
    }

    if (section.uses) {
      var tags = el('div', 'tags');
      section.uses.forEach(function (use) { tags.appendChild(el('span', 'tag', use)); });
      node.appendChild(tags);
    }

    // Where the model stands among its kind first, then the highlights, in one list.
    if (section.lines || section.items) {
      var points = el('ul', 'highlights');
      (section.lines || []).forEach(function (line) {
        points.appendChild(el('li', null, line.text));
      });
      (section.items || []).forEach(function (item) {
        var point = el('li');
        point.appendChild(el('strong', null, item.key));
        point.appendChild(document.createTextNode(' ' + item.text));
        points.appendChild(point);
      });
      node.appendChild(points);
    }

    if (section.specs) {
      var specs = el('dl', 'specs');
      section.specs.forEach(function (spec) {
        specs.appendChild(el('dt', null, spec.label));
        specs.appendChild(el('dd', null, spec.value));
      });
      node.appendChild(specs);
    }

    if (section.products) {
      var cards = el('div', 'cards');
      section.products.forEach(function (product) {
        var card = productCard(section, product, live, labels);
        if (card) {
          cards.appendChild(card);
        }
      });
      if (!cards.firstChild) {
        return null;
      }
      productViews(node, cards, labels);

      // The store's own category pages for more of the same kind.
      var browse = el('div', 'browse');
      (section.categories || []).forEach(function (category) {
        var url = safeUrl(category.url);
        if (!url) {
          return;
        }
        var link = el('a', null, String(labels.browse || ':name').replace(':name', category.title));
        link.href = url;
        link.addEventListener('click', function () { track('click', section, 'panel'); });
        browse.appendChild(link);
      });
      if (browse.firstChild) {
        node.appendChild(browse);
      }
    }

    if (section.guides) {
      var guides = guideList(section, section.guides);
      if (guides) {
        node.appendChild(guides);
      } else if (!section.uses) {
        return null;
      }
    }

    return node.firstChild ? node : null;
  }

  /** Drops what live store data contradicts. Without live data, price claims are dropped. */
  function reconcile(live) {
    var current = live && live[PAGE_ID];

    bank.sections = bank.sections.map(function (section) {
      if (section.require_sale && !live) {
        return null;
      }
      if (!section.lines) {
        return section;
      }
      var lines = section.lines.filter(function (line) {
        if (line.price === undefined) {
          return true;
        }
        if (!current) {
          return false;
        }
        var livePrice = amount(current.prices, 'price');
        return Math.abs(livePrice - line.price) <= Math.max(1, line.price * 0.005);
      });
      if (lines.length) {
        return Object.assign({}, section, { lines: lines });
      }
      // A price line the live price contradicts is gone; the highlights beside it stay.
      return section.items && section.items.length ? Object.assign({}, section, { lines: null }) : null;
    }).filter(Boolean);
  }

  function render(live, previous) {
    var labels = bank.labels || {};

    if (previous) {
      bank.sections.push({
        candidate: 'compare',
        model: 'compare',
        title: labels.compare_title,
        chip: String(labels.compare_chip || '').replace(':title', previous.title.length > 24 ? previous.title.slice(0, 22) + '…' : previous.title),
        previous: previous
      });
    }

    // What this visitor looked at, and the invitation to have it kept for next time. Never part of
    // the page bank: the bank is the same for everyone and cached, this is one person's own.
    if (viewed && viewed.products && viewed.products.length) {
      bank.sections.push({
        candidate: 'recent',
        model: 'recent',
        title: labels.recent_title,
        chip: labels.recent_chip,
        products: viewed.products
      });
    }

    // Render every body first: a section whose products are all gone gets no circle. In the tag
    // view the page's own tags come first, then its sections, all as tags in one bank.
    var tagView = bank.layout === 'tags';
    var rendered = [];
    (tagView ? (bank.tags || []).concat(bank.sections) : bank.sections).forEach(function (section) {
      var body = renderBody(section, live, labels);
      if (body) {
        rendered.push({ section: section, body: body });
      }
    });

    // The question box is always the last circle.
    if (bank.ask) {
      var askSection = { candidate: 'ask', model: 'chat', title: labels.ask_title, chip: labels.ask_chip };
      rendered.push({ section: askSection, body: askPanel(askSection, labels, live) });
    }

    if (rendered.length === 0 && !bank.contact && !bank.popularity) {
      return;
    }

    var host = el('div', 'let-agents-widget');
    host.setAttribute('data-let-agents', PAGE_TYPE);
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

    var style = el('style');
    style.textContent = CSS;
    root.appendChild(style);

    var wrap = el('div', 'let-agents');
    wrap.setAttribute('dir', bank.dir || 'rtl');
    wrap.setAttribute('lang', bank.locale || 'he');

    if (teamPreview) {
      var note = el('div', 'note', labels.preview);
      var explainUrl = safeUrl(bank.explain_url);
      if (explainUrl) {
        note.appendChild(document.createTextNode(' '));
        var explain = el('a', null, labels.why_shown);
        explain.href = explainUrl;
        explain.target = '_blank';
        explain.rel = 'noopener';
        note.appendChild(explain);
      }
      wrap.appendChild(note);
    }

    var chips = el('div', 'chips');
    var panel = el('div', 'panel');
    panel.id = 'let-agents-panel';
    panel.hidden = true;
    panel.setAttribute('role', 'region');

    var head = el('div', 'head');
    var heading = el('h3');
    var close = el('button', 'close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', labels.close || 'Close');
    head.appendChild(heading);
    head.appendChild(close);
    panel.appendChild(head);
    var holder = el('div');
    panel.appendChild(holder);

    // The invitation to leave a phone or an email: built once, shown under whatever is open.
    var signup = bank.signup || (viewed && viewed.signed_up)
      ? signUpBox({ signup: bank.signup, signed_up: viewed && viewed.signed_up }, labels)
      : null;

    var open = null;
    var exposed = {};

    function setOpen(index, reason) {
      if (open !== null) {
        rendered[open].pill.setAttribute('aria-expanded', 'false');
      }

      if (index === null || index === open) {
        var closing = open;
        open = null;
        panel.hidden = true;
        if (closing !== null) {
          track('dismiss', rendered[closing].section, CHIP_SLOTS[Math.min(closing, CHIP_SLOTS.length - 1)], { reason: reason || 'closed' });
          rendered[closing].pill.focus();
        }
        return;
      }

      open = index;
      var item = rendered[index];
      item.pill.setAttribute('aria-expanded', 'true');
      heading.textContent = item.section.title;
      holder.textContent = '';
      holder.appendChild(item.body);
      panel.hidden = false;
      if (typeof item.body.load === 'function') {
        item.body.load();
      }
      track('open', item.section, CHIP_SLOTS[Math.min(index, CHIP_SLOTS.length - 1)]);

      var exposureKey = item.section.key || item.section.candidate;
      if (!exposed[exposureKey]) {
        exposed[exposureKey] = true;
        watchExposure(item.body, function (ms, ratio) {
          track('exposure', item.section, 'panel', { visible_ms: ms, ratio: ratio });
        });
      }
    }

    // The key sentence, readable without a click: the first superlative or highlight, as a quote.
    // A highlight the store repeats on many products is never the key sentence.
    var quoteIndex = -1;
    var quoteItem = null;
    for (var q = 0; q < rendered.length && quoteIndex === -1; q++) {
      var candidate = rendered[q].section;
      var items = (candidate.items || []).filter(function (item) { return !item.common; });
      if (candidate.lines && candidate.lines.length) {
        quoteIndex = q;
      } else if (items.length) {
        quoteIndex = q;
        quoteItem = items[0];
      }
    }

    var quoteText = quoteIndex === -1 ? null
      : (quoteItem ? quoteItem.key + ' ' + quoteItem.text : rendered[quoteIndex].section.lines[0].text);

    var pop = popularityLine();
    var strip = contactStrip(live);

    if (bank.layout === 'chat') {
      renderChat();
    } else {
      renderCircles();
    }

    root.appendChild(wrap);

    if (!place(host)) {
      return;
    }

    if (strip) {
      watchExposure(strip, function (ms, ratio) {
        track('exposure', { candidate: 'contact', model: 'contact' }, 'teaser', { visible_ms: ms, ratio: ratio });
      });
    }
    if (pop && pop.parentNode) {
      watchExposure(pop, function (ms, ratio) {
        track('exposure', { candidate: 'popularity', model: 'popularity' }, 'teaser', { visible_ms: ms, ratio: ratio });
      });
    }

    /** The circles: the quote, the row of circles, one panel under them, the strip. */
    function renderCircles() {
      var quote = null;
      if (tagView) {
        chips.className = 'chips tagbank';
        wrap.appendChild(el('div', 'tagbank-title', labels.tags_title || ''));
      }
      if (quoteIndex !== -1 && !tagView) {
        quote = el('button', 'quote');
        quote.type = 'button';
        quote.setAttribute('aria-controls', 'let-agents-panel');
        quote.appendChild(el('span', 'mark', '”'));
        var words = el('span', 'quote-text');
        if (quoteItem) {
          words.appendChild(el('strong', null, quoteItem.key));
          words.appendChild(document.createTextNode(' ' + quoteItem.text));
        } else {
          words.textContent = quoteText;
        }
        quote.appendChild(words);
        quote.addEventListener('click', function () { setOpen(quoteIndex, 'closed'); });
      }

      rendered.forEach(function (item, index) {
        var pill = el('button', 'pill');
        pill.type = 'button';
        pill.setAttribute('aria-expanded', 'false');
        pill.setAttribute('aria-controls', 'let-agents-panel');

        if (index === 0 && !tagView) {
          pill.innerHTML = SPARK;
        }
        if (tagView) {
          pill.className = 'pill tag-pill';
        }
        pill.appendChild(el('span', 'chip-label', item.section.chip || item.section.title));
        var count = pillCount(item.body);
        if (count) {
          pill.appendChild(el('span', 'count', count));
        }
        pill.title = item.section.title;

        pill.addEventListener('click', function () { setOpen(index, 'closed'); });
        item.pill = pill;
        chips.appendChild(pill);
      });

      close.addEventListener('click', function () { setOpen(null, 'closed'); });
      wrap.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && open !== null) {
          setOpen(null, 'escape');
        }
      });

      if (signup) {
        panel.appendChild(signup);
      }
      if (quote) {
        wrap.appendChild(quote);
      }
      if (pop) {
        wrap.appendChild(pop);
      }
      if (bank.find) {
        wrap.appendChild(findField());
      }
      wrap.appendChild(chips);
      wrap.appendChild(panel);
      if (strip) {
        wrap.appendChild(strip);
      }

      /**
       * The field above the circles or tags. Typing matches what this page already holds: the
       * circles, their products and guides, and the answers this page gave. Nothing is fetched
       * and no model is asked while typing. Enter asks the question in the question box, with
       * its handover to the shop, or searches the whole site for words.
       */
      function findField() {
        var findSection = { candidate: 'find', model: 'find', title: labels.find_title };
        var form = el('form', 'find');
        form.setAttribute('role', 'search');
        var input = el('input', 'find-input');
        input.type = 'search';
        input.maxLength = 200;
        input.placeholder = labels.find_placeholder || '';
        input.setAttribute('aria-label', labels.find_placeholder || '');
        input.setAttribute('autocomplete', 'off');
        var send = el('button', 'find-send', labels.find_send || '');
        send.type = 'submit';
        form.appendChild(input);
        form.appendChild(send);

        var timer = null;
        input.addEventListener('input', function () {
          clearTimeout(timer);
          timer = setTimeout(function () { match(input.value); }, 80);
        });
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          clearTimeout(timer);
          submitFind(input.value);
        });

        function showFind(title, body) {
          if (open !== null) {
            rendered[open].pill.setAttribute('aria-expanded', 'false');
            open = null;
          }
          heading.textContent = title;
          holder.textContent = '';
          holder.appendChild(body);
          panel.hidden = false;
        }

        function match(raw) {
          var text = String(raw || '').trim();
          var words = findWords(text);
          rendered.forEach(function (item) {
            if (!item.pill) {
              return;
            }
            var products = (item.section.products || []).map(function (p) { return p.title; }).join(' ');
            var hay = (item.section.chip || '') + ' ' + (item.section.title || '') + ' ' + products;
            item.pill.hidden = !!text && item.section.candidate !== 'ask' && words.length > 0 && findCovers(hay, words) < 0.5;
          });

          if (!text) {
            if (open === null) {
              panel.hidden = true;
            }
            return;
          }

          var body = el('div', 'body find-body');
          var question = isQuestion(text);
          var saved = null;
          (bank.answers || []).forEach(function (answer) {
            var score = findCovers(answer.q, words);
            if (score >= 0.6 && (!saved || score > saved.score)) {
              saved = { answer: answer, score: score };
            }
          });

          if (saved && (question || words.length >= 2)) {
            var box = el('div', 'find-answer');
            box.appendChild(el('span', 'find-tag', labels.find_saved || ''));
            box.appendChild(el('strong', null, saved.answer.q));
            box.appendChild(el('p', null, saved.answer.a));
            body.appendChild(box);
          } else if (question && bank.ask) {
            var ask = el('button', 'find-ask');
            ask.type = 'button';
            ask.appendChild(el('span', null, labels.find_ask_hint || ''));
            ask.appendChild(el('b', null, labels.find_ask || ''));
            ask.addEventListener('click', function () { submitFind(text); });
            body.appendChild(ask);
          }

          var seen = {};
          var cards = el('div', 'cards');
          rendered.forEach(function (item) {
            (item.section.products || []).forEach(function (product) {
              if (cards.childNodes.length >= 4 || seen[product.id] || findCovers(product.title, words) < 0.5) {
                return;
              }
              var card = productCard(findSection, product, live, labels);
              if (card) {
                seen[product.id] = true;
                cards.appendChild(card);
              }
            });
          });
          if (cards.firstChild) {
            productViews(body, cards, labels);
          }

          var guides = [];
          rendered.forEach(function (item) {
            (item.section.guides || []).forEach(function (guide) {
              if (guides.length < 3 && guides.indexOf(guide) === -1 && findCovers(guide.title, words) >= 0.5) {
                guides.push(guide);
              }
            });
          });
          var guideNodes = guides.length ? guideList(findSection, guides) : null;
          if (guideNodes) {
            body.appendChild(guideNodes);
          }

          if (!body.firstChild) {
            body.appendChild(el('p', 'find-none', labels.find_none || ''));
          }
          showFind(labels.find_title || text, body);
        }

        function submitFind(raw) {
          var text = String(raw || '').trim();
          if (!text) {
            return;
          }

          var askIndex = -1;
          rendered.forEach(function (item, index) {
            if (item.section.candidate === 'ask') {
              askIndex = index;
            }
          });

          // A question goes to the question box: the same answer, limits and way to the shop.
          if (isQuestion(text) && askIndex !== -1 && typeof rendered[askIndex].body.ask === 'function') {
            if (open !== askIndex) {
              setOpen(askIndex, 'closed');
            }
            rendered[askIndex].body.ask(text);
            return;
          }

          // Words: what the store's own search finds, one request on Enter.
          var body = el('div', 'body find-body');
          body.appendChild(el('p', 'find-none', labels.find_searching || ''));
          showFind(text, body);
          fetch(API + '/search/' + encodeURIComponent(ctx.site) + '?q=' + encodeURIComponent(text), { credentials: 'omit' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (result) {
              var groups = (result && result.groups) || {};
              var items = (groups.product || []).slice(0, 6).concat((groups.content || []).slice(0, 3)).map(function (item) {
                return { id: item.external_id, title: item.title, url: item.url, image: item.image };
              });
              body.textContent = '';
              var list = items.length ? guideList(findSection, items) : null;
              body.appendChild(list || el('p', 'find-none', labels.find_nothing || ''));
            })
            .catch(function () {
              body.textContent = '';
              body.appendChild(el('p', 'find-none', labels.find_nothing || ''));
            });
        }

        return form;
      }

      // One exposure per section: the quote's section when it is shown, and the first circle's when it is another.
      if (quote) {
        watchExposure(quote, function (ms, ratio) {
          track('exposure', rendered[quoteIndex].section, 'teaser', { visible_ms: ms, ratio: ratio });
        });
      }
      if (quoteIndex !== 0 && rendered.length) {
        watchExposure(chips, function (ms, ratio) {
          track('exposure', rendered[0].section, quote ? 'chip_1' : 'teaser', { visible_ms: ms, ratio: ratio });
        });
      }
    }

    /**
     * The assistant: the page loads with one closed line that invites a click; it opens into a
     * conversation whose suggestions are the same sections, each answered from the bank already
     * in hand. No model is called for any of it; only a typed question goes to the Assistant.
     */
    function renderChat() {
      var chatSection = { candidate: 'chat', model: 'chat' };

      // Closed, the assistant is a banner that changes what it holds: the opening line, what
      // goes with this product, where it stands, what the shop promises. A click opens the
      // conversation, and a click on a frame opens it straight on what that frame showed.
      var banner = el('div', 'bn');
      banner.setAttribute('aria-live', 'polite');
      var bhead = el('div', 'bn-head');
      bhead.innerHTML = SPARK_G;
      bhead.appendChild(el('span', 'bn-who', labels.chat_who));
      banner.appendChild(bhead);
      var stage = el('div', 'bn-stage');
      banner.appendChild(stage);
      var feet = el('div', 'bn-feet');
      banner.appendChild(feet);

      var frames = bannerFrames(rendered, labels, previous);
      var stopTurning = bannerRotate(stage, feet, frames, labels);
      frames.forEach(function (frame) {
        frame.node.addEventListener('click', function () {
          openChat();
          if (frame.cta) {
            startLead(frame.cta);
          } else if (frame.ask) {
            askInChat(frame.ask);
          } else if (frame.index !== null) {
            pick(frame.index);
          }
        });
      });

      var card = el('div', 'chat');
      card.hidden = true;
      var chead = el('div', 'chat-head');
      chead.innerHTML = SPARK_G;
      chead.appendChild(el('span', 'who', labels.chat_who));
      chead.appendChild(el('span', 'aside', labels.chat_aside));
      card.appendChild(chead);

      var thread = el('div', 'thread');

      /**
       * Keeps the conversation to what is being said now.
       *
       * A thread that only grows pushes the newest answer, and then the field itself, off the
       * bottom of the screen — on a phone within three questions. So everything past the last
       * few turns is folded away behind a button, which is where a reader would look for it
       * anyway, and nothing is thrown out.
       */
      var KEEP = 4;
      var expanded = false;
      var earlier = el('button', 'earlier');
      earlier.type = 'button';
      earlier.hidden = true;
      earlier.addEventListener('click', function () {
        // Asked for the whole conversation: it stays whole from here on.
        expanded = true;
        var all = thread.querySelectorAll('.bubble');
        for (var i = 0; i < all.length; i++) { all[i].hidden = false; }
        earlier.hidden = true;
      });
      thread.appendChild(earlier);

      function fold() {
        if (expanded) {
          return;
        }
        var all = thread.querySelectorAll('.bubble');
        var over = all.length - KEEP;
        // Run over every bubble each time, not only the new ones: a thread that folded once
        // and never again grows just as far off the screen, only more confusingly.
        for (var i = 0; i < all.length; i++) {
          all[i].hidden = i < over;
        }
        earlier.textContent = over === 1
          ? String(labels.chat_earlier_one || '')
          : String(labels.chat_earlier || '').replace(':n', over);
        earlier.hidden = over <= 0;
      }

      /**
       * Brings what was just said, and the field it is answered in, back into view.
       *
       * The bottom of the card, not the nearest edge of it: with the top of the card already
       * on the screen a browser considers 'nearest' satisfied and scrolls nothing, which
       * leaves the newest answer and the field itself below the fold — the whole complaint.
       */
      function showLatest() {
        fold();
        thread.scrollTop = thread.scrollHeight;
        if (typeof card.scrollIntoView !== 'function') {
          return;
        }
        try {
          card.scrollIntoView({ block: 'end', behavior: 'smooth' });
        } catch (e) {
          card.scrollIntoView(false);
        }
      }

      thread.appendChild(el('div', 'bubble', labels.chat_greeting));
      if (quoteText) {
        var said = el('div', 'bubble');
        said.appendChild(el('span', 'mark', '”'));
        said.appendChild(document.createTextNode(quoteText));
        thread.appendChild(said);
      }
      // What to ask sits under the field, where a thumb already is, not above the conversation.
      var suggestions = el('div', 'more');
      card.appendChild(thread);

      var askItem = null;
      rendered.forEach(function (item) {
        if (item.section.candidate === 'ask') {
          askItem = item;
        }
      });

      function chip(item, index) {
        var button = el('button', 'pill');
        button.type = 'button';
        button.appendChild(el('span', 'chip-label', item.section.chip || item.section.title));
        var count = pillCount(item.body);
        if (count) {
          button.appendChild(el('span', 'count', count));
        }
        button.addEventListener('click', function () { pick(index); });
        return button;
      }

      function offer(except) {
        suggestions.textContent = '';
        if (except !== null) {
          suggestions.appendChild(el('span', 'lead', labels.chat_more));
        }
        rendered.forEach(function (item, i) {
          if (i !== except) {
            suggestions.appendChild(chip(item, i));
          }
        });
      }
      offer(null);

      /**
       * Opens the flow straight away, for a reader who came to the offer rather than to a
       * question. They can still ask anything: the question box is a bubble away.
       */
      function startLead(cta) {
        if (offered) {
          return;
        }
        offered = true;
        var bubble = el('div', 'bubble');
        bubble.appendChild(el('div', 'bubble-lead', cta.headline));
        bubble.appendChild(leadFlow(labels, function () { return answered; }, null));
        thread.appendChild(bubble);
        showLatest();
        track('click', { candidate: 'cta', model: cta.variant }, 'banner', { cta: cta.id });
      }

      /** Puts a question to the assistant inside the conversation, opening its bubble first. */
      function askInChat(text) {
        if (!askItem || typeof askItem.body.ask !== 'function') {
          return;
        }
        if (thread.contains(askItem.body)) {
          askItem.body.ask(text);
        } else {
          pick(rendered.indexOf(askItem));
          setTimeout(function () { askItem.body.ask(text); }, 650);
        }
      }

      var busy = false;
      function pick(index) {
        if (busy) {
          return;
        }
        busy = true;
        var item = rendered[index];
        thread.appendChild(el('div', 'bubble me', item.section.chip || item.section.title));
        showLatest();
        var dots = el('div', 'bubble dots');
        dots.appendChild(el('span'));
        dots.appendChild(el('span'));
        dots.appendChild(el('span'));
        thread.appendChild(dots);
        showLatest();
        suggestions.textContent = '';

        // A moment of "thinking": the answer was ready before the page finished loading.
        setTimeout(function () {
          thread.removeChild(dots);
          var reply = el('div', 'bubble');
          reply.appendChild(el('div', 'bubble-lead', item.section.title));
          reply.appendChild(item.body);
          thread.appendChild(reply);
          showLatest();
          if (typeof item.body.load === 'function') {
            item.body.load();
          }
          offer(index);
          track('open', item.section, CHIP_SLOTS[Math.min(index, CHIP_SLOTS.length - 1)]);
          if (!exposed[item.section.candidate]) {
            exposed[item.section.candidate] = true;
            watchExposure(item.body, function (ms, ratio) {
              track('exposure', item.section, 'panel', { visible_ms: ms, ratio: ratio });
            });
          }
          busy = false;
          if (reply.scrollIntoView) {
            reply.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          }
        }, 600);
      }

      if (askItem) {
        var composer = el('form', 'composer');
        var input = el('input');
        input.type = 'text';
        input.maxLength = 200;
        input.placeholder = labels.chat_placeholder || '';
        input.setAttribute('aria-label', labels.ask_title || '');
        var send = el('button');
        send.type = 'submit';
        send.setAttribute('aria-label', labels.chat_send || '');
        send.innerHTML = ARROW;
        composer.appendChild(input);
        composer.appendChild(send);
        composer.addEventListener('submit', function (event) {
          event.preventDefault();
          var text = input.value.trim();
          if (text) {
            input.value = '';
            askInChat(text);
          }
        });
        card.appendChild(composer);
      }

      // Under the field: what there is to ask about, so the thread above stays the conversation.
      card.appendChild(suggestions);

      // The offer the plugin left inside the article opens this same conversation.
      if (bank.cta && bank.lead) {
        fillCtaSlot(bank.cta, labels, function () {
          openChat();
          startLead(bank.cta);
          if (typeof card.scrollIntoView === 'function') {
            try { card.scrollIntoView({ block: 'end', behavior: 'smooth' }); } catch (e) { card.scrollIntoView(false); }
          }
        });
      }

      function openChat() {
        if (!card.hidden) {
          return;
        }
        stopTurning();
        banner.hidden = true;
        quick.hidden = true;
        card.hidden = false;
        track('open', chatSection, 'teaser');
        if (card.scrollIntoView) {
          card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
      }

      // Under the closed line, the product lists as small tags with their counts — similar
      // products, what the shopper viewed — so the numbers show before anything is opened.
      // A tag opens the conversation straight on that section.
      var quick = el('div', 'quick');
      rendered.forEach(function (item, index) {
        var count = item.body.querySelector('.card') ? pillCount(item.body) : null;
        if (!count) {
          return;
        }
        var tag = el('button', 'pill');
        tag.type = 'button';
        tag.appendChild(el('span', 'chip-label', item.section.chip || item.section.title));
        tag.appendChild(el('span', 'count', count));
        tag.addEventListener('click', function () {
          openChat();
          pick(index);
        });
        quick.appendChild(tag);
      });

      wrap.appendChild(banner);
      if (quick.firstChild) {
        wrap.appendChild(quick);
      }
      // The way to a person stands on its own, under the banner: a shopper who wants the team
      // rather than the assistant should not have to open a conversation to find them.
      if (strip) {
        wrap.appendChild(strip);
      }
      // The offer stands on its own too, and both stay put once the chat opens.
      if (signup) {
        wrap.appendChild(signup);
      }
      wrap.appendChild(card);

      watchExposure(banner, function (ms, ratio) {
        track('exposure', chatSection, 'teaser', { visible_ms: ms, ratio: ratio });
      });
    }
  }

  /**
   * What the closed line says, one sentence per thing this page really has: the comparison with
   * what the shopper viewed before, the questions already asked here, the products that go with
   * it, and the standing invitation to ask. Nothing is claimed that is not on the page.
   */
  function teaserLines(rendered, previous, labels) {
    var lines = [];
    var count = function (candidate, selector) {
      for (var i = 0; i < rendered.length; i++) {
        if (rendered[i].section.candidate === candidate) {
          return rendered[i].body.querySelectorAll(selector || '.card').length;
        }
      }
      return 0;
    };
    var say = function (one, many, n) {
      if (n > 0) {
        lines.push(n === 1 ? String(labels[one] || '') : String(labels[many] || '').replace(':count', String(n)));
      }
    };

    if (previous && previous.title) {
      lines.push(String(labels.chat_line_compare || '').replace(':title', previous.title));
    }
    say('chat_line_complement_one', 'chat_line_complement', count('complement'));
    say('chat_line_similar_one', 'chat_line_similar', count('alternatives'));
    say('chat_line_viewed_one', 'chat_line_viewed', count('recent'));
    say('chat_line_points_one', 'chat_line_points', count('highlights', '.highlights li'));
    say('chat_line_asked_one', 'chat_line_asked', Math.max(0, parseInt(bank.asked, 10) || 0));
    if (bank.ask) {
      lines.push(String(labels.chat_line_ask || ''));
    }

    if (!lines.length) {
      lines.push(rendered.length === 1
        ? String(labels.chat_teaser_one || '')
        : String(labels.chat_teaser || '').replace(':count', String(rendered.length)));
    }

    return lines.filter(function (text) { return text; });
  }

  /**
   * The line writes itself out, waits, erases and moves to the next, the way a chat does. One
   * line, or a shopper who asked for less motion, just gets the text.
   *
   * @return {function} stops it
   */
  function typeLines(target, lines, base) {
    var still = false;
    base = base || 'teaser-text';
    try {
      still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) { /* old browser */ }

    if (!lines.length || still) {
      target.textContent = lines[0] || '';
      return function () {};
    }

    var timer = null;
    var index = 0;
    var at = 0;
    var erasing = false;
    target.className = base + ' is-typing';

    function tick() {
      var text = lines[index];
      at = erasing ? at - 1 : at + 1;
      target.textContent = text.slice(0, at);

      var wait = erasing ? 16 : 32;
      if (!erasing && at >= text.length) {
        // One line writes itself out and stays; several take turns.
        if (lines.length < 2) {
          target.className = base;
          return;
        }
        erasing = true;
        wait = 2800;
      } else if (erasing && at <= 0) {
        erasing = false;
        index = (index + 1) % lines.length;
        wait = 320;
      }
      timer = setTimeout(tick, wait);
    }
    tick();

    return function () {
      clearTimeout(timer);
      target.className = base;
      target.textContent = lines[index];
    };
  }

  /** A frame's row: a tile or a stack of pictures, then a line, and a quieter one under it. */
  function bannerRow(lead, title, sub, plain) {
    var row = el('div', 'bn-row');
    if (lead) {
      row.appendChild(lead);
    }
    var text = el('div', 'bn-text');
    text.appendChild(el('div', 'bn-title' + (plain ? ' is-plain' : ''), title));
    if (sub) {
      text.appendChild(el('div', 'bn-sub', sub));
    }
    row.appendChild(text);
    return row;
  }

  /** Up to three of a list's own pictures, overlapping, and "+N" for the rest. */
  function bannerBubbles(body, max) {
    var images = body.querySelectorAll('.card img');
    var shown = Math.min(images.length, max || 3);

    if (!shown) {
      return null;
    }

    var wrap = el('div', 'bn-bubbles');
    for (var i = 0; i < shown; i++) {
      var picture = el('img');
      picture.src = images[i].src;
      picture.alt = '';
      picture.loading = 'lazy';
      wrap.appendChild(picture);
    }
    if (images.length > shown) {
      wrap.appendChild(el('span', 'bn-more', '+' + (images.length - shown)));
    }
    return wrap;
  }

  var SHIELD = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v6c0 4.2-2.9 7.7-7 9-4.1-1.3-7-4.8-7-9V6z"/><path d="M9 12l2 2 4-4"/></svg>';
  var STAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.1 4.6 5 .6-3.7 3.4 1 4.9L12 14.1 7.6 16.5l1-4.9L4.9 8.2l5-.6z"/></svg>';

  var BULB = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 18h5M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.4.3.6.7.6 1.1v.5h5.8V15c0-.4.2-.8.6-1.1A6 6 0 0 0 12 3z"/></svg>';

  var SCALES = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v16M7 20h10M4 8h16M8 8l-3 6a3 3 0 0 0 6 0zM16 8l3 6a3 3 0 0 1-6 0z"/></svg>';

  var QUESTION = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12a8 8 0 1 1-3.2-6.4M9.6 9a2.5 2.5 0 1 1 3.4 2.3c-.7.3-1 .9-1 1.6v.3"/><circle cx="12" cy="17" r=".6" fill="currentColor"/></svg>';

  /** The square at the start of a frame: an icon, or where the product stands in its set. */
  function bannerTile(kind, icon) {
    var tile = el('div', 'bn-tile is-' + kind);
    tile.innerHTML = icon;
    return tile;
  }

  function bannerRank(of, labels) {
    var tile = el('div', 'bn-tile is-rank');
    tile.appendChild(el('b', null, '#1'));
    if (of) {
      tile.appendChild(el('i', null, String(labels.banner_of || '').replace(':count', String(of))));
    }
    return tile;
  }

  /**
   * What the banner can show on this page. Every frame stands on something the page really has —
   * the complements with their pictures, where the product stands, how wanted it is, what the
   * shopper saw, what the shop promises and what the product is made of. Nothing else joins the
   * round, so a quiet page simply turns fewer frames, and a page with one frame turns none.
   *
   * @return {Array} each {key, label, node, index (a section to open, or null), enter, leave}
   */
  function bannerFrames(rendered, labels, previous) {
    var frames = [];
    var at = function (candidate) {
      for (var i = 0; i < rendered.length; i++) {
        if (rendered[i].section.candidate === candidate) {
          return { item: rendered[i], index: i };
        }
      }
      return null;
    };
    var add = function (key, row, index) {
      var node = el('button', 'bn-frame');
      node.type = 'button';
      node.appendChild(row);
      frames.push({ key: key, label: labels['banner_label_' + key] || '', node: node, index: index === undefined ? null : index });
      return frames[frames.length - 1];
    };
    var shorten = function (text) {
      text = String(text || '').replace(/\s+/g, ' ').trim();
      return text.length > 22 ? text.slice(0, 20).replace(/\s+$/, '') + '…' : text;
    };

    /** A list of products as a frame: their own pictures, how many there are, and where it leads. */
    var listFrame = function (key, candidate, one, many, cta) {
      var found = at(candidate);

      if (!found) {
        return;
      }
      var bubbles = bannerBubbles(found.item.body, 3);
      var count = found.item.body.querySelectorAll('.card').length;

      if (!bubbles || !count) {
        return;
      }
      add(key, bannerRow(
        bubbles,
        count === 1 ? String(labels[one] || '') : String(labels[many] || '').replace(':count', String(count)),
        labels[cta],
        true
      ), found.index);
    };

    // The comparison with the product this shopper looked at before: the strongest thing the
    // widget can say on a page, so it comes first.
    var compare = at('compare');
    if (compare && previous && previous.title) {
      add('compare', bannerRow(
        bannerTile('compare', SCALES),
        String(labels.banner_compare || '').replace(':title', shorten(previous.title)),
        labels.banner_compare_cta
      ), compare.index);
    }

    // What goes with it, in its own pictures.
    var goes = at('complement');
    if (goes) {
      var bubbles = bannerBubbles(goes.item.body, 3);
      var cards = goes.item.body.querySelectorAll('.card');
      var firstTitle = cards.length ? shorten(cards[0].querySelector('.title') ? cards[0].querySelector('.title').textContent : '') : '';
      if (bubbles && firstTitle) {
        add('goes', bannerRow(
          bubbles,
          cards.length === 1
            ? String(labels.banner_goes_one || '').replace(':title', firstTitle)
            : String(labels.banner_goes || '').replace(':title', firstTitle).replace(':count', String(cards.length - 1)),
          labels.banner_all,
          true
        ), goes.index);
      }
    }

    // The same product in another size, and similar products the shop put on sale.
    listFrame('family', 'family', 'banner_family_one', 'banner_family', 'banner_all');
    listFrame('sale', 'on_sale', 'banner_sale_one', 'banner_sale', 'banner_all');

    // Where it stands among its kind: only a real first place, never a tie.
    var best = at('highlights');
    if (best) {
      var lines = best.item.section.lines || [];
      for (var l = 0; l < lines.length; l++) {
        if (lines[l].of) {
          add('best', bannerRow(bannerRank(lines[l].of, labels), lines[l].short || lines[l].text, lines[l].note), best.index);
          break;
        }
      }
    }

    // What is worth knowing about it, in the store's own words, a point per turn. A point the
    // store repeats on half its catalogue is not worth a frame, so the common ones are left out.
    if (best) {
      var points = (best.item.section.items || []).filter(function (item) {
        return item && item.key && item.text && !item.common;
      }).slice(0, 3);

      if (points.length) {
        var point = add('points', bannerRow(bannerTile('points', BULB), points[0].key, points[0].text), best.index);
        var pointTitle = point.node.querySelector('.bn-title');
        var pointText = point.node.querySelector('.bn-sub');
        var pointAt = 0;

        point.enter = function () {
          var shown = points[pointAt % points.length];
          pointTitle.textContent = shown.key;
          pointText.textContent = shown.text;
          pointAt++;
        };
      }
    }

    // How wanted it is, from the nightly counts.
    var pop = bank.popularity;
    if (pop && (pop.badge || pop.text)) {
      add('hot', bannerRow(bannerTile('hot', FLAME), pop.badge || pop.text, pop.badge ? pop.text : null));
    }

    // What the shop promises, read from its own pages, and what this product is made of.
    (bank.assurances || []).forEach(function (assurance) {
      var kind = assurance.scope === 'product' ? 'made' : 'promise';
      add(kind, bannerRow(bannerTile(kind, kind === 'made' ? STAR : SHIELD), assurance.text, assurance.note));
    });

    // What this page offers a reader. It goes before the question frame: a shopper who is
    // ready to talk to somebody should meet that offer before being handed another question.
    if (bank.cta && bank.lead) {
      var offer = add('cta', bannerRow(bannerTile('ask', SPARK), bank.cta.headline, bank.cta.body));
      offer.ask = null;
      offer.cta = bank.cta;
    }

    // A question a shopper really asked here, when there is one. It reached this frame only
    // because both checks passed — the question was about this page and the answer was about it
    // too — so it is a question that fitted. A click asks it again and the saved answer comes
    // back with no model. With nothing asked yet, the invitation stands in its place.
    var ask = at('ask');
    var asked = (bank.questions || []).filter(function (text) { return text; });
    // Nobody has asked anything here yet on most pages, and an invitation to ask is a worse
    // offer than a question. So what the scan decided this page can answer stands in for the
    // questions that have not been asked, and a click asks it exactly the same way.
    var offers = asked.length ? asked : (bank.suggested || []).filter(function (text) { return text; });
    var note = asked.length ? labels.banner_asked_note : labels.banner_suggested_note;

    if (ask && offers.length) {
      var question = add('asked', bannerRow(bannerTile('ask', QUESTION), offers[0], note), ask.index);
      var heading = question.node.querySelector('.bn-title');
      var turn = 0;
      question.ask = offers[0];
      question.enter = function () {
        question.ask = offers[turn % offers.length];
        heading.textContent = question.ask;
        turn++;
      };
    } else if (ask) {
      add('ask', bannerRow(bannerTile('ask', QUESTION), labels.banner_ask, labels.banner_ask_note), ask.index);
    }

    // What this shopper looked at before.
    listFrame('seen', 'recent', 'banner_seen_one', 'banner_seen', 'banner_list');

    // The opening line comes first, and carries the whole invitation when it is alone.
    var typed = el('div', 'bn-type');
    var lines = frames.length ? [String(labels.banner_opener || '')] : teaserLines(rendered, previous, labels);
    var stop = null;
    var opener = add('open', typed);
    opener.node.className = 'bn-frame';
    opener.node.textContent = '';
    opener.node.appendChild(typed);
    opener.enter = function () {
      if (stop) {
        stop();
      }
      stop = typeLines(typed, lines, 'bn-type');
    };
    opener.leave = function () {
      if (stop) {
        stop();
        stop = null;
      }
    };
    frames.unshift(frames.pop());

    return frames;
  }

  /** How long a frame stays before the next one takes its place. The opener reads longer. */
  function bannerHold(frame) {
    return frame.key === 'open' ? 5600 : 4200;
  }

  /**
   * The banner turns itself, softly: one frame fades in, the dots say where it is, and a word
   * stops it for whoever wants to read. One frame, or a shopper who asked for less motion,
   * simply stays still.
   *
   * @return {function} stops the turning for good
   */
  function bannerRotate(stage, feet, frames, labels) {
    var index = 0;
    var timer = null;
    var playing = true;
    var still = false;
    try {
      still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) { /* old browser */ }

    var dots = [];
    var turning = frames.length > 1 && !still;

    frames.forEach(function (frame, i) {
      frame.node.hidden = i !== 0;
      stage.appendChild(frame.node);

      if (frames.length < 2) {
        return;
      }
      var dot = el('button', 'bn-dot');
      dot.type = 'button';
      dot.setAttribute('aria-label', frame.label);
      dot.setAttribute('aria-current', i === 0 ? 'true' : 'false');
      dot.addEventListener('click', function () {
        show(i);
        wait();
      });
      dots.push(dot);
      feet.appendChild(dot);
    });

    function show(next) {
      if (next === index) {
        return;
      }
      var leaving = frames[index];
      if (typeof leaving.leave === 'function') {
        leaving.leave();
      }
      leaving.node.hidden = true;

      index = next;
      frames[index].node.hidden = false;
      if (typeof frames[index].enter === 'function') {
        frames[index].enter();
      }
      dots.forEach(function (dot, i) {
        dot.setAttribute('aria-current', i === index ? 'true' : 'false');
      });
    }

    function wait() {
      clearTimeout(timer);
      if (playing && turning) {
        timer = setTimeout(function () {
          show((index + 1) % frames.length);
          wait();
        }, bannerHold(frames[index]));
      }
    }

    if (turning) {
      var toggle = el('button', 'bn-play', labels.banner_pause);
      toggle.type = 'button';
      toggle.addEventListener('click', function () {
        playing = !playing;
        toggle.textContent = playing ? labels.banner_pause : labels.banner_play;
        wait();
      });
      feet.appendChild(toggle);
    }

    if (typeof frames[0].enter === 'function') {
      frames[0].enter();
    }
    wait();

    return function () {
      playing = false;
      clearTimeout(timer);
      if (typeof frames[index].leave === 'function') {
        frames[index].leave();
      }
    };
  }

  var WHATSAPP = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.46 1.33 4.96L2 22l5.25-1.37c1.44.79 3.07 1.2 4.72 1.2h.01c5.46 0 9.91-4.45 9.91-9.91C21.89 6.45 17.5 2 12.04 2zm0 18.06h-.01c-1.48 0-2.93-.4-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.39c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.83 2.41a8.18 8.18 0 0 1 2.41 5.83c0 4.54-3.7 8.24-8.24 8.24zm4.52-6.17c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.48c-.16 0-.43.06-.65.31-.22.25-.85.84-.85 2.04 0 1.2.87 2.36.99 2.53.12.16 1.71 2.62 4.15 3.67.58.25 1.03.4 1.39.51.58.19 1.11.16 1.53.1.47-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.1-.22-.16-.47-.28z"/></svg>';

  var SPARK_G = '<svg class="spark-g" viewBox="0 0 24 24" aria-hidden="true"><defs><linearGradient id="let-agents-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#4285F4"/><stop offset=".6" stop-color="#8B5CF6"/><stop offset="1" stop-color="#EC4899"/></linearGradient></defs><path fill="url(#let-agents-g)" d="M12 2l2.3 6.4 6.4 2.3-6.4 2.3L12 19.4l-2.3-6.4L3.3 10.7l6.4-2.3z"/><path fill="url(#let-agents-g)" opacity=".7" d="M19 15l.9 2.4 2.4.9-2.4.9L19 21.6l-.9-2.4-2.4-.9 2.4-.9z"/></svg>';

  var ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>';

  var FLAME = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 2c.3 2.4-.3 4.5-1.7 6.2C10.2 10.1 8 11.5 8 14.5A4.5 4.5 0 0 0 12.5 19c2.6 0 4.5-1.9 4.5-4.5 0-1.6-.6-2.8-1.5-3.8-.3 1.1-.9 1.9-1.7 2.3.6-2 .3-4.8-.5-6.5C12.7 5 12.2 3.3 13.5 2z"/></svg>';

  /**
   * What a circle holds, counted from what was really rendered (live stock already applied):
   * "+4" for products or guides, "3" for points. Nothing for a comparison or the question box.
   */
  function pillCount(body) {
    var products = body.querySelectorAll('.card').length;
    if (products) {
      return '+' + products;
    }
    var guides = body.querySelectorAll('.guides li').length;
    if (guides) {
      return '+' + guides;
    }
    var points = body.querySelectorAll('.highlights li').length;
    return points ? String(points) : null;
  }

  /** How wanted the product is: the nightly counts as one quiet line, with a mark when it is among the shop's most wanted. */
  function popularityLine() {
    var pop = bank.popularity;
    if (!pop || (!pop.text && !pop.badge)) {
      return null;
    }
    var line = el('div', 'pop');
    if (pop.badge) {
      var hot = el('span', 'hot');
      hot.innerHTML = FLAME;
      hot.appendChild(document.createTextNode(pop.badge));
      line.appendChild(hot);
    }
    if (pop.text) {
      line.appendChild(el('span', 'pop-text', pop.text));
    }
    return line;
  }

  // ---------------------------------------------------------------- start

  function start() {
    watchStoreAdds();

    var query = '?type=' + PAGE_TYPE + '&id=' + encodeURIComponent(PAGE_ID) +
      '&locale=' + encodeURIComponent(String(ctx.locale || 'he').slice(0, 2)) +
      (previewKey ? '&preview=' + previewKey : '');

    fetch(API + '/widget/' + ctx.site + '/page' + query, { credentials: 'omit' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data || !data.shop) {
          return;
        }
        bank = data;
        shop = data.shop;
        teamPreview = isPreviewMode && data.preview === true;
        holdout = heldOut(data.holdout_percent || 0);

        // The control group is shown the arrangement the learning never touched. The bank is
        // cached and shared, so it carries both orders and the side is chosen here.
        if (holdout && data.baseline_order && data.sections) {
          var order = data.baseline_order;
          data.sections = data.sections.slice().sort(function (a, b) {
            var ai = order.indexOf(a.candidate);
            var bi = order.indexOf(b.candidate);
            return (ai < 0 ? 99 : ai) - (bi < 0 ? 99 : bi);
          });
          bank.sections = data.sections;
          bank.teaser = null;
        }

        track('page_view');
        setTimeout(function () { flush(false); }, 1500);

        var showWidget = data.enabled && (!isPreviewMode || teamPreview);
        var previous = PAGE_TYPE === 'product' && showWidget ? comparable() : null;
        var sections = data.sections || [];

        // In preview mode real visitors only count as page views; the widget is for the team.
        if (!showWidget) {
          rememberCurrent(null);
          return;
        }

        return fetchViewed().then(function (recent) {
          viewed = recent;
          var hasViewed = !!(recent && recent.products && recent.products.length);

          if (sections.length === 0 && !(data.layout === 'tags' && data.tags && data.tags.length) && !previous && !hasViewed && !data.ask && !data.contact && !data.popularity) {
            rememberCurrent(null);
            return;
          }

          // The tag view's products are checked against the live store like any other.
          var ids = [];
          sections.concat(data.layout === 'tags' ? (data.tags || []) : []).forEach(function (section) {
            (section.products || []).forEach(function (product) { ids.push(String(product.id)); });
          });
          if (hasViewed) {
            recent.products.forEach(function (product) { ids.push(String(product.id)); });
          }
          if (PAGE_TYPE === 'product') {
            ids.push(PAGE_ID);
          }
          if (previous) {
            ids.push(String(previous.id));
          }

          return liveProducts(ids).then(function (live) {
            reconcile(live);
            render(live, previous);
            rememberCurrent(live);
          });
        });
      })
      .catch(function () { /* the page works without the widget */ });
  }

  /**
   * The products this visitor looked at, for their own circle. Asked for separately from the page
   * bank, which is cached and the same for everyone, and the visitor travels in the body, not the
   * URL. The widget works without it.
   */
  function fetchViewed() {
    if (!bank.recent) {
      return Promise.resolve(null);
    }

    return fetch(API + '/widget/' + ctx.site + '/recent', {
      method: 'POST',
      credentials: 'omit',
      headers: { 'Content-Type': 'text/plain' },
      body: JSON.stringify({
        vid: vid,
        id: PAGE_TYPE === 'product' ? PAGE_ID : '',
        locale: String(ctx.locale || 'he').slice(0, 2)
      })
    })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (json) { return (json && json.data) || null; })
      .catch(function () { return null; });
  }

  /** Remember this product for a later comparison, with its live title and link. */
  function rememberCurrent(live) {
    if (PAGE_TYPE !== 'product' || !bank || !bank.compare) {
      return;
    }
    var data = live && live[PAGE_ID];
    var heading = document.querySelector('h1');
    rememberProduct({
      id: PAGE_ID,
      key: bank.compare.key,
      title: data ? decodeEntities(data.name) : (heading ? heading.textContent.trim().slice(0, 120) : ''),
      url: data ? data.permalink : location.href.split('?')[0],
      rows: bank.compare.rows,
      at: Date.now()
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
