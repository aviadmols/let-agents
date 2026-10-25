  // ---------------------------------------------------------------- filters read from the words
  // A value the shopper typed ("אלון", "שחור", "במבצע") is chosen for them and marked ✓. Taken off, it stays off.
  function C(turn) { if (!turn.c || !turn.c.no) { turn.c = Object.assign(blankC(), turn.c || {}); } return turn.c; }
  function stem(w) { return w.replace(/^[והבלמשכ](?=[א-ת]{2})/, ''); }
  function wordsOf(s) { return String(s || '').toLowerCase().replace(/[^\p{L}\p{N}\s]/gu, ' ').split(/\s+/).filter(Boolean); }
  function says(text, label) { return !!saidBy(text, label); }
  // Whether a product's own words say a value: each of its words as a word of them, maybe after a prefix letter or
  // with a plural or feminine ending; no sound-alikes, which are for what a shopper mistypes ("עגול" is not "עגילים").
  // As the server reads it (HebrewSearch::says).
  function plainlySays(text, label) {
    var E = S.engine, norm = function (s) { try { return E && E.normalize ? E.normalize(String(s || '')) : String(s || ''); } catch (e) { return String(s || ''); } };
    var hay = ' ' + wordsOf(norm(text)).join(' ') + ' ', parts = wordsOf(norm(label));
    return parts.length > 0 && parts.every(function (w) { return new RegExp(' [והבלמשכ]?' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?:ימ|ים|ות|ה|י|ת)? ').test(hay); });
  }
  // which typed words name the label, one for each of its words; null when one of them is not said
  function saidBy(text, label) { var ws = wordsSaid(text, label); return ws.of > 0 && ws.n === ws.of ? ws.at : sizedBy(text, label); }
  // "חולצה מידה M", "size S": a value right after the word for size is that size, however short (as on the server,
  // HebrewSearch::facets); "M" alone is said by too much to be read
  function sizedBy(text, label) {
    var tw = wordsOf(text), v = String(label || '').toLowerCase().trim();
    if (!v || /\s/.test(v)) { return null; }
    for (var i = 1; i < tw.length; i++) { if (tw[i] === v && /^(?:[ובה]?מיד[הת]|size)$/.test(tw[i - 1])) { return [i - 1, i]; } }
    return null;
  }
  // How many of the label's words the typed words say (n of of), and which typed words say them (at). A name of several
  // words is said by words close together, in its order, at most one other word between any two of them, each typed
  // word naming one of its words: "עגילים בזהב צהוב עם אבן אדומה" says "זהב צהוב" and "אדום", never "זהב אדום", and
  // "אדומים בזהב" is not "זהב אדום"; "עגילים אדומים בזהב צהוב" still says "עגילים זהב צהוב" (as Aviad asked, 2026-10-21).
  // Where a word is typed twice, the places that keep the name closest together count.
  function wordsSaid(text, label) {
    var tw = wordsOf(text), lw = wordsOf(label);
    if (!lw.length || lw.join('').length < 2) { return { n: 0, of: 0, at: [] }; }
    var places = lw.map(function (w) {
      var out = [];
      // a word that names what the products are (a category, a kind) is that, never a look-alike value: "עגילי זהב"
      // is earrings, not "עגול" (Rocks, 25.10). Said as it is, it still names the value.
      tw.forEach(function (x, i) { if (x === w || stem(x) === w || (w.length >= 4 && x.indexOf(w) === 0 && x.length - w.length <= 2) || (alike(x, w) && !namesKind(x))) { out.push(i); } });
      return out;
    });
    var best = [];
    var spread = function (at) { return at.length ? at[at.length - 1] - at[0] : 0; };
    (function walk(k, at) {
      if (k === lw.length) {
        if (at.length > best.length || (at.length === best.length && spread(at) < spread(best))) { best = at.slice(); }
        return;
      }
      walk(k + 1, at); // this word of the name not typed
      places[k].forEach(function (i) {
        var last = at.length ? at[at.length - 1] : -1;
        if (i <= last || (at.length && i - last > 2)) { return; }
        at.push(i);
        walk(k + 1, at);
        at.pop();
      });
    })(0, []);
    return { n: best.length, of: lw.length, at: best };
  }
  // Whether a typed word is a form of a word in the name of one of the store's categories or kinds ("עגילי" of "עגילים").
  function namesKind(x) {
    if (x.length < 3) { return false; }
    return (S.facets || []).some(function (f) {
      return (f.k === 'category' || f.k === 'type') && (f.v || []).some(function (v) {
        return wordsOf(v.l).some(function (w) { return w.length >= 3 && (x === w || stem(x) === w || ((w.indexOf(x) === 0 || x.indexOf(w) === 0) && Math.abs(w.length - x.length) <= 2)); });
      });
    });
  }
  // A category (or a tag shown as a filter) is read from the words when its whole name is typed, or, for a name of
  // three words or more, all of it but one word and at least two: "ספרי מופת" is "ספרי מופת מתורגמים".
  function namesGroup(text, label) {
    var ws = wordsSaid(text, label);
    if (ws.of < 2) { return null; }
    return ws.n === ws.of || (ws.of >= 3 && ws.n >= 2 && ws.n >= ws.of - 1) ? ws.at : null;
  }
  // The same word as the search engine reads it, through a spelling slip: "לבין" is "לבן", "אדומה" is "אדום"
  // (its stem, or how it sounds once its look-alike letters are one). Short words must be exact.
  function alike(x, w) {
    var E = S.engine;
    if (!E || !E.normalize || !E.stem || x.length < 3 || w.length < 3) { return false; }
    try {
      var a = E.normalize(x), b = E.normalize(w);
      return E.stem(a) === E.stem(b) || (b.length >= 4 && !!E.sound && E.sound(a) === E.sound(b));
    } catch (e) { return false; }
  }
  function readTags(turn) {
    var c = C(turn), q = turn.q || '', all = (turn.ids || []).map(rec).filter(Boolean), counts = {}, best = {}, said = {}, part = {};
    all.forEach(function (r) { (r.f || []).forEach(function (code) { counts[code] = (counts[code] || 0) + 1; }); });
    Object.keys(counts).forEach(function (code) {
      var facet = S.facets[Math.floor(code / 1000)], value = facet && facet.v[code % 1000];
      // a category (or a tag shown as a filter) of one word is chosen by the shopper, never read from the words: "ברגים"
      // finds screws outside the screws category too. One of several words, said enough (namesGroup), is it.
      if (!value || c.no[code] || /^(אחר|other)$/i.test(value.l)) { return; }
      var grouped = facet.k === 'category' || /^tag:/.test(facet.k);
      var by = grouped ? namesGroup(q, value.l) : saidBy(q, value.l);
      if (!by) { return; }
      // one value name in several attributes ("שחור" as a colour and as a coating): the one most results carry
      var k = value.l.toLowerCase();
      if (!best[k] || counts[code] > counts[best[k]]) { best[k] = code; said[k] = by; part[k] = grouped && !saidBy(q, value.l); }
    });
    // A name read from all its words but one is read only where it is the one: "זהב צהוב" is all of "טבעות זהב צהוב" and part
    // of "תליונים זהב צהוב" and "עגילים זהב צהוב". A name said whole in its filter takes the place of the partial ones;
    // several partial and none whole, none is read.
    var byGroup = {};
    Object.keys(best).forEach(function (k) { var g = Math.floor(best[k] / 1000); (byGroup[g] = byGroup[g] || []).push(k); });
    Object.keys(byGroup).forEach(function (g) {
      var ks = byGroup[g], whole = ks.filter(function (k) { return !part[k]; }), partial = ks.filter(function (k) { return part[k]; });
      if (partial.length && (whole.length || partial.length > 1)) { partial.forEach(function (k) { delete best[k]; delete said[k]; }); }
    });
    c.auto = {};
    c.at = {};
    // The most precise name first, and a typed word names one thing: "ספר" is said within "ספרי מופת מתורגמים", so only
    // the category is chosen; in "עגילים אדומים בזהב צהוב" the word "בזהב" belongs to "עגילים זהב צהוב", so "זהב אדום"
    // is not read from "אדומים בזהב", and "אדומים" is left to the stone's colour.
    var taken = [], precise = {};
    Object.keys(best).sort(function (a, b) { return said[b].length - said[a].length || counts[best[b]] - counts[best[a]] || (a < b ? -1 : a > b ? 1 : 0); }).forEach(function (k) {
      if (said[k].some(function (i) { return taken.indexOf(i) >= 0; })) { return; }
      c.auto[best[k]] = true;
      c.at[best[k]] = said[k].slice();
      precise[best[k]] = said[k].length;
      taken.push.apply(taken, said[k]);
    });
    var acts = (turn.actions || []).map(function (a) { return a.id || a; });
    // in stock by default in every search (a pill the shopper can take off, and it stays off), unless nothing found is;
    // marked as read from the words only when the words said it
    c.saidStock = /(^|\s)(במלאי|in stock)(\s|$)/i.test(q) || acts.indexOf('in_stock') >= 0;
    c.autoStock = !c.no.stock && (c.saidStock || all.some(function (r) { return r.s === 1; }));
    c.autoSale = !c.no.sale && (/(^|\s)(ב?מבצע|on sale)(\s|$)/i.test(q) || acts.indexOf('on_sale') >= 0);
    c.autoSort = c.no.sort ? '' : acts.indexOf('cheapest') >= 0 ? 'asc' : acts.indexOf('dearest') >= 0 ? 'desc' : acts.indexOf('newest') >= 0 ? 'new' : '';
    // "טבעת רובי במידה גדולה": the largest third of the sizes these results come in. A size on the product itself
    // was already applied (the "band" action, as on the server); sizes as variants are chosen here, in stock
    // together with the size; with no size anywhere, the shopper is told so instead of the size being ignored.
    var band = acts.indexOf('large_sizes') >= 0 ? 'top' : acts.indexOf('small_sizes') >= 0 ? 'bottom' : (SIZE_BAND.top.test(q) ? 'top' : SIZE_BAND.bottom.test(q) ? 'bottom' : '');
    turn.sizeUnknown = false;
    if (band && !all.some(function (r) { return typeof r.z === 'number'; })) {
      var sg = S.facets.map(function (f) { return f.k; }).indexOf('variant_size');
      var sizes = sg < 0 ? [] : Object.keys(counts).map(Number).filter(function (code) { return Math.floor(code / 1000) === sg && !c.no[code]; }).sort(function (a, b) { return a - b; });
      if (sizes.length) {
        var take = Math.max(1, Math.round(sizes.length / 3));
        (band === 'top' ? sizes.slice(sizes.length - take) : sizes.slice(0, take)).forEach(function (code) { c.auto[code] = true; });
      } else { turn.sizeUnknown = true; }
    }
    // What was read never empties the results, and never stays marked when it is not applied: while nothing holds it
    // all, the least precise of it is let go (its chip unmarked, the shopper can turn it back on).
    // "In stock" by default is weighed apart: what the shopper named, all sold out, is shown sold out, not swapped for
    // something else in stock.
    var loose = Object.keys(c.auto).map(Number).sort(function (a, b) { return (precise[a] || 0) - (precise[b] || 0) || a - b; });
    var stocked = c.autoStock;
    c.autoStock = false;
    while (loose.length && all.length && !filtered(turn).length) { delete c.auto[loose.shift()]; }
    c.autoStock = stocked;
    if (c.autoStock && !c.saidStock && all.length && !filtered(turn).length) { c.autoStock = false; }
  }
  // The words the filters read from them did not take: what is searched, while those filters narrow it ("ספרים על חטופים"
  // is "על חטופים" among books). Null when nothing was taken, or nothing that means anything is left: the words stand whole.
  // Only where the words read as filters made the search broad: they still hold most of what it found ("ספרים" in a book
  // shop holds every book). Where the filters narrowed it on their own ("עגילים זהב צהוב" out of rings and pendants), the
  // search stands as it is, with no second wait.
  function restWords(turn) {
    var c = C(turn), taken = [], all = (turn.ids || []).length;
    if (all < 12 || filtered(turn).length < all / 2) { return null; }
    Object.keys(c.auto || {}).forEach(function (code) { [].push.apply(taken, (c.at && c.at[code]) || []); });
    if (c.autoStock && c.saidStock) { [].push.apply(taken, saidAt(turn.q, /^במלאי$/, ['in', 'stock'])); }
    if (c.autoSale) { [].push.apply(taken, saidAt(turn.q, /^ב?מבצע$/, ['on', 'sale'])); }
    if (!taken.length) { return null; }
    // only the words that mean something: a joining word left alone ("עם" of "טבעות עם רובי") would narrow the search to
    // the products whose names happen to hold it
    var rest = meantWords(wordsOf(turn.q).filter(function (w, i) { return taken.indexOf(i) < 0; }).join(' ')).join(' ');
    return rest || null;
  }
  var SIZE_BAND = { top: /(^|\s)(ב?מיד(ה|ות)\s+(גדול|רחב)|large\s+sizes?|big\s+sizes?|plus\s+sizes?)/i, bottom: /(^|\s)(ב?מיד(ה|ות)\s+(קטנ|צר)|small\s+sizes?|petite)/i };
  function onCode(c, code) { return !!(c.f[code] || (c.auto && c.auto[code])); }
  function onStock(c) { return !!(c.stock || c.autoStock); }
  function onSale(c) { return !!(c.sale || c.autoSale); }
  function sortOf(c) { return c.sort || c.autoSort || ''; }
  function codesOn(c) { var o = {}; Object.keys(c.f).concat(Object.keys(c.auto || {})).forEach(function (k) { o[k] = 1; }); return Object.keys(o).map(Number); }
  // a size alone on a chip is a bare number ("59"): it says what it is ("מידה 59"); a letter size or a colour says itself
  // a size number says it is a size ("מידה 59"); a store field says which ("מחבר: סלע מאיר", not the category of that name)
  function chipText(facet, value) { return facet && facet.k === 'variant_size' && /^\d/.test(String(value.l)) ? facet.l + ' ' + value.l : facet && /^field:/.test(facet.k) ? facet.l + ': ' + value.l : value.l; }
  function valueOf(code) { var f = S.facets[Math.floor(code / 1000)]; return f ? { facet: f, value: f.v[code % 1000] } : null; }
  // the colour and size filters the index reads from products' variants (BuildSearchIndex::variantFacets)
  function variantFacet(g) { var f = S.facets && S.facets[g]; return !!(f && (f.k === 'variant_color' || f.k === 'variant_size')); }

  // colour values get a circle of their colour
  var SWATCH = {
    'שחור': '#161616', black: '#161616', 'לבן': '#ffffff', white: '#ffffff', 'אפור': '#8e8e94', grey: '#8e8e94', gray: '#8e8e94',
    'חום': '#7a4a28', brown: '#7a4a28', 'צהוב': '#f1c21b', yellow: '#f1c21b', yellow_zinc: 'linear-gradient(135deg,#f6e27a,#c9a227)', 'כחול': '#2563c9', blue: '#2563c9',
    'אדום': '#cf3a2e', red: '#cf3a2e', 'ירוק': '#2f8f4e', green: '#2f8f4e', 'כתום': '#ee7a1f', orange: '#ee7a1f', 'ורוד': '#ec8fb3', pink: '#ec8fb3', 'סגול': '#7b4bb7', purple: '#7b4bb7',
    'בז׳': '#d9c4a0', beige: '#d9c4a0', 'זהב': 'linear-gradient(135deg,#f3d27a,#b4842a)', gold: 'linear-gradient(135deg,#f3d27a,#b4842a)',
    'כסף': 'linear-gradient(135deg,#f4f4f5,#a6a6ac)', silver: 'linear-gradient(135deg,#f4f4f5,#a6a6ac)', 'ניקל': 'linear-gradient(135deg,#ebe8df,#9b978b)', nickel: 'linear-gradient(135deg,#ebe8df,#9b978b)',
    'כרום': 'linear-gradient(135deg,#ffffff,#8c9198)', chrome: 'linear-gradient(135deg,#ffffff,#8c9198)', 'נירוסטה': 'linear-gradient(135deg,#eeeff1,#9ea3aa)', stainless: 'linear-gradient(135deg,#eeeff1,#9ea3aa)',
    galvanized: 'linear-gradient(135deg,#dfe3e6,#9aa3aa)', 'מגולבן': 'linear-gradient(135deg,#dfe3e6,#9aa3aa)',
    'שקוף': 'clear', clear: 'clear', transparent: 'clear', custom_tint: 'conic-gradient(#e5484d,#f5d90a,#30a46c,#0090ff,#8e4ec6,#e5484d)', 'גוון לבחירה': 'conic-gradient(#e5484d,#f5d90a,#30a46c,#0090ff,#8e4ec6,#e5484d)'
  };
  function swatch(facet, value) {
    if (!facet || !value || !(/colou?r|coating/i.test(facet.k) || /צבע|גוון/.test(facet.l))) { return ''; }
    // a colour family read from variants carries its own (h); several colours together, a wheel
    var v = value.h || SWATCH[value.l] || SWATCH[value.k] || (value.k === 'multi' ? SWATCH.custom_tint : null);
    if (!v) { return ''; }
    return '<span class="ls-swatch' + (v === 'clear' ? ' ls-swatch--clear' : '') + '"' + (v === 'clear' ? '' : ' style="--sw:' + esc(v) + '"') + ' aria-hidden="true"></span>';
  }

  // active filters, each a dark pill with its ×: what was chosen, and what was read from the words (✦)
  function activeRow(turn) {
    var c = C(turn), pills = [];
    codesOn(c).forEach(function (code) { var x = valueOf(code); if (x && x.value) { pills.push({ key: 'f:' + code, label: chipText(x.facet, x.value), auto: !c.f[code], sw: swatch(x.facet, x.value) }); } });
    if (onStock(c)) { pills.push({ key: 'stock', label: t('inStock'), auto: !c.stock && !!c.saidStock }); }
    if (onSale(c)) { pills.push({ key: 'sale', label: t('onSale'), auto: !c.sale }); }
    if (!pills.length) { return ''; }
    return '<div class="ls-active" role="group" aria-label="' + esc(t('activeFilters')) + '">' + pills.map(function (p) {
      return '<button class="ls-pill' + (p.auto ? ' is-auto' : '') + '" data-chip="' + esc(p.key) + '" data-turn="' + turn.id + '" aria-label="' + esc(t('removeFilter', { t: p.label })) + '"' + (p.auto ? ' title="' + esc(t('detected')) + '"' : '') + '>' +
        (p.auto ? icon('spark', 'ls-icon--sm ls-pill__spark') : '') + (p.sw || '') + '<span>' + esc(p.label) + '</span>' + icon('x', 'ls-icon--sm') + '</button>';
    }).join('') + (pills.length > 1 ? '<button class="ls-btn ls-btn--link ls-active__clear" data-clear-filters="' + turn.id + '">' + esc(t('clearFilters')) + '</button>' : '') + '</div>';
  }

  // guides and pages: a real picture, what it is, and how well it answers the search
  var GUIDE_FIRST = 90, GUIDE_FIRST_WORDS = 4;
  function meantWords(q) { return wordsOf(q).filter(function (w) { return w.length >= 2 && !STOP.test(w); }); }
  function matchOf(q, title, kw, rank) {
    var qw = meantWords(q);
    if (!qw.length) { return 60; }
    var tw = wordsOf(title), kws = wordsOf(kw), hit = 0;
    qw.forEach(function (w) {
      var s = stem(w), near = function (x) { return x === w || stem(x) === s || (s.length >= 3 && stem(x).indexOf(s) === 0); };
      if (tw.some(near)) { hit += 1; } else if (kws.some(near)) { hit += 0.6; }
    });
    return Math.max(41, Math.min(98, 52 + Math.round(44 * hit / qw.length) - rank * 3));
  }
  function guidesHtml(list, q) {
    if (!list || !list.length) { return ''; }
    return '<div class="ls-block"><div class="ls-block__title">' + esc(t('guides')) + '</div><div class="ls-guides">' + list.map(function (c, i) {
      var r = S.records[c.id] || {}, img = c.image || c.img || r.img, kind = (c.kind || r.kind) === 'page' ? t('page') : t('guide'), m = matchOf(q, c.title, r.kw, i);
      return '<a class="ls-guide" data-opt href="' + esc(c.url) + '">' + (img ? '<img class="ls-guide__img" src="' + esc(img) + '" alt="" decoding="async">' : '<span class="ls-guide__img ls-guide__img--none">' + icon('doc') + '</span>') +
        '<span class="ls-guide__body"><span class="ls-guide__kind">' + esc(kind) + '</span><span class="ls-guide__t" dir="auto">' + esc(decode(c.title)) + '</span>' +
        '<span class="ls-match" style="--m:' + (m / 100) + '"><span class="ls-match__bar"><i></i></span><span class="ls-num">' + esc(t('match', { n: m })) + '</span></span></span></a>';
    }).join('') + '</div></div>';
  }
  // how many cards a results turn shows before "show all": 12 on a phone or the large view, 8 in the drawer;
  // after "show all" on a phone, every one, in place
  // how many more products a "show all" or "show more" brings: a few at a time, never the whole list
  var STEP = 12;
  // a button that waits: the Lets mark turns in it, and a second press does nothing until it is done
  function busy(btn) {
    if (!btn || btn.getAttribute('aria-busy') === 'true') { return false; }
    btn.setAttribute('aria-busy', 'true'); btn.classList.add('ls-btn--busy');
    btn.innerHTML = mark(true) + '<span>' + btn.textContent.trim().replace(/[<>&]/g, '') + '</span>';
    return true;
  }
  function gridN(turn) { return fill(turn && turn.more ? turn.more : isPhone() || S.expanded ? 12 : 8); }
  // A grid ends on a full row: as many cards as the columns the screen fits, rounded up (10 or 15 on five
  // columns, not 8 or 12). The columns are measured after each render, and again when the window is resized.
  var COLS = 0, fitTimer = null;
  function fill(n) { return COLS > 1 ? Math.ceil(n / COLS) * COLS : n; }
  function fitCols() {
    var g = FLOW.querySelector('.ls-grid'), c = g ? win.getComputedStyle(g).gridTemplateColumns.split(' ').filter(Boolean).length : 0;
    if (c && c !== COLS) { COLS = c; render(); }
  }
  // "show all": on a phone the rest load right there, under the cards already seen; elsewhere the large view
  function allBtn(turn, label, opt) { return '<button class="ls-btn ls-btn--secondary ls-btn--block" style="margin-top:20px"' + (opt ? ' data-opt' : '') + (isPhone() ? ' data-more="' : ' data-wide="') + turn.id + '">' + esc(label) + '</button>'; }
  function prow(r) {
    return '<a class="ls-prow" data-opt href="' + esc(r.url) + '" data-goto="' + esc(pid(r.id)) + '"><img class="ls-prow__img" src="' + esc(r.img || '') + '" alt="" decoding="async">' +
      '<div class="ls-prow__body"><div class="ls-prow__title" dir="auto">' + esc(decode(r.title)) + '</div><div class="ls-prow__sub">' + priceHtml(r) + (r.s === 0 ? '<span class="ls-stock ls-stock--out">' + esc(t('outStock')) + '</span>' : '') + '</div></div></a>';
  }
  function priceHtml(r) {
    if (off('price')) { return ''; }
    var now = livePrice(r.id), shown = now != null ? now : r.pr, reg = regular(r.id);
    return shown == null ? '' : '<span class="ls-price" data-price="' + esc(pid(r.id)) + '">' + money(shown) + (reg ? '<s>' + money(reg) + '</s>' : '') + '</span>';
  }

