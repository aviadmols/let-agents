/*!
 * Let Agents storefront search v1.
 *
 * Loaded by the Let Agents WordPress plugin on every page, which sets window.LetAgentsSearchContext:
 *   { site, api, locale, storeApi, nonce, cartUrl, searchUrl }
 *
 * What it does:
 *   1. Waits until someone focuses or touches a text field. Only then downloads the shop's
 *      search index (cached, public), and attaches to the fields that match the shop's selector.
 *   2. Suggests as the shopper types, in the browser, with no request per keystroke: the same
 *      typo-tolerant Hebrew algorithm the server runs (Search\Support\HebrewSearch), so the
 *      suggestions and the full results agree.
 *   3. On Enter, shows the full results from the server, which also searches by meaning; or, when
 *      the shop prefers its own results page, lets the form submit and the plugin orders that page.
 *   4. Shows live prices and stock from the store's own Store API, never last night's.
 *   5. Counts searches and clicks without anything about the shopper: one count per query per tab.
 *   6. Where the shop's pictures are indexed, adds a camera beside the box: a shopper uploads or
 *      takes a photo, it is shrunk in the browser, and the products that look most like it show.
 *      The photo goes to Let Agents once and is not kept.
 *
 * Everything renders in a shadow root. Text is always set with textContent; links and images must
 * be http(s). The engine below must stay identical to HebrewSearch.php: change both or neither.
 */
