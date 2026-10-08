/*!
 * Let Agents storefront search v1.
 *
 * Loaded on every page by the Let Agents WordPress plugin, or on Shopify by the theme app embed,
 * which set window.LetAgentsSearchContext:
 *   WooCommerce  { site, api, locale, storeApi, nonce, cartUrl, searchUrl }
 *   Shopify      { platform: 'shopify', site, api, locale, root, searchUrl, cartUrl, currency, shopCurrency, moneyFormat }
 *
 * What it does:
 *   1. Waits until someone focuses or touches a text field. Only then downloads the shop's
 *      search index (cached, public), and attaches to the fields that match the shop's selector.
 *   2. Suggests as the shopper types, in the browser, with no request per keystroke: the same
 *      typo-tolerant Hebrew algorithm the server runs (Search\Support\HebrewSearch), so the
 *      suggestions and the full results agree.
 *   3. On Enter, shows the full results from the server, which also searches by meaning; or, when
 *      the shop prefers its own results page, lets the form submit and the plugin orders that page.
 *   4. Shows live prices and stock from the store itself, never last night's: the WooCommerce
 *      Store API, or on Shopify the public product JSON ({root}products/{handle}.js).
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

  // Words that are not asked of a record for it to hold the whole query (the server's FILLER list).
  var FILLER = ['איך', 'כמה', 'האם', 'למה', 'מדוע', 'מה', 'מהו', 'מהי', 'מהמ', 'איפה', 'היכנ', 'מתי', 'מי', 'איזה', 'איזו', 'אילו',
    'אפשר', 'ניתנ', 'יש', 'צריכ', 'כדאי', 'מותר', 'מתאימ', 'מתאימה', 'מתאימימ', 'הכי', 'לי', 'לנו', 'עמ', 'של', 'את', 'על', 'ליד', 'זה', 'זו',
    'טוב', 'טובה', 'לקנות', 'לבחור', 'בשביל', 'או', 'גמ', 'the', 'a', 'an', 'to', 'for', 'of', 'with', 'best', 'need', 'buy', 'i', 'my',
    'how', 'what', 'why', 'when', 'where', 'which', 'who', 'can', 'does', 'do', 'is', 'are', 'should'];

  function rank(index, query) {
    var tokens = query.split(' ');
    var score = {};
    var hits = {};
    var found = [];
    var needed = tokens.length <= 2 ? tokens.length : tokens.length - 1;
    // The words that must all be there for a record to hold the whole query.
    var required = [];
    for (var q = 0; q < tokens.length; q++) {
      if (FILLER.indexOf(tokens[q]) === -1) {
        required.push(q);
      }
    }
    if (!required.length) {
      for (var q2 = 0; q2 < tokens.length; q2++) {
        required.push(q2);
      }
    }
    var matched = {};

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
        if (!matched[bestOrder[b]]) {
          matched[bestOrder[b]] = {};
        }
        matched[bestOrder[b]][t] = true;
      }
    }

    for (var r = 0; r < index.items.length; r++) {
      var record = index.items[r];
      var tier = exactTier(record, query, tokens);
      if (tier < 0 && (hits[r] || 0) < needed) {
        continue;
      }
      var full = tier >= 0;
      if (!full) {
        full = true;
        for (var k = 0; k < required.length; k++) {
          if (!matched[r] || !matched[r][required[k]]) {
            full = false;
            break;
          }
        }
      }
      found.push({ id: record.id, title: record.title, score: (tier >= 0 ? 10 - tier : 0) + (score[r] || 0) / tokens.length, exact: tier >= 0, full: full });
    }

    // What holds the whole query before what holds part of it; then by score.
    found.sort(function (a, b) {
      return ((b.full ? 1 : 0) - (a.full ? 1 : 0)) || (b.score - a.score) || (a.title.length - b.title.length);
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

  /**
   * A question rather than words to look up: it ends with a question mark, or it is two words or
   * more and opens like a question. Code only; nothing is asked while the shopper types.
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

  /** Words a question wraps around what it is about, for telling one saved question from another. */
  var ANSWER_FILLER = ['כדאי', 'צריך', 'מתאים', 'מתאימה', 'מתאימים', 'הכי', 'לי', 'לנו', 'עם', 'של', 'את', 'על', 'ליד', 'זה', 'זו', 'יש', 'אפשר',
    'טוב', 'טובה', 'לקנות', 'לבחור', 'בשביל', 'או', 'גם', 'the', 'a', 'an', 'to', 'for', 'of', 'with', 'best', 'need', 'buy', 'i', 'my'];
  var answerDrop = null;

  /** The words a text is about: no question or filler words, each by its stem. */
  function coreWords(text) {
    if (!answerDrop) {
      answerDrop = {};
      QUESTION_WORDS.concat(ANSWER_FILLER).forEach(function (word) { answerDrop[normalize(word)] = true; });
    }
    return normalize(text).split(' ').filter(function (word) { return word && !answerDrop[word]; }).map(stem);
  }

  /**
   * Whether a saved question is the one being typed, closely enough to show its answer: every
   * word typed is in it (a short last word still being typed waits), at least one of them has
   * three letters or more, and they cover most of what the saved question is about. A loose
   * match never shows an answer to another question.
   */
  function closeAnswer(raw, savedQuestion) {
    var typed = coreWords(raw);
    if (typed.length && typed[typed.length - 1].length < 3 && !/\s$/.test(String(raw))) {
      typed.pop();
    }
    typed = typed.filter(function (word) { return word.length >= 2; });
    var saved = coreWords(savedQuestion);
    if (!typed.length || !saved.length || !typed.some(function (word) { return word.length >= 3; })) {
      return false;
    }
    var inside = function (list, word) {
      return list.some(function (other) {
        return other === word || (word.length >= 3 && other.indexOf(word) === 0) || (other.length >= 3 && word.indexOf(other) === 0);
      });
    };
    var typedFound = typed.filter(function (word) { return inside(saved, word); }).length;
    var savedFound = saved.filter(function (word) { return inside(typed, word); }).length;
    return typedFound === typed.length && savedFound / saved.length >= 0.6;
  }

  // ---------------------------------------------------------------- Shopify helpers (pure, tested in node)

  /**
   * The product handle in a Shopify product link: /products/{handle}, also under a market or
   * language prefix (/en/products/...) or a collection (/collections/x/products/...).
   */
  function handleFromUrl(url) {
    var match = /\/products\/([^\/?#]+)/.exec(String(url || ''));
    if (!match) {
      return null;
    }
    try {
      return decodeURIComponent(match[1]).replace(/\.(js|json)$/, '') || null;
    } catch (e) {
      return null;
    }
  }

  // Shopify's money placeholders: decimals, thousands separator, decimal separator.
  var MONEY_STYLES = {
    amount: [2, ',', '.'],
    amount_no_decimals: [0, ',', '.'],
    amount_with_comma_separator: [2, '.', ','],
    amount_no_decimals_with_comma_separator: [0, '.', ','],
    amount_with_apostrophe_separator: [2, "'", '.'],
    amount_no_decimals_with_space_separator: [0, ' ', '.'],
    amount_with_space_separator: [2, ' ', ','],
    amount_with_period_and_space_separator: [2, ' ', '.']
  };

  /**
   * A price in cents, the way the shop writes prices: its own money format ("₪{{amount}}",
   * "{{amount_with_comma_separator}} €"), or, without one, the browser's currency format.
   * The format may carry HTML (a <span class="money">); only its text is used.
   */
  function formatMoney(cents, format, currency, locale) {
    var value = Number(cents);
    if (cents === null || cents === undefined || cents === '' || !isFinite(value)) {
      return '';
    }
    var pattern = String(format || '')
      .replace(/<[^>]*>/g, '')
      .replace(/&#(\d+);/g, function (all, code) { return String.fromCharCode(Number(code)); })
      .replace(/&nbsp;/g, ' ')
      .replace(/&amp;/g, '&');
    var placeholder = /\{\{\s*(\w+)\s*\}\}/.exec(pattern);
    if (!placeholder) {
      try {
        return new Intl.NumberFormat(locale || undefined, { style: 'currency', currency: currency }).format(value / 100);
      } catch (e) {
        return (value / 100).toFixed(2);
      }
    }
    var style = MONEY_STYLES[placeholder[1]] || MONEY_STYLES.amount;
    var parts = (value / 100).toFixed(style[0]).split('.');
    var text = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, style[1]) + (parts[1] ? style[2] + parts[1] : '');
    return pattern.replace(placeholder[0], function () { return text; });
  }

  /**
   * A Shopify product (the JSON of /products/{handle}.js) in the shape the painting code knows from
   * the WooCommerce Store API. The variant shown and bought is the first one in stock, or, when
   * none is, the cheapest. Prices are in cents of the currency the shopper browses in.
   */
  function fromShopifyProduct(product) {
    var variants = (product && product.variants) || [];
    if (!variants.length) {
      return null;
    }
    var chosen = null;
    for (var i = 0; i < variants.length && !chosen; i++) {
      if (variants[i].available) {
        chosen = variants[i];
      }
    }
    if (!chosen) {
      chosen = variants.slice().sort(function (a, b) { return Number(a.price) - Number(b.price); })[0];
    }
    var price = Number(chosen.price);
    var compare = chosen.compare_at_price == null ? null : Number(chosen.compare_at_price);
    var sale = compare !== null && compare > price;
    return {
      is_in_stock: !!chosen.available,
      on_sale: sale,
      prices: { price: price, regular_price: sale ? compare : price, currency_minor_unit: 2 },
      variant: chosen.id,
      variants: variants.length
    };
  }

  function boot(win) {
    var doc = win.document;
    var ctx = win.LetAgentsSearchContext;
    if (!ctx || !ctx.site || !ctx.api || win.__letAgentsSearch) {
      return;
    }
    win.__letAgentsSearch = true;

    var API = String(ctx.api).replace(/\/+$/, '');
    var STORE_API = ctx.storeApi ? String(ctx.storeApi).replace(/\/?$/, '/') : null;
    var SHOPIFY = ctx.platform === 'shopify';
    // The storefront's root with its market or language prefix ("/", "/en/"), so prices and the cart
    // are the ones this shopper sees.
    var ROOT = SHOPIFY ? String(ctx.root || '/').replace(/\/?$/, '/') : null;
    // A product can go to the cart from the results: through the Store API, or Shopify's own cart.
    var CAN_BUY = !!STORE_API || SHOPIFY;
    var WP_SELECTOR = 'input[name="s"], input[type="search"]';
    var SHOPIFY_SELECTOR = 'input[name="q"], input[type="search"]';
    var LOCALE = ctx.locale === 'en' ? 'en' : 'he';
    var COUNTED_KEY = 'let_agents_search_counted';
    var PAUSE_MS = 2000;
    var TYPE_MS = 60;

    var state = { loading: null, data: null, index: null, records: {}, labels: {}, config: null };
    var live = {};
    var inflight = {}; // Shopify: product id -> the request already on its way, so typing asks once
    var urls = {}; // Shopify: product id -> its link, where the handle comes from
    var nonce = ctx.nonce || null;
    var attached = [];
    var current = null; // the field being typed into
    var dropdown = null;
    var panel = null;
    var active = -1;
    var shown = [];
    var typeTimer = null;
    var pauseTimer = null;
    var visitorId = null;
    var currentSheet = null; // where results are shown now, for "similar items" to replace

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
      remember(query);
    }

    /**
     * The shopper's own last searches, kept in this browser only. They leave it only if the
     * shopper later leaves an email before payment and agrees, to explain their cart to the shop.
     */
    function remember(query) {
      try {
        var key = 'let_agents_searches';
        var list = JSON.parse(win.localStorage.getItem(key) || '[]');
        var cutoff = Date.now() - 14 * 864e5;
        list = list.filter(function (s) { return s && s.q && Date.parse(s.at) > cutoff && s.q !== query.trim(); });
        list.push({ q: String(query).trim().slice(0, 120), at: new Date().toISOString() });
        win.localStorage.setItem(key, JSON.stringify(list.slice(-20)));
      } catch (e) { /* storage blocked: nothing kept */ }
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
      if (SHOPIFY && wanted.length) {
        return shopifyProducts(wanted);
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

    function rememberUrl(id, url) {
      if (SHOPIFY && id != null && url) {
        urls[String(id)] = url;
      }
    }

    /**
     * Shopify has no "these products by id" for a storefront, so each product on screen is read from
     * its public JSON, four at a time, once per page view. Nothing here needs a token.
     */
    function shopifyProducts(wanted) {
      var waits = [];
      var queue = [];
      for (var i = 0; i < wanted.length; i++) {
        var id = wanted[i];
        if (inflight[id]) {
          waits.push(inflight[id]);
          continue;
        }
        var handle = handleFromUrl(urls[id] || (state.records['p:' + id] || {}).url);
        if (handle) {
          queue.push({ id: id, handle: handle });
        }
      }
      var at = 0;
      var next = function () {
        if (at >= queue.length) {
          return null;
        }
        var job = queue[at++];
        return win.fetch(ROOT + 'products/' + encodeURIComponent(job.handle) + '.js', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (response) { return response.ok ? response.json() : null; })
          .then(function (json) { live[job.id] = fromShopifyProduct(json); })
          .catch(function () { live[job.id] = null; })
          .then(next);
      };
      if (queue.length) {
        var workers = [];
        for (var w = 0; w < Math.min(4, queue.length); w++) {
          workers.push(next());
        }
        var batch = Promise.all(workers).then(function () {
          for (var q = 0; q < queue.length; q++) {
            delete inflight[queue[q].id];
          }
        });
        for (var j = 0; j < queue.length; j++) {
          inflight[queue[j].id] = batch;
        }
        waits.push(batch);
      }
      return Promise.all(waits).then(function () { return live; });
    }

    function money(prices) {
      if (!prices || prices.price == null) {
        return '';
      }
      if (SHOPIFY) {
        // The shop's money format is written for its own currency. A shopper browsing in another one
        // (Shopify Markets) gets that currency in the browser's format instead of the wrong symbol.
        var foreign = ctx.currency && ctx.shopCurrency && ctx.currency !== ctx.shopCurrency;
        return formatMoney(prices.price, foreign ? '' : ctx.moneyFormat, ctx.currency, LOCALE);
      }
      var minor = Number(prices.currency_minor_unit || 0);
      var value = Number(prices.price) / Math.pow(10, minor);
      var text = value.toFixed(value % 1 === 0 ? 0 : minor);
      return (prices.currency_prefix || '') + text + (prices.currency_suffix || '');
    }

    /**
     * Shopify: the variant in stock goes to the theme's own cart (cart/add.js). A product with several
     * variants (sizes, colours) is not guessed: the shopper chooses on its page.
     */
    function shopifyAddToCart(externalId, button) {
      button.disabled = true;
      liveProducts([externalId]).then(function () {
        var product = live[externalId];
        var page = safeUrl(urls[externalId]) || safeUrl((state.records['p:' + externalId] || {}).url);
        if (product && product.variants > 1 && page) {
          win.location.href = page;
          return;
        }
        if (!product || !product.variant || !product.is_in_stock) {
          button.textContent = label('add_failed');
          return;
        }
        return win.fetch(ROOT + 'cart/add.js', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          // The cart icon section comes back with the answer, the way Dawn and its relatives ask for it.
          body: JSON.stringify({
            items: [{ id: Number(product.variant), quantity: 1 }],
            sections: 'cart-icon-bubble',
            sections_url: win.location.pathname
          })
        }).then(function (response) {
          button.textContent = response.ok ? label('added') : label('add_failed');
          if (!response.ok) {
            return;
          }
          return response.json().catch(function () { return null; }).then(function (json) {
            refreshShopifyCart(json && json.sections);
            doc.body.dispatchEvent(new CustomEvent('let-agents:added_to_cart', { detail: { id: externalId, variant: product.variant } }));
          });
        });
      }).catch(function () {
        button.textContent = label('add_failed');
      });
    }

    /**
     * Themes show the cart in many ways and none is standard, so this tries the common ones and
     * gives up quietly: Dawn's cart icon section, counters fed from cart.js, and the events several
     * themes listen to for redrawing their cart drawer.
     */
    function refreshShopifyCart(sections) {
      var bubble = doc.getElementById('cart-icon-bubble');
      var html = sections && sections['cart-icon-bubble'];
      if (bubble && typeof html === 'string' && win.DOMParser) {
        var section = new win.DOMParser().parseFromString(html, 'text/html').querySelector('.shopify-section');
        if (section) {
          bubble.innerHTML = section.innerHTML;
        }
      }
      var announce = function (cart) {
        ['cart:refresh', 'cart:updated'].forEach(function (name) {
          doc.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: { cart: cart } }));
        });
      };
      win.fetch(ROOT + 'cart.js', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (cart) {
          if (cart && cart.item_count != null) {
            var counters = doc.querySelectorAll('[data-cart-count], .cart-count-bubble span[aria-hidden="true"]');
            for (var i = 0; i < counters.length; i++) {
              counters[i].textContent = String(cart.item_count);
            }
          }
          announce(cart);
        })
        .catch(function () { announce(null); });
    }

    function addToCart(externalId, button) {
      if (SHOPIFY) {
        shopifyAddToCart(externalId, button);
        return;
      }
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
      '.answer{margin:8px;padding:12px 14px;border-radius:12px;background:var(--rs-soft);display:grid;gap:6px;font-size:14px;line-height:1.55}',
      '.answer .tag{font-size:11px;letter-spacing:.04em;opacity:.65}',
      '.answer .q{font-weight:600}',
      '.answer .a{white-space:pre-line}',
      '.answer .src{display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:12px}',
      '.answer .src a{color:var(--rs-accent-text);text-decoration:none;border:1px solid var(--rs-line);border-radius:999px;padding:2px 9px;background:var(--rs-bg)}',
      '.ask{display:flex;align-items:center;justify-content:space-between;gap:10px;width:calc(100% - 16px);margin:8px;padding:10px 12px;border:1.5px dashed var(--rs-accent-text);',
      'border-radius:12px;background:transparent;color:inherit;font:inherit;font-size:14px;cursor:pointer;text-align:start}',
      '.ask[aria-selected="true"],.ask:hover{background:var(--rs-soft)}',
      '.ask b{color:var(--rs-accent-text);white-space:nowrap}',
      '.btn.wa{background:#1f9d55;border-color:#1f9d55;color:#fff;display:inline-block;justify-self:start}',
      '.section.answer-box{display:grid;gap:10px}',
      '.section.answer-box .answer{margin:0}',
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
      '.overlay.side{justify-content:flex-start;align-items:stretch;padding:0}',
      '.overlay.side.end{justify-content:flex-end}',
      '.sheet.drawer{width:min(440px,100%);max-height:none;height:100%;border-radius:0;display:flex;flex-direction:column;overflow:hidden}',
      '.drawer .top{position:static;background:var(--rs-fg);color:var(--rs-bg);border:0;justify-content:space-between}',
      '.drawer .top h2{text-align:center;font-size:19px;letter-spacing:.04em}',
      '.drawer .x{color:inherit;opacity:.9}',
      '.drawer .scroll{flex:1;overflow:auto;background:var(--rs-soft)}',
      '.drawer .grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}',
      '.drawer .card{background:var(--rs-bg);border:1px solid var(--rs-line);border-radius:12px;padding:10px}',
      '.drawer .foot{flex-direction:column;align-items:stretch;padding:12px 14px;background:var(--rs-bg);border-top:1px solid var(--rs-line)}',
      '.askbox{display:flex;align-items:center;gap:10px;border:1.5px solid var(--rs-fg);border-radius:14px;padding:6px;padding-inline-start:12px}',
      '.askbox input{all:unset;flex:1;min-width:0;font:inherit;font-size:14px;color:var(--rs-fg)}',
      '@media (hover:none) and (pointer:coarse){.askbox input{font-size:16px}}',
      '.askbox button{all:unset;cursor:pointer;flex:none;width:38px;height:38px;border-radius:50%;background:var(--rs-fg);color:var(--rs-bg);display:grid;place-items:center}',
      '.askbox button svg{width:18px;height:18px}',
      '.card .sim{margin-top:2px}',
      '.more{display:block;margin:16px auto 0;font:inherit;font-size:13px;padding:8px 40px;border:1px solid var(--rs-fg);border-radius:var(--rs-radius);background:transparent;color:var(--rs-fg);cursor:pointer}',
      '.card[hidden]{display:none}',
      '.backlink{font:inherit;font-size:13px;border:0;background:transparent;color:var(--rs-accent-text);cursor:pointer;padding:0}',
      '@media (max-width:600px){.overlay{padding:0}.sheet{max-height:100vh;height:100vh;border-radius:0}.grid{grid-template-columns:repeat(2,1fr);gap:12px}}',
      // The suggestions under the box: side column (categories, articles), products, foot.
      '.d{font-size:15px;line-height:1.45;color:var(--rs-fg);background:var(--rs-bg);border:1px solid var(--rs-line);border-radius:16px;',
      'box-shadow:0 30px 80px -24px rgba(0,0,0,.35),0 2px 10px rgba(0,0,0,.06);overflow:hidden;display:flex;flex-direction:column}',
      '.d-cols{display:grid;grid-template-columns:minmax(200px,280px) minmax(0,1fr);overflow:auto;min-height:0;flex:1 1 auto}',
      '.d.one .d-cols{grid-template-columns:minmax(0,1fr)}',
      '.d-side{background:var(--rs-soft);padding:18px 20px;display:grid;gap:2px;align-content:start;border-inline-end:1px solid var(--rs-line)}',
      '.d-h{margin:0 0 4px;font-size:13px;font-weight:500;opacity:.62}',
      '.d-cat,.d-art{display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding:6px 8px;margin:0 -8px;border-radius:8px;color:inherit;text-decoration:none;cursor:pointer}',
      '.d-cat{font-weight:600}',
      '.d-cat small{opacity:.6;font-weight:400;font-size:13px;flex:none}',
      '.d-path{display:block;font-size:12px;opacity:.6;font-weight:400}',
      '.d-hr{border:0;border-top:1px solid var(--rs-line);margin:10px 0;width:100%}',
      '.d-main{padding:18px 20px;display:grid;gap:12px;align-content:start;min-width:0}',
      '.d-prods{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 22px}',
      '.d-prod{display:grid;grid-template-columns:88px minmax(0,1fr);gap:14px;align-items:center;color:inherit;text-decoration:none;padding:6px;margin:-6px;border-radius:12px;cursor:pointer;min-width:0}',
      '.d-cat:hover,.d-art:hover,.d-cat[aria-selected="true"],.d-art[aria-selected="true"]{background:var(--rs-hover)}',
      '.d-prod:hover,.d-prod[aria-selected="true"]{background:var(--rs-soft)}',
      '.d-pic{position:relative;display:block;width:88px;height:88px;border-radius:10px;background:var(--rs-soft);overflow:hidden;flex:none}',
      '.d-pic img{width:100%;height:100%;object-fit:cover;display:block}',
      '.d-tag{position:absolute;top:6px;inset-inline-end:6px;background:var(--rs-fg);color:var(--rs-bg);font-size:11px;font-weight:600;padding:2px 7px;border-radius:6px;line-height:1.4}',
      '.d-tag[hidden]{display:none}',
      '.d-match{position:absolute;bottom:6px;inset-inline-start:6px;background:var(--rs-bg);color:var(--rs-fg);font-size:11px;font-weight:700;padding:2px 7px;border-radius:999px;box-shadow:0 1px 3px rgba(0,0,0,.15)}',
      '.d-info{display:block;min-width:0}',
      '.d-row{position:relative;min-width:0}',
      '.d-sim{position:absolute;inset-inline-end:2px;bottom:0;font:inherit;font-size:12px;line-height:1;padding:5px 10px;border:0;border-radius:999px;background:var(--rs-soft);color:inherit;cursor:pointer;opacity:.85}',
      '.d-sim:hover{opacity:1}',
      '.d-name{font-size:14.5px;line-height:1.35;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;color:inherit;text-decoration:none}',
      '.d-price{display:flex;flex-wrap:wrap;align-items:baseline;gap:2px 8px;font-weight:700;margin-top:4px;font-size:14px;min-height:1.2em}',
      '.d-price s{opacity:.5;font-weight:400}',
      '.d-price.out{opacity:.6;font-weight:500}',
      '.d-empty{display:grid;gap:4px}',
      '.d-foot{border-top:1px solid var(--rs-line);display:grid;background:var(--rs-bg);flex:none}',
      '.d-all{all:unset;box-sizing:border-box;cursor:pointer;text-align:center;padding:14px;font-weight:600;font-size:15px}',
      '.d-all:hover,.d-all[aria-selected="true"]{background:var(--rs-soft)}',
      '.d-ask{all:unset;box-sizing:border-box;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 20px;',
      'background:color-mix(in srgb,var(--rs-accent) 7%,var(--rs-bg));border-top:1px solid var(--rs-line)}',
      '.d-ask:hover,.d-ask[aria-selected="true"]{background:color-mix(in srgb,var(--rs-accent) 14%,var(--rs-bg))}',
      '.d-who{display:flex;align-items:center;gap:10px;min-width:0}',
      '.d-who>span:last-child{min-width:0}',
      '.d-dot{width:34px;height:34px;border-radius:50%;background:var(--rs-accent);color:var(--rs-accent-fg);display:grid;place-items:center;flex:none}',
      '.d-dot svg{width:18px;height:18px}',
      '.d-who b{display:block;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '.d-who small{display:block;opacity:.65;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '.d-go{font-weight:700;color:var(--rs-accent-text);white-space:nowrap;flex:none}',
      '.d-reply{display:grid;gap:10px}',
      '.d-answer{border:1px solid color-mix(in srgb,var(--rs-accent) 22%,var(--rs-bg));background:color-mix(in srgb,var(--rs-accent) 4%,var(--rs-bg));',
      'border-radius:14px;padding:14px 16px;display:grid;gap:8px;font-size:14.5px;line-height:1.55;color:var(--rs-fg)}',
      '.d-label{font-size:12px;font-weight:600;color:var(--rs-accent-text)}',
      '.d-q{font-weight:600}',
      '.d-a{white-space:pre-line}',
      '.d-src{display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:13px}',
      '.d-src a{color:var(--rs-accent-text);text-decoration:none;border:1px solid var(--rs-line);border-radius:999px;padding:2px 10px;background:var(--rs-bg)}',
      '.d-thinking{opacity:.7;font-size:14px;display:flex;align-items:center;gap:8px}',
      '.d-thinking:before{content:"";width:8px;height:8px;border-radius:50%;background:var(--rs-accent);flex:none}',
      '@media (prefers-reduced-motion:no-preference){.d-thinking:before{animation:pulse 1s ease-in-out infinite alternate}@keyframes pulse{to{opacity:.25}}}',
      '.d-picks{list-style:none;margin:2px 0 0;padding:0;display:grid;gap:10px}',
      '.d-pick{display:grid;grid-template-columns:26px 64px minmax(0,1fr);gap:12px;align-items:center;background:var(--rs-bg);border:1px solid var(--rs-line);border-radius:12px;padding:8px 10px}',
      '.d-num{width:26px;height:26px;border-radius:50%;background:var(--rs-accent);color:var(--rs-accent-fg);display:grid;place-items:center;font-weight:700;font-size:13px}',
      '.d-pick .d-pic{width:64px;height:64px}',
      '.d-pick a{color:inherit;text-decoration:none}',
      '.d-why{opacity:.75;font-size:13.5px;line-height:1.4;margin-top:2px}',
      '.d-buy{display:inline-block;margin-top:6px;font:inherit;font-size:12.5px;font-weight:600;padding:4px 12px;border-radius:var(--rs-radius);border:1.5px solid var(--rs-fg);',
      'background:transparent;color:var(--rs-fg);cursor:pointer;text-decoration:none}',
      '.d-buy[disabled]{opacity:.5;cursor:default}',
      '.d-contact{border:1.5px solid #1f9d55;background:color-mix(in srgb,#1f9d55 7%,var(--rs-bg));border-radius:14px;padding:14px 16px;display:grid;gap:10px;font-size:14.5px;line-height:1.55}',
      '.d-wa{display:inline-flex;align-items:center;gap:8px;background:#1f9d55;color:#fff;border-radius:999px;padding:9px 16px;font-weight:600;font-size:14px;text-decoration:none;justify-self:start}',
      '.d-wa svg{width:18px;height:18px;flex:none}',
      '.d-zone{margin:0;padding:28px 18px;background:var(--rs-soft)}',
      '.d-zone strong{font-size:16px}',
      '.d-yours{display:flex;align-items:center;gap:14px}',
      '.d-yours img{width:76px;height:76px;object-fit:cover;border-radius:12px;border:1px solid var(--rs-line)}',
      '.d-yours b{display:block}',
      '.d-link{all:unset;cursor:pointer;color:var(--rs-accent-text);font-weight:600;font-size:14px;justify-self:start}',
      '.d-tags{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:12px 0 4px}',
      '.d-tags-h{font-size:13px;opacity:.7}',
      '.d-chip{all:unset;cursor:pointer;padding:6px 14px;border:1px solid var(--rs-line);border-radius:999px;font-size:14px;line-height:1.2;transition:border-color .2s,background .2s}',
      '.d-chip:hover,.d-chip:focus-visible{border-color:var(--rs-accent);background:var(--rs-soft,rgba(0,0,0,.04))}',
      '.d-chip[aria-pressed="true"]{border-color:var(--rs-accent);background:var(--rs-accent);color:var(--rs-accent-fg,#fff)}',
      '.d-link:focus-visible,.d-all:focus-visible,.d-ask:focus-visible,.m-back:focus-visible{outline:2px solid var(--rs-accent);outline-offset:-2px}',
      // On a phone the suggestions cover the page.
      '.d.m{position:fixed;inset:0;height:100%;border:0;border-radius:0;box-shadow:none}',
      '.m-top{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid var(--rs-line);flex:none}',
      '.m-back{all:unset;cursor:pointer;width:38px;height:38px;display:grid;place-items:center;border-radius:50%;flex:none}',
      '.m-back svg{width:22px;height:22px}',
      ':host([dir="rtl"]) .m-back svg{transform:scaleX(-1)}',
      '.m-box{flex:1;display:flex;align-items:center;gap:4px;border:1.5px solid var(--rs-fg);border-radius:12px;padding:2px 4px;padding-inline-start:12px;min-width:0}',
      '.m-box input{all:unset;flex:1;min-width:0;font-size:16px;padding:8px 0;color:var(--rs-fg)}',
      '.m-box input::-webkit-search-cancel-button{display:none}',
      '.m-x{all:unset;cursor:pointer;width:32px;height:32px;display:grid;place-items:center;border-radius:50%;opacity:.55;flex:none}',
      '.m-x svg{width:18px;height:18px}',
      '.m-body{flex:1 1 auto;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;padding:12px 14px 18px}',
      '.d.m .d-main{padding:0;gap:14px}',
      '.m-cats{display:flex;gap:8px;overflow-x:auto;scrollbar-width:none;margin:0 -14px;padding:0 14px 2px}',
      '.m-cats::-webkit-scrollbar{display:none}',
      '.m-chip{flex:none;border:1px solid var(--rs-line);border-radius:999px;padding:6px 12px;font-size:13px;font-weight:600;white-space:nowrap;color:inherit;text-decoration:none}',
      '.m-chip small{opacity:.6;font-weight:400;margin-inline-start:4px}',
      '.m-list{display:grid;gap:12px}',
      '.m-arts{display:grid}',
      '.d.m .d-prod{grid-template-columns:86px minmax(0,1fr);gap:12px;margin:0;padding:0}',
      '.d.m .d-prod .d-pic{width:86px;height:86px}',
      '.d.m .d-art{margin:0;padding:8px 0;border-bottom:1px solid var(--rs-line);border-radius:0}',
      '.d.m .d-all{padding:13px}',
      '.d.m .d-ask{padding:11px 14px}',
      '.d.m .d-foot{padding-bottom:env(safe-area-inset-bottom)}',
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
      host.style.setProperty('--rs-hover', 'color-mix(in srgb, ' + (body.color || '#111') + ' 10%, ' + bg + ')');
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

    var NARROW = 720; // at most this wide, the suggestions cover the page
    var SPARK = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.5c.5 4.6 2.9 7 7.5 7.5-4.6.5-7 2.9-7.5 7.5-.5-4.6-2.9-7-7.5-7.5 4.6-.5 7-2.9 7.5-7.5Z"/><path d="M19 15.5c.2 1.9 1.1 2.8 3 3-1.9.2-2.8 1.1-3 3-.2-1.9-1.1-2.8-3-3 1.9-.2 2.8-1.1 3-3Z"/></svg>';
    var BACK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="m11 6-6 6 6 6"/></svg>';
    var CLEAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>';
    var WHATSAPP = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.8-1.4.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z"/></svg>';

    function narrow() {
      return win.innerWidth <= NARROW;
    }

    /** On a phone the page under the suggestions does not scroll. */
    function lockPage(on) {
      if (on) {
        doc.documentElement.style.overflow = 'hidden';
      } else if (!panel) {
        doc.documentElement.style.overflow = '';
      }
    }

    function closeDropdown() {
      if (dropdown) {
        dropdown.host.parentNode && dropdown.host.parentNode.removeChild(dropdown.host);
        if (dropdown.mobile) {
          lockPage(false);
        }
        dropdown = null;
      }
      active = -1;
      shown = [];
    }

    /** Clicks inside the suggestions keep the focus where the shopper types. */
    function keepFocus(event) {
      var target = event.target;
      if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
        return;
      }
      event.preventDefault();
    }

    /** The suggestions box, or on a phone the sheet over the page, emptied for new content. */
    function freshDropdown() {
      var mobile = narrow();
      if (dropdown && dropdown.mobile !== mobile) {
        closeDropdown();
      }
      if (!dropdown) {
        dropdown = shadowHost('let-agents-search-suggest');
        dropdown.mobile = mobile;
        if (mobile) {
          sheetFrame(dropdown);
          lockPage(true);
        }
      }
      dropdown.upload = false;
      dropdown.sticky = false;
      dropdown.raw = null;
      dropdown.products = [];
      dropdown.main = null;
      shown = [];
      active = -1;
      return dropdown;
    }

    /** The phone sheet's top: back, and a box that types into the shop's own field. */
    function sheetFrame(d) {
      var frame = el('div', 'd m');
      frame.setAttribute('role', 'dialog');
      frame.setAttribute('aria-modal', 'true');
      frame.setAttribute('aria-label', label('search_label'));
      var top = el('div', 'm-top');
      var back = el('button', 'm-back');
      back.type = 'button';
      back.innerHTML = BACK;
      back.setAttribute('aria-label', label('back'));
      back.addEventListener('click', function () { closeDropdown(); });
      top.appendChild(back);

      var box = el('div', 'm-box');
      var field = el('input');
      field.type = 'search';
      field.value = current ? current.value : '';
      field.placeholder = (current && current.placeholder) || '';
      field.setAttribute('aria-label', label('search_label'));
      field.setAttribute('autocomplete', 'off');
      field.setAttribute('enterkeyhint', 'search');
      field.addEventListener('input', function () {
        if (!current) {
          return;
        }
        current.value = field.value;
        clearTimeout(typeTimer);
        typeTimer = setTimeout(function () { suggest(current); }, TYPE_MS);
      });
      field.addEventListener('keydown', function (event) {
        if (current) {
          onKey(event, current, true);
        }
      });
      box.appendChild(field);
      var clear = el('button', 'm-x');
      clear.type = 'button';
      clear.innerHTML = CLEAR;
      clear.setAttribute('aria-label', label('clear'));
      clear.addEventListener('click', function () {
        field.value = '';
        if (current) {
          current.value = '';
          suggest(current);
        }
        field.focus();
      });
      box.appendChild(clear);
      if (state.config.photos) {
        var cam = el('button', 'cam');
        cam.type = 'button';
        cam.innerHTML = CAMERA;
        cam.setAttribute('aria-label', label('photo_search'));
        cam.title = label('photo_search');
        cam.addEventListener('click', function () { openUpload(); });
        box.appendChild(cam);
      }
      top.appendChild(box);
      frame.appendChild(top);
      frame.addEventListener('mousedown', keepFocus);
      d.root.appendChild(frame);
      d.frame = frame;
      d.field = field;
      d.fresh = true;
    }

    /** Puts the parts in place: a side column and the main area on a wide screen, one scroll on a phone. */
    function layout(side, main, foot) {
      var d = dropdown;
      if (d.mobile) {
        while (d.frame.childNodes.length > 1) {
          d.frame.removeChild(d.frame.lastChild);
        }
        var scroll = el('div', 'm-body');
        scroll.appendChild(main);
        d.frame.appendChild(scroll);
        if (foot) {
          d.frame.appendChild(foot);
        }
        d.scroll = scroll;
        return;
      }
      var rootNode = d.root;
      while (rootNode.childNodes.length > 1) {
        rootNode.removeChild(rootNode.lastChild);
      }
      var box = el('div', 'd' + (side ? '' : ' one'));
      box.setAttribute('role', 'listbox');
      var cols = el('div', 'd-cols');
      if (side) {
        cols.appendChild(side);
      }
      cols.appendChild(main);
      box.appendChild(cols);
      if (foot) {
        box.appendChild(foot);
      }
      box.addEventListener('mousedown', keepFocus);
      rootNode.appendChild(box);
      d.box = box;
      d.scroll = cols;
    }

    function position() {
      if (!dropdown) {
        return;
      }
      var s = dropdown.host.style;
      s.zIndex = '2147482000';
      if (dropdown.mobile !== narrow()) {
        closeDropdown();
        return;
      }
      if (dropdown.mobile) {
        s.position = 'fixed';
        s.top = '0';
        s.left = '0';
        s.width = '100%';
        s.height = '100%';
        return;
      }
      if (!current) {
        return;
      }
      var rect = current.getBoundingClientRect();
      var room = win.innerWidth - 32;
      var width = Math.min(Math.max(rect.width, 1000), room);
      var left = rect.left + rect.width / 2 - width / 2;
      left = Math.max(16, Math.min(left, win.innerWidth - width - 16));
      s.position = 'absolute';
      s.top = (rect.bottom + win.scrollY + 8) + 'px';
      s.left = (left + win.scrollX) + 'px';
      s.width = width + 'px';
      s.height = '';
      if (dropdown.box) {
        dropdown.box.style.maxHeight = Math.max(280, win.innerHeight - Math.max(rect.bottom, 0) - 24) + 'px';
      }
    }

    /**
     * Suggestions as the shopper types, all in the browser: categories and articles on the side,
     * products with pictures and live prices, the way to all results, and the way to ask.
     */
    function suggest(input) {
      var raw = input.value;
      var query = normalize(raw);
      if (!state.index || query.length < 1) {
        if (dropdown && dropdown.mobile) {
          freshDropdown();
          layout(null, el('div', 'd-main'), null);
        } else {
          closeDropdown();
        }
        return;
      }
      var question = isQuestion(raw);
      var hits = lookup(raw, question);
      var max = state.config.suggestions || 6;
      var products = [];
      var categories = [];
      var articles = [];
      var answers = [];
      var total = 0;
      var contentTotal = 0;
      // "טבעת יהלום סוליטר בכסף": when products hold every word, only they are offered, as on the server.
      var whole = false;
      if (query.indexOf(' ') !== -1) {
        for (var w = 0; w < hits.length; w++) {
          var holder = state.records[hits[w].id];
          if (hits[w].full && holder && holder.t === 'product') {
            whole = true;
            break;
          }
        }
      }
      for (var i = 0; i < hits.length; i++) {
        var record = state.records[hits[i].id];
        if (!record) {
          continue;
        }
        if (record.t === 'answer') {
          if (!answers.length) {
            answers.push(record);
          }
        } else if (record.t === 'product') {
          if (whole && !hits[i].full) {
            continue;
          }
          total++;
          if (products.length < max) {
            products.push(record);
          }
        } else if (record.t === 'category') {
          if (categories.length < 5) {
            categories.push(record);
          }
        } else {
          contentTotal++;
          if (articles.length < 6) {
            articles.push(record);
          }
        }
      }

      var d = freshDropdown();
      d.raw = raw;
      d.products = products;
      if (d.field && d.field !== d.root.activeElement && d.field.value !== raw) {
        d.field.value = raw;
      }
      var main = el('div', 'd-main');
      d.main = main;

      // The answer the site already gave to this question. Typing never reaches a model.
      if (answers.length && closeAnswer(raw, answers[0].title)) {
        main.appendChild(answerBlock(answers[0].title, answers[0].ans, answers[0].src, raw, answers[0]));
      }

      if (!products.length && !categories.length && !articles.length && !answers.length && !question) {
        var empty = el('div', 'd-empty');
        empty.appendChild(el('span', null, label('no_results', { query: raw.trim() })));
        empty.appendChild(el('span', 'muted', label('try_other')));
        main.appendChild(empty);
      }

      var chips = null;
      if (d.mobile && categories.length) {
        chips = el('div', 'm-cats');
        main.appendChild(chips);
      }

      if (products.length) {
        main.appendChild(el('h4', 'd-h', label('products')));
        var grid = el('div', d.mobile ? 'm-list' : 'd-prods');
        for (var p = 0; p < products.length; p++) {
          grid.appendChild(productRow(products[p], raw));
        }
        main.appendChild(grid);
        fillPrices(grid, products);
      }

      // A site of articles and pages, or a search that found no product: the articles and pages
      // are the results, in the main area with their pictures.
      var contentFirst = !products.length && articles.length > 0;
      if (contentFirst) {
        main.appendChild(el('h4', 'd-h', label('articles')));
        var reading = el('div', d.mobile ? 'm-list' : 'd-prods');
        for (var r = 0; r < articles.length; r++) {
          reading.appendChild(contentRow(articles[r], raw));
        }
        main.appendChild(reading);
        articles = [];
      }

      var side = null;
      if (d.mobile) {
        for (var c = 0; c < categories.length; c++) {
          chips.appendChild(categoryChip(categories[c], raw));
        }
        if (articles.length) {
          main.appendChild(el('h4', 'd-h', label('articles')));
          var list = el('div', 'm-arts');
          for (var a = 0; a < articles.length; a++) {
            list.appendChild(articleRow(articles[a], raw));
          }
          main.appendChild(list);
        }
      } else if (categories.length || articles.length) {
        side = sideColumn(categories, articles, raw);
      }

      layout(side, main, footer(input, raw, total || contentTotal, question));
      position();
      if (d.fresh) {
        d.fresh = false;
        focusField(d);
      }

      clearTimeout(pauseTimer);
      pauseTimer = setTimeout(function () { countSearch(raw, hits.length); }, PAUSE_MS);
    }

    /** Words a question wraps around what it is about; dropped to find the products it is about. */
    var FILLER = ['כדאי', 'צריך', 'מתאים', 'מתאימה', 'מתאימים', 'הכי', 'לי', 'לנו', 'עם', 'של', 'את', 'על', 'ליד', 'זה', 'זו', 'יש', 'אפשר',
      'טוב', 'טובה', 'לקנות', 'לבחור', 'בשביל', 'או', 'גם', 'the', 'a', 'an', 'to', 'for', 'of', 'with', 'best', 'need', 'buy', 'i', 'my'];
    var dropWords = null;

    /** The client-side hits; for a question, also the hits for what it asks about. */
    function lookup(raw, question) {
      var hits = search(state.index, raw);
      if (!question) {
        return hits;
      }
      if (!dropWords) {
        dropWords = {};
        QUESTION_WORDS.concat(FILLER).forEach(function (word) { dropWords[normalize(word)] = true; });
      }
      var core = normalize(raw).split(' ').filter(function (word) { return word && !dropWords[word]; }).join(' ');
      if (!core || core === normalize(raw)) {
        return hits;
      }
      var more = search(state.index, core);
      if (!more.length) {
        // Words no product holds ("ביתית", "וזולה") describe; search what is left.
        var known = core.split(' ').filter(function (word) { return word.length >= 2 && closeWords(state.index, word).words.length; }).join(' ');
        more = known && known !== core ? search(state.index, known) : [];
      }
      var seen = {};
      hits.forEach(function (hit) { seen[hit.id] = true; });
      return hits.concat(more.filter(function (hit) { return !seen[hit.id]; }));
    }

    function focusField(d) {
      if (!d.field) {
        return;
      }
      d.field.focus();
      try {
        d.field.setSelectionRange(d.field.value.length, d.field.value.length);
      } catch (e) { /* not every field takes a caret */ }
    }

    function sideColumn(categories, articles, raw) {
      var side = el('div', 'd-side');
      if (categories.length) {
        side.appendChild(el('h4', 'd-h', label('categories_match')));
        for (var c = 0; c < categories.length; c++) {
          side.appendChild(categoryRow(categories[c], raw));
        }
      }
      if (categories.length && articles.length) {
        side.appendChild(el('hr', 'd-hr'));
      }
      if (articles.length) {
        side.appendChild(el('h4', 'd-h', label('articles')));
        for (var a = 0; a < articles.length; a++) {
          side.appendChild(articleRow(articles[a], raw));
        }
      }
      return side;
    }

    /** Where a category sits: its path words before its own name. */
    function categoryPath(record) {
      var words = String(record.kw || '');
      var at = words.lastIndexOf(record.title);
      return at > 0 ? words.slice(0, at).trim() : '';
    }

    function linkTo(className, record, raw, text) {
      var link = el('a', className, text);
      var url = safeUrl(record.url);
      if (url) {
        link.href = url;
      }
      link.setAttribute('role', 'option');
      link.addEventListener('click', function () { countClick(raw, record); });
      shown.push({ node: link, record: record });
      return link;
    }

    function categoryRow(record, raw) {
      var link = linkTo('d-cat', record, raw);
      var name = el('span', null, record.title);
      var path = categoryPath(record);
      if (path) {
        name.appendChild(el('span', 'd-path', path));
      }
      link.appendChild(name);
      if (record.n) {
        link.appendChild(el('small', null, record.n));
      }
      return link;
    }

    function categoryChip(record, raw) {
      var link = linkTo('m-chip', record, raw, record.title);
      if (record.n) {
        link.appendChild(el('small', null, record.n));
      }
      return link;
    }

    /** An article or a page as a result of its own: its picture and title, no price. */
    function contentRow(record, raw) {
      var link = linkTo('d-prod', record, raw);
      link.appendChild(picture(safeUrl(record.img), false));
      var info = el('span', 'd-info');
      info.appendChild(el('span', 'd-name', record.title));
      link.appendChild(info);
      return link;
    }

    /** A result the server found, in the shape of a record of the downloaded index. */
    function fromServer(item) {
      return {
        id: item.id,
        t: item.type,
        title: item.title,
        url: item.url,
        img: item.image,
        buy: item.buy ? 1 : 0,
        s: 1
      };
    }

    function articleRow(record, raw) {
      return linkTo('d-art', record, raw, record.title);
    }

    /** A square picture; a missing or broken one leaves the soft square, never a broken icon. */
    function picture(src, sale) {
      var pic = el('span', 'd-pic');
      if (src) {
        var img = el('img');
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.addEventListener('error', function () {
          if (img.parentNode) {
            img.parentNode.removeChild(img);
          }
        });
        img.src = src;
        pic.appendChild(img);
      }
      if (sale) {
        var tag = el('span', 'd-tag', label('sale'));
        tag.hidden = true;
        pic.appendChild(tag);
      }
      return pic;
    }

    function productRow(record, raw, match) {
      var link = linkTo('d-prod', record, raw);
      link.setAttribute('data-pid', String(record.id).slice(2));
      rememberUrl(String(record.id).slice(2), record.url);
      var pic = picture(safeUrl(record.img), true);
      if (match) {
        pic.appendChild(el('span', 'd-match', label('photo_match', { match: match })));
      }
      link.appendChild(pic);
      var info = el('span', 'd-info');
      info.appendChild(el('span', 'd-name', record.title));
      var price = el('span', 'd-price');
      if (record.s === 0) {
        price.className = 'd-price out';
        price.textContent = label('out_of_stock');
      }
      info.appendChild(price);
      link.appendChild(info);
      if (!(state.config && state.config.similar)) {
        return link;
      }
      // "Similar items" beside every product, from the suggestions too.
      var row = el('div', 'd-row');
      row.appendChild(link);
      var sim = el('button', 'd-sim', label('similar'));
      sim.type = 'button';
      sim.setAttribute('aria-label', label('similar_to', { title: record.title }));
      sim.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        showSimilar({ id: record.id, external_id: String(record.id).slice(2), title: record.title }, raw);
      });
      row.appendChild(sim);
      return row;
    }

    function footer(input, raw, total, question) {
      var foot = el('div', 'd-foot');
      var text = total > 1 ? label('show_all', { count: total }) : (total === 1 ? label('show_all_one') : label('all_results', { query: raw.trim() }));
      var all = el('button', 'd-all', text);
      all.type = 'button';
      all.setAttribute('role', 'option');
      all.addEventListener('click', function () { submit(input); });
      shown.push({ node: all, all: true });
      foot.appendChild(all);
      if (state.config.ask) {
        foot.appendChild(askRow(input, raw, question));
      }
      return foot;
    }

    /** "Have a question?": asks the assistant only when pressed. */
    function askRow(input, raw, question) {
      var typed = raw.trim();
      var row = el('button', 'd-ask');
      row.type = 'button';
      row.setAttribute('role', 'option');
      var who = el('span', 'd-who');
      var dot = el('span', 'd-dot');
      dot.innerHTML = SPARK;
      who.appendChild(dot);
      var words = el('span');
      words.appendChild(el('b', null, question ? label('ask_title_question') : label('ask_title', { query: typed })));
      words.appendChild(el('small', null, question ? '"' + typed + '"' : label('ask_sub')));
      who.appendChild(words);
      row.appendChild(who);
      row.appendChild(el('span', 'd-go', label('ask_go')));
      row.addEventListener('click', function () { askInline(input); });
      shown.push({ node: row, ask: true });
      return row;
    }

    /** Live price, the regular price struck when on sale, and the sale tag, from the store itself. */
    function paint(node, product) {
      var price = node.querySelector('.d-price');
      if (!price || !product) {
        return;
      }
      price.textContent = '';
      price.className = 'd-price';
      if (product.is_in_stock === false) {
        price.className = 'd-price out';
        price.textContent = label('out_of_stock');
        var buy = node.querySelector('button.d-buy');
        if (buy) {
          buy.disabled = true;
        }
        return;
      }
      chooseOnPage(node.querySelector('button.d-buy'), product);
      var prices = product.prices || {};
      price.appendChild(doc.createTextNode(money(prices)));
      var sale = !!product.on_sale && prices.regular_price != null && Number(prices.regular_price) > Number(prices.price);
      if (sale) {
        price.appendChild(el('s', null, money({
          price: prices.regular_price,
          currency_minor_unit: prices.currency_minor_unit,
          currency_prefix: prices.currency_prefix,
          currency_suffix: prices.currency_suffix
        })));
      }
      var tag = node.querySelector('.d-tag');
      if (tag) {
        tag.hidden = !sale;
      }
    }

    /** Shopify: a product with several variants says "view", and its button opens the product page. */
    function chooseOnPage(button, product) {
      if (button && product && product.variants > 1) {
        button.textContent = label('view');
      }
    }

    function fillPrices(container, products) {
      var ids = [];
      for (var i = 0; i < products.length; i++) {
        ids.push(String(products[i].id).slice(2));
      }
      liveProducts(ids).then(function () { paintAll(container); });
    }

    function paintAll(container) {
      var nodes = container.querySelectorAll('[data-pid]');
      for (var i = 0; i < nodes.length; i++) {
        paint(nodes[i], live[nodes[i].getAttribute('data-pid')]);
      }
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
      if (shown[active].node.scrollIntoView) {
        shown[active].node.scrollIntoView({ block: 'nearest' });
      }
    }

    // ---------------------------------------------------------------- questions

    /** The same anonymous id the on-page module uses, so a shopper's daily questions add up once. */
    function visitor() {
      if (visitorId) {
        return visitorId;
      }
      var match = doc.cookie.match(/(?:^|; )let_agents_vid=([^;]*)/);
      var id = match ? decodeURIComponent(match[1]) : null;
      if (!/^anon-[A-Za-z0-9_-]{16,64}$/.test(id || '')) {
        try {
          id = win.localStorage.getItem('let_agents_vid');
        } catch (e) {
          id = null;
        }
      }
      if (!/^anon-[A-Za-z0-9_-]{16,64}$/.test(id || '')) {
        var alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        var bytes = new Uint8Array(22);
        (win.crypto || win.msCrypto).getRandomValues(bytes);
        id = 'anon-';
        for (var i = 0; i < bytes.length; i++) {
          id += alphabet.charAt(bytes[i] % alphabet.length);
        }
        try {
          win.localStorage.setItem('let_agents_vid', id);
        } catch (e) { /* the cookie still carries it */ }
        doc.cookie = 'let_agents_vid=' + encodeURIComponent(id) + '; max-age=31536000; path=/; samesite=lax';
      }
      visitorId = id;
      return id;
    }

    /** The site's pages an answer came from, as small links. */
    function sourceLinks(block, sources, raw, record) {
      var links = (sources || []).filter(function (source) { return source && safeUrl(source.url); });
      if (!links.length) {
        return;
      }
      var src = el('div', 'd-src');
      src.appendChild(el('span', null, label('sources')));
      links.forEach(function (source) {
        var link = el('a', null, source.title || source.url);
        link.href = source.url;
        if (record) {
          link.addEventListener('click', function () { countClick(raw, record); });
        }
        src.appendChild(link);
      });
      block.appendChild(src);
    }

    /** An answer the site already gave, with the site's pages it came from. */
    function answerBlock(question, text, sources, raw, record) {
      var block = el('div', 'd-answer');
      block.appendChild(el('span', 'd-label', label('answer_from_site')));
      if (question) {
        block.appendChild(el('span', 'd-q', question));
      }
      block.appendChild(el('span', 'd-a', text || ''));
      sourceLinks(block, sources, raw, record);
      return block;
    }

    function requestAnswer(raw, ids) {
      return win.fetch(API + '/search/' + encodeURIComponent(ctx.site) + '/ask', {
        method: 'POST',
        mode: 'cors',
        credentials: 'omit',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({ question: raw, vid: visitor(), locale: LOCALE, products: ids.slice(0, 12) })
      })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (json) { return (json && json.data) || null; })
        .catch(function () { return null; });
    }

    /**
     * The shopper pressed "ask" (or Enter on a question): the answer opens inside the suggestions,
     * above the products they already see, which go with the question so the assistant picks from them.
     */
    function askInline(input) {
      var raw = String(input.value || '').trim();
      if (!raw) {
        return;
      }
      clearTimeout(typeTimer); // a suggestion still on its way would replace the answer
      if (!dropdown || !dropdown.main || dropdown.raw !== input.value) {
        suggest(input);
      }
      if (!dropdown || !dropdown.main) {
        askText(raw);
        return;
      }
      var d = dropdown;
      d.sticky = true;
      clearTimeout(pauseTimer);
      var row = d.root.querySelector('.d-ask');
      if (row && row.parentNode) {
        row.parentNode.removeChild(row);
      }
      if (active >= 0 && shown[active]) {
        shown[active].node.removeAttribute('aria-selected');
      }
      active = -1;
      shown = shown.filter(function (item) { return !item.ask; });
      var ready = d.main.querySelectorAll('.d-answer, .d-reply');
      for (var i = 0; i < ready.length; i++) {
        ready[i].parentNode.removeChild(ready[i]);
      }
      var box = el('div', 'd-reply');
      box.setAttribute('aria-live', 'polite');
      box.appendChild(el('div', 'd-thinking', label('ask_thinking')));
      d.main.insertBefore(box, d.main.firstChild);
      if (d.scroll) {
        d.scroll.scrollTop = 0;
      }
      var normalized = normalize(raw);
      var url = API + '/search/' + encodeURIComponent(ctx.site) + '?q=' + encodeURIComponent(raw) + (isCounted(normalized) ? '&counted=1' : '');
      markCounted(normalized);
      win.fetch(url, { mode: 'cors', credentials: 'omit' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .catch(function () { return null; })
        .then(function (result) {
          if (box.isConnected === false) {
            return; // the shopper typed on; this answer is no longer wanted here
          }
          var found = addResults(d, result, raw);
          var ids = [];
          found.concat(d.products).forEach(function (record) {
            var id = String(record.id).slice(2);
            if (record.t === 'product' && ids.indexOf(id) === -1) {
              ids.push(id);
            }
          });
          requestAnswer(raw, ids).then(function (data) {
            if (box.isConnected === false) {
              return;
            }
            renderReply(box, data, raw);
          });
        });
    }

    /**
     * What the full search found for the question, under the answer: the products (and the
     * articles and pages) that fit, where typing alone had found too few. Returns the products.
     */
    function addResults(d, result, raw) {
      var groups = (result && result.groups) || {};
      var products = (groups.product || []).map(fromServer);
      var content = (groups.content || []).map(fromServer);
      var shownIds = {};
      (d.products || []).forEach(function (record) { shownIds[record.id] = true; });
      // The server found the kind of product the question asks about: its list replaces what the
      // browser matched by words (a bracket "for wooden posts" for "which wood").
      if (result && result.subject && products.length) {
        shownIds = {};
        d.products = [];
        Array.prototype.slice.call(d.main.querySelectorAll('.d-prods, .m-list')).forEach(function (node) {
          var title = node.previousElementSibling;
          if (title && title.classList.contains('d-h')) {
            title.parentNode.removeChild(title);
          }
          node.parentNode.removeChild(node);
        });
      }
      var fresh = products.filter(function (record) { return !shownIds[record.id]; }).slice(0, state.config.suggestions || 6);

      if (fresh.length) {
        var head = el('h4', 'd-h', label('products'));
        var grid = el('div', d.mobile ? 'm-list' : 'd-prods');
        fresh.forEach(function (record) { grid.appendChild(productRow(record, raw)); });
        var existing = d.main.querySelector('.d-prods, .m-list');
        if (existing && !(d.products || []).length) {
          existing.parentNode.removeChild(existing);
        }
        d.main.appendChild(head);
        d.main.appendChild(grid);
        fillPrices(grid, fresh);
      }

      if (!products.length && content.length && !d.main.querySelector('.d-prods, .m-list')) {
        d.main.appendChild(el('h4', 'd-h', label('articles')));
        var reading = el('div', d.mobile ? 'm-list' : 'd-prods');
        content.slice(0, 6).forEach(function (record) { reading.appendChild(contentRow(record, raw)); });
        d.main.appendChild(reading);
      }

      return products;
    }

    /** A question from the drawer's own field: the answer in the results sheet, the results under it. */
    function askText(raw) {
      if (!raw) {
        return;
      }
      closeDropdown();
      clearTimeout(pauseTimer);
      var sheet = openSheet(raw, raw);
      var box = el('div', 'section d-reply');
      box.appendChild(el('div', 'd-thinking', label('ask_thinking')));
      sheet.appendChild(box);
      var below = el('div');
      sheet.appendChild(below);

      var ids = [];
      if (state.index) {
        var hits = lookup(raw, isQuestion(raw));
        for (var i = 0; i < hits.length && ids.length < 12; i++) {
          var record = state.records[hits[i].id];
          if (record && record.t === 'product') {
            ids.push(String(record.id).slice(2));
          }
        }
      }
      requestAnswer(raw, ids).then(function (data) { renderReply(box, data, raw); });

      var normalized = normalize(raw);
      var url = API + '/search/' + encodeURIComponent(ctx.site) + '?q=' + encodeURIComponent(raw) + (isCounted(normalized) ? '&counted=1' : '');
      markCounted(normalized);
      win.fetch(url, { mode: 'cors', credentials: 'omit' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (result) { renderResults(below, result, raw, true); })
        .catch(function () { /* the answer stands alone */ });
    }

    /**
     * The assistant's reply: the answer, the products it picked from what the shopper saw and why,
     * the pages it came from; and where the site has no answer, the shop's WhatsApp.
     */
    function renderReply(box, data, raw) {
      box.textContent = '';
      var askId = data && data.ask_id ? String(data.ask_id) : null;
      var q = normalize(raw);
      var answered = !!(data && data.outcome === 'answered');
      if (answered) {
        var block = el('div', 'd-answer');
        block.appendChild(el('span', 'd-label', label('ask_answer')));
        block.appendChild(el('div', 'd-a', data.answer || ''));
        var picks = (data.picks || []).filter(function (pick) { return pick && pick.external_id != null && pick.title; }).slice(0, 6);
        if (picks.length) {
          block.appendChild(pickList(picks, q, askId));
        }
        sourceLinks(block, data.sources, raw, null);
        box.appendChild(block);
      }
      if (!answered || (data && data.whatsapp === true)) {
        box.appendChild(contactBox(data, raw, q, askId, answered));
      }
    }

    function pickList(picks, q, askId) {
      var list = el('ol', 'd-picks');
      var ids = [];
      picks.forEach(function (pick, at) {
        var id = String(pick.external_id);
        var record = state.records['p:' + id] || {};
        var href = safeUrl(pick.url) || safeUrl(record.url);
        var counted = function () {
          var event = { type: 'ask_pick', q: q, id: id };
          if (askId) {
            event.ask_id = askId;
          }
          send([event]);
        };
        ids.push(id);
        rememberUrl(id, href);
        var item = el('li', 'd-pick');
        item.setAttribute('data-pid', id);
        item.appendChild(el('span', 'd-num', String(at + 1)));
        var picLink = el('a');
        if (href) {
          picLink.href = href;
        }
        picLink.appendChild(picture(safeUrl(pick.image) || safeUrl(record.img), false));
        picLink.addEventListener('click', counted);
        item.appendChild(picLink);
        var info = el('div', 'd-info');
        var name = el('a', 'd-name', pick.title);
        if (href) {
          name.href = href;
        }
        name.addEventListener('click', counted);
        info.appendChild(name);
        if (pick.why) {
          info.appendChild(el('div', 'd-why', pick.why));
        }
        info.appendChild(el('span', 'd-price'));
        var action;
        if (record.buy && CAN_BUY) {
          action = el('button', 'd-buy', label('add'));
          action.type = 'button';
          action.addEventListener('click', function () {
            counted();
            addToCart(id, action);
          });
        } else {
          action = el('a', 'd-buy', label('view'));
          if (href) {
            action.href = href;
          }
          action.addEventListener('click', counted);
        }
        info.appendChild(action);
        item.appendChild(info);
        list.appendChild(item);
      });
      liveProducts(ids).then(function () { paintAll(list); });
      return list;
    }

    /** No answer on the site: say so, and offer the shop's WhatsApp with the question in it. */
    function contactBox(data, raw, q, askId, answered) {
      var box = el('div', 'd-contact');
      box.appendChild(el('div', 'd-a', answered ? label('contact_more') : ((data && data.answer) || label('no_answer'))));
      var number = String((state.config && state.config.whatsapp) || '').replace(/\D/g, '');
      var offered = (data && data.whatsapp === true) || (state.config && state.config.askWhatsapp !== false);
      if (offered && /^\d{8,15}$/.test(number)) {
        var template = typeof state.config.whatsappMessage === 'string' && state.config.whatsappMessage ? state.config.whatsappMessage : label('wa_message');
        var text = template.split(':question').join(raw).split(':url').join(win.location.href);
        var wa = el('a', 'd-wa');
        wa.innerHTML = WHATSAPP;
        wa.appendChild(doc.createTextNode(label('whatsapp')));
        wa.href = 'https://wa.me/' + number + '?text=' + encodeURIComponent(text);
        wa.target = '_blank';
        wa.rel = 'noopener';
        wa.addEventListener('click', function () {
          var event = { type: 'ask_whatsapp', q: q };
          if (askId) {
            event.ask_id = askId;
          }
          send([event]);
        });
        box.appendChild(wa);
      }
      return box;
    }

    // ---------------------------------------------------------------- full results

    /** The shop's own results page: WordPress reads ?s=, Shopify reads ?q= and matches the last word as a prefix. */
    function siteSearchUrl(raw) {
      var base = String(ctx.searchUrl);
      var join = base.indexOf('?') === -1 ? '?' : '&';
      if (SHOPIFY) {
        return base + join + 'q=' + encodeURIComponent(raw) + '&options[prefix]=last';
      }
      return base + join + 's=' + encodeURIComponent(raw);
    }

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
          win.location.href = siteSearchUrl(raw);
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

    function drawerMode() {
      return !!(state.config && state.config.results === 'drawer');
    }

    /**
     * The side drawer: a dark head with the close and new-search buttons, the results in the
     * middle, and a field at the foot for another search or a question without closing it.
     * Returns the scrolling middle, where the results go.
     */
    function openDrawer(raw) {
      if (!panel) {
        panel = shadowHost('let-agents-search-results');
      }
      var rootNode = panel.root;
      while (rootNode.childNodes.length > 1) {
        rootNode.removeChild(rootNode.lastChild);
      }
      var overlay = el('div', 'overlay side' + (state.config.drawerSide === 'end' ? ' end' : ''));
      overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
          closePanel();
        }
      });
      var sheet = el('aside', 'sheet drawer');
      sheet.setAttribute('role', 'dialog');
      sheet.setAttribute('aria-modal', 'true');
      sheet.setAttribute('aria-label', label('drawer_title'));
      var top = el('div', 'top');
      var x = el('button', 'x', '\u00D7');
      x.type = 'button';
      x.setAttribute('aria-label', label('close'));
      x.addEventListener('click', closePanel);
      var again = el('button', 'x', '\u21BB');
      again.type = 'button';
      again.setAttribute('aria-label', label('new_search'));
      top.appendChild(x);
      top.appendChild(el('h2', null, label('drawer_title')));
      top.appendChild(again);
      sheet.appendChild(top);

      var scroll = el('div', 'scroll');
      sheet.appendChild(scroll);

      var foot = el('form', 'foot');
      var box = el('div', 'askbox');
      var field = el('input');
      field.type = 'search';
      field.value = raw || '';
      field.maxLength = 120;
      field.placeholder = label('ask_placeholder');
      field.setAttribute('aria-label', label('ask_placeholder'));
      var send = el('button');
      send.type = 'submit';
      send.setAttribute('aria-label', label('send'));
      send.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/></svg>';
      box.appendChild(field);
      // Search by photo from the drawer too, where the shop has it on and its pictures are scanned.
      if (state.config.photos) {
        var cam = el('button', 'cam');
        cam.type = 'button';
        cam.setAttribute('aria-label', label('photo_search'));
        cam.title = label('photo_search');
        cam.innerHTML = CAMERA;
        cam.addEventListener('click', function () { fileInput(false, true).click(); });
        box.appendChild(cam);
      }
      box.appendChild(send);
      foot.appendChild(box);
      foot.addEventListener('submit', function (event) {
        event.preventDefault();
        var text = field.value.trim();
        if (!text) {
          return;
        }
        if (state.config.ask && isQuestion(text)) {
          askText(text);
        } else {
          openPanel(text);
        }
      });
      again.addEventListener('click', function () {
        scroll.textContent = '';
        field.value = '';
        field.focus();
      });
      sheet.appendChild(foot);

      overlay.appendChild(sheet);
      rootNode.appendChild(overlay);
      doc.documentElement.style.overflow = 'hidden';
      (raw ? x : field).focus();
      currentSheet = scroll;
      return scroll;
    }

    /** An empty results sheet over the page, with a title and a close button. Returns the sheet. */
    function openSheet(titleText, raw) {
      if (drawerMode()) {
        return openDrawer(raw === undefined ? '' : raw);
      }
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
      currentSheet = sheet;
      return sheet;
    }

    function openPanel(raw) {
      var sheet = openSheet(label('all_results', { query: raw }), raw);
      var body = el('div', 'section', label('searching'));
      sheet.appendChild(body);

      var normalized = normalize(raw);
      var url = API + '/search/' + encodeURIComponent(ctx.site) + '?q=' + encodeURIComponent(raw) + (isCounted(normalized) ? '&counted=1' : '');
      markCounted(normalized);

      win.fetch(url, { mode: 'cors', credentials: 'omit' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (result) {
          if (body.parentNode) {
            body.parentNode.removeChild(body);
          }
          renderResults(sheet, result, raw);
        })
        .catch(function () {
          body.textContent = label('no_results', { query: raw });
        });
    }

    /** Products like one in the results, in place of the results, with the way back. */
    function showSimilar(item, raw) {
      // From the suggestions there is no sheet yet: one opens for the similar items.
      var sheet = currentSheet || openSheet(label('similar_to', { title: item.title }), raw);
      if (!sheet) {
        return;
      }
      countClick(raw, { id: item.id, title: item.title });
      sheet.textContent = '';
      var head = el('div', 'section');
      var back = el('button', 'backlink', '\u2192 ' + label('back_to'));
      back.type = 'button';
      back.addEventListener('click', function () { openPanel(raw); });
      head.appendChild(back);
      head.appendChild(el('h3', null, label('similar_to', { title: item.title })));
      sheet.appendChild(head);
      var body = el('div', 'section', label('searching'));
      sheet.appendChild(body);

      win.fetch(API + '/search/' + encodeURIComponent(ctx.site) + '/similar?id=' + encodeURIComponent(item.external_id), { mode: 'cors', credentials: 'omit' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (json) {
          var products = (json && json.products) || [];
          body.textContent = '';
          if (!products.length) {
            body.textContent = label('no_similar');
            return;
          }
          var grid = el('div', 'grid');
          var ids = [];
          products.forEach(function (product) {
            grid.appendChild(card(product, raw));
            ids.push(product.external_id);
          });
          body.appendChild(grid);
          liveProducts(ids).then(function () { updateCards(grid); });
        })
        .catch(function () { body.textContent = label('no_similar'); });
    }

    function renderResults(sheet, result, raw, quiet) {
      var groups = (result && result.groups) || {};
      var products = groups.product || [];
      var content = groups.content || [];
      var categories = groups.category || [];
      var answers = groups.answer || [];

      if (answers.length && !quiet && closeAnswer(raw, answers[0].title)) {
        var said = el('div', 'section answer-box');
        said.appendChild(answerBlock(answers[0].title, answers[0].answer, answers[0].sources, raw, answers[0]));
        sheet.appendChild(said);
      }

      if (quiet && (products.length || content.length)) {
        var related = el('div', 'section');
        related.appendChild(el('h3', null, label('related')));
        sheet.appendChild(related);
      }

      if (!products.length && !content.length && !categories.length) {
        if (quiet) {
          return;
        }
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
        // The drawer shows a few at a time and the rest on request.
        if (drawerMode() && products.length > 6) {
          var cardsInGrid = grid.querySelectorAll('.card');
          for (var h = 6; h < cardsInGrid.length; h++) {
            cardsInGrid[h].hidden = true;
          }
          var more = el('button', 'more', label('show_more'));
          more.type = 'button';
          more.addEventListener('click', function () {
            for (var k = 0; k < cardsInGrid.length; k++) {
              cardsInGrid[k].hidden = false;
            }
            more.parentNode.removeChild(more);
          });
          section.appendChild(more);
        }
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
        site.href = siteSearchUrl(raw);
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
      rememberUrl(item.external_id, item.url);
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
      if (item.buy && CAN_BUY) {
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
      if (state.config && state.config.similar) {
        var sim = el('button', 'btn ghost sim', label('similar'));
        sim.type = 'button';
        sim.addEventListener('click', function () { showSimilar(item, raw); });
        node.appendChild(sim);
      }
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
        } else {
          chooseOnPage(cards[i].querySelector('button[data-action]'), product);
        }
      }
    }

    // ---------------------------------------------------------------- search by photo

    // A lens: four rounded corners of a frame, the camera's bump on top, the eye in the middle.
    var CAMERA = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M3.5 9.5V8a3 3 0 0 1 3-3h1.8l1.2-1.6h5l1.2 1.6h1.8a3 3 0 0 1 3 3v1.5"/>'
      + '<path d="M20.5 14.5V17a3 3 0 0 1-3 3H15"/><path d="M9 20H6.5a3 3 0 0 1-3-3v-2.5"/>'
      + '<circle cx="12" cy="12.5" r="3.2"/><circle cx="12" cy="12.5" r="1" fill="currentColor" stroke="none"/></svg>';
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
        // The camera closes only the empty upload area. Over a photo's results it asks for the
        // next photo: closing them there read as "nothing happens" on the third try.
        if (dropdown && dropdown.zone) {
          closeDropdown();
        } else {
          openUpload();
        }
      });
      shadow.appendChild(button);
      input.insertAdjacentElement('afterend', host);
    }

    /** inSheet: the results go to the open results sheet or drawer, not to the suggestions. */
    function fileInput(capture, inSheet) {
      var picker = doc.createElement('input');
      picker.type = 'file';
      picker.accept = 'image/jpeg,image/png,image/webp';
      if (capture) {
        picker.setAttribute('capture', 'environment');
      }
      picker.addEventListener('change', function () {
        if (picker.files && picker.files[0]) {
          if (inSheet) {
            searchPhotoSheet(picker.files[0]);
          } else {
            searchPhoto(picker.files[0]);
          }
        }
      });
      return picker;
    }

    /** The upload area, inside the suggestions (or the phone sheet), until the shopper leaves it. */
    function openUpload() {
      var d = freshDropdown();
      d.upload = true;
      d.zone = true;
      var mobile = d.mobile;
      var main = el('div', 'd-main');
      var zone = el('div', 'zone d-zone');
      zone.tabIndex = 0;
      zone.setAttribute('role', 'button');
      zone.innerHTML = CAMERA;
      zone.appendChild(el('strong', null, label('photo_search')));
      zone.appendChild(el('span', null, label(mobile ? 'photo_drop_mobile' : 'photo_drop')));
      var buttons = el('div', 'btns');
      var choose = el('button', 'btn', label('photo_choose'));
      choose.type = 'button';
      var take = el('button', 'btn ghost', label('photo_take'));
      take.type = 'button';
      buttons.appendChild(choose);
      buttons.appendChild(take);
      zone.appendChild(buttons);
      zone.appendChild(el('span', 'muted', label('photo_formats') + '. ' + label('photo_private')));
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
      main.appendChild(zone);
      layout(null, main, null);
      position();
      if (!mobile) {
        zone.focus();
      }
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

    /**
     * Sends the photo once; resolves with { products, tags }: the products that look like it and
     * what the photo shows in the shop's own words. A label key says why not, when neither came.
     */
    function photoRequest(file) {
      return shrink(file).then(function (blob) {
        var maxBytes = ((state.config && state.config.photoMaxKb) || 5120) * 1024;
        if (blob.size > maxBytes) {
          return 'photo_too_big';
        }
        var form = new FormData();
        form.append('photo', blob, 'photo.jpg');
        return win.fetch(API + '/search/' + encodeURIComponent(ctx.site) + '/photo', { method: 'POST', body: form, mode: 'cors', credentials: 'omit' })
          .then(function (response) {
            if (response.status === 413) {
              return 'photo_too_big';
            }
            return response.ok ? response.json() : 'photo_failed';
          });
      }).then(function (result) {
        if (typeof result === 'string') {
          return result;
        }
        var products = (result && result.groups && result.groups.product) || [];
        var tags = (result && result.tags) || [];
        if (result && result.nothing) {
          return 'photo_nothing';
        }
        return products.length || tags.length ? { products: products, tags: tags, seen: (result && result.seen) || '' } : 'photo_none';
      }).catch(function () { return 'photo_failed'; });
    }

    /**
     * What the photo shows, as tags. A tag narrows the photo's own results to it, and pressing
     * it again shows them all; the shopper stays with the photo.
     */
    function photoTagRow(tags, choose, seen) {
      var row = el('div', 'd-tags');
      row.appendChild(el('span', 'd-tags-h', seen ? label('photo_seen', { seen: seen }) : label('photo_tags')));
      var chips = [];
      var chosen = null;
      tags.forEach(function (tag, at) {
        var chip = el('button', 'd-chip', tag.title);
        chip.type = 'button';
        chip.setAttribute('aria-pressed', 'false');
        chip.setAttribute('data-photo-tag', tag.kind || '');
        chip.addEventListener('click', function () {
          chosen = chosen === at ? null : at;
          chips.forEach(function (other, i) { other.setAttribute('aria-pressed', i === chosen ? 'true' : 'false'); });
          choose(chosen);
        });
        chips.push(chip);
        row.appendChild(chip);
      });
      return row;
    }

    /** The photo's products to show: all, or those of the chosen tag; at most PHOTO_SHOW. */
    function photoShown(products, chosen) {
      var shown = chosen === null ? products : products.filter(function (p) { return (p.tags || []).indexOf(chosen) !== -1; });
      return shown.slice(0, PHOTO_SHOW);
    }

    function preview(file) {
      var thumb = el('img');
      thumb.alt = label('photo_yours');
      try {
        thumb.src = win.URL.createObjectURL(file);
      } catch (e) { /* no preview */ }
      return thumb;
    }

    var PHOTO_SHOW = 12;

    /** Search by photo inside the suggestions: the shopper's picture, then the products like it. */
    function searchPhoto(file) {
      if (!file || !/^image\//.test(file.type || '')) {
        return;
      }
      if (!dropdown || !dropdown.upload) {
        openUpload();
      }
      var d = dropdown;
      var main = el('div', 'd-main');
      var head = el('div', 'd-yours');
      head.appendChild(preview(file));
      var words = el('div');
      words.appendChild(el('b', null, label('photo_results')));
      var status = el('div', 'd-thinking', label('photo_searching'));
      words.appendChild(status);
      head.appendChild(words);
      main.appendChild(head);
      layout(null, main, null);
      d.upload = true;
      d.zone = false;
      position();

      photoRequest(file).then(function (result) {
        if (main.isConnected === false) {
          return;
        }
        var products = typeof result === 'string' ? result : result.products;
        var again = el('button', 'd-link', label('photo_again'));
        again.type = 'button';
        again.addEventListener('click', function () { openUpload(); });
        if (typeof products === 'string') {
          status.className = 'muted';
          status.textContent = label(products);
          main.appendChild(again);
          return;
        }
        status.className = 'muted';
        var grid = el('div', d.mobile ? 'm-list' : 'd-prods');
        var wider = el('button', 'd-link');
        wider.type = 'button';
        wider.hidden = true;
        var draw = function (chosen) {
          var shown = photoShown(products, chosen);
          status.textContent = shown.length
            ? label('photo_count', { count: shown.length }) + (chosen === null ? '' : ' · ' + result.tags[chosen].title)
            : label('photo_only_tags');
          grid.textContent = '';
          var records = [];
          for (var i = 0; i < shown.length; i++) {
            var item = shown[i];
            var record = state.records['p:' + item.external_id] || {};
            var row = {
              id: 'p:' + item.external_id,
              t: 'product',
              title: item.title || record.title || '',
              url: item.url || record.url,
              img: item.image || record.img,
              s: record.s
            };
            records.push(row);
            grid.appendChild(productRow(row, '', item.match));
          }
          fillPrices(grid, records);
          // The whole site for the chosen tag stays one step away, never the default.
          wider.hidden = chosen === null;
          wider.textContent = chosen === null ? '' : label('photo_tag_site', { tag: result.tags[chosen].title });
          wider.onclick = chosen === null ? null : function () {
            var field = d.field || current;
            if (field) {
              field.value = result.tags[chosen].title;
              suggest(field);
              focusField(dropdown || d);
            }
          };
        };
        if (result.tags.length) {
          main.appendChild(photoTagRow(result.tags, draw, result.seen));
        }
        main.appendChild(grid);
        main.appendChild(wider);
        main.appendChild(again);
        draw(null);
      });
    }

    /** Search by photo from the results sheet or drawer: the results fill that sheet. */
    function searchPhotoSheet(file) {
      if (!file || !/^image\//.test(file.type || '')) {
        return;
      }
      closeDropdown();
      var sheet = openSheet(label('photo_results'));
      var head = el('div', 'yours');
      head.appendChild(preview(file));
      head.appendChild(el('strong', null, label('photo_results')));
      sheet.appendChild(head);
      var status = el('div', 'section', label('photo_searching'));
      sheet.appendChild(status);

      photoRequest(file).then(function (result) {
        if (typeof result === 'string') {
          status.textContent = label(result);
          return;
        }
        var products = result.products;
        sheet.removeChild(status);
        var note = el('div', 'section muted');
        var section = el('div', 'section');
        var grid = el('div', 'grid');
        var draw = function (chosen) {
          var shown = photoShown(products, chosen);
          note.textContent = shown.length ? '' : label('photo_only_tags');
          note.hidden = !!shown.length;
          grid.textContent = '';
          var ids = [];
          for (var i = 0; i < shown.length; i++) {
            var node = card(shown[i], '');
            if (shown[i].match) {
              node.appendChild(el('span', 'badge', label('photo_match', { match: shown[i].match })));
            }
            grid.appendChild(node);
            ids.push(shown[i].external_id);
          }
          liveProducts(ids).then(function () { updateCards(grid); });
        };
        if (result.tags.length) {
          var tagRow = photoTagRow(result.tags, draw, result.seen);
          tagRow.className += ' section';
          sheet.appendChild(tagRow);
        }
        sheet.appendChild(note);
        section.appendChild(grid);
        sheet.appendChild(section);
        draw(null);
        var foot = el('div', 'foot');
        var again = el('button', 'btn ghost', label('photo_again'));
        again.type = 'button';
        again.addEventListener('click', function () { fileInput(false, true).click(); });
        foot.appendChild(again);
        sheet.appendChild(foot);
      });
    }

    // ---------------------------------------------------------------- wiring

    /**
     * The fields to attach to. The server's default names WordPress's field; on Shopify that default
     * means "not configured" and Shopify's own search field (q) is used. A selector the shop set wins.
     */
    function fieldSelector() {
      var configured = String((state.config && state.config.selector) || '').replace(/\s+/g, ' ').trim();
      if (SHOPIFY && (!configured || configured === WP_SELECTOR)) {
        return SHOPIFY_SELECTOR;
      }
      return configured || WP_SELECTOR;
    }

    /**
     * Shopify themes built on Dawn have their own suggestions (predictive-search) on the same field.
     * Two dropdowns would stack, so, once ours is attached: the theme's results are hidden with CSS
     * (its markup and requests stay as they are), and the arrow and Enter keys stop at the field, so
     * the theme cannot move or open a selection the shopper cannot see. Typing still reaches the
     * theme, nothing else is intercepted, and without Let Agents the theme works as before.
     */
    function quietThemeSearch(input) {
      var theirs = input.closest ? input.closest('predictive-search') : null;
      var owners = [theirs, input.form];
      for (var i = 0; i < owners.length; i++) {
        if (owners[i]) {
          owners[i].setAttribute('data-let-agents-search', '');
        }
      }
      if (!doc.getElementById('let-agents-search-quiet')) {
        var style = doc.createElement('style');
        style.id = 'let-agents-search-quiet';
        style.textContent = '[data-let-agents-search] .predictive-search,[data-let-agents-search] [data-predictive-search],'
          + '[data-let-agents-search] [id^="predictive-search-results"]{display:none!important}';
        (doc.head || doc.documentElement).appendChild(style);
      }
      if (theirs) {
        ['keydown', 'keyup'].forEach(function (type) {
          input.addEventListener(type, function (event) {
            if (event.key === 'ArrowUp' || event.key === 'ArrowDown' || event.key === 'Enter') {
              event.stopPropagation();
            }
          });
        });
      }
    }

    function matches(node) {
      var selector = fieldSelector();
      try {
        return node && node.matches && node.matches(selector);
      } catch (e) {
        return false;
      }
    }

    /** Arrows move through the rows, Enter opens one, asks a question, or shows all results. */
    function onKey(event, input, fromSheet) {
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
        if ((picked && picked.ask) || (!picked && state.config && state.config.ask && isQuestion(input.value))) {
          event.preventDefault();
          askInline(input);
        } else if (picked && picked.all) {
          event.preventDefault();
          submit(input);
        } else if (picked && picked.record && picked.node.href) {
          event.preventDefault();
          countClick(input.value, picked.record);
          win.location.href = picked.node.href;
        } else if (fromSheet || (state.config && state.config.results) !== 'page') {
          event.preventDefault();
          submit(input);
        } else {
          closeDropdown();
        }
      }
    }

    /**
     * A phone zooms the page into a field whose text is under 16px when it is tapped, and the page
     * then scrolls sideways. The store's field gets 16px on a touch screen; a pixel nobody sees.
     */
    function noZoom(input) {
      try {
        var touch = win.matchMedia && win.matchMedia('(hover: none) and (pointer: coarse)').matches;
        if (touch && parseFloat(win.getComputedStyle(input).fontSize) < 16) {
          input.style.setProperty('font-size', '16px', 'important');
        }
      } catch (e) { /* an old browser keeps the theme's size */ }
    }

    function attach(input) {
      if (input.__letAgentsSearch) {
        return;
      }
      input.__letAgentsSearch = true;
      attached.push(input);
      addCamera(input);
      input.setAttribute('autocomplete', 'off');
      noZoom(input);
      if (SHOPIFY) {
        quietThemeSearch(input);
      }

      input.addEventListener('input', function () {
        current = input;
        clearTimeout(typeTimer);
        // On a phone the sheet opens at once, while the keyboard is up, so its field can take over.
        if (narrow() && !(dropdown && dropdown.mobile) && input.value.trim()) {
          suggest(input);
          return;
        }
        typeTimer = setTimeout(function () { suggest(input); }, TYPE_MS);
      });
      input.addEventListener('focus', function () {
        current = input;
        if (input.value.trim() && !(dropdown && dropdown.raw === input.value)) {
          suggest(input);
        }
      });
      input.addEventListener('blur', function () {
        setTimeout(function () {
          if (!dropdown || dropdown.mobile || dropdown.upload || dropdown.sticky) {
            return;
          }
          if (doc.activeElement !== input && doc.activeElement !== dropdown.host) {
            closeDropdown();
          }
        }, 150);
      });
      input.addEventListener('keydown', function (event) {
        current = input;
        onKey(event, input, false);
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
      var selector = fieldSelector();
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
      if (dropdown && target === dropdown.host) {
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
      if (event.key !== 'Escape') {
        return;
      }
      if (panel) {
        closePanel();
      } else if (dropdown) {
        closeDropdown();
      }
    });
    // An answer or the photo area stays open while the shopper reads; a click elsewhere closes it.
    doc.addEventListener('pointerdown', function (event) {
      var target = event.target;
      if (dropdown && !dropdown.mobile && (dropdown.upload || dropdown.sticky) && target !== dropdown.host && target !== current &&
        !(target.closest && target.closest('.let-agents-search-camera'))) {
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
    isQuestion: isQuestion,
    closeAnswer: closeAnswer,
    handleFromUrl: handleFromUrl,
    formatMoney: formatMoney,
    fromShopifyProduct: fromShopifyProduct,
    boot: boot
  };
}));
