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
 * the app proxy, the WordPress plugin signs the user). A list is shared by link or WhatsApp. A link
 * of the theme's own to "#wishlist" (a menu item, a header icon) opens the list, even empty; an
 * empty <span data-let-agents-wishlist> (or the WordPress shortcode) becomes a heart with the count.
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
  var WISH_HASH = '#wishlist';

  if (!API || !SITE) {
    return;
  }

  // ---- What this browser keeps -------------------------------------------------------------

  /** {compare: [{id, title, image, url}], wish: [id], pending: [id] added but not yet confirmed, token, share, account, phone, phoneShown} */
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
      account: !!data.account,
      // Signed in by phone: the token the server gave, and the number as it may be shown (masked).
      phone: typeof data.phone === 'string' && /^[A-Za-z0-9]{40}$/.test(data.phone) ? data.phone : null,
      phoneShown: typeof data.phoneShown === 'string' ? data.phoneShown : null
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
    // The shop may want the heart alone on its cards, with no circle and no shadow.
    button.setAttribute('style', kind === 'wish' && cfg.heartPlain ? ROUND.replace(/background:[^;]+;/, 'background:transparent;').replace(/box-shadow:[^;]+;/, 'box-shadow:none;') : ROUND);
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
    paintHearts();
  }

  /**
   * The shop's own way to the list: an element it placed (<span data-let-agents-wishlist>, the
   * WordPress shortcode) and the heart after the element its setting names (the search icon).
   * Each shows how many products are saved; a click opens the list, even empty.
   */
  function placeHearts() {
    if (!cfg.wishlist) {
      return;
    }
    if ((cfg.wishlistPlace || cfg.wishlistHeader) && !doc.querySelector('[data-la-heart-placed]')) {
      var after = null;
      try {
        after = cfg.wishlistPlace ? doc.querySelector(cfg.wishlistPlace) : headerSearch();
      } catch (e) { /* a bad selector in the settings places nothing */ }
      if (after && after.parentNode) {
        var link = doc.createElement('a');
        link.href = WISH_HASH;
        link.setAttribute('data-let-agents-wishlist', '');
        link.setAttribute('data-la-heart-placed', '1');
        var place = inRow(after);
        if (place.tagName === 'LI' && place.parentNode) {
          // A list of header icons (Rocks and Gold's): an item of its own, as the theme draws the others.
          var item = doc.createElement('li');
          item.className = String(place.className || '').split(/\s+/).filter(function (c) { return c && !/search/i.test(c); }).join(' ');
          item.appendChild(link);
          place.parentNode.insertBefore(item, place.nextSibling);
        } else {
          place.parentNode.insertBefore(link, place.nextSibling);
        }
      }
    }
    paintHearts();
  }

  /**
   * The element beside which a new icon sits in the header's row: up from the search while its
   * container lays out in a block (Gueta's bar is a block inside the row; beside it, the heart would
   * drop under the bar), to the one the row lays out (flex or grid). A list's item is its own place.
   */
  function inRow(node) {
    var row = function (box) {
      var display = win.getComputedStyle ? win.getComputedStyle(box).display : '';
      return /flex|grid/.test(display);
    };
    while (node.parentNode && node.parentNode.nodeType === 1 && node.parentNode !== doc.body && node.tagName !== 'LI'
      && !(node.parentNode.matches && node.parentNode.matches('header, [role="banner"]')) && !row(node.parentNode)) {
      node = node.parentNode;
    }
    return node;
  }

  /**
   * The search in the site's header, its icon or its box, as themes draw it: the heart goes right
   * after it. The whole widget (a details, a form), not the input inside it. Null when none shows.
   */
  function headerSearch() {
    var headers = doc.querySelectorAll('header, [role="banner"], #header, .header, .site-header, #shopify-section-header, .elementor-location-header');
    var looks = ['.header__icon--search', 'details-modal.header__search', '[aria-label*="search" i]', '[aria-label*="חיפוש"]', 'a[href*="/search"]', 'form[role="search"]', 'form[action*="search"]', 'form.search-form', 'input[type="search"]', 'input[name="s"]', 'input[name="q"]'];
    var shown = function (node) { return !!(node && node.getClientRects && node.getClientRects().length); };
    for (var h = 0; h < headers.length; h++) {
      for (var l = 0; l < looks.length; l++) {
        var found = headers[h].querySelectorAll(looks[l]);
        for (var f = 0; f < found.length; f++) {
          var node = found[f];
          if (node.closest && node.closest('#la-shortlist, [data-let-agents-wishlist]')) {
            continue;
          }
          var whole = node.closest && (node.closest('details') || node.closest('form'));
          node = whole && headers[h].contains(whole) ? whole : node;
          if (shown(node)) {
            return node;
          }
        }
      }
    }
    return null;
  }

  function paintHearts() {
    var nodes = doc.querySelectorAll('[data-let-agents-wishlist]');
    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i];
      if (!node.getAttribute('data-la-heart')) {
        // A shop's element with its own content keeps it; an empty one gets the heart.
        if (node.children.length || node.textContent.trim()) {
          continue;
        }
        node.setAttribute('data-la-heart', '1');
        node.setAttribute('role', node.tagName === 'A' ? 'link' : 'button');
        node.setAttribute('tabindex', '0');
        node.setAttribute('aria-label', label('wish_title'));
        node.setAttribute('style', 'position:relative;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;flex:none;color:inherit;cursor:pointer;text-decoration:none;vertical-align:middle;');
        node.innerHTML = HEART + '<i style="position:absolute;top:0;inset-inline-end:-2px;min-width:16px;height:16px;border-radius:999px;background:#e11d48;color:#fff;font:600 10px/16px system-ui,sans-serif;font-style:normal;text-align:center;padding:0 4px;box-sizing:border-box"></i>';
        node.querySelector('path').setAttribute('fill', 'none');
        node.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openDrawer();
          }
        });
      }
      var count = node.querySelector('i');
      if (count) {
        count.textContent = String(state.wish.length);
        count.style.display = state.wish.length ? '' : 'none';
      }
    }
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
    '.drawer{position:fixed;top:0;bottom:0;inset-inline-end:0;width:min(520px,100vw);background:#fff;z-index:2147483002;display:flex;flex-direction:column;box-shadow:0 0 30px rgba(0,0,0,.25)}',
    '.drawer{color:var(--la-ink,#000)}',
    '.list{flex:1;overflow:auto;padding:28px 24px 12px;display:grid;grid-template-columns:1fr 1fr;gap:36px 20px;align-content:start}',
    '.list .msg{grid-column:1/-1}',
    '.item{display:flex;flex-direction:column;gap:12px;min-width:0}',
    '.item .pic{position:relative;border-radius:7px;overflow:hidden;background:#f5f5f5}',
    '.item img{display:block;width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;cursor:pointer}',
    '.item .gone{position:absolute;inset-inline-start:0;bottom:0;padding:5px 9px;background:#000;color:#fff;font-size:10px;font-weight:400;letter-spacing:1px}',
    '.item .meta{display:flex;flex-direction:column;gap:2px;min-width:0}',
    '.item a{color:inherit;text-decoration:none;font-size:13px;font-weight:400;line-height:1.4;letter-spacing:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
    '.item .price{color:inherit;font-size:15px;font-weight:400;line-height:1.3}',
    '.item .buy{display:block;width:100%;padding:8px 12px;border:1px solid currentColor;border-radius:0;background:transparent;color:inherit;font:inherit;font-size:12px;font-weight:400;letter-spacing:1px;line-height:1.2;cursor:pointer;transition:all .5s ease}',
    '.item .buy:hover,.item .buy.done{background:var(--la-ink,#000);border-color:var(--la-ink,#000);color:#fff}',
    '.item .buy[disabled]{opacity:.5;cursor:default}',
    '.item .sub{display:flex;justify-content:space-between;align-items:center;gap:12px}',
    '.item .sub button{border:0;background:none;padding:0;font:inherit;font-size:11px;font-weight:400;letter-spacing:.5px;cursor:pointer}',
    '.item .alike{color:inherit;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px}',
    '.item .rm{color:inherit}',
    '.item .rm:hover{text-decoration:underline;text-underline-offset:3px}',
    '.item .sub button:only-child{margin-inline-start:auto}',
    '@media (max-width:360px){.list{grid-template-columns:1fr}}',
    '.foot{border-top:1px solid #efefef;padding:16px 24px 22px;display:grid;gap:14px}',
    '.note{color:#6b7280;font-size:12.5px}',
    '.drawer .note{color:inherit;font-size:11.5px;font-weight:400}',
    '.drawer .msg,.drawer .link,.drawer .phone-consent{color:inherit}',
    '.share{display:grid;grid-template-columns:1fr 1fr;gap:12px}',
    '.share:has(> :only-child){grid-template-columns:1fr}',
    '.drawer .btn{border:1px solid currentColor;border-radius:0;padding:9px 12px;background:transparent;color:inherit;font:inherit;font-size:12px;font-weight:400;letter-spacing:1px;line-height:1.2;transition:all .5s ease}',
    '.drawer .btn:hover{background:var(--la-ink,#000);border-color:var(--la-ink,#000);color:#fff}',
    '.drawer .btn.wa{background:transparent;color:inherit}',
    '.drawer .btn.wa:hover{background:var(--la-ink,#000);border-color:var(--la-ink,#000);color:#fff}',
    '.phone{display:grid;gap:10px}',
    '.phone > b{font-size:12px;font-weight:400;letter-spacing:1px}',
    '.phone > .note{margin-top:-6px}',
    '.phone-row{display:flex;gap:20px;align-items:flex-end}',
    '.phone-row input{flex:1;min-width:0;border:0;border-bottom:1px solid #bcbcbc;border-radius:0;padding:10px 0;background:transparent;color:inherit;font:inherit;font-size:16px;transition:all .5s ease}',
    '.phone-row input:focus{outline:0;box-shadow:none;border-bottom:2px solid #000}',
    '.phone-row .btn{flex:none;padding:9px 16px}',
    '.phone-code{display:grid;gap:10px}',
    '.phone-code[hidden]{display:none}',
    '.phone-consent{display:flex;gap:8px;align-items:flex-start;font-size:12.5px;color:#374151}',
    '.phone-err{color:#b91c1c;font-size:12.5px}',
    '.phone-err:empty{display:none}',
    '.link{border:0;background:none;padding:0;color:#000;text-decoration:underline;text-underline-offset:3px;font:inherit;cursor:pointer}',
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
    var payload = { token: state.token, add: add, remove: body.remove || [], ctx: visit() };
    if (state.phone) {
      payload.phone = state.phone;
    }
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
    // A phone sign-in the server no longer knows (signed out elsewhere) is forgotten here too.
    if (state.phone && !data.phone && !data.account) {
      state.phone = null;
    }
    state.phoneShown = data.phone || (state.phone ? state.phoneShown : null);
    state.wish = (data.items || []).map(String);
    state.pending = [];
    state.share = data.share || state.share;
    state.account = !!data.account;
    (data.products || []).forEach(function (p) { cards[p.id] = p; });
    save();
    repaint();
    return data;
  }

  /** The visit, as the search script keeps it for this tab (its session, the page, where the shopper came from). */
  function visit() {
    var sid = null;
    var ref = '';
    try {
      sid = win.sessionStorage.getItem('let_agents_sid');
      if (!sid) {
        sid = 's' + Math.random().toString(36).slice(2, 12) + Date.now().toString(36);
        win.sessionStorage.setItem('let_agents_sid', sid);
        var from = doc.referrer || '';
        win.sessionStorage.setItem('let_agents_ref', from && from.indexOf(win.location.protocol + '//' + win.location.host) !== 0 ? from : '');
      }
      ref = win.sessionStorage.getItem('let_agents_ref') || '';
    } catch (e) { /* storage blocked */ }
    return { sid: sid, page: win.location.pathname, ref: ref };
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
    flash(label(adding ? 'wish_saved' : 'wish_removed'));
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
    // The shop may have no floating heart: the list opens from its header or a link.
    if (!state.wish.length || drawerOpen || cfg.wishlistFloating === false) {
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

  /**
   * The site's own text color, for the drawer's words and lines. Black where the page's text is too
   * light to read on the drawer's white (a dark theme) or cannot be read at all.
   */
  function siteInk() {
    try {
      var color = win.getComputedStyle(doc.body).color;
      var rgb = String(color || '').match(/\d+(\.\d+)?/g);
      if (!rgb || rgb.length < 3 || (rgb.length > 3 && Number(rgb[3]) < 1)) {
        return '#000';
      }
      var light = (0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]) / 255;
      return light > 0.6 ? '#000' : 'rgb(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ')';
    } catch (e) {
      return '#000';
    }
  }

  /** The drawer: this shopper's list, or (shared) someone else's with "save to my list". */
  function renderDrawer(products, shared) {
    own('veil');
    own('drawer');
    var veil = el('div', 'veil');
    veil.addEventListener('click', closeDrawer);
    var drawer = el('div', 'drawer');
    drawer.style.setProperty('--la-ink', siteInk());
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
      if (a.href) {
        img.addEventListener('click', function () { win.location.href = a.href; });
      }
      var pic = el('div', 'pic');
      pic.appendChild(img);
      if (p.in_stock === false) {
        pic.appendChild(el('span', 'gone', label('out_of_stock')));
      }
      item.appendChild(pic);
      item.appendChild(meta);
      // To the cart (sizes and colours chosen in a small window), then similar items and remove,
      // through the search script where it is on the page.
      var search = win.LetAgentsSearch;
      if (search && search.addToCart && p.in_stock !== false) {
        var buy = el('button', 'buy', label('add_to_cart'));
        buy.type = 'button';
        buy.addEventListener('click', function () {
          search.addToCart(String(p.id), buy, function () {
            buy.textContent = label('in_cart');
            buy.classList.add('done');
            flash(label('in_cart'));
          });
        });
        item.appendChild(buy);
      }
      var sub = el('div', 'sub');
      if (search && search.similar && (!search.canSimilar || search.canSimilar())) {
        var alike = el('button', 'alike', label('similar'));
        alike.type = 'button';
        alike.addEventListener('click', function () {
          closeDrawer();
          search.similar({ id: String(p.id), title: p.title, image: p.image, url: p.url });
        });
        sub.appendChild(alike);
      }
      if (!shared) {
        var rm = el('button', 'rm', label('remove'));
        rm.type = 'button';
        rm.addEventListener('click', function () { toggleWish(p); item.parentNode && item.parentNode.removeChild(item); });
        sub.appendChild(rm);
      }
      if (sub.firstChild) {
        item.appendChild(sub);
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
          // The drawer may be drawn again meanwhile: the message is said where it is seen.
          var done = function () { copy.textContent = label('copied'); flash(label('copied')); };
          if (win.navigator.clipboard && win.navigator.clipboard.writeText) {
            win.navigator.clipboard.writeText(link).then(done, function () { win.prompt('', link); });
          } else {
            win.prompt('', link);
          }
        });
      });
      // The shop may share by link only.
      if (cfg.wishlistWhatsapp !== false) {
        row.appendChild(wa);
      }
      row.appendChild(copy);
      foot.appendChild(row);
    }
    if (!shared) {
      if (state.phoneShown && state.phone) {
        var kept = el('div', 'note');
        kept.appendChild(el('span', null, label('phone_kept', { phone: state.phoneShown }) + ' · '));
        var out = el('button', 'link', label('phone_out'));
        out.type = 'button';
        out.addEventListener('click', phoneOut);
        kept.appendChild(out);
        foot.appendChild(kept);
      } else if (cfg.phoneLogin && !state.account && !ctx.customer && !ctx.loggedIn) {
        foot.appendChild(phoneBox());
      } else {
        foot.appendChild(el('div', 'note', label(state.account ? 'wish_account' : 'wish_guest')));
      }
    }
    drawer.appendChild(foot);
    wrap().appendChild(veil);
    wrap().appendChild(drawer);
  }

  /**
   * Keeping the list by phone: the number, a four-digit code by SMS, the code back (with an
   * optional yes to hearing from the store), and the list is the number's on any device.
   */
  function phoneBox() {
    var box = el('div', 'phone');
    box.appendChild(el('b', null, label('phone_title')));
    box.appendChild(el('div', 'note', label('phone_help')));
    var row = el('div', 'phone-row');
    var number = el('input');
    number.type = 'tel';
    number.inputMode = 'tel';
    number.autocomplete = 'tel';
    number.placeholder = label('phone_placeholder');
    number.setAttribute('aria-label', label('phone_placeholder'));
    number.dir = 'ltr';
    var send = el('button', 'btn', label('phone_send'));
    send.type = 'button';
    row.appendChild(number);
    row.appendChild(send);
    box.appendChild(row);
    var said = el('div', 'phone-err');
    var second = el('div', 'phone-code');
    second.hidden = true;
    box.appendChild(second);
    box.appendChild(said);

    var ask = function () {
      said.textContent = '';
      send.disabled = true;
      send.textContent = label('phone_sending');
      phonePost('code', { phone: number.value }).then(function (data) {
        send.disabled = false;
        send.textContent = label('phone_resend');
        if (!data.sent) {
          said.textContent = label('phone_' + (data.error || 'unavailable'));
          return;
        }
        second.hidden = false;
        var code = second.querySelector('input[inputmode="numeric"]');
        if (code) {
          code.focus();
        }
      });
    };
    send.addEventListener('click', ask);
    number.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); ask(); } });

    var codeRow = el('div', 'phone-row');
    var code = el('input');
    code.type = 'text';
    code.inputMode = 'numeric';
    code.autocomplete = 'one-time-code';
    code.maxLength = 4;
    code.placeholder = label('phone_code');
    code.setAttribute('aria-label', label('phone_code'));
    code.dir = 'ltr';
    var confirm = el('button', 'btn', label('phone_verify'));
    confirm.type = 'button';
    codeRow.appendChild(code);
    codeRow.appendChild(confirm);
    second.appendChild(codeRow);
    var consent = el('label', 'phone-consent');
    var agree = el('input');
    agree.type = 'checkbox';
    consent.appendChild(agree);
    consent.appendChild(el('span', null, label('phone_consent')));
    second.appendChild(consent);

    var check = function () {
      said.textContent = '';
      confirm.disabled = true;
      phonePost('verify', { phone: number.value, code: code.value, token: state.token, consent: agree.checked }).then(function (data) {
        confirm.disabled = false;
        if (!data.ok) {
          said.textContent = label('phone_' + (data.error || 'bad_code'));
          return;
        }
        state.phone = data.session;
        state.phoneShown = data.phone;
        state.pending = [];
        save();
        sync().then(function (synced) { renderDrawer((synced && synced.products) || []); });
      });
    };
    confirm.addEventListener('click', check);
    code.addEventListener('input', function () { if (/^\d{4}$/.test(code.value)) { check(); } });
    return box;
  }

  function phonePost(step, body) {
    return fetch(API + '/shortlist/' + encodeURIComponent(SITE) + '/phone/' + step, {
      method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body)
    }).then(function (r) { return r.json(); }).catch(function () { return { sent: false, ok: false, error: 'unavailable' }; });
  }

  /** Signed out on this browser: the list stays the number's; this browser starts its own again. */
  function phoneOut() {
    var session = state.phone;
    state.phone = null;
    state.phoneShown = null;
    state.wish = [];
    state.pending = [];
    state.share = null;
    save();
    repaint();
    phonePost('out', { phone: session });
    renderDrawer([]);
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
    placeHearts();
  }

  function start() {
    scan();
    repaint();
    var timer = null;
    if ((cfg.cardSelector || cfg.wishlistPlace || cfg.wishlistHeader) && win.MutationObserver) {
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
    } else if (cfg.wishlist && win.location.hash === WISH_HASH) {
      openDrawer();
    }
    if (cfg.wishlist) {
      // The shop's own way in: any link to "#wishlist", in the menu or the header, now or added later.
      doc.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href$="' + WISH_HASH + '"], [data-let-agents-wishlist]') : null;
        if (link) {
          event.preventDefault();
          openDrawer();
        }
      }, true);
    }
    // Once a session: what the account or another device changed; a guest with nothing saved asks nothing.
    var synced = false;
    try {
      synced = win.sessionStorage.getItem(KEY + '_synced') === '1';
      win.sessionStorage.setItem(KEY + '_synced', '1');
    } catch (e) {
      synced = false;
    }
    if (cfg.wishlist && !synced && (state.wish.length || state.phone || ctx.customer || ctx.loggedIn)) {
      sync();
    }
  }

  win.LetAgentsShortlist = { decorate: decorate, compare: cfg.compare, wishlist: cfg.wishlist, openWishlist: openDrawer };

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})(window, document);
