/*!
 * Let Agents — compare and wishlist, on a store's pages.
 *
 * Loaded by the search script (let-agents-search.js) only in a shop that turned either on, with the
 * shop's settings in window.LetAgentsShortlistConfig. It adds two small buttons, a heart and a
 * compare box, to every product card it can name: the search results (the search script calls
 * LetAgentsShortlist.decorate), the theme's own cards on category pages (a selector the shop sets)
 * and the product page (under the add-to-cart form).
 *
 * Compare: up to five products in a tray at the bottom; "Compare" opens a table built on our side
 * from the store's approved facts. Wishlist: kept in this browser and on our side, so it waits on the
 * next visit; signed in to the store, it is the account's (Shopify vouches for the customer through
 * the app proxy, the WordPress plugin signs the user). A list is shared by link or WhatsApp.
 *
 * Nothing here calls a model; every request is a cached table or a few rows.
 */
(function (win, doc) {
  'use strict';

  if (win.LetAgentsShortlist || !win.LetAgentsSearchContext || !win.LetAgentsShortlistConfig) {
    return;
  }

  var ctx = win.LetAgentsSearchContext;
  var cfg = win.LetAgentsShortlistConfig;
  var API = String(ctx.api || '').replace(/\/+$/, '');
  var SITE = String(ctx.site || '');
  var LOCALE = ctx.locale === 'en' ? 'en' : 'he';
  var RTL = LOCALE === 'he';
  var L = cfg.labels || {};
  var MAX = Math.max(2, Math.min(5, cfg.compareMax || 5));
  var KEY = 'la_shortlist_' + SITE;
  var PROXY = '/apps/letagents/wishlist';
  var SHARE_PARAM = 'la_list';

  if (!API || !SITE) {
    return;
  }

  // ---- What this browser keeps -------------------------------------------------------------

  /** {compare: [{id, title, image, url}], wish: [id], pending: [id] added but not yet confirmed, token, share, account} */
  var state = read();
  var cards = {};
  var buttons = [];

  function read() {
    var data = null;
    try {
      data = JSON.parse(win.localStorage.getItem(KEY) || 'null');
    } catch (e) {
      data = null;
    }
    data = data && typeof data === 'object' ? data : {};
    return {
      compare: Array.isArray(data.compare) ? data.compare.filter(function (p) { return p && p.id; }).slice(0, MAX) : [],
      wish: Array.isArray(data.wish) ? data.wish.map(String) : [],
      pending: Array.isArray(data.pending) ? data.pending.map(String) : [],
      token: typeof data.token === 'string' && /^[A-Za-z0-9_-]{24,64}$/.test(data.token) ? data.token : token(),
      share: typeof data.share === 'string' ? data.share : null,
      account: !!data.account
    };
  }

  function save() {
    try {
      win.localStorage.setItem(KEY, JSON.stringify(state));
    } catch (e) {
      // Private windows: the list lives as long as the page.
    }
  }

  function token() {
    var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    var out = '';
    var bytes = new Uint8Array(32);
    (win.crypto || win.msCrypto).getRandomValues(bytes);
    for (var i = 0; i < bytes.length; i++) {
      out += chars.charAt(bytes[i] % chars.length);
    }
    return out;
  }

  function label(key, values) {
    var text = String(L[key] || key);
    Object.keys(values || {}).forEach(function (name) {
      text = text.split(':' + name).join(String(values[name]));
    });
    return text;
  }

  function el(tag, css, text) {
    var node = doc.createElement(tag);
    if (css) {
      node.className = css;
    }
    if (text !== undefined && text !== null) {
      node.textContent = text;
    }
    return node;
  }

  function safeUrl(url) {
    try {
      var parsed = new URL(String(url || ''), win.location.href);
      return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.href : null;
    } catch (e) {
      return null;
    }
  }

  function money(amount, currency) {
    if (amount === null || amount === undefined || isNaN(amount)) {
      return '';
    }
    try {
      return new Intl.NumberFormat(LOCALE === 'he' ? 'he-IL' : 'en-US', { style: 'currency', currency: currency || ctx.currency || 'ILS', maximumFractionDigits: amount % 1 === 0 ? 0 : 2 }).format(amount);
    } catch (e) {
      return String(amount);
    }
  }

  // ---- The buttons on a product ------------------------------------------------------------

  var HEART = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8.1 3.6 4.5 7.2 4.5c2 0 3.6 1.1 4.8 2.7 1.2-1.6 2.8-2.7 4.8-2.7 3.6 0 5.7 3.6 4.5 6.8-1.8 4.6-9.3 9.2-9.3 9.2z" fill="currentColor" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>';
  var SCALES = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h13l-3-3M17 17H4l3 3"/></svg>';
  var ROUND = 'all:initial;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.94);color:#111827;cursor:pointer;box-shadow:0 1px 4px rgba(0,0,0,.18);font:inherit;';

  /** One round button for a card, styled inline: a card may sit inside another script's shadow root. */
  function roundButton(kind, info) {
    var button = doc.createElement('button');
    button.type = 'button';
    button.setAttribute('style', ROUND);
    button.innerHTML = kind === 'wish' ? HEART : SCALES;
    button.setAttribute('data-la-shortlist', kind);
    // The search box keeps its focus, so the suggestions around the card stay open.
    button.addEventListener('mousedown', function (event) { event.preventDefault(); });
    button.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      kind === 'wish' ? toggleWish(info) : toggleCompare(info);
    });
    buttons.push({ node: button, kind: kind, id: info.id, wide: false });
    paint(buttons[buttons.length - 1]);
    return button;
  }

  /** A button with words, for the product page. */
  function wideButton(kind, info) {
    var button = doc.createElement('button');
    button.type = 'button';
    button.setAttribute('style', 'all:initial;box-sizing:border-box;display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border:1px solid #d1d5db;border-radius:999px;background:#fff;color:#111827;cursor:pointer;font:inherit;font-size:14px;line-height:1.2;');
    button.setAttribute('data-la-shortlist', kind);
    button.addEventListener('click', function (event) {
      event.preventDefault();
      kind === 'wish' ? toggleWish(info) : toggleCompare(info);
    });
    buttons.push({ node: button, kind: kind, id: info.id, wide: true });
    paint(buttons[buttons.length - 1]);
    return button;
  }

  function isWished(id) {
    return state.wish.indexOf(String(id)) !== -1;
  }

  function isCompared(id) {
    return state.compare.some(function (p) { return p.id === String(id); });
  }

  function paint(entry) {
    var on = entry.kind === 'wish' ? isWished(entry.id) : isCompared(entry.id);
    var text = entry.kind === 'wish' ? label(on ? 'wish_added' : 'wish_add') : label(on ? 'compare_added' : 'compare_add');
    entry.node.setAttribute('aria-pressed', on ? 'true' : 'false');
    entry.node.setAttribute('aria-label', text);
    entry.node.title = text;
    if (entry.wide) {
      entry.node.innerHTML = (entry.kind === 'wish' ? HEART : SCALES) + '<span></span>';
      entry.node.lastChild.textContent = text;
    }
    var icon = entry.node.querySelector('svg');
    if (entry.kind === 'wish' && icon) {
      icon.querySelector('path').setAttribute('fill', on ? '#e11d48' : 'none');
      icon.style.color = on ? '#e11d48' : '#111827';
    }
    if (entry.kind === 'compare') {
      entry.node.style.background = on ? '#111827' : (entry.wide ? '#fff' : 'rgba(255,255,255,.94)');
      entry.node.style.color = on ? '#fff' : '#111827';
    }
  }

  function repaint() {
    buttons = buttons.filter(function (entry) { return entry.node.isConnected; });
    buttons.forEach(paint);
    renderTray();
    renderWishButton();
  }

  /**
   * The search script's cards (and anyone's): a heart and a compare box over the picture.
   * info: {id, title, image, url}.
   */
  function decorate(node, info) {
    if (!node || !info || !info.id || node.getAttribute('data-la-shortlisted')) {
      return;
    }
    info = { id: String(info.id), title: String(info.title || ''), image: info.image || null, url: info.url || null };
    cards[info.id] = info;
    node.setAttribute('data-la-shortlisted', '1');
    if (win.getComputedStyle && node.isConnected && win.getComputedStyle(node).position === 'static') {
      node.style.position = 'relative';
    } else if (!node.isConnected && !node.style.position) {
      node.style.position = 'relative';
    }
    var bar = doc.createElement('div');
    bar.setAttribute('style', 'position:absolute;top:8px;' + (RTL ? 'left' : 'right') + ':8px;display:flex;flex-direction:column;gap:6px;z-index:3;');
    if (cfg.wishlist) {
      bar.appendChild(roundButton('wish', info));
    }
    if (cfg.compare) {
      bar.appendChild(roundButton('compare', info));
    }
    node.appendChild(bar);
  }

  // ---- The theme's own cards and the product page --------------------------------------------

  var paths = null;
  var pathsAsked = null;

  function pathOf(url) {
    try {
      var path = decodeURIComponent(new URL(url, win.location.href).pathname).toLowerCase().replace(/\/+$/, '');
      var product = path.match(/\/products\/[^/]+$/);
      return product ? product[0] : path;
    } catch (e) {
      return null;
    }
  }

  /** Product address → the store's id, asked once and only when a card or page needs it. */
  function withPaths(done) {
    if (paths) {
      done(paths);
      return;
    }
    if (!pathsAsked) {
      pathsAsked = fetch(API + '/shortlist/' + encodeURIComponent(SITE) + '/paths', { credentials: 'omit' })
        .then(function (r) { return r.ok ? r.json() : { paths: {} }; })
        .then(function (data) { paths = data.paths || {}; return paths; })
        .catch(function () { paths = {}; return paths; });
    }
    pathsAsked.then(done);
  }

  /** The store's id of a theme card: WooCommerce prints it; anything else is read from its link. */
  function cardId(card, known) {
    var marked = card.querySelector('[data-product_id],[data-product-id]') || (card.matches('[data-product_id],[data-product-id]') ? card : null);
    if (marked) {
      var value = marked.getAttribute('data-product_id') || marked.getAttribute('data-product-id');
      if (/^[0-9]+$/.test(value || '')) {
        return value;
      }
    }
    var post = String(card.className || '').match(/(?:^|\s)post-([0-9]+)(?:\s|$)/);
    if (post) {
      return post[1];
    }
    var link = card.querySelector('a[href]');
    return link && known ? known[pathOf(link.href)] || null : null;
  }

  function cardInfo(card, id) {
    var title = card.querySelector('.woocommerce-loop-product__title, .card__heading, .product-title, .product__title, h2, h3');
    var img = card.querySelector('img');
    var link = card.querySelector('a[href]');
    return { id: id, title: title ? title.textContent.trim() : '', image: img ? img.currentSrc || img.src : null, url: link ? link.href : null };
  }

  function decorateTheme() {
    if (!cfg.cardSelector) {
      return;
    }
    var found;
    try {
      found = Array.prototype.slice.call(doc.querySelectorAll(cfg.cardSelector)).filter(function (card) { return !card.getAttribute('data-la-shortlisted'); });
    } catch (e) {
      return;
    }
    if (!found.length) {
      return;
    }
    var named = found.every(function (card) { return cardId(card, null); });
    var go = function (known) {
      found.forEach(function (card) {
        var id = cardId(card, known);
        if (id) {
          decorate(card, cardInfo(card, id));
        }
      });
    };
    named ? go(null) : withPaths(go);
  }

  function pageProductId(known) {
    var meta = win.ShopifyAnalytics && win.ShopifyAnalytics.meta && win.ShopifyAnalytics.meta.product;
    if (meta && meta.id) {
      return String(meta.id);
    }
    var body = String(doc.body.className || '').match(/(?:^|\s)postid-([0-9]+)(?:\s|$)/);
    if (body && /(?:^|\s)single-product(?:\s|$)/.test(doc.body.className)) {
      return body[1];
    }
    return known ? known[pathOf(win.location.href)] || null : null;
  }

  function decorateProductPage() {
    if (!cfg.productSelector || doc.querySelector('[data-la-shortlist-page]')) {
      return;
    }
    var anchor;
    try {
      anchor = doc.querySelector(cfg.productSelector);
    } catch (e) {
      return;
    }
    if (!anchor) {
      return;
    }
    var place = function (known) {
      var id = pageProductId(known);
      if (!id || doc.querySelector('[data-la-shortlist-page]')) {
        return;
      }
      var og = function (name) { var m = doc.querySelector('meta[property="og:' + name + '"]'); return m ? m.getAttribute('content') : null; };
      var h1 = doc.querySelector('h1');
      var info = { id: id, title: (h1 && h1.textContent.trim()) || og('title') || doc.title, image: og('image'), url: win.location.href };
      cards[id] = info;
      var row = doc.createElement('div');
      row.setAttribute('data-la-shortlist-page', '1');
      row.setAttribute('style', 'display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;');
      if (cfg.wishlist) {
        row.appendChild(wideButton('wish', info));
      }
      if (cfg.compare) {
        row.appendChild(wideButton('compare', info));
      }
      anchor.parentNode.insertBefore(row, anchor.nextSibling);
    };
    // Shopify and WooCommerce say on the page which product it is; their other pages need no lookup.
    var platformPage = !!(win.ShopifyAnalytics && win.ShopifyAnalytics.meta) || /(?:^|\s)woocommerce(?:-page)?(?:\s|$)/.test(doc.body.className);
    if (pageProductId(null)) {
      place(null);
    } else if (!platformPage) {
      withPaths(place);
    }
  }

  // ---- Our own surface: tray, table, drawer --------------------------------------------------

  var host = doc.createElement('div');
  host.id = 'la-shortlist';
  var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
  var CSS = [
    ':host{all:initial}',
    '*{box-sizing:border-box;font-family:inherit}',
    '.wrap{font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;color:#111827;font-size:14px;line-height:1.4}',
    '.tray{position:fixed;bottom:16px;left:50%;transform:translateX(-50%);z-index:2147483000;background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 8px 28px rgba(0,0,0,.18);padding:10px 12px;display:flex;align-items:center;gap:10px;max-width:calc(100vw - 24px)}',
    '.tray b{white-space:nowrap}',
    '.thumbs{display:flex;gap:6px;overflow-x:auto;padding:7px 7px 2px}',
    '.thumb{position:relative;width:46px;height:46px;border-radius:10px;border:1px solid #e5e7eb;background:#f3f4f6 center/cover no-repeat;flex:none}',
    '.thumb button{position:absolute;top:-6px;inset-inline-end:-6px;width:20px;height:20px;border-radius:50%;border:0;background:#111827;color:#fff;font-size:12px;line-height:20px;cursor:pointer;padding:0}',
    '.btn{border:0;border-radius:999px;padding:9px 16px;background:#111827;color:#fff;font:inherit;font-weight:600;cursor:pointer;white-space:nowrap}',
    '.btn[disabled]{opacity:.45;cursor:default}',
    '.ghost{background:transparent;color:#374151;font-weight:500;padding:9px 10px;border:0;font:inherit;cursor:pointer;text-decoration:underline}',
    '.fab{position:fixed;bottom:16px;inset-inline-end:16px;z-index:2147483000;width:50px;height:50px;border-radius:50%;border:0;background:#fff;color:#e11d48;box-shadow:0 6px 20px rgba(0,0,0,.2);cursor:pointer;display:flex;align-items:center;justify-content:center}',
    '.fab i{position:absolute;top:-4px;inset-inline-end:-4px;min-width:20px;height:20px;border-radius:999px;background:#111827;color:#fff;font-size:11px;font-style:normal;line-height:20px;text-align:center;padding:0 5px}',
    '.veil{position:fixed;inset:0;background:rgba(17,24,39,.45);z-index:2147483001;display:flex;align-items:center;justify-content:center;padding:16px}',
    '.box{background:#fff;border-radius:16px;max-width:1100px;width:100%;max-height:calc(100vh - 32px);display:flex;flex-direction:column;overflow:hidden}',
    '.head{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid #e5e7eb}',
    '.head h2{margin:0;font-size:18px;flex:1}',
    '.x{border:0;background:#f3f4f6;border-radius:50%;width:34px;height:34px;font-size:18px;cursor:pointer;line-height:34px;padding:0}',
    '.body{overflow:auto;padding:0 0 12px}',
    'table{border-collapse:separate;border-spacing:0;width:100%;min-width:560px}',
    'th,td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:top;text-align:start}',
    'th.rowname{position:sticky;inset-inline-start:0;background:#fafafa;font-weight:600;color:#374151;width:150px;min-width:120px;z-index:1}',
    'tr.differs td{background:#fffbea}',
    'td.prod{min-width:160px}',
    'td.prod img{width:100%;max-width:150px;aspect-ratio:1;object-fit:cover;border-radius:10px;background:#f3f4f6;display:block}',
    'td.prod a{color:#111827;text-decoration:none;font-weight:600;display:block;margin-top:8px}',
    'td.prod .rm{margin-top:6px;border:0;background:none;color:#6b7280;text-decoration:underline;cursor:pointer;padding:0;font:inherit;font-size:13px}',
    '.none{color:#9ca3af}',
    '.chk{display:flex;align-items:center;gap:6px;font-size:13px;color:#374151;cursor:pointer}',
    '.drawer{position:fixed;top:0;bottom:0;inset-inline-end:0;width:min(420px,100vw);background:#fff;z-index:2147483002;display:flex;flex-direction:column;box-shadow:0 0 30px rgba(0,0,0,.25)}',
    '.list{flex:1;overflow:auto;padding:8px 18px}',
    '.item{display:flex;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid #f0f0f0}',
    '.item img{width:64px;height:64px;border-radius:10px;object-fit:cover;background:#f3f4f6;flex:none}',
    '.item a{color:#111827;text-decoration:none;font-weight:600}',
    '.item .meta{flex:1;min-width:0}',
    '.item .price{color:#374151;font-size:13px;margin-top:2px}',
    '.item button{border:0;background:none;color:#6b7280;cursor:pointer;font-size:18px;padding:4px}',
    '.foot{border-top:1px solid #e5e7eb;padding:12px 18px;display:grid;gap:8px}',
    '.note{color:#6b7280;font-size:12.5px}',
    '.share{display:flex;gap:8px;flex-wrap:wrap}',
    '.wa{background:#25d366}',
    '.pop{position:fixed;bottom:84px;left:50%;transform:translateX(-50%);z-index:2147483003;background:#111827;color:#fff;border-radius:999px;padding:9px 16px;font-size:13.5px;box-shadow:0 6px 20px rgba(0,0,0,.2)}',
    '.msg{padding:24px 18px;color:#6b7280}',
    '@media (max-width:600px){.tray{left:12px;right:12px;transform:none;bottom:12px}.fab{bottom:84px}}'
  ].join('');

  function mount() {
    if (host.isConnected) {
      return;
    }
    var style = doc.createElement('style');
    style.textContent = CSS;
    root.appendChild(style);
    var wrap = el('div', 'wrap');
    wrap.setAttribute('dir', RTL ? 'rtl' : 'ltr');
    root.appendChild(wrap);
    doc.body.appendChild(host);
  }

  function wrap() {
    mount();
    return root.querySelector('.wrap');
  }

  function own(css) {
    var node = wrap().querySelector('.' + css.split(' ')[0]);
    if (node) {
      node.parentNode.removeChild(node);
    }
  }

  // ---- Compare ---------------------------------------------------------------------------------

  function toggleCompare(info) {
    var id = String(info.id);
    if (isCompared(id)) {
      state.compare = state.compare.filter(function (p) { return p.id !== id; });
    } else if (state.compare.length >= MAX) {
      flash(label('compare_full', { max: MAX }));
      return;
    } else {
      var known = cards[id] || info;
      state.compare.push({ id: id, title: known.title || '', image: known.image || null, url: known.url || null });
    }
    save();
    repaint();
  }

  function renderTray() {
    if (!cfg.compare) {
      return;
    }
    own('tray');
    if (!state.compare.length) {
      return;
    }
    var tray = el('div', 'tray');
    tray.appendChild(el('b', null, label('compare_tray', { n: state.compare.length, max: MAX })));
    var thumbs = el('div', 'thumbs');
    state.compare.forEach(function (p) {
      var thumb = el('div', 'thumb');
      if (safeUrl(p.image)) {
        thumb.style.backgroundImage = 'url("' + safeUrl(p.image).replace(/"/g, '%22') + '")';
      }
      thumb.title = p.title;
      var x = el('button', null, '×');
      x.type = 'button';
      x.setAttribute('aria-label', label('remove') + ' ' + p.title);
      x.addEventListener('click', function () { toggleCompare(p); });
      thumb.appendChild(x);
      thumbs.appendChild(thumb);
    });
    tray.appendChild(thumbs);
    var go = el('button', 'btn', label('compare_go'));
    go.type = 'button';
    go.disabled = state.compare.length < 2;
    go.title = state.compare.length < 2 ? label('compare_need') : '';
    go.addEventListener('click', openComparison);
    tray.appendChild(go);
    var clear = el('button', 'ghost', label('clear'));
    clear.type = 'button';
    clear.addEventListener('click', function () { state.compare = []; save(); repaint(); });
    tray.appendChild(clear);
    wrap().appendChild(tray);
  }

  function openComparison() {
    var ids = state.compare.map(function (p) { return p.id; });
    var veil = el('div', 'veil');
    var box = el('div', 'box');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    var head = el('div', 'head');
    head.appendChild(el('h2', null, label('compare_title')));
    var diff = el('label', 'chk');
    var check = el('input');
    check.type = 'checkbox';
    diff.appendChild(check);
    diff.appendChild(doc.createTextNode(label('only_diff')));
    head.appendChild(diff);
    var x = el('button', 'x', '×');
    x.type = 'button';
    x.setAttribute('aria-label', label('close'));
    head.appendChild(x);
    var body = el('div', 'body');
    body.appendChild(el('div', 'msg', label('loading')));
    box.appendChild(head);
    box.appendChild(body);
    veil.appendChild(box);
    var close = function () { veil.parentNode && veil.parentNode.removeChild(veil); doc.removeEventListener('keydown', onKey); };
    var onKey = function (e) { if (e.key === 'Escape') { close(); } };
    x.addEventListener('click', close);
    veil.addEventListener('click', function (e) { if (e.target === veil) { close(); } });
    doc.addEventListener('keydown', onKey);
    wrap().appendChild(veil);
    x.focus();

    fetch(API + '/shortlist/' + encodeURIComponent(SITE) + '/compare?locale=' + LOCALE + '&ids=' + encodeURIComponent(ids.join(',')), { credentials: 'omit' })
      .then(function (r) { if (!r.ok) { throw new Error('compare'); } return r.json(); })
      .then(function (data) {
        body.innerHTML = '';
        body.appendChild(table(data, check));
        check.addEventListener('change', function () {
          Array.prototype.forEach.call(body.querySelectorAll('tr[data-same]'), function (tr) { tr.style.display = check.checked ? 'none' : ''; });
        });
      })
      .catch(function () { body.innerHTML = ''; body.appendChild(el('div', 'msg', label('failed'))); });
  }

  function table(data) {
    var products = data.products || [];
    var t = el('table');
    var tr = el('tr');
    tr.appendChild(el('th', 'rowname', ''));
    products.forEach(function (p) {
      var td = el('td', 'prod');
      var href = safeUrl(p.url);
      var img = el('img');
      img.alt = '';
      if (safeUrl(p.image)) {
        img.src = p.image;
      }
      var a = el('a', null, p.title);
      if (href) {
        a.href = href;
      }
      var rm = el('button', 'rm', label('remove'));
      rm.type = 'button';
      rm.addEventListener('click', function () {
        toggleCompare(p);
        td.style.opacity = '.35';
        rm.disabled = true;
      });
      td.appendChild(img);
      td.appendChild(a);
      td.appendChild(rm);
      tr.appendChild(td);
    });
    t.appendChild(tr);

    var row = function (name, values, differs) {
      var line = el('tr', differs ? 'differs' : null);
      if (!differs) {
        line.setAttribute('data-same', '1');
      }
      line.appendChild(el('th', 'rowname', name));
      values.forEach(function (v) {
        var td = el('td', v === null || v === '' ? 'none' : null, v === null || v === '' ? '—' : v);
        line.appendChild(td);
      });
      t.appendChild(line);
    };
    var prices = products.map(function (p) { return money(p.price, p.currency); });
    row(label('price'), prices, unique(prices) > 1);
    var stock = products.map(function (p) { return label(p.in_stock ? 'in_stock' : 'out_of_stock'); });
    row(label('stock'), stock, unique(stock) > 1);
    (data.rows || []).forEach(function (r) { row(r.label, r.values, !!r.differs); });
    return t;
  }

  function unique(values) {
    return values.filter(function (v, i) { return values.indexOf(v) === i; }).length;
  }

  // ---- Wishlist --------------------------------------------------------------------------------

  /** Through Shopify's app proxy when a customer is signed in there; our API otherwise. */
  function viaProxy() {
    return ctx.platform === 'shopify' && !!ctx.loggedIn;
  }

  var proxyFailed = false;

  function send(body) {
    // Adds the server has not confirmed yet go with every request: one that failed is not lost.
    var add = (body.add || []).concat(state.pending.filter(function (id) { return (body.add || []).indexOf(id) === -1 && (body.remove || []).indexOf(id) === -1; }));
    var payload = { token: state.token, add: add, remove: body.remove || [] };
    var direct = function () {
      if (ctx.customer) {
        payload.customer = ctx.customer;
      }
      return fetch(API + '/shortlist/' + encodeURIComponent(SITE) + '/wishlist', {
        method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
      }).then(function (r) { if (!r.ok) { throw new Error('wishlist'); } return r.json(); });
    };
    if (!viaProxy() || proxyFailed) {
      return direct();
    }
    return fetch(PROXY, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload) })
      .then(function (r) {
        var type = r.headers.get('Content-Type') || '';
        if (!r.ok || type.indexOf('json') === -1) {
          throw new Error('proxy');
        }
        return r.json();
      })
      .catch(function () { proxyFailed = true; return direct(); });
  }

  function take(data) {
    state.wish = (data.items || []).map(String);
    state.pending = [];
    state.share = data.share || state.share;
    state.account = !!data.account;
    (data.products || []).forEach(function (p) { cards[p.id] = p; });
    save();
    repaint();
    return data;
  }

  function sync() {
    return send({}).then(take).catch(function () { return null; });
  }

  function toggleWish(info) {
    var id = String(info.id);
    var adding = !isWished(id);
    cards[id] = cards[id] || info;
    state.wish = adding ? [id].concat(state.wish) : state.wish.filter(function (w) { return w !== id; });
    state.pending = adding ? [id].concat(state.pending) : state.pending.filter(function (w) { return w !== id; });
    save();
    repaint();
    send(adding ? { add: [id] } : { remove: [id] }).then(function (data) {
      take(data);
      if (drawerOpen) {
        renderDrawer(data.products || []);
      }
    }).catch(function () {});
  }

  var drawerOpen = false;

  function renderWishButton() {
    if (!cfg.wishlist) {
      return;
    }
    own('fab');
    if (!state.wish.length || drawerOpen) {
      return;
    }
    var fab = el('button', 'fab');
    fab.type = 'button';
    fab.innerHTML = HEART;
    fab.querySelector('path').setAttribute('fill', '#e11d48');
    fab.setAttribute('aria-label', label('wish_title'));
    fab.appendChild(el('i', null, String(state.wish.length)));
    fab.addEventListener('click', openDrawer);
    wrap().appendChild(fab);
  }

  function openDrawer() {
    drawerOpen = true;
    renderWishButton();
    renderDrawer(null);
    send({}).then(function (data) { take(data); renderDrawer(data.products || []); }).catch(function () {
      renderDrawer(state.wish.map(function (id) { return cards[id]; }).filter(Boolean));
    });
  }

  function closeDrawer() {
    drawerOpen = false;
    own('veil');
    own('drawer');
    renderWishButton();
  }

  /** The drawer: this shopper's list, or (shared) someone else's with "save to my list". */
  function renderDrawer(products, shared) {
    own('veil');
    own('drawer');
    var veil = el('div', 'veil');
    veil.addEventListener('click', closeDrawer);
    var drawer = el('div', 'drawer');
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'true');
    var head = el('div', 'head');
    head.appendChild(el('h2', null, label(shared ? 'shared_title' : 'wish_title')));
    var x = el('button', 'x', '×');
    x.type = 'button';
    x.setAttribute('aria-label', label('close'));
    x.addEventListener('click', closeDrawer);
    head.appendChild(x);
    drawer.appendChild(head);

    var list = el('div', 'list');
    if (products === null) {
      list.appendChild(el('div', 'msg', label('loading')));
    } else if (!products.length) {
      list.appendChild(el('div', 'msg', label('wish_empty')));
    }
    (products || []).forEach(function (p) {
      var item = el('div', 'item');
      var img = el('img');
      img.alt = '';
      if (safeUrl(p.image)) {
        img.src = p.image;
      }
      var meta = el('div', 'meta');
      var a = el('a', null, p.title);
      if (safeUrl(p.url)) {
        a.href = safeUrl(p.url);
      }
      meta.appendChild(a);
      meta.appendChild(el('div', 'price', money(p.price, p.currency)));
      item.appendChild(img);
      item.appendChild(meta);
      if (!shared) {
        var rm = el('button', null, '×');
        rm.type = 'button';
        rm.setAttribute('aria-label', label('remove'));
        rm.addEventListener('click', function () { toggleWish(p); item.parentNode && item.parentNode.removeChild(item); });
        item.appendChild(rm);
      }
      list.appendChild(item);
    });
    drawer.appendChild(list);

    var foot = el('div', 'foot');
    if (shared && products && products.length) {
      var all = el('button', 'btn', label('save_all'));
      all.type = 'button';
      all.addEventListener('click', function () {
        var add = products.map(function (p) { return String(p.id); }).filter(function (id) { return !isWished(id); });
        state.wish = add.concat(state.wish);
        state.pending = add.concat(state.pending);
        save();
        send({ add: add }).then(take).catch(function () {});
        all.disabled = true;
        all.textContent = label('saved_all');
      });
      foot.appendChild(all);
    } else if (!shared && products && products.length) {
      var row = el('div', 'share');
      var wa = el('button', 'btn wa', label('share_whatsapp'));
      wa.type = 'button';
      wa.addEventListener('click', function () { share(function (link) { win.open('https://wa.me/?text=' + encodeURIComponent(label('share_text', { shop: shopName() }) + '\n' + link), '_blank', 'noopener'); }); });
      var copy = el('button', 'btn', label('copy_link'));
      copy.type = 'button';
      copy.addEventListener('click', function () {
        share(function (link) {
          var done = function () { copy.textContent = label('copied'); };
          if (win.navigator.clipboard && win.navigator.clipboard.writeText) {
            win.navigator.clipboard.writeText(link).then(done, function () { win.prompt('', link); });
          } else {
            win.prompt('', link);
          }
        });
      });
      row.appendChild(wa);
      row.appendChild(copy);
      foot.appendChild(row);
    }
    if (!shared) {
      foot.appendChild(el('div', 'note', label(state.account ? 'wish_account' : 'wish_guest')));
    }
    drawer.appendChild(foot);
    wrap().appendChild(veil);
    wrap().appendChild(drawer);
  }

  /** "ב-Rocks and Gold" but "בחנות": a Hebrew prefix letter joins a Hebrew word only. */
  function shopName() {
    var name = String(cfg.shopName || win.location.hostname);
    return LOCALE === 'he' && !/^[\u0590-\u05FF]/.test(name) ? '-' + name : name;
  }

  /** The list's share link, on the store's own address: it opens this list for whoever has it. */
  function share(done) {
    var go = function () {
      if (!state.share) {
        return;
      }
      var url = new URL(win.location.origin + '/');
      url.searchParams.set(SHARE_PARAM, state.share);
      done(url.href);
    };
    state.share ? go() : sync().then(go);
  }

  /** A shared link: someone's list, read-only, with "save to my list". */
  function openShared(code) {
    drawerOpen = true;
    renderWishButton();
    renderDrawer(null, true);
    fetch(API + '/shortlist/' + encodeURIComponent(SITE) + '/shared/' + encodeURIComponent(code), { credentials: 'omit' })
      .then(function (r) { if (!r.ok) { throw new Error('shared'); } return r.json(); })
      .then(function (data) { renderDrawer(data.products || [], true); })
      .catch(function () { renderDrawer([], true); });
  }

  // ---- A short message, then start -------------------------------------------------------------

  function flash(text) {
    own('pop');
    var note = el('div', 'pop', text);
    wrap().appendChild(note);
    setTimeout(function () { own('pop'); }, 2200);
  }

  function scan() {
    decorateTheme();
    decorateProductPage();
  }

  function start() {
    scan();
    repaint();
    var timer = null;
    if (cfg.cardSelector && win.MutationObserver) {
      // Infinite scroll and filters add cards after the page loaded.
      new MutationObserver(function () {
        clearTimeout(timer);
        timer = setTimeout(scan, 300);
      }).observe(doc.body, { childList: true, subtree: true });
    }
    var code = null;
    try {
      code = new URL(win.location.href).searchParams.get(SHARE_PARAM);
    } catch (e) {
      code = null;
    }
    if (cfg.wishlist && code && /^[A-Za-z0-9]{8,16}$/.test(code)) {
      openShared(code);
    }
    // Once a session: what the account or another device changed; a guest with nothing saved asks nothing.
    var synced = false;
    try {
      synced = win.sessionStorage.getItem(KEY + '_synced') === '1';
      win.sessionStorage.setItem(KEY + '_synced', '1');
    } catch (e) {
      synced = false;
    }
    if (cfg.wishlist && !synced && (state.wish.length || ctx.customer || ctx.loggedIn)) {
      sync();
    }
  }

  win.LetAgentsShortlist = { decorate: decorate, compare: cfg.compare, wishlist: cfg.wishlist };

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})(window, document);