(function (root, factory) {
  'use strict';
  var engine = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = engine;
    return;
  }
  if (root && root.document) {
    engine.boot(root);
  }
}(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  // ---------------------------------------------------------------- engine (same as HebrewSearch.php)

  var HEBREW_WORD = /^[א-ת]+$/;
  var PREFIX_LETTER = /^[והבלמשכ]/; // ו ה ב ל מ ש כ
  var FINALS = { 'ך': 'כ', 'ם': 'מ', 'ן': 'נ', 'ף': 'פ', 'ץ': 'צ' };
  var ALIKE = { 'ט': 'ת', 'ק': 'כ', 'ח': 'כ', 'ע': 'א', 'ס': 'ש', 'ה': 'א' };
  var KEYBOARD = {
    e: 'ק', r: 'ר', t: 'א', y: 'ט', u: 'ו', i: 'נ', o: 'מ', p: 'פ',
    a: 'ש', s: 'ד', d: 'ג', f: 'כ', g: 'ע', h: 'י', j: 'ח', k: 'ל', l: 'כ', ';': 'פ',
    z: 'ז', x: 'ס', c: 'ב', v: 'ה', b: 'נ', n: 'מ', m: 'צ', ',': 'ת', '.': 'צ'
  };
  var FLOOR = 0.45;
  var RELATIVE_FLOOR = 0.8;

  function normalize(text) {
    return String(text == null ? '' : text)
      .toLowerCase()
      .replace(/[֑-ׇ]/g, '')
      .replace(/[׳״'"`]/g, '')
      .replace(/[ךםןףץ]/g, function (letter) { return FINALS[letter]; })
      .replace(/[^\p{L}\p{N}]+/gu, ' ')
      .trim();
  }

  function stem(word) {
    if (!HEBREW_WORD.test(word) || word.length < 3) {
      return word;
    }
    var base = word.length > 4 ? word.replace(/(ימ|ות)$/, '') : word;
    return base.charAt(0) + base.slice(1).replace(/[וי]/g, '');
  }

  function sound(word) {
    return stem(word).replace(/[טקחעסה]/g, function (letter) { return ALIKE[letter]; });
  }

  function addPieces(word, vector, order) {
    var padded = ' ' + word + ' ';
    for (var i = 0; i < padded.length - 2; i++) {
      var piece = padded.substr(i, 3);
      if (!Object.prototype.hasOwnProperty.call(vector, piece)) {
        vector[piece] = 0;
        order.push(piece);
      }
      vector[piece] += 1;
    }
  }

  /** @return {{pieces: string[], weights: Object}} pieces in insertion order, as PHP iterates them */
  function wordVector(word) {
    var weights = {};
    var pieces = [];
    var heard = sound(word);
    addPieces(word, weights, pieces);
    if (heard !== word) {
      addPieces('~' + heard, weights, pieces);
    }
    var length = 0;
    for (var i = 0; i < pieces.length; i++) {
      length += weights[pieces[i]] * weights[pieces[i]];
    }
    length = Math.sqrt(length) || 1;
    for (var j = 0; j < pieces.length; j++) {
      weights[pieces[j]] = weights[pieces[j]] / length;
    }
    return { pieces: pieces, weights: weights };
  }

  function fromKeyboard(query) {
    if (!/^[a-z;,. ]+$/.test(query) || !/[a-z]/.test(query)) {
      return '';
    }
    return query.replace(/[a-z;,.]/g, function (key) { return KEYBOARD[key] || key; });
  }

  /** items: [{ id, title, keywords }] */
  function build(items) {
    var records = [];
    var list = [];
    var holders = {};
    var postings = {};

    for (var at = 0; at < items.length; at++) {
      var item = items[at];
      var name = normalize(item.title);
      var hay = normalize(item.title + ' ' + (item.keywords || ''));
      records.push({ id: String(item.id), title: String(item.title), name: name, named: ' ' + name + ' ', hay: hay });
      var words = hay.split(' ');
      for (var w = 0; w < words.length; w++) {
        var word = words[w];
        if (!word) {
          continue;
        }
        if (!Object.prototype.hasOwnProperty.call(holders, word)) {
          holders[word] = [];
          list.push(word);
        }
        var owners = holders[word];
        if (owners[owners.length - 1] !== at) {
          owners.push(at);
        }
      }
    }

    for (var n = 0; n < list.length; n++) {
      var vector = wordVector(list[n]);
      for (var p = 0; p < vector.pieces.length; p++) {
        var piece = vector.pieces[p];
        if (!Object.prototype.hasOwnProperty.call(postings, piece)) {
          postings[piece] = [];
        }
        postings[piece].push(n, vector.weights[piece]);
      }
    }

    return { items: records, list: list, holders: holders, postings: postings };
  }

  /** @return {{words: string[], score: Object}} close catalogue words in the order PHP keeps them */
  function closeWords(index, typed) {
    var found = {};
    var order = [];
    var variants = [typed];
    if (PREFIX_LETTER.test(typed) && typed.length > 3) {
      variants.push(typed.slice(1));
    }

    for (var position = 0; position < variants.length; position++) {
      var variant = variants[position];
      var scores = {};
      var base = stem(variant);
      var length = variant.length;
      var vector = wordVector(variant);

      for (var p = 0; p < vector.pieces.length; p++) {
        var piece = vector.pieces[p];
        var postings = index.postings[piece];
        if (!postings) {
          continue;
        }
        for (var k = 0; k < postings.length; k += 2) {
          scores[postings[k]] = (scores[postings[k]] || 0) + vector.weights[piece] * postings[k + 1];
        }
      }

      for (var w = 0; w < index.list.length; w++) {
        var candidate = index.list[w];
        var score = scores[w] || 0;
        if (candidate === variant) {
          score = 1;
        } else if (length >= 2 && candidate.indexOf(variant) === 0) {
          score = Math.max(score, 0.9 - 0.02 * (candidate.length - length));
        } else if (length >= 3 && stem(candidate) === base) {
          score = Math.max(score, 0.85);
        } else if (candidate.length > 3 && PREFIX_LETTER.test(candidate) && candidate.slice(1) === variant) {
          score = Math.max(score, 0.85);
        }
        if (position) {
          score *= 0.95;
        }
        if (score >= FLOOR && (found[candidate] || 0) < score) {
          if (!Object.prototype.hasOwnProperty.call(found, candidate)) {
            order.push(candidate);
          }
          found[candidate] = score;
        }
      }
    }

    var best = 0;
    for (var i = 0; i < order.length; i++) {
      best = Math.max(best, found[order[i]]);
    }
    var floor = order.length ? Math.max(FLOOR, best * RELATIVE_FLOOR) : FLOOR;
    var kept = [];
    for (var j = 0; j < order.length; j++) {
      if (found[order[j]] >= floor) {
        kept.push(order[j]);
      }
    }
    return { words: kept, score: found };
  }

  function exactTier(record, query, tokens) {
    var i;
    for (i = 0; i < tokens.length; i++) {
      if (record.hay.indexOf(tokens[i]) === -1) {
        return -1;
      }
    }
    if (record.name === query) {
      return 0;
    }
    if (record.name.indexOf(query) === 0) {
      return 1;
    }
    if ((' ' + record.name).indexOf(' ' + query) !== -1) {
      return 2;
    }
    if (record.name.indexOf(query) !== -1) {
      return 3;
    }
    for (i = 0; i < tokens.length; i++) {
      if ((' ' + record.name).indexOf(' ' + tokens[i]) === -1) {
        return 5;
      }
    }
    return 4;
  }

  function rank(index, query) {
    var tokens = query.split(' ');
    var score = {};
    var hits = {};
    var found = [];
    var needed = tokens.length <= 2 ? tokens.length : tokens.length - 1;

    for (var t = 0; t < tokens.length; t++) {
      var close = closeWords(index, tokens[t]);
      var best = {};
      var bestOrder = [];
      for (var c = 0; c < close.words.length; c++) {
        var word = close.words[c];
        var owners = index.holders[word] || [];
        for (var o = 0; o < owners.length; o++) {
          var at = owners[o];
          var value = close.score[word] * (index.items[at].named.indexOf(' ' + word + ' ') !== -1 ? 1 : 0.7);
          if (!Object.prototype.hasOwnProperty.call(best, at)) {
            bestOrder.push(at);
            best[at] = 0;
          }
          if (best[at] < value) {
            best[at] = value;
          }
        }
      }
      for (var b = 0; b < bestOrder.length; b++) {
        score[bestOrder[b]] = (score[bestOrder[b]] || 0) + best[bestOrder[b]];
        hits[bestOrder[b]] = (hits[bestOrder[b]] || 0) + 1;
      }
    }

    for (var r = 0; r < index.items.length; r++) {
      var record = index.items[r];
      var tier = exactTier(record, query, tokens);
      if (tier < 0 && (hits[r] || 0) < needed) {
        continue;
      }
      found.push({ id: record.id, title: record.title, score: (tier >= 0 ? 10 - tier : 0) + (score[r] || 0) / tokens.length, exact: tier >= 0 });
    }

    found.sort(function (a, b) {
      return (b.score - a.score) || (a.title.length - b.title.length);
    });
    return found;
  }

  function search(index, raw) {
    var query = normalize(raw);
    if (!query) {
      return [];
    }
    var found = rank(index, query);
    var typed = fromKeyboard(query);
    if (typed) {
      var hebrew = rank(index, typed);
      if (hebrew.length && (!found.length || hebrew[0].score > found[0].score)) {
        found = hebrew;
      }
    }
    return found;
  }

  // ---------------------------------------------------------------- storefront

  function boot(win) {
    var doc = win.document;
    var ctx = win.LetAgentsSearchContext;
    if (!ctx || !ctx.site || !ctx.api || win.__letAgentsSearch) {
      return;
    }
    win.__letAgentsSearch = true;

    var API = String(ctx.api).replace(/\/+$/, '');
    var STORE_API = ctx.storeApi ? String(ctx.storeApi).replace(/\/?$/, '/') : null;
    var LOCALE = ctx.locale === 'en' ? 'en' : 'he';
    var COUNTED_KEY = 'let_agents_search_counted';
    var PAUSE_MS = 2000;
    var TYPE_MS = 60;

    var state = { loading: null, data: null, index: null, records: {}, labels: {}, config: null };
    var live = {};
    var nonce = ctx.nonce || null;
    var attached = [];
    var current = null; // the field being typed into
    var dropdown = null;
    var panel = null;
    var active = -1;
    var shown = [];
    var typeTimer = null;
    var pauseTimer = null;

    function label(key, params) {
      var text = String(state.labels[key] || key);
      if (params) {
        for (var name in params) {
          if (Object.prototype.hasOwnProperty.call(params, name)) {
            text = text.split(':' + name).join(String(params[name]));
          }
        }
      }
      return text;
    }

    function safeUrl(value) {
      return typeof value === 'string' && /^https?:\/\//i.test(value) ? value : null;
    }

    function el(tag, className, text) {
      var node = doc.createElement(tag);
      if (className) {
        node.className = className;
      }
      if (text != null) {
        node.textContent = String(text);
      }
      return node;
    }

    // ---------------------------------------------------------------- counting

    function countedSet() {
      try {
        return JSON.parse(win.sessionStorage.getItem(COUNTED_KEY) || '[]');
      } catch (e) {
        return [];
      }
    }

    function isCounted(query) {
      return countedSet().indexOf(query) !== -1;
    }

    function markCounted(query) {
      try {
        var list = countedSet();
        if (list.indexOf(query) === -1) {
          list.push(query);
          win.sessionStorage.setItem(COUNTED_KEY, JSON.stringify(list.slice(-50)));
        }
      } catch (e) { /* private mode: may count twice, nothing worse */ }
    }

    function send(events) {
      var body = JSON.stringify({ events: events });
      var url = API + '/search/' + encodeURIComponent(ctx.site) + '/events';
      try {
        if (win.navigator.sendBeacon && win.navigator.sendBeacon(url, new Blob([body], { type: 'text/plain' }))) {
          return;
        }
      } catch (e) { /* fall through */ }
      try {
        win.fetch(url, { method: 'POST', body: body, keepalive: true, mode: 'cors', credentials: 'omit', headers: { 'Content-Type': 'text/plain' } });
      } catch (e) { /* counts are best effort */ }
    }

    function countSearch(query, results) {
      var normalized = normalize(query);
      if (normalized.length < 2 || isCounted(normalized)) {
        return;
      }
      markCounted(normalized);
      send([{ type: 'search', q: normalized, results: results }]);
    }

    function countClick(query, item) {
      var normalized = normalize(query);
      if (!normalized) {
        return;
      }
      var events = [];
      if (!isCounted(normalized)) {
        markCounted(normalized);
        events.push({ type: 'search', q: normalized, results: shown.length });
      }
      events.push({ type: 'click', q: normalized, id: item.id, title: item.title });
      send(events);
    }

    // ---------------------------------------------------------------- data

    function load() {
      if (state.loading) {
        return state.loading;
      }
      var url = API + '/search/' + encodeURIComponent(ctx.site) + '/index?locale=' + LOCALE;
      state.loading = win.fetch(url, { mode: 'cors', credentials: 'omit' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) {
          if (!data || !data.enabled || !data.items) {
            return null;
          }
          state.data = data;
          state.config = data.config || {};
          state.labels = data.labels || {};
          var source = [];
          for (var i = 0; i < data.items.length; i++) {
            var item = data.items[i];
            state.records[item.id] = item;
            source.push({ id: item.id, title: item.title, keywords: item.kw || '' });
          }
          state.index = build(source);
          attachAll();
          return data;
        })
        .catch(function () { return null; });
      return state.loading;
    }

    function liveProducts(ids) {
      var wanted = [];
      for (var i = 0; i < ids.length; i++) {
        if (!Object.prototype.hasOwnProperty.call(live, ids[i])) {
          wanted.push(ids[i]);
        }
      }
      if (!STORE_API || !wanted.length) {
        return Promise.resolve(live);
      }
      var url = STORE_API + 'products?per_page=' + wanted.length + '&include=' + wanted.map(encodeURIComponent).join(',');
      return win.fetch(url, { credentials: 'same-origin' })
        .then(function (response) { return response.ok ? response.json() : []; })
        .then(function (products) {
          for (var w = 0; w < wanted.length; w++) {
            live[wanted[w]] = null;
          }
          for (var p = 0; p < (products || []).length; p++) {
            live[String(products[p].id)] = products[p];
          }
          return live;
        })
        .catch(function () { return live; });
    }

    function money(prices) {
      if (!prices || prices.price == null) {
        return '';
      }
      var minor = Number(prices.currency_minor_unit || 0);
      var value = Number(prices.price) / Math.pow(10, minor);
      var text = value.toFixed(value % 1 === 0 ? 0 : minor);
      return (prices.currency_prefix || '') + text + (prices.currency_suffix || '');
    }

    function addToCart(externalId, button) {
      if (!STORE_API) {
        return;
      }
      button.disabled = true;
      var headers = { 'Content-Type': 'application/json' };
      if (nonce) {
        headers.Nonce = nonce;
      }
      win.fetch(STORE_API + 'cart/add-item', {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify({ id: Number(externalId), quantity: 1 })
      }).then(function (response) {
        var fresh = response.headers.get('Nonce');
        if (fresh) {
          nonce = fresh;
        }
        button.textContent = response.ok ? label('added') : label('add_failed');
        if (response.ok) {
          if (win.jQuery) {
            win.jQuery(doc.body).trigger('wc_fragment_refresh');
          }
          doc.body.dispatchEvent(new CustomEvent('let-agents:added_to_cart', { detail: { id: externalId } }));
        }
      }).catch(function () {
        button.textContent = label('add_failed');
      });
    }

    // ---------------------------------------------------------------- look

    var CSS = [
      ':host{all:initial}',
      '*{box-sizing:border-box}',
      '.box{font:inherit;color:var(--rs-fg);background:var(--rs-bg);border:1px solid var(--rs-line);border-radius:12px;',
      'box-shadow:0 18px 40px -18px rgba(0,0,0,.28),0 2px 8px rgba(0,0,0,.06);overflow:hidden}',
      '.row{display:flex;align-items:center;gap:12px;padding:8px 12px;cursor:pointer;text-decoration:none;color:inherit}',
      '.row[aria-selected="true"],.row:hover{background:var(--rs-soft)}',
      '.thumb{width:44px;height:44px;border-radius:7px;object-fit:cover;background:var(--rs-soft);flex:0 0 auto}',
      '.name{flex:1;min-width:0;font-size:14px;line-height:1.35;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}',
      '.price{font-size:13px;font-weight:600;white-space:nowrap;opacity:.9}',
      '.muted{font-size:12px;opacity:.6}',
      '.head{font-size:11px;letter-spacing:.08em;text-transform:uppercase;opacity:.55;padding:10px 12px 4px}',
      '.chips{display:flex;flex-wrap:wrap;gap:6px;padding:4px 12px 10px}',
      '.chip{font:inherit;font-size:13px;border:1px solid var(--rs-line);border-radius:999px;padding:4px 10px;background:transparent;color:inherit;cursor:pointer;text-decoration:none}',
      '.chip:hover{border-color:var(--rs-fg)}',
      '.all{display:block;width:100%;text-align:start;font:inherit;font-size:14px;font-weight:600;padding:10px 12px;border:0;border-top:1px solid var(--rs-line);',
      'background:transparent;color:var(--rs-accent-text);cursor:pointer}',
      '.all[aria-selected="true"],.all:hover{background:var(--rs-soft)}',
      '.empty{padding:14px 12px;font-size:14px}',
      '.overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);display:flex;justify-content:center;align-items:flex-start;padding:min(8vh,64px) 16px 16px;z-index:2147483000}',
      '.sheet{width:min(1100px,100%);max-height:calc(100vh - min(8vh,64px) - 16px);overflow:auto;background:var(--rs-bg);color:var(--rs-fg);border-radius:14px;',
      'box-shadow:0 30px 80px -20px rgba(0,0,0,.45);font:inherit}',
      '.top{position:sticky;top:0;display:flex;align-items:center;gap:12px;padding:16px 20px;background:var(--rs-bg);border-bottom:1px solid var(--rs-line);z-index:1}',
      '.top h2{margin:0;font-size:18px;font-weight:600;flex:1;min-width:0}',
      '.x{font:inherit;font-size:22px;line-height:1;border:0;background:transparent;color:inherit;cursor:pointer;padding:4px 8px;opacity:.7}',
      '.section{padding:16px 20px}',
      '.section h3{margin:0 0 12px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;opacity:.6;font-weight:600}',
      '.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px}',
      '.card{display:flex;flex-direction:column;gap:6px;font-size:14px;min-width:0}',
      '.card a{color:inherit;text-decoration:none}',
      '.card img,.card .ph{width:100%;aspect-ratio:1;object-fit:cover;border-radius:9px;background:var(--rs-soft)}',
      '.card .title{line-height:1.35;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}',
      '.btn{font:inherit;font-size:13px;font-weight:600;padding:8px 12px;border-radius:var(--rs-radius);border:1px solid var(--rs-accent);',
      'background:var(--rs-accent);color:var(--rs-accent-fg);cursor:pointer;text-align:center;text-decoration:none}',
      '.btn.ghost{background:transparent;color:var(--rs-accent-text)}',
      '.btn[disabled]{opacity:.6;cursor:default}',
      '.list{display:grid;gap:4px}',
      '.foot{padding:12px 20px 20px;display:flex;gap:12px;flex-wrap:wrap}',
      '.cam{display:inline-grid;place-items:center;width:36px;height:36px;border:0;border-radius:50%;background:transparent;color:var(--rs-fg);cursor:pointer;padding:0}',
      '.cam:hover,.cam:focus-visible{background:var(--rs-soft);outline:none}',
      '.cam svg{width:20px;height:20px}',
      '.zone{display:grid;gap:8px;justify-items:center;text-align:center;margin:12px;padding:22px 14px;border:1.5px dashed var(--rs-line);border-radius:12px;cursor:pointer}',
      '.zone.over,.zone:hover{border-color:var(--rs-accent);background:var(--rs-soft)}',
      '.zone svg{width:34px;height:34px;opacity:.6}',
      '.zone .btns{display:flex;gap:8px;flex-wrap:wrap;justify-content:center}',
      '.yours{display:flex;gap:14px;align-items:center;padding:16px 20px 0}',
      '.yours img{width:84px;height:84px;object-fit:cover;border-radius:10px;border:1px solid var(--rs-line)}',
      '.card{position:relative}',
      '.badge{position:absolute;top:8px;inset-inline-start:8px;background:var(--rs-bg);color:var(--rs-fg);font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px}',
      '@media (max-width:600px){.overlay{padding:0}.sheet{max-height:100vh;height:100vh;border-radius:0}.grid{grid-template-columns:repeat(2,1fr);gap:12px}}',
      '@media (prefers-reduced-motion:no-preference){.sheet{animation:in .25s ease}@keyframes in{from{transform:translateY(8px);opacity:0}}}'
    ].join('');

    /** The shop's own look: its font, text colour and main button, read once from the page. */
    function theme(host) {
      var body = win.getComputedStyle(doc.body);
      var button = doc.querySelector('.single_add_to_cart_button, button[type="submit"], .button, button');
      var bs = button ? win.getComputedStyle(button) : null;
      var accent = bs && bs.backgroundColor && !/rgba\(0, 0, 0, 0\)|transparent/.test(bs.backgroundColor) ? bs.backgroundColor : body.color;
      var bg = body.backgroundColor && !/rgba\(0, 0, 0, 0\)|transparent/.test(body.backgroundColor) ? body.backgroundColor : '#fff';
      host.style.setProperty('--rs-fg', body.color || '#111');
      host.style.setProperty('--rs-bg', bg);
      host.style.setProperty('--rs-soft', 'color-mix(in srgb, ' + (body.color || '#111') + ' 6%, ' + bg + ')');
      host.style.setProperty('--rs-line', 'color-mix(in srgb, ' + (body.color || '#111') + ' 14%, ' + bg + ')');
      host.style.setProperty('--rs-accent', accent);
      host.style.setProperty('--rs-accent-fg', bs && bs.color ? bs.color : '#fff');
      host.style.setProperty('--rs-accent-text', accent);
      host.style.setProperty('--rs-radius', bs ? bs.borderRadius || '6px' : '6px');
      host.style.fontFamily = body.fontFamily;
      host.dir = doc.documentElement.dir || (LOCALE === 'he' ? 'rtl' : 'ltr');
    }

    function shadowHost(id) {
      var host = doc.createElement('div');
      host.id = id;
      theme(host);
      var shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
      var style = doc.createElement('style');
      style.textContent = CSS;
      shadow.appendChild(style);
      doc.body.appendChild(host);
      return { host: host, root: shadow };
    }

    // ---------------------------------------------------------------- suggestions

    function closeDropdown() {
      if (dropdown) {
        dropdown.host.parentNode && dropdown.host.parentNode.removeChild(dropdown.host);
        dropdown = null;
      }
      active = -1;
      shown = [];
    }

    function position() {
      if (!dropdown || !current) {
        return;
      }
      var rect = current.getBoundingClientRect();
      var width = Math.max(rect.width, Math.min(380, win.innerWidth - 16));
      var rtl = dropdown.host.dir === 'rtl';
      var left = rtl ? rect.right - width : rect.left;
      left = Math.max(8, Math.min(left, win.innerWidth - width - 8));
      var s = dropdown.host.style;
      s.position = 'absolute';
      s.zIndex = '2147482000';
      s.top = (rect.bottom + win.scrollY + 6) + 'px';
      s.left = (left + win.scrollX) + 'px';
      s.width = width + 'px';
    }

    function suggest(input) {
      var raw = input.value;
      var query = normalize(raw);
      if (!state.index || query.length < 1) {
        closeDropdown();
        return;
      }
      var hits = search(state.index, raw);
      var max = state.config.suggestions || 6;
      var products = [];
      var others = [];
      for (var i = 0; i < hits.length; i++) {
        var record = state.records[hits[i].id];
        if (!record) {
          continue;
        }
        if (record.t === 'product') {
          if (products.length < max) {
            products.push(record);
          }
        } else if (others.length < 4) {
          others.push(record);
        }
      }

      if (!dropdown) {
        dropdown = shadowHost('let-agents-search-suggest');
      }
      dropdown.upload = false;
      var rootNode = dropdown.root;
      while (rootNode.childNodes.length > 1) {
        rootNode.removeChild(rootNode.lastChild);
      }
      var box = el('div', 'box');
      box.setAttribute('role', 'listbox');
      shown = [];
      active = -1;

      if (!products.length && !others.length) {
        box.appendChild(el('div', 'empty', label('no_results', { query: raw.trim() })));
      }

      if (products.length) {
        box.appendChild(el('div', 'head', label('products')));
        for (var p = 0; p < products.length; p++) {
          box.appendChild(row(products[p], raw));
        }
        fillPrices(box, products);
      }

      if (others.length) {
        var chips = el('div', 'chips');
        for (var o = 0; o < others.length; o++) {
          chips.appendChild(chip(others[o], raw));
        }
        box.appendChild(chips);
      }

      var all = el('button', 'all', label('all_results', { query: raw.trim() }));
      all.type = 'button';
      all.setAttribute('role', 'option');
      all.addEventListener('mousedown', function (event) {
        event.preventDefault();
        submit(input);
      });
      shown.push({ node: all, all: true });
      box.appendChild(all);

      rootNode.appendChild(box);
      position();

      clearTimeout(pauseTimer);
      pauseTimer = setTimeout(function () { countSearch(raw, hits.length); }, PAUSE_MS);
    }

    function row(record, raw) {
      var link = el('a', 'row');
      var url = safeUrl(record.url);
      if (url) {
        link.href = url;
      }
      link.setAttribute('role', 'option');
      var img = safeUrl(record.img);
      if (img) {
        var thumb = el('img', 'thumb');
        thumb.src = img;
        thumb.alt = '';
        thumb.loading = 'lazy';
        link.appendChild(thumb);
      } else {
        link.appendChild(el('span', 'thumb'));
      }
      link.appendChild(el('span', 'name', record.title));
      var price = el('span', 'price');
      price.setAttribute('data-price', record.id);
      link.appendChild(price);
      link.addEventListener('mousedown', function () { countClick(raw, record); });
      shown.push({ node: link, record: record });
      return link;
    }

    function chip(record, raw) {
      var link = el('a', 'chip', record.title);
      var url = safeUrl(record.url);
      if (url) {
        link.href = url;
      }
      link.addEventListener('mousedown', function () { countClick(raw, record); });
      return link;
    }

    function fillPrices(container, products) {
      var ids = [];
      for (var i = 0; i < products.length; i++) {
        ids.push(products[i].id.slice(2));
      }
      liveProducts(ids).then(function () {
        for (var j = 0; j < products.length; j++) {
          var node = container.querySelector('[data-price="' + products[j].id.replace(/"/g, '') + '"]');
          var product = live[products[j].id.slice(2)];
          if (node && product) {
            node.textContent = product.is_in_stock === false ? label('out_of_stock') : money(product.prices);
          }
        }
      });
    }

    function move(step) {
      if (!shown.length) {
        return;
      }
      if (active >= 0 && shown[active]) {
        shown[active].node.removeAttribute('aria-selected');
      }
      active = (active + step + shown.length) % shown.length;
      shown[active].node.setAttribute('aria-selected', 'true');
    }

    // ---------------------------------------------------------------- full results

    function submit(input) {
      var raw = input.value.trim();
      if (!raw) {
        return;
      }
      closeDropdown();
      clearTimeout(pauseTimer);
      if ((state.config && state.config.results) === 'page') {
        var form = input.form;
        if (form) {
          form.submit();
        } else if (ctx.searchUrl) {
          win.location.href = ctx.searchUrl + (ctx.searchUrl.indexOf('?') === -1 ? '?' : '&') + 's=' + encodeURIComponent(raw);
        }
        return;
      }
      openPanel(raw);
    }

    function closePanel() {
      if (panel) {
        panel.host.parentNode && panel.host.parentNode.removeChild(panel.host);
        panel = null;
        doc.documentElement.style.overflow = '';
      }
      if (current) {
        current.focus();
      }
    }

    /** An empty results sheet over the page, with a title and a close button. Returns the sheet. */
    function openSheet(titleText) {
      if (!panel) {
        panel = shadowHost('let-agents-search-results');
      }
      var rootNode = panel.root;
      while (rootNode.childNodes.length > 1) {
        rootNode.removeChild(rootNode.lastChild);
      }
      var overlay = el('div', 'overlay');
      overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
          closePanel();
        }
      });
      var sheet = el('div', 'sheet');
      sheet.setAttribute('role', 'dialog');
      sheet.setAttribute('aria-modal', 'true');
      var top = el('div', 'top');
      top.appendChild(el('h2', null, titleText));
      var x = el('button', 'x', '\u00D7');
      x.type = 'button';
      x.setAttribute('aria-label', label('close'));
      x.addEventListener('click', closePanel);
      top.appendChild(x);
      sheet.appendChild(top);
      overlay.appendChild(sheet);
      rootNode.appendChild(overlay);
      doc.documentElement.style.overflow = 'hidden';
      x.focus();
      return sheet;
    }

    function openPanel(raw) {
      if (!panel) {
        panel = shadowHost('let-agents-search-results');
      }
      var rootNode = panel.root;
      while (rootNode.childNodes.length > 1) {
        rootNode.removeChild(rootNode.lastChild);
      }
      var overlay = el('div', 'overlay');
      overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
          closePanel();
        }
      });
      var sheet = el('div', 'sheet');
      sheet.setAttribute('role', 'dialog');
      sheet.setAttribute('aria-modal', 'true');
      var top = el('div', 'top');
      var title = el('h2', null, label('all_results', { query: raw }));
      top.appendChild(title);
      var x = el('button', 'x', '×');
      x.type = 'button';
      x.setAttribute('aria-label', label('close'));
      x.addEventListener('click', closePanel);
      top.appendChild(x);
      sheet.appendChild(top);
      var body = el('div', 'section', label('searching'));
      sheet.appendChild(body);
      overlay.appendChild(sheet);
      rootNode.appendChild(overlay);
      doc.documentElement.style.overflow = 'hidden';
      x.focus();

      var normalized = normalize(raw);
      var url = API + '/search/' + encodeURIComponent(ctx.site) + '?q=' + encodeURIComponent(raw) + (isCounted(normalized) ? '&counted=1' : '');
      markCounted(normalized);

      win.fetch(url, { mode: 'cors', credentials: 'omit' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (result) {
          sheet.removeChild(body);
          renderResults(sheet, result, raw);
        })
        .catch(function () {
          body.textContent = label('no_results', { query: raw });
        });
    }

    function renderResults(sheet, result, raw) {
      var groups = (result && result.groups) || {};
      var products = groups.product || [];
      var content = groups.content || [];
      var categories = groups.category || [];

      if (!products.length && !content.length && !categories.length) {
        var empty = el('div', 'section');
        empty.appendChild(el('p', null, label('no_results', { query: raw })));
        empty.appendChild(el('p', 'muted', label('try_other')));
        sheet.appendChild(empty);
        return;
      }

      if (categories.length) {
        var cats = el('div', 'section');
        cats.appendChild(el('h3', null, label('category')));
        var chips = el('div', 'chips');
        chips.style.padding = '0';
        for (var c = 0; c < categories.length; c++) {
          chips.appendChild(resultChip(categories[c], raw));
        }
        cats.appendChild(chips);
        sheet.appendChild(cats);
      }

      if (products.length) {
        var section = el('div', 'section');
        section.appendChild(el('h3', null, label('products')));
        var grid = el('div', 'grid');
        var ids = [];
        for (var p = 0; p < products.length; p++) {
          grid.appendChild(card(products[p], raw));
          ids.push(products[p].external_id);
        }
        section.appendChild(grid);
        sheet.appendChild(section);
        liveProducts(ids).then(function () { updateCards(grid); });
      }

      if (content.length) {
        var guides = el('div', 'section');
        guides.appendChild(el('h3', null, label('content')));
        var list = el('div', 'list');
        for (var g = 0; g < content.length; g++) {
          var item = content[g];
          var link = el('a', 'row');
          var href = safeUrl(item.url);
          if (href) {
            link.href = href;
          }
          if (safeUrl(item.image)) {
            var thumb = el('img', 'thumb');
            thumb.src = item.image;
            thumb.alt = '';
            thumb.loading = 'lazy';
            link.appendChild(thumb);
          }
          link.appendChild(el('span', 'name', item.title));
          bindClick(link, raw, item);
          list.appendChild(link);
        }
        guides.appendChild(list);
        sheet.appendChild(guides);
      }

      if (ctx.searchUrl) {
        var foot = el('div', 'foot');
        var site = el('a', 'btn ghost', label('on_site'));
        site.href = ctx.searchUrl + (ctx.searchUrl.indexOf('?') === -1 ? '?' : '&') + 's=' + encodeURIComponent(raw);
        foot.appendChild(site);
        sheet.appendChild(foot);
      }
    }

    function bindClick(node, raw, item) {
      node.addEventListener('click', function () { countClick(raw, { id: item.id, title: item.title }); });
    }

    function resultChip(item, raw) {
      var text = item.title + (item.products ? ' · ' + label('products_in', { count: item.products }) : '');
      var link = el('a', 'chip', text);
      var href = safeUrl(item.url);
      if (href) {
        link.href = href;
      }
      bindClick(link, raw, item);
      return link;
    }

    function card(item, raw) {
      var node = el('div', 'card');
      node.setAttribute('data-product', item.external_id);
      var link = el('a');
      var href = safeUrl(item.url);
      if (href) {
        link.href = href;
      }
      if (safeUrl(item.image)) {
        var img = el('img');
        img.src = item.image;
        img.alt = '';
        img.loading = 'lazy';
        link.appendChild(img);
      } else {
        link.appendChild(el('div', 'ph'));
      }
      link.appendChild(el('div', 'title', item.title));
      bindClick(link, raw, item);
      node.appendChild(link);
      node.appendChild(el('div', 'price'));
      var action;
      if (item.buy && STORE_API) {
        action = el('button', 'btn', label('add'));
        action.type = 'button';
        action.addEventListener('click', function () {
          countClick(raw, { id: item.id, title: item.title });
          addToCart(item.external_id, action);
        });
      } else {
        action = el('a', 'btn ghost', label('view'));
        if (href) {
          action.href = href;
        }
        bindClick(action, raw, item);
      }
      action.setAttribute('data-action', '1');
      node.appendChild(action);
      return node;
    }

    function updateCards(grid) {
      var cards = grid.querySelectorAll('[data-product]');
      for (var i = 0; i < cards.length; i++) {
        var product = live[cards[i].getAttribute('data-product')];
        if (!product) {
          continue;
        }
        cards[i].querySelector('.price').textContent = product.is_in_stock === false ? label('out_of_stock') : money(product.prices);
        if (product.is_in_stock === false) {
          var button = cards[i].querySelector('button[data-action]');
          if (button) {
            button.disabled = true;
          }
        }
      }
    }

    // ---------------------------------------------------------------- search by photo

    var CAMERA = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>';
    var PICTURE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/></svg>';
    var MAX_SIDE = 1024;

    /** A camera beside the box, in its own shadow root, so the theme's buttons do not restyle it. */
    function addCamera(input) {
      if (!(state.config && state.config.photos) || input.__letAgentsCamera) {
        return;
      }
      input.__letAgentsCamera = true;
      var host = doc.createElement('span');
      host.className = 'let-agents-search-camera';
      host.style.display = 'inline-block';
      host.style.verticalAlign = 'middle';
      theme(host);
      var shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
      var style = doc.createElement('style');
      style.textContent = CSS;
      shadow.appendChild(style);
      var button = el('button', 'cam');
      button.type = 'button';
      button.innerHTML = CAMERA;
      button.setAttribute('aria-label', label('photo_search'));
      button.title = label('photo_search');
      button.addEventListener('click', function () {
        current = input;
        openUpload();
      });
      shadow.appendChild(button);
      input.insertAdjacentElement('afterend', host);
    }

    function fileInput(capture) {
      var picker = doc.createElement('input');
      picker.type = 'file';
      picker.accept = 'image/jpeg,image/png,image/webp';
      if (capture) {
        picker.setAttribute('capture', 'environment');
      }
      picker.addEventListener('change', function () {
        if (picker.files && picker.files[0]) {
          searchPhoto(picker.files[0]);
        }
      });
      return picker;
    }

    function openUpload() {
      if (!dropdown) {
        dropdown = shadowHost('let-agents-search-suggest');
      }
      var rootNode = dropdown.root;
      while (rootNode.childNodes.length > 1) {
        rootNode.removeChild(rootNode.lastChild);
      }
      shown = [];
      active = -1;
      dropdown.upload = true;
      var box = el('div', 'box');
      var zone = el('div', 'zone');
      zone.tabIndex = 0;
      zone.setAttribute('role', 'button');
      zone.innerHTML = PICTURE;
      zone.appendChild(el('strong', null, label('photo_drop')));
      zone.appendChild(el('span', 'muted', label('photo_formats')));
      var buttons = el('div', 'btns');
      var choose = el('button', 'btn', label('photo_choose'));
      choose.type = 'button';
      var take = el('button', 'btn ghost', label('photo_take'));
      take.type = 'button';
      buttons.appendChild(choose);
      buttons.appendChild(take);
      zone.appendChild(buttons);
      choose.addEventListener('click', function (event) {
        event.stopPropagation();
        fileInput(false).click();
      });
      take.addEventListener('click', function (event) {
        event.stopPropagation();
        fileInput(true).click();
      });
      zone.addEventListener('click', function () { fileInput(false).click(); });
      zone.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          fileInput(false).click();
        }
      });
      ['dragenter', 'dragover'].forEach(function (name) {
        zone.addEventListener(name, function (event) {
          event.preventDefault();
          zone.classList.add('over');
        });
      });
      ['dragleave', 'drop'].forEach(function (name) {
        zone.addEventListener(name, function (event) {
          event.preventDefault();
          zone.classList.remove('over');
        });
      });
      zone.addEventListener('drop', function (event) {
        var dropped = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
        if (dropped) {
          searchPhoto(dropped);
        }
      });
      box.appendChild(zone);
      box.appendChild(el('p', 'muted', label('photo_private'))).style.margin = '0 12px 12px';
      rootNode.appendChild(box);
      position();
      zone.focus();
    }

    /** The photo, at most MAX_SIDE pixels on its long side, as JPEG: smaller to send, same to compare. */
    function shrink(file) {
      return new Promise(function (resolve) {
        if (!win.createImageBitmap || !win.HTMLCanvasElement) {
          resolve(file);
          return;
        }
        win.createImageBitmap(file).then(function (bitmap) {
          var scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
          var canvas = doc.createElement('canvas');
          canvas.width = Math.max(1, Math.round(bitmap.width * scale));
          canvas.height = Math.max(1, Math.round(bitmap.height * scale));
          canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
          canvas.toBlob(function (blob) { resolve(blob || file); }, 'image/jpeg', 0.85);
        }).catch(function () { resolve(file); });
      });
    }

    function searchPhoto(file) {
      if (!file || !/^image\//.test(file.type || '')) {
        return;
      }
      closeDropdown();
      var sheet = openSheet(label('photo_results'));
      var preview = el('div', 'yours');
      var thumb = el('img');
      thumb.alt = label('photo_yours');
      try {
        thumb.src = win.URL.createObjectURL(file);
      } catch (e) { /* no preview */ }
      preview.appendChild(thumb);
      preview.appendChild(el('strong', null, label('photo_results')));
      sheet.appendChild(preview);
      var status = el('div', 'section', label('photo_searching'));
      sheet.appendChild(status);

      shrink(file).then(function (blob) {
        var maxBytes = ((state.config && state.config.photoMaxKb) || 5120) * 1024;
        if (blob.size > maxBytes) {
          status.textContent = label('photo_too_big');
          return null;
        }
        var form = new FormData();
        form.append('photo', blob, 'photo.jpg');
        return win.fetch(API + '/search/' + encodeURIComponent(ctx.site) + '/photo', { method: 'POST', body: form, mode: 'cors', credentials: 'omit' });
      }).then(function (response) {
        if (!response) {
          return null;
        }
        if (response.status === 413) {
          status.textContent = label('photo_too_big');
          return null;
        }
        return response.ok ? response.json() : (status.textContent = label('photo_failed'), null);
      }).then(function (result) {
        if (!result) {
          return;
        }
        var products = (result.groups && result.groups.product) || [];
        if (!products.length) {
          status.textContent = label('photo_none');
          return;
        }
        sheet.removeChild(status);
        var section = el('div', 'section');
        var grid = el('div', 'grid');
        var ids = [];
        for (var i = 0; i < products.length; i++) {
          var node = card(products[i], '');
          if (products[i].match) {
            node.appendChild(el('span', 'badge', label('photo_match', { match: products[i].match })));
          }
          grid.appendChild(node);
          ids.push(products[i].external_id);
        }
        section.appendChild(grid);
        sheet.appendChild(section);
        liveProducts(ids).then(function () { updateCards(grid); });
        var foot = el('div', 'foot');
        var again = el('button', 'btn ghost', label('photo_again'));
        again.type = 'button';
        again.addEventListener('click', function () {
          closePanel();
          openUpload();
        });
        foot.appendChild(again);
        sheet.appendChild(foot);
      }).catch(function () {
        status.textContent = label('photo_failed');
      });
    }

    // ---------------------------------------------------------------- wiring

    function matches(node) {
      var selector = (state.config && state.config.selector) || 'input[name="s"], input[type="search"]';
      try {
        return node && node.matches && node.matches(selector);
      } catch (e) {
        return false;
      }
    }

    function attach(input) {
      if (input.__letAgentsSearch) {
        return;
      }
      input.__letAgentsSearch = true;
      attached.push(input);
      addCamera(input);
      input.setAttribute('autocomplete', 'off');

      input.addEventListener('input', function () {
        current = input;
        clearTimeout(typeTimer);
        typeTimer = setTimeout(function () { suggest(input); }, TYPE_MS);
      });
      input.addEventListener('focus', function () {
        current = input;
        if (input.value.trim()) {
          suggest(input);
        }
      });
      input.addEventListener('blur', function () {
        setTimeout(function () {
          if (doc.activeElement !== input && !(dropdown && dropdown.upload)) {
            closeDropdown();
          }
        }, 150);
      });
      input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          move(1);
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          move(-1);
        } else if (event.key === 'Escape') {
          closeDropdown();
        } else if (event.key === 'Enter') {
          var picked = active >= 0 ? shown[active] : null;
          if (picked && picked.record && picked.node.href) {
            event.preventDefault();
            countClick(input.value, picked.record);
            win.location.href = picked.node.href;
          } else if ((state.config && state.config.results) !== 'page') {
            event.preventDefault();
            submit(input);
          } else {
            closeDropdown();
          }
        }
      });
      if (input.form && !input.form.__letAgentsSearch) {
        input.form.__letAgentsSearch = true;
        input.form.addEventListener('submit', function (event) {
          if ((state.config && state.config.results) !== 'page' && input.value.trim()) {
            event.preventDefault();
            submit(input);
          }
        });
      }
    }

    function attachAll() {
      var selector = (state.config && state.config.selector) || 'input[name="s"], input[type="search"]';
      var nodes = [];
      try {
        nodes = doc.querySelectorAll(selector);
      } catch (e) { /* a bad selector in the settings attaches to nothing */ }
      for (var i = 0; i < nodes.length; i++) {
        attach(nodes[i]);
      }
      if (current && matches(current)) {
        attach(current);
        if (current.value.trim()) {
          suggest(current);
        }
      }
    }

    function wake(event) {
      var target = event.target;
      if (!target || target.tagName !== 'INPUT' || !/^(text|search)$/i.test(target.type || 'text')) {
        return;
      }
      current = target;
      load();
    }

    doc.addEventListener('focusin', wake, true);
    doc.addEventListener('pointerdown', wake, true);
    win.addEventListener('resize', position);
    win.addEventListener('scroll', position, { passive: true });
    doc.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && panel) {
        closePanel();
      } else if (event.key === 'Escape' && dropdown && dropdown.upload) {
        closeDropdown();
      }
    });
    doc.addEventListener('pointerdown', function (event) {
      var target = event.target;
      if (dropdown && dropdown.upload && target !== dropdown.host && !(target.closest && target.closest('.let-agents-search-camera'))) {
        closeDropdown();
      }
    }, true);
  }

  return {
    normalize: normalize,
    stem: stem,
    sound: sound,
    fromKeyboard: fromKeyboard,
    build: build,
    closeWords: closeWords,
    search: search,
    boot: boot
  };
}));
