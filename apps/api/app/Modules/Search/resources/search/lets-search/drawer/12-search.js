  // ---------------------------------------------------------------- submitting
  // typed: the shopper wrote it (the box, the store's own search page), so it is one of their recent searches
  function submit(raw, kind, about, typed) {
    var q = String(raw || '').trim();
    if (!q) { return; }
    // what was being looked at live goes with the search: its filters, and its results when they're the server's
    var lv = S.live && S.live.q === q ? S.live : null;
    var carried = lv ? JSON.parse(JSON.stringify(lv.c)) : (S.pendingC || null);
    S.pendingC = null;
    INPUT.value = ''; FIELD.classList.remove('has-text'); hideLive();
    S.tab = 'chat'; S.wide = null;
    if (kind === 'ask' && off('ask') && !(about || S.askMode)) { kind = 'search'; }
    if (kind === 'ask' || (kind !== 'search' && (isQuestion(q) || aboutProduct(q)))) { askTurn(q, null, about || S.askMode); return blurPhone(); }
    if (typed) { remember(q); }
    if (lv && lv.server) {
      addTurn({ kind: 'results', q: q, loading: false, ids: lv.ids, total: lv.total, focus: lv.focus, single: lv.single, content: lv.content, cats: lv.cats, actions: lv.actions, c: carried });
      // kept without asking the server again, so it is counted here (once per tab, as a pause would)
      countSearch(q, lv.ids.length);
      announce(t('announceResults', { n: lv.ids.length }));
    } else { searchTurn(q, carried); }
    blurPhone();
  }
  function blurPhone() { if (isPhone()) { INPUT.blur(); } }
  // The shopper's own recent searches, in this browser only: what they typed and searched, never a chip, a
  // suggested question, a link someone sent or a question to the assistant. The same words once, the latest first.
  function remember(q) {
    var key = q.toLowerCase();
    S.recent = [q].concat(S.recent.filter(function (x) { return String(x).toLowerCase() !== key; })).slice(0, RECENT_MAX);
    store('local', 'ls_searches', S.recent);
  }
  function addTurn(turn) {
    turn.id = turn.id || uid();
    // once something is asked or searched, "about this product" steps back with the conversation before it
    // (as Aviad asked, 2026-10-18): the new answer stands alone, the product's block waits behind the line
    if (turn.kind !== 'about' && turn.kind !== 'note') { S.turns.forEach(function (x) { if (x.kind === 'about' && !x.prev) { x.prev = true; } }); }
    S.turns.push(turn);
    fresh[turn.id] = 1;
    // the last search for products sent is what the next opening shows (open(), backTo()); after a question, nothing
    if ((turn.kind === 'results' || turn.kind === 'answer') && turn.q) { keepBack(turn.kind === 'results' ? turn.q : null, turn.id); }
    persist(); render();
    return turn;
  }
  function update(turn, patch) {
    Object.assign(turn, patch);
    fresh[turn.id] = 1;
    persist(); render();
  }

  // ---------------------------------------------------------------- search turn
  function searchTurn(q, carried) {
    var turn = addTurn({ kind: 'results', q: q, loading: true, c: carried || blankC() });
    announce(t('announceSearching'));
    loadIndex().then(function () {
      // the server counts this search, unless this tab already counted it (counted=1)
      var spelling = S.spellingNext; S.spellingNext = false;
      return json(API + '/search/' + SITE + '?q=' + encodeURIComponent(q) + (isCounted(norm(q)) ? '&counted=1' : '') + (spelling ? '&spelling=1' : '') + visitQuery());
    }).then(function (r) {
      markCounted(norm(q)); rememberSearch(q);
      return r;
    }).then(function (r) {
      var d = r.body || {};
      var ids = (d.ids || []).filter(function (id) { return /^p:/.test(id); });
      if (!ids.length) { ids = ((d.groups && d.groups.product) || []).map(function (p) { return p.id; }); }
      var single = d.single && (d.single.external_id || d.single.id) ? pid(d.single.external_id || d.single.id) : null;
      // the words' own asks ("הכי זול", "במבצע") are read with the tags: chosen, marked, and can be taken off
      Object.assign(turn, { ids: ids, actions: d.actions || [] });
      readTags(turn);
      return products(ids.slice(0, 8)).then(function () {
        update(turn, { loading: false, ids: ids, total: d.total || ids.length, unknown: d.unknown || null, held: typeof d.held === 'number' ? d.held : null, focus: d.focus || null, single: single, content: ((d.groups && d.groups.content) || []).slice(0, 3), cats: ((d.groups && d.groups.category) || []).slice(0, 3) });
        announce(t('announceResults', { n: ids.length }));
      });
    }).catch(function () { update(turn, { loading: false, error: true }); });
  }
  function filtered(turn) {
    var c = C(turn), out = (turn.ids || []).map(rec).filter(Boolean);
    var fcodes = codesOn(c);
    if (fcodes.length) {
      // within one attribute any value matches; across attributes all must. A value read from the words also matches a
      // product the attribute says nothing about (or only something broader, "זהב" for "זהב צהוב") whose own words say
      // it plainly; a product with a value of its own is what it carries: a pendant in "תליונים זהב צהוב" is not in
      // "עגילים זהב צהוב" however its words read ("עגול" is not "עגיל").
      var groups = {};
      fcodes.forEach(function (code) { var g = Math.floor(code / 1000); (groups[g] = groups[g] || []).push(code); });
      // colour and size, read from the variants, are asked of one variant together (and of one in stock when
      // stock is asked): black in M sold out, but black in L and navy in M there, is not "black, M, in stock"
      var vgs = Object.keys(groups).filter(variantFacet), stock = onStock(c);
      var holds = function (r, code) {
        var f = r.f || [], g = Math.floor(code / 1000);
        if (f.indexOf(code) >= 0) { return true; }
        var x = c.auto[code] && !c.f[code] && valueOf(code);
        if (!x || !x.value) { return false; }
        var asked = wordsOf(x.value.l);
        var own = f.filter(function (o) { return Math.floor(o / 1000) === g; }).some(function (o) {
          var v = valueOf(o);
          return !v || !v.value || !wordsOf(v.value.l).every(function (w) { return asked.indexOf(w) >= 0; });
        });
        return !own && plainlySays(r.title + ' ' + (r.kw || ''), x.value.l);
      };
      // Values of one attribute read together from the words are asked together: "טבעת טופז עם יהלומים" is a ring
      // with both stones, not either. Values the shopper picks are any of them; and when nothing holds all that was
      // read, any of them, so reading never empties the results.
      var together = Object.keys(groups).filter(function (g) { return vgs.indexOf(g) < 0 && groups[g].length > 1 && groups[g].every(function (code) { return c.auto[code] && !c.f[code]; }); });
      var pass = function (all) {
        return out.filter(function (r) {
          if (vgs.length && r.v && !r.v.some(function (x) { return (!stock || x[1] === 1) && vgs.every(function (g) { return groups[g].some(function (code) { return x[0].indexOf(code) >= 0; }); }); })) { return false; }
          return Object.keys(groups).every(function (g) {
            if (r.v && vgs.indexOf(g) >= 0) { return true; }
            return all && together.indexOf(g) >= 0 ? groups[g].every(function (code) { return holds(r, code); }) : groups[g].some(function (code) { return holds(r, code); });
          });
        });
      };
      var strict = together.length ? pass(true) : [];
      out = strict.length ? strict : pass(false);
    }
    if (onStock(c)) { out = out.filter(function (r) { return r.s === 1; }); }
    if (onSale(c)) { out = out.filter(function (r) { return r.sl === 1; }); }
    var so = sortOf(c);
    if (so === 'asc' || so === 'desc') { out = out.slice().sort(function (a, b) { return ((a.pr || 0) - (b.pr || 0)) * (so === 'asc' ? 1 : -1); }); }
    else if (so === 'new') { out = out.slice().sort(function (a, b) { return (b.d || 0) - (a.d || 0); }); }
    else { out = out.slice().sort(function (a, b) { return (b.s === 0 ? 0 : 1) - (a.s === 0 ? 0 : 1); }); }
    return out;
  }
  function facetChips(turn, max) {
    var c = C(turn), all = (turn.ids || []).map(rec).filter(Boolean), n = all.length, counts = {};
    all.forEach(function (r) { (r.f || []).forEach(function (code) { counts[code] = (counts[code] || 0) + 1; }); });
    codesOn(c).forEach(function (code) { if (!counts[code]) { counts[code] = 0; } });
    var typeAt = S.facets.map(function (f) { return f.k; }).indexOf('type');
    var codes = Object.keys(counts).map(Number).filter(function (code) {
      var facet = S.facets[Math.floor(code / 1000)], value = facet && facet.v[code % 1000];
      return value && (onCode(c, code) || (counts[code] < n && counts[code] >= (max ? 2 : 1))) && !/^(אחר|other)$/i.test(value.l);
    });
    // what the words come close to naming first ("ספרי מופת" puts "ספרי מופת מתורגמים" in the row), then the most held
    var near = {};
    codes.forEach(function (code) { near[code] = wordsSaid(turn.q || '', S.facets[Math.floor(code / 1000)].v[code % 1000].l).n; });
    codes.sort(function (a, b) { return (onCode(c, b) ? 1 : 0) - (onCode(c, a) ? 1 : 0) || near[b] - near[a] || (Math.floor(a / 1000) === typeAt) - (Math.floor(b / 1000) === typeAt) || counts[b] - counts[a]; });
    // one name once: "סלע מאיר" as a category and as a publisher is one chip, the first of them
    var named = {};
    codes = codes.filter(function (code) { var l = String(S.facets[Math.floor(code / 1000)].v[code % 1000].l).toLowerCase(); if (named[l] && !onCode(c, code)) { return false; } named[l] = true; return true; });
    return { codes: max ? codes.slice(0, max) : codes, counts: counts, n: n,
      stock: all.some(function (r) { return r.s === 0; }) && all.some(function (r) { return r.s === 1; }),
      sale: all.some(function (r) { return r.sl === 1; }) && all.some(function (r) { return r.sl !== 1; }) };
  }
  function activeFilters(c) { return codesOn(c).length + (onStock(c) ? 1 : 0) + (onSale(c) ? 1 : 0); }
  function chipRow(turn) {
    var fc = facetChips(turn, 4), c = C(turn), act = activeFilters(c);
    var chips = '<button class="ls-chip ls-chip--quiet' + (act ? ' is-on' : '') + '" data-filters="' + turn.id + '">' + icon('sliders') + esc(t('filter')) + (act ? '<span class="ls-filter-count">' + act + '</span>' : '') + '</button>';
    if (fc.stock || onStock(c)) { chips += chip(t('inStock'), onStock(c), 'stock', turn.id, c.autoStock && !c.stock && !!c.saidStock); }
    if (fc.sale || onSale(c)) { chips += chip(t('onSale'), onSale(c), 'sale', turn.id, c.autoSale && !c.sale); }
    fc.codes.forEach(function (code) { var f = S.facets[Math.floor(code / 1000)], v = f.v[code % 1000]; chips += chip(chipText(f, v), onCode(c, code), 'f:' + code, turn.id, c.auto[code] && !c.f[code], swatch(f, v)); });
    return '<div class="ls-chips ls-chips--bar">' + chips + '</div>' + activeRow(turn);
  }
  function chip(label, on, key, tid, auto, sw, count) { return '<button class="ls-chip' + (auto ? ' is-auto' : '') + '" aria-pressed="' + !!on + '" data-chip="' + esc(key) + '" data-turn="' + tid + '"' + (auto ? ' title="' + esc(t('detected')) + '"' : '') + '><span class="ls-chip__check">' + icon('check', 'ls-icon--sm') + '</span>' + (sw || '') + esc(label) + (count != null ? ' <span class="ls-chip__count ls-num">' + count + '</span>' : '') + '</button>'; }
  function sortLabel(s) { return s === 'asc' ? t('sortAsc') : s === 'desc' ? t('sortDesc') : s === 'new' ? t('sortNew') : t('sortRel'); }

  function card(r) {
    var id = pid(r.id), saved = S.saved.indexOf(id) >= 0;
    var badge = r.s === 0 ? t('outBadge') : r.sl === 1 ? t('onSaleBadge') : '';
    return '<article class="ls-card' + (r.s === 0 ? ' is-out' : '') + '" data-card="' + esc(id) + '">' +
      // the picture opens the product, as its name does
      '<div class="ls-card__media"><a class="ls-card__link" href="' + esc(r.url) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(r.img || '') + '" alt="">' + pic2(r) + '</a>' + (badge ? '<span class="ls-card__badge">' + esc(badge) + '</span>' : '') +
      '<span class="ls-card__heart">' + heartBtn(id, saved, true) + '</span></div>' +
      '<a class="ls-card__title" dir="auto" href="' + esc(r.url) + '">' + esc(decode(r.title)) + '</a>' +
      '<div class="ls-card__row">' + priceHtml(r) + '<button class="ls-card__similar" data-similar="' + esc(id) + '">' + esc(t('similar')) + '</button></div>' +
      buyBtn(r, 'ls-btn--sm ls-btn--block') + '<div data-after="' + esc(id) + '"></div></article>';
  }
  // the product's second picture (the index's i2), shown over the first on hover; nothing on touch
  function pic2(r) { return r && r.i2 ? '<img class="ls-pic2" src="' + esc(r.i2) + '" alt="" decoding="async" draggable="false">' : ''; }
  function heartBtn(id, on, float) { if (SHORT && SHORT.heartResults === false) { return ''; } return '<button class="ls-ibtn ls-ibtn--sm ls-heart' + (float ? ' ls-ibtn--float' : '') + '" aria-pressed="' + on + '" aria-label="' + esc(t('saved')) + '" data-heart="' + esc(id) + '">' + icon('heart', 'ls-icon--sm') + '</button>'; }
  function buyBtn(r, cls) {
    var id = pid(r.id);
    if (off('cart')) { return r.url ? '<a class="ls-btn ls-btn--secondary ' + cls + '" href="' + esc(r.url) + '">' + esc(t('orderPage')) + '</a>' : ''; }
    if (r.s === 0) { return '<button class="ls-btn ls-btn--secondary ' + cls + '" data-similar="' + esc(id) + '">' + esc(t('similarInStock')) + '</button>'; }
    if (r.buy !== 1) { return '<button class="ls-btn ls-btn--secondary ' + cls + '" data-options="' + esc(id) + '">' + esc(t('options')) + '</button>'; }
    return '<button class="ls-btn ls-btn--primary ' + cls + ' ls-add" data-add="' + esc(id) + '"><span class="ls-add__label">' + esc(t('add')) + '</span><span class="ls-add__busy">' + mark(true) + '</span><span class="ls-add__done"><svg class="ls-icon ls-icon--sm ls-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg>' + esc(t('added')) + '</span></button>';
  }
  function you(turn) {
    if (turn.kind === 'photo') { return '<div class="ls-you"><img class="ls-you__photo" src="' + esc(turn.src || '') + '" alt="' + esc(t('yourPhoto')) + '"></div>'; }
    if (turn.kind === 'similar' && turn.of) { return rec(turn.of) ? '<div class="ls-you ls-you--of">' + ofHero(turn.of, turn.size, turn.oos) + '</div>' : ''; }
    return turn.q ? '<div class="ls-you"><div class="ls-you__bubble" dir="auto">' + esc(turn.q) + '</div></div>' : '';
  }
  // the product the similar items are for: big enough to keep, compare against, or buy
  function ofHero(id, size, oos) {
    var r = rec(id);
    if (!r) { return ''; }
    var k = pid(r.id);
    return '<div class="ls-of"><a class="ls-of__media" href="' + esc(r.url) + '" tabindex="-1"><img src="' + esc(r.img || '') + '" alt=""></a>' +
      '<div class="ls-of__body"><div class="ls-of__label">' + esc(size ? sizeWord('size_similar_title', { size: size.shown }) : oos ? t('oosHead') : t('similarHead')) + '</div><a class="ls-of__title" dir="auto" href="' + esc(r.url) + '">' + esc(decode(r.title)) + '</a>' +
      '<div class="ls-of__price">' + priceHtml(r) + (r.s === 0 ? '<span class="ls-stock">' + esc(t('outStock')) + '</span>' : '') + '</div>' +
      '<div class="ls-of__actions">' + (r.s === 0 ? '' : buyBtn(r, 'ls-btn--sm')) + heartBtn(k, S.saved.indexOf(k) >= 0) + '</div><div data-after="' + esc(k) + '"></div></div></div>';
  }
  function thinking(text) { return '<div class="ls-think">' + mark(true) + '<span class="ls-think__text">' + esc(text || t('thinking')) + '</span></div>'; }
  function errorHtml(turn) {
    return '<div class="ls-error" role="alert"><span class="ls-error__icon">' + icon('x', 'ls-icon--sm') + '</span><div><div style="font-weight:500">' + esc(t('error')) + '</div><div style="font-size:14px;color:var(--ls-fg-muted);margin:2px 0 12px">' + esc(t('errorSub')) + '</div><button class="ls-btn ls-btn--secondary ls-btn--sm" data-retry="' + turn.id + '">' + icon('history', 'ls-icon--sm') + esc(t('retry')) + '</button></div></div>';
  }

  function turnHtml(turn) {
    var cls = 'ls-turn' + (fresh[turn.id] ? ' is-new' : '') + (turn.kind === 'about' ? ' ls-about-turn' : '');
    var inner = turn.kind === 'results' ? resultsHtml(turn) : turn.kind === 'answer' ? answerHtml(turn) : turn.kind === 'photo' ? photoHtml(turn)
      : turn.kind === 'similar' ? similarHtml(turn) : turn.kind === 'order' ? orderHtml(turn) : turn.kind === 'shared' ? '<div class="ls-order__title">' + esc(t('sharedList')) + '</div>' + (turn.loading ? thinking() : (turn.ids || []).length ? '<div class="ls-grid">' + turn.ids.map(rec).filter(Boolean).map(card).join('') + '</div>' : '<p class="ls-noanswer__text">' + esc(t('savedEmptyT')) + '</p>') : turn.kind === 'about' ? aboutHtml(turn) : turn.kind === 'note' ? '<p class="ls-noanswer__text">' + esc(turn.text) + '</p>' : '';
    return '<section class="' + cls + '" data-tid="' + turn.id + '">' + (turn.kind === 'about' || turn.kind === 'note' || turn.kind === 'order' || turn.kind === 'shared' ? '' : you(turn)) + inner + '</section>';
  }

  // What the results are, said before them, whenever the words did not decide them: a word the store has nothing close to
  // ("חולצה" in a jewellery store), no product holding the words ("מדע בדיוני" in a store without science fiction), or only
  // a few of many. The AI one press away: "תראה לי <the words>" goes to the set finder, which reads the catalogue for it.
  var WEAK_HELD = 3, WEAK_TOTAL = 8;
  function noteHtml(turn) {
    var E = S.engine, total = (turn.ids || []).length, held = typeof turn.held === 'number' ? turn.held : null;
    // the words the store has nothing close to: the server's, or the engine's own while typing
    var words = turn.unknown && turn.unknown.length ? turn.unknown : localUnknown(turn.q);
    var typed = [], kept = [];
    String(turn.q || '').replace(/[?؟!.,״"']/g, ' ').split(/\s+/).filter(Boolean).forEach(function (w) {
      if (E && E.normalize && words.indexOf(E.normalize(w)) >= 0) { typed.push(w); } else if (!STOP.test(w)) { kept.push(w); }
    });
    // "מה זה ספרי פנטזיה?": the books are shown, and it says so; "פנטזיה" is not on the site
    var text = typed.length && total && kept.length ? t('showingFor', { kept: kept.join(' '), gone: typed.join(' ') })
      : typed.length ? t('notHere', { w: typed.join(' ') })
      : held === 0 && total ? t('meaningOnly', { q: turn.q })
      : held !== null && held < WEAK_HELD && total >= WEAK_TOTAL ? t('meaningMostly', { n: held }) : '';
    if (!text) { return ''; }
    return '<div data-unknown>' + askCardHtml(turn.q, text) + '</div>';
  }
  // the words of a search the catalogue has nothing close to, as the server's unknownWords reads them
  function localUnknown(q) {
    var E = S.engine;
    if (!E || !E.closeWords || !E.normalize || !S.built) { return []; }
    var out = [];
    String(q || '').replace(/[?؟!.,״"']/g, ' ').split(/\s+/).forEach(function (w) {
      if (w.length < 3 || /\d/.test(w) || STOP.test(w)) { return; }
      var n = E.normalize(w), close = E.closeWords(S.built, n), top = 0;
      (close.words || []).forEach(function (x) { top = Math.max(top, close.score[x] || 0); });
      if (top < 0.75) { out.push(n); }
    });
    return out;
  }
  // Nothing found, or found only in part: the store's AI one press away, in the box the search box offers a question in
  // ("שאלו את … / the words"). It reads the whole catalogue for them ("תראה לי …": the set finder).
  function askCardHtml(q, text) {
    return (text ? '<p class="ls-note__text">' + esc(text) + '</p>' : '') +
      '<div class="ls-note"><button class="ls-ask" data-askcard data-ask="' + esc(t('askFindQ', { q: String(q || '').replace(/[?؟.!\s]+$/, '') })) + '"><span class="ls-ask__mark">' + mark() + '</span>' +
      '<span class="ls-ask__text"><span class="ls-ask__who">' + esc(t('askWho', { name: CFG.assistant })) + '</span><span class="ls-ask__q" dir="auto">' + esc(q) + '</span></span>' +
      '<span class="ls-kbd">' + icon('enter', 'ls-icon--sm') + '</span></button></div>';
  }
  function resultsHtml(turn) {
    if (turn.loading) { return thinking(); }
    if (turn.error) { return errorHtml(turn); }
    var list = filtered(turn), total = (turn.ids || []).length, html = '';
    if (turn.single && rec(turn.single)) {
      var s = rec(turn.single);
      html += '<div class="ls-exact"><a class="ls-pick__media" href="' + esc(s.url) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(s.img) + '" alt=""></a><div><div class="ls-exact__tag">' + icon('check', 'ls-icon--sm') + esc(s.sku ? t('exactSku', { sku: s.sku }) : t('exact')) + '</div><a class="ls-pick__title" dir="auto" href="' + esc(s.url) + '" style="color:inherit;text-decoration:none">' + esc(decode(s.title)) + '</a><div class="ls-pick__row">' + priceHtml(s) + buyBtn(s, 'ls-btn--sm') + '</div><div data-after="' + esc(pid(s.id)) + '"></div></div></div>';
    }
    if (!total) {
      if (turn.focus && turn.focus.options && turn.focus.options.length) {
        html += '<p class="ls-noanswer__text">' + esc(t('didYouMean', { q: turn.q })) + '</p><div class="ls-chips" style="margin-bottom:14px">' +
          turn.focus.options.map(function (o) { return '<button class="ls-chip ls-chip--quiet" data-q="' + esc(o.query) + '">' + esc(o.query) + ' <span class="ls-chip__count ls-num">(' + o.count + ')</span></button>'; }).join('') +
          (turn.focus.category ? '<button class="ls-chip ls-chip--quiet" data-q="' + esc(turn.focus.category.title) + '">' + esc(t('category', { t: turn.focus.category.title })) + '</button>' : '') + '</div>';
      } else {
        html += '<p class="ls-noanswer__text"><b>' + esc(t('noResults', { q: turn.q })) + '</b><br><span style="color:var(--ls-fg-muted)">' + esc(t('noResultsSub', { name: CFG.assistant })) + '</span></p>';
      }
      html += askCardHtml(turn.q);
      return html;
    }
    // a guide or a page comes before the products only for a sentence ("איך בוחרים טבעת אירוסין לפי תקציב"), when it
    // names it almost word for word (90% or more) and better than any product does; "טבעת זהב" keeps the products first
    var g0 = turn.content && turn.content[0], gm = g0 && meantWords(turn.q).length >= GUIDE_FIRST_WORDS ? matchOf(turn.q, g0.title, (S.records[g0.id] || {}).kw, 0) : 0;
    var guideFirst = gm >= GUIDE_FIRST && (!list.length || gm > matchOf(turn.q, decode(list[0].title), list[0].kw, 0));
    if (guideFirst) { html += guidesHtml(turn.content, turn.q); }
    html += noteHtml(turn);
    html += '<div class="ls-summary"><div class="ls-summary__count ls-num">' + esc(list.length === 1 ? t('oneProduct') : t('productsCount', { n: list.length })) + '</div>' +
      '<button class="ls-btn ls-btn--ghost ls-btn--sm" data-sort="' + turn.id + '">' + esc(sortLabel(sortOf(C(turn)))) + icon('down', 'ls-icon--sm') + '</button></div>';
    html += chipRow(turn);
    // a size asked for that no product here says: told, with the team one press away, never silently ignored
    if (turn.sizeUnknown) {
      html += '<div class="ls-note">' + icon('info') + '<span>' + esc(t('sizeUnknown')) + ' · <button class="ls-btn ls-btn--link" data-ask="' + esc(turn.q) + '">' + esc(t('askWho', { name: CFG.assistant })) + '</button></span></div>';
    }
    if (!list.length) { return html + '<div class="ls-empty-filter">' + esc(t('noFilterMatch')) + '<div style="margin-top:10px"><button class="ls-btn ls-btn--secondary ls-btn--sm" data-clear-filters="' + turn.id + '">' + esc(t('clearAll')) + '</button></div></div>'; }
    var n = gridN(turn);
    html += '<div class="ls-grid">' + list.slice(0, n).map(card).join('') + '</div>';
    if (list.length > n) { html += allBtn(turn, t('showAll', { n: list.length }), true); }
    if (!guideFirst) { html += guidesHtml(turn.content, turn.q); }
    // asked of the store's AI only over a few results (search.not_sure_max): a long list is narrowed by its filters first
    if (!off('ask') && list.length <= notSureMax()) html += '<button class="ls-row" data-opt style="margin-top:8px;color:var(--ls-fg-muted)" data-not-sure="' + esc(turn.id) + '"><span class="ls-mark" style="--s:14px;color:var(--ls-fg)"><i></i><i></i></span><span class="ls-row__text">' + esc(t('notSure', { name: CFG.assistant })) + '</span></button>' + (turn.aiOpen ? myAi(turn) : '');
    return html;
  }

  function wideView() {
    var turn = turnById(S.wide);
    if (!turn) { S.wide = null; return ''; }
    if (turn.kind === 'compare') { return compareHtml(turn); }
    // no "back to chat" here: the tab above and the search field lead back
    var back = '';
    var similar = turn.kind === 'similar';
    if (turn.loading) { return '<div class="ls-wide-head">' + back + (similar ? '<div style="margin-top:8px">' + ofHero(turn.of, turn.size, turn.oos) + '</div>' : '') + '</div><div style="padding:12px 0">' + thinking() + '</div>'; }
    if (turn.error) { return '<div class="ls-wide-head">' + back + '</div>' + errorHtml(turn); }
    var list = filtered(turn), shown = list.slice(0, fill(turn.limit || 24));
    var title = similar ? esc(t('productsCount', { n: list.length })) + ' <span>' + esc(t('similar')) + '</span>' : esc(t('productsCount', { n: list.length })) + ' <span>"' + esc(turn.q) + '"</span>';
    return '<div class="ls-wide-head">' + back + (similar ? '<div style="margin-top:8px">' + ofHero(turn.of, turn.size, turn.oos) + '</div>' + oosNote(turn) : '') +
      '<div class="ls-summary" style="margin-top:' + (similar ? 18 : 8) + 'px"><div class="ls-summary__count ls-num" style="font-size:20px;font-weight:500">' + title + '</div>' +
      '<button class="ls-btn ls-btn--ghost ls-btn--sm" data-sort="' + turn.id + '">' + esc(sortLabel(sortOf(C(turn)))) + icon('down', 'ls-icon--sm') + '</button></div>' + chipRow(turn) + '</div>' +
      (similar && !(turn.ids || []).length ? '<p class="ls-noanswer__text">' + esc(t('noSimilar')) + '</p>' : list.length ? '<div class="ls-grid">' + shown.map(card).join('') + '</div>' : '<div class="ls-empty-filter">' + esc(t('noFilterMatch')) + '</div>') +
      // no way out to the theme's own search page: the drawer's results are the search (as Aviad asked, 2026-10-21)
      (list.length > shown.length ? '<div class="ls-more"><button class="ls-btn ls-btn--secondary" data-loadmore="' + turn.id + '">' + esc(t('loadMore')) + '</button></div>' : '');
  }

