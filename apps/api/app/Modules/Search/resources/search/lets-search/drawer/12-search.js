  // ---------------------------------------------------------------- submitting
  // typed: the shopper wrote it (the box, the store's own search page), so it is one of their recent searches
  function submit(raw, kind, about, typed) {
    var q = String(raw || '').trim();
    if (!q) { return; }
    if (q === S.keptQ && kind !== 'ask' && !isQuestion(q) && S.turns.some(function (x) { return x.kind === 'results' && x.q === q && !x.prev; })) { return blurPhone(); }
    // what was being looked at live goes with the search: its filters, and its results when they're the server's
    var lv = S.live && S.live.q === q ? S.live : null;
    var carried = lv ? JSON.parse(JSON.stringify(lv.c)) : (S.pendingC || null);
    S.pendingC = null;
    INPUT.value = ''; FIELD.classList.remove('has-text'); hideLive();
    S.tab = 'chat'; S.wide = null;
    if (kind === 'ask' && off('ask') && !(about || S.askMode)) { kind = 'search'; }
    // a question the shopper typed is one of their recent searches too (QA, 16.11: "הדפס לימון", asked of the AI, was not kept)
    if (kind === 'ask' || (kind !== 'search' && (isQuestion(q) || aboutProduct(q) || repliesToChat(q)))) { S.keptQ = null; if (typed) { remember(q); } askTurn(q, null, about || S.askMode); return blurPhone(); }
    // a search stays in the field as it was typed: back in the field, the shopper goes on from it (as Aviad asked,
    // 2026-10-21). The same words again ask nothing: their results are already on screen.
    keepInField(q);
    if (typed) { remember(q); }
    // kept as it is only once the meaning pass, when one was coming, has answered: Enter before it asks the
    // server again, with the meaning, instead of showing "no results" and dropping what the meaning finds
    if (lv && lv.server && (lv.meant || !lv.willMean)) {
      addTurn({ kind: 'results', q: q, loading: false, ids: lv.ids, total: lv.total, price: lv.price || null, focus: lv.focus, single: lv.single, singleBy: lv.singleBy, content: lv.content, cats: lv.cats, actions: lv.actions, c: carried });
      // kept without asking the server again, so it is counted here (once per tab, as a pause would)
      countSearch(q, lv.ids.length);
      announce(t('announceResults', { n: lv.ids.length }));
    } else { searchTurn(q, carried); }
    blurPhone();
  }
  function blurPhone() { if (isPhone()) { INPUT.blur(); } }
  // A reply to the conversation on screen, typed in the main box: a question (its mark, or as the engine reads one), or a
  // sentence that goes on from what was said (opening with "ו": "ומה עם עגילי פנינים לאמא שלי? באותו תקציב"). It goes to the
  // AI with the conversation, never to a search of its words (QA, 16.11: its budget and its subject were lost)
  function repliesToChat(q) {
    q = String(q || '').trim();
    if (!q || !conversationGoingOn()) { return false; }
    var words = q.split(/\s+/);
    // ...in English too: "and the same in gold", "what about earrings for my mom"
    return isQuestion(q) || /[?؟]/.test(q) || (words.length >= 2 && (/^ו[֐-׿]{2,}/.test(words[0] || '') || /^(and|also|plus|what about|how about)\s/i.test(q)));
  }
  // the words of the search on screen, left in the field; typing on from them is a new search, as always
  function keepInField(q) {
    S.keptQ = q || null;
    INPUT.value = q || '';
    FIELD.classList.toggle('has-text', !!q);
  }
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
    // ...unless it is asked about that very product: its block stays, under the answer (QA, 16.11: "על המוצר הזה" went away
    // with the first question about it)
    if (turn.kind !== 'about' && turn.kind !== 'note') { S.turns.forEach(function (x) { if (x.kind === 'about' && !x.prev && !(turn.ctx && x.id === turn.ctx)) { x.prev = true; } }); }
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
    runSearch(addTurn({ kind: 'results', q: q, loading: true, c: carried || blankC() }), q);
  }
  // A filter read from the words, taken off, takes its words with it (as Aviad asked, 2026-10-21): the search goes on
  // with the words left, in the same place, with the filters the shopper chose and the ones they took off; the field
  // says the same words. Nothing left: the search is closed, and the drawer is ready for a new one.
  function dropWords(turn, places) {
    searchAgainWith(turn, wordsOf(turn.q).filter(function (w, i) { return places.indexOf(i) < 0; }).join(' '));
  }
  // the search again with these words, in the same place (a filter taken off with its words: a word, a price)
  function searchAgainWith(turn, q) {
    q = String(q || '').trim();
    if (turn === S.live) { INPUT.value = q; onType(); return; }
    var inField = INPUT.value.trim() === String(turn.q || '').trim();
    if (!q) {
      S.turns = S.turns.filter(function (x) { return x !== turn; });
      if (S.wide === turn.id) { S.wide = null; }
      store('local', 'ls_back', null);
      if (inField) { keepInField(''); }
      persist(); render();
      return;
    }
    var c = C(turn);
    turn.c = Object.assign(blankC(), { f: c.f, no: c.no, sort: c.sort, stock: c.stock, sale: c.sale });
    Object.assign(turn, { q: q, loading: true, ids: [], more: null, limit: S.wide === turn.id ? STEP : null, single: null, focus: null, content: [], cats: [], price: null });
    if (inField) { keepInField(q); }
    keepBack(q, turn.id, S.wide === turn.id);
    update(turn, {});
    runSearch(turn, q);
  }
  // the products of a search answer, in its order
  // a search of whom alone, where the store files whom its products are for: all it files for them, the found first; null
  // when a word of it says more than whom, or the store files no more of them than were found
  function whomAlone(q, found) {
    var g = (S.facets || []).map(function (f) { return f.k; }).indexOf('audience'), E = S.engine;
    var words = meantWords(q || '');
    if (g < 0 || !words.length || !S.records) { return null; }
    var codes = [];
    for (var i = 0; i < words.length; i++) {
      var whom = whomOf(E && E.normalize ? E.normalize(words[i]) : words[i]), at = whom ? (S.facets[g].v || []).map(function (v) { return v.k; }).indexOf(whom) : -1;
      if (at < 0) { return null; }
      if (codes.indexOf(g * 1000 + at) < 0) { codes.push(g * 1000 + at); }
    }
    var seen = {}, out = [];
    (found || []).forEach(function (id) { seen[id] = 1; out.push(id); });
    Object.keys(S.records).forEach(function (k) {
      var r = S.records[k];
      if (!seen[k] && r && r.t === 'product' && codes.some(function (code) { return (r.f || []).indexOf(code) >= 0; })) { out.push(k); }
    });
    return out.length > (found || []).length ? out : null;
  }
  function idsOf(d) {
    var ids = (d.ids || []).filter(function (id) { return /^p:/.test(id); });
    return ids.length ? ids : ((d.groups && d.groups.product) || []).map(function (p) { return p.id; });
  }
  function runSearch(turn, q) {
    announce(t('announceSearching'));
    // the server asked while the store's index loads, not after it: the first search after the drawer opens waited for
    // the whole index first (Rocks, 16.11: ~1s more). The results are read once both are in.
    var spelling = S.spellingNext; S.spellingNext = false;
    // the server counts this search, unless this tab already counted it (counted=1)
    var asked = json(API + '/search/' + SITE + '?q=' + encodeURIComponent(q) + (isCounted(norm(q)) ? '&counted=1' : '') + (spelling ? '&spelling=1' : '') + visitQuery());
    asked.catch(function () { /* read below, with the index */ });
    loadIndex().then(function () {
      return asked;
    }).then(function (r) {
      markCounted(norm(q)); rememberSearch(q);
      return r;
    }).then(function (r) {
      var d = r.body || {}, ids = idsOf(d);
      // the words' own asks ("הכי זול", "במבצע") are read with the tags: chosen, marked, and can be taken off
      Object.assign(turn, { ids: ids, actions: d.actions || [], kept: typeof d.query === 'string' ? d.query + ' ' + ((d.loosened && d.loosened.left) || []).join(' ') : null });
      readTags(turn);
      // whom alone ("נשים", "לילדים"): every product the store files for them, those its words found first. Miss Nori, 17.11:
      // "נשים" showed the one product whose text says the word, of 262 filed for women
      var whole = whomAlone(turn.q, ids);
      if (whole) { ids = whole; d = Object.assign({}, d, { total: whole.length }); Object.assign(turn, { ids: ids }); readTags(turn); return { d: d, ids: ids }; }
      // "ספרים על חטופים": "ספרים" is the filter "ספר", so the search is for "על חטופים", filtered to books, not every
      // book there is (as Aviad asked, 2026-10-21). Asked once more, counted once; when the rest finds nothing, the
      // whole words stand.
      var rest = restWords(turn);
      if (!rest) { return { d: d, ids: ids }; }
      return json(API + '/search/' + SITE + '?q=' + encodeURIComponent(rest) + '&counted=1' + visitQuery()).then(function (r2) {
        var d2 = r2.body || {}, ids2 = idsOf(d2);
        if (!ids2.length) { return { d: d, ids: ids }; }
        Object.assign(turn, { ids: ids2, words: rest });
        readTags(turn);
        return { d: d2, ids: ids2 };
      }, function () { return { d: d, ids: ids }; });
    }).then(function (got) {
      var d = got.d, ids = got.ids;
      var single = d.single && (d.single.external_id || d.single.id) ? pid(d.single.external_id || d.single.id) : null;
      return products(ids.slice(0, 8)).then(function () {
        update(turn, { loading: false, ids: ids, total: d.total || ids.length, unknown: d.unknown || null, held: typeof d.held === 'number' ? d.held : null, focus: d.focus || null, loosened: d.loosened || null, near: d.near || null, price: d.price || null, single: single, singleBy: single ? d.single.by : null, content: ((d.groups && d.groups.content) || []).slice(0, contentShown(ids.length)), cats: ((d.groups && d.groups.category) || []).slice(0, 3) });
        announce(t('announceResults', { n: ids.length }));
      });
    }).catch(function () { update(turn, { loading: false, error: true }); });
  }
  // 1 for a product whose name says a word of the search, in any of its forms ("נעלי" of "נעליים", "sandal" of "sandals"),
  // else 0; all 0 when nothing was typed that means something
  function namesSearch(turn) {
    var E = S.engine, st = function (w) { try { return E && E.stem && E.normalize ? E.stem(E.normalize(w)) : w; } catch (e) { return w; } };
    var asked = [];
    meantWords(turn.words || turn.q || '').forEach(function (w) { wordForms(w).forEach(function (f) { asked.push(st(f)); }); });
    if (!asked.length) { return function () { return 0; }; }
    return function (r) { return wordsOf(decode(r.title || '')).some(function (w) { return wordForms(w).some(function (f) { return asked.indexOf(st(f)) >= 0; }); }) ? 1 : 0; };
  }
  // search.evidence_filter, as the index says it: the filters read from the words go by how each value was read
  function byEvidence() { return !!(S.config && S.config.evidence); }
  // how sure a product holds a filter's value (the server's Evidence::of): its title says it, or it is filed with no weak
  // reading kept: 85; a weak reading: its kept score; a check found it wrong: 0; its words alone: 30
  function evidenceOf(r, code) {
    var v = valueOf(code);
    if (v && v.value && plainlySays(decode(r.title || ''), v.value.l)) { return 85; }
    if ((r.no || []).indexOf(code) >= 0) { return 0; }
    if (r.ev && r.ev[code] != null) { return Number(r.ev[code]); }
    return (r.f || []).indexOf(code) >= 0 ? 85 : 30;
  }
  // the products held in the filters read from the words, those held surely first; in stock stays first, and within each
  // the order they came in. One word read as a value of two filters is sure when either is.
  function surestFirst(list, codes) {
    if (!codes.length) { return list; }
    var asked = {};
    codes.forEach(function (code) { var v = valueOf(code), k = v && v.value ? String(v.value.l).toLowerCase() : String(code); (asked[k] = asked[k] || []).push(code); });
    var tier = function (r) {
      var least = 100;
      Object.keys(asked).forEach(function (k) { var best = 0; asked[k].forEach(function (code) { best = Math.max(best, evidenceOf(r, code)); }); least = Math.min(least, best); });
      return least >= 80 ? 2 : least >= 50 ? 1 : 0;
    };
    return list.map(function (r, at) { return { r: r, s: r.s === 1 ? 1 : 0, t: tier(r), at: at }; })
      .sort(function (a, b) { return (b.s - a.s) || (b.t - a.t) || (a.at - b.at); }).map(function (x) { return x.r; });
  }
  // how wholly a product is of the colour asked, by its picture's main colours (mc): that colour alone 2, first of two 1. A
  // variant filed "black" whose picture is burgundy (Authentic, "Black/Red") passes the filter, and comes after the black
  // shoes (VANS QA, 17.11)
  function shadeOf(c) {
    var E = S.engine, norm = function (w) { return E && E.normalize ? E.normalize(String(w)) : String(w).toLowerCase(); };
    var colours = codesOn(c).filter(function (code) { var f = S.facets[Math.floor(code / 1000)]; return f && /colou?r/.test(String(f.k)); })
      .map(function (code) { var v = valueOf(code); return v && v.value ? norm(v.value.l) : null; }).filter(Boolean);
    if (!colours.length) { return function () { return 0; }; }
    return function (r) {
      var mc = (r.mc || []).map(norm);
      return !mc.length ? 0 : mc.every(function (m) { return colours.indexOf(m) >= 0; }) ? 2 : colours.indexOf(mc[0]) >= 0 ? 1 : 0;
    };
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
        // where the shop asks it (search.evidence_filter): a value a check found wrong for it is held only by its own title,
        // never by its other words (the server's notRefused; Rocks QA, 17.11: studs under "חישוקים" by their picture's words)
        if (byEvidence() && (r.no || []).indexOf(code) >= 0) { var refused = valueOf(code); if (!(refused && refused.value && plainlySays(decode(r.title || ''), refused.value.l))) { return false; } }
        // a kind of the value is the value, chosen or read: "זהב" holds a ring in "זהב צהוב" (under)
        var me = valueOf(code);
        if (me && me.value && f.some(function (o) { var v = o !== code && Math.floor(o / 1000) === g && valueOf(o); return !!(v && v.value && under(v.value.l, me.value.l)); })) { return true; }
        var x = c.auto[code] && !c.f[code] && valueOf(code);
        if (!x || !x.value) { return false; }
        var asked = wordsOf(x.value.l);
        // the store's tags are many to a product, none of them what it is instead of another: a sandal tagged "APMA
        // Accepted" and not "Sandals" is still a sandal by its name or its picture (Naot QA, 16.11), never by a word of its
        // description ("spring ring clasp" on a necklace is no "Ring": Jean Rachel)
        // (its words whole only where no picture was read)
        if (andGroup(g)) { var seen = tgWords(r); return plainlySays(decode(r.title || '') + ' ' + (seen || (f.some(function (o) { return Math.floor(o / 1000) === g; }) ? '' : r.kw || '')), x.value.l); }
        var own = f.filter(function (o) { return Math.floor(o / 1000) === g; }).some(function (o) {
          var v = valueOf(o);
          return !v || !v.value || !wordsOf(v.value.l).every(function (w) { return asked.indexOf(w) >= 0; });
        });
        // its own name saying it plainly is it, whatever else the attribute lists: "טבעת דיאנה משובצת יהלומים ואמרלד" is a
        // ring with diamonds though its stone filter names the emerald alone (Rocks, 26.10: 4 of the store's rings left)
        return plainlySays(decode(r.title || ''), x.value.l) || (!own && plainlySays(r.title + ' ' + (r.kw || ''), x.value.l));
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
            return (all && together.indexOf(g) >= 0) || andGroup(g) ? groups[g].every(function (code) { return holds(r, code); }) : groups[g].some(function (code) { return holds(r, code); });
          });
        });
      };
      var strict = together.length ? pass(true) : [];
      out = strict.length ? strict : pass(false);
      if (byEvidence()) { out = surestFirst(out, fcodes.filter(function (code) { return c.auto[code] && !c.f[code]; })); }
    }
    // the words read as chips: what says each of them
    // a typed word's chip gives way to the store's word chosen in its place
    var instead = givenWay(c);
    var ws = Object.keys(c.words || {}).filter(function (k) { return !c.words[k].shown && !instead[k]; });
    if (ws.length) { out = out.filter(function (r) { return ws.every(function (k) { return holdsChip(r, c.words[k]) !== !!c.words[k].not; }); }); }
    // the store's words a shopper chose from what was offered ("מדע בדיוני" beside "פנטזיה")
    var picked = Object.keys(c.picked || {});
    if (picked.length) { out = out.filter(function (r) { return picked.every(function (k) { return holdsChip(r, c.picked[k]); }); }); }
    if (onStock(c)) { out = out.filter(function (r) { return r.s === 1; }); }
    if (onSale(c)) { out = out.filter(function (r) { return r.sl === 1; }); }
    var so = sortOf(c);
    // by price, the products whose names say the search first: "נעליים" cheapest first is the cheapest shoes, not a pair of
    // socks whose picture's words say "גרבי נעל" (Vans QA, 16.11); then the rest, by price too
    if (so === 'asc' || so === 'desc') {
      var said = namesSearch(turn);
      out = out.map(function (r) { return { r: r, n: said(r) }; }).sort(function (a, b) { return (b.n - a.n) || ((a.r.pr || 0) - (b.r.pr || 0)) * (so === 'asc' ? 1 : -1); }).map(function (x) { return x.r; });
    }
    else if (so === 'new') { out = out.slice().sort(function (a, b) { return (b.d || 0) - (a.d || 0); }); }
    else {
      // a colour chosen or read ("black sandals"): what comes in that colour alone first, then what is it with another
      // ("Oily Coal Combo", black and grey: Naot QA, 16.11), each in the search's order
      // and a tag read from the words ("Sandals") first on what its own name or picture says it is: a clog the store files
      // under "Men > Sandals" after the sandals (Naot QA, 16.11: "Fiord")
      // ...and before both, what its own name says the search is: black flip-flops after the black shoes (VANS QA, 17.11), where
      // some names say it
      var pure = onlyColour(c), kind = seenAs(c), shade = shadeOf(c), says = namesSearch(turn);
      if (!out.some(function (r) { return says(r); })) { says = function () { return 0; }; }
      out = out.slice().sort(function (a, b) { return (b.s === 0 ? 0 : 1) - (a.s === 0 ? 0 : 1) || says(b) - says(a) || pure(b) - pure(a) || shade(b) - shade(a) || kind(b) - kind(a); });
    }
    // two colours of one model under one name ("Kayla" and "Kayla"): one card, its colours its dots; nothing told them
    // apart (Naot QA, 15.11). Under names of their own ("Julius - BA6 Soft Black") each keeps its card.
    var named = {};
    out = out.filter(function (r) {
      if (!r.cf) { return true; }
      // one model the store links or names (its family marked "s" or "m"): one card, the best of it for the search, the
      // rest its dots (as Aviad asked, 16.11)
      var k = /^[sm]/.test(r.cf) ? r.cf : r.cf + '|' + String(r.title || '').trim().toLowerCase();
      if (named[k]) { return false; }
      named[k] = 1;
      return true;
    });
    return out;
  }
  // 1 for a product one of whose versions (in stock, when stock is asked) is only the colours chosen; a product with no
  // versions, by its own colours; else 0. All 1 when no colour is chosen.
  function onlyColour(c) {
    var g = (S.facets || []).map(function (f) { return f.k; }).indexOf('variant_color');
    var on = g < 0 ? [] : codesOn(c).filter(function (code) { return Math.floor(code / 1000) === g; });
    if (!on.length) { return function () { return 1; }; }
    var stock = onStock(c), mine = function (codes) { var cs = (codes || []).filter(function (x) { return Math.floor(x / 1000) === g; }); return cs.length > 0 && cs.every(function (x) { return on.indexOf(x) >= 0; }); };
    return function (r) {
      if (r.v && r.v.length) { return r.v.some(function (x) { return (!stock || x[1] === 1) && mine(x[0]); }) ? 1 : 0; }
      return mine(r.f) ? 1 : 0;
    };
  }
  // what its picture says it is: the index's tags by meaning, their words
  function tgWords(r) { return Array.isArray(r.tg) ? r.tg.map(function (t) { return Array.isArray(t) ? t[0] : t; }).join(' ') : String(r.tg || ''); }
  // 1 for a product whose title or picture's words (tg) say every store tag read from the search's words; all 1 when none
  function seenAs(c) {
    var labels = Object.keys(c.auto || {}).map(Number).filter(function (code) { return !c.f[code] && andGroup(Math.floor(code / 1000)); }).map(function (code) { var x = valueOf(code); return x && x.value ? x.value.l : null; }).filter(Boolean);
    if (!labels.length) { return function () { return 1; }; }
    return function (r) {
      if (r._seenAs === undefined || r._seenAsOf !== labels.join('|')) {
        var own = decode(r.title || '') + ' ' + tgWords(r);
        r._seenAsOf = labels.join('|');
        r._seenAs = labels.every(function (l) { return plainlySays(own, l); }) ? 1 : 0;
      }
      return r._seenAs;
    };
  }
  // The store's tags narrow together: "Organic" and "Sterling Silver" is what is both, each one more chosen fewer, as the
  // counts beside them say (Jean Rachel QA, 16.11: with Organic on, the other tags still counted the whole search). A
  // colour, a size, a category is one of its values: any of them.
  function andGroup(g) { var f = S.facets && S.facets[g]; return !!(f && f.k === 'store_tags'); }
  // The articles beside the results, narrowed by the filters on that articles carry too (a tag a post was given, the
  // index's f of a content record): "10 to 14 days" keeps the guides about such trips. A filter no article carries (a
  // price, a colour) narrows the products only, and leaves the articles as they were.
  var carriedOf = null, carried = null;
  function guidesFor(turn) {
    var list = turn.content || [], on = codesOn(C(turn));
    if (!list.length || !on.length) { return list; }
    if (carriedOf !== S.records) {
      carried = {}; carriedOf = S.records;
      Object.keys(S.records || {}).forEach(function (id) { var r = S.records[id]; if (r && r.t === 'content') { (r.f || []).forEach(function (code) { carried[Math.floor(code / 1000)] = true; }); } });
    }
    var groups = {};
    on.forEach(function (code) { var g = Math.floor(code / 1000); if (carried[g]) { (groups[g] = groups[g] || []).push(code); } });
    var gs = Object.keys(groups);
    if (!gs.length) { return list; }
    return list.filter(function (x) {
      var f = (S.records[x.id] || {}).f || [];
      return gs.every(function (g) { return groups[g].some(function (code) { return f.indexOf(code) >= 0; }); });
    });
  }
  // How many products each value would show, chosen with everything else that is on: its own attribute's choices give
  // way to it (within one attribute any value is enough), the others, the words' chips, stock and sale stay. A value no
  // product left carries shows 0 and is not offered: a filter never leads to an empty page (as Aviad asked, 11.11:
  // "מגן ראש" with size "6-12 (6)" beside it showed no product at all). Read once per state of the turn's choices.
  function wouldCounts(turn, all) {
    var c = C(turn);
    var key = all.length + '|' + (turn.ids || [])[0] + '|' + JSON.stringify([c.f, c.auto, Object.keys(c.words || {}), Object.keys(c.picked || {}), onStock(c), onSale(c)]);
    if (turn._would && turn._would.key === key) { return turn._would; }
    var seen = {};
    all.forEach(function (r) { (r.f || []).forEach(function (code) { seen[code] = 1; }); });
    codesOn(c).forEach(function (code) { seen[code] = 1; });
    var f0 = c.f, a0 = c.auto, s0 = c.stock, sl0 = c.sale, counts = {}, now = filtered(turn).length, stock = 0, sale = 0;
    var without = function (o, g) { var x = {}; Object.keys(o || {}).forEach(function (k) { if (Math.floor(k / 1000) !== g) { x[k] = o[k]; } }); return x; };
    try {
      Object.keys(seen).map(Number).forEach(function (code) {
        // a value read from the words is counted as it is applied, by its words too ("Women's" on, 9 shown, counted 8
        // beside it: Jean Rachel QA, 16.11); a tag with the others chosen, within what they show
        var g = Math.floor(code / 1000), and = andGroup(g);
        c.f = and ? Object.assign({}, f0) : without(f0, g); c.auto = and ? Object.assign({}, a0) : without(a0, g);
        if (a0[code] && !f0[code]) { c.auto[code] = true; } else { c.f[code] = true; }
        counts[code] = filtered(turn).length;
      });
      c.f = f0; c.auto = a0;
      c.stock = true; stock = filtered(turn).length; c.stock = s0;
      c.sale = true; sale = filtered(turn).length;
    } finally { c.f = f0; c.auto = a0; c.stock = s0; c.sale = sl0; }
    turn._would = { key: key, counts: counts, now: now, stock: stock, sale: sale };
    return turn._would;
  }
  function facetChips(turn, max) {
    var c = C(turn), all = (turn.ids || []).map(rec).filter(Boolean), n = all.length;
    var would = wouldCounts(turn, all), counts = would.counts, now = would.now, held = {};
    // the order is by what the search found, never by what is chosen: a press changes the numbers, nothing moves
    all.forEach(function (r) { (r.f || []).forEach(function (code) { held[code] = (held[code] || 0) + 1; }); });
    var typeAt = S.facets.map(function (f) { return f.k; }).indexOf('type');
    // offered: what some product left carries, and what narrows (a value every product shown carries changes nothing),
    // unless its attribute has a choice already, where it is another of them
    var chosenIn = {};
    codesOn(c).forEach(function (code) { chosenIn[Math.floor(code / 1000)] = true; });
    // In the filter sheet (max 0) every value some result carries stays where it is, one that would show nothing greyed
    // with its 0: a press never brings a new tag in under the finger, nor takes one away (Jean Rachel QA, 16.11: "Organic"
    // pressed brought "STERLING SILVER" in under it). The row over the results offers only what narrows.
    var codes = Object.keys(counts).map(Number).filter(function (code) {
      var g = Math.floor(code / 1000), facet = S.facets[g], value = facet && facet.v[code % 1000];
      if (!value || /^(אחר|other)$/i.test(value.l)) { return false; }
      if (onCode(c, code)) { return true; }
      if (!max) { return (held[code] || 0) > 0; }
      return counts[code] >= 2 && ((chosenIn[g] && !andGroup(g)) || counts[code] < now);
    });
    // what the words come close to naming first ("ספרי מופת" puts "ספרי מופת מתורגמים" in the row), then the most held.
    // A value chosen keeps its place, marked by its ✓ and its fill, never moved to the front (as Aviad asked, 11.11):
    // the order is the results', the same before and after a press.
    var near = {};
    codes.forEach(function (code) { near[code] = wordsSaid(turn.q || '', S.facets[Math.floor(code / 1000)].v[code % 1000].l).n; });
    // The row over the results (max) offers first what splits them most evenly: a size nearly every one comes in tells
    // little ("Size 48" first over men's sandals: QA, 16.11). The sheet keeps the most held first. Both read the results
    // the search found, never what is chosen, so nothing moves on a press.
    var split = function (code) { var h = held[code] || 0; return max ? Math.min(h, n - h) : h; };
    codes.sort(function (a, b) { return near[b] - near[a] || (Math.floor(a / 1000) === typeAt) - (Math.floor(b / 1000) === typeAt) || split(b) - split(a) || (held[b] || 0) - (held[a] || 0) || a - b; });
    // one name once: "סלע מאיר" as a category and as a publisher is one chip, the first of them
    var named = {};
    codes = codes.filter(function (code) { var l = String(S.facets[Math.floor(code / 1000)].v[code % 1000].l).toLowerCase(); if (named[l] && !onCode(c, code)) { return false; } named[l] = true; return true; });
    return { codes: max ? codes.slice(0, max) : codes, counts: counts, n: n,
      // "in stock" and "on sale" offered only where they leave something and narrow it
      stock: stockMeans() && would.stock > 0 && (onStock(c) || would.stock < now),
      sale: would.sale > 0 && (onSale(c) || would.sale < now) };
  }
  // the chips of typed words a store's word chosen in their place stands for
  function givenWay(c) {
    var out = {};
    Object.keys(c.picked || {}).forEach(function (k) { (c.picked[k].instead || []).forEach(function (w) { out[w] = 1; }); });
    return out;
  }
  function activeFilters(c) { return codesOn(c).length + Object.keys(c.words || {}).length + Object.keys(c.picked || {}).length + (onStock(c) ? 1 : 0) + (onSale(c) ? 1 : 0); }
  // The row over the results offers what more there is to choose; what is chosen, by the shopper or read from the words,
  // is shown once, in the row under it with its × (activeRow), never a second time here with a ✓ (as Aviad asked, 1.11)
  var OFFERED = 4;
  function chipRow(turn) {
    var fc = facetChips(turn, 99), c = C(turn), act = activeFilters(c) + (turn.price ? 1 : 0);
    var chips = '<button class="ls-chip ls-chip--quiet' + (act ? ' is-on' : '') + '" data-filters="' + turn.id + '">' + icon('sliders') + esc(t('filter')) + (act ? '<span class="ls-filter-count">' + act + '</span>' : '') + '</button>';
    if (fc.stock && !onStock(c)) { chips += chip(t('inStock'), false, 'stock', turn.id, false); }
    if (fc.sale && !onSale(c)) { chips += chip(t('onSale'), false, 'sale', turn.id, false); }
    sizesInOrder(fc.codes.filter(function (code) { return !onCode(c, code); }).slice(0, OFFERED)).forEach(function (code) { var f = S.facets[Math.floor(code / 1000)], v = f.v[code % 1000]; chips += chip(asWhole(f, v, chipText(f, v)), false, 'f:' + code, turn.id, false, swatch(f, v)); });
    // the store's words close to what was typed, offered to choose (a chosen one is in the row under it)
    Object.keys(c.suggest || {}).forEach(function (k) { if (!(c.picked || {})[k]) { chips += chip(capital(c.suggest[k].label), false, 'g:' + k, turn.id, false); } });
    return '<div class="ls-chips ls-chips--bar">' + chips + '</div>' + activeRow(turn);
  }
  // its count in brackets, smaller: "38 (41)", never "38 41" read as a range of sizes (Vans QA, 16.11)
  function chip(label, on, key, tid, auto, sw, count) { return '<button class="ls-chip' + (auto ? ' is-auto' : '') + '" aria-pressed="' + !!on + '"' + (count === 0 && !on ? ' disabled' : '') + ' data-chip="' + esc(key) + '" data-turn="' + tid + '"' + (auto ? ' title="' + esc(t('detected')) + '"' : '') + '><span class="ls-chip__check">' + icon('check', 'ls-icon--sm') + '</span>' + (sw || '') + esc(label) + (count != null ? ' <span class="ls-chip__count ls-num">(' + count + ')</span>' : '') + '</button>'; }
  function sortLabel(s) { return s === 'asc' ? t('sortAsc') : s === 'desc' ? t('sortDesc') : s === 'new' ? t('sortNew') : t('sortRel'); }

  // first: the product the search showed in this card, kept while a colour dot turns the card to another (its dots
  // stay in their order)
  function card(r, first) {
    var id = pid(r.id), saved = S.saved.indexOf(id) >= 0;
    var badge = r.s === 0 ? t('outBadge') : r.sl === 1 ? t('onSaleBadge') : '';
    // .map(card) hands it the index: only a product is a first
    first = first && typeof first === 'object' ? first : r;
    return '<article class="ls-card' + (r.s === 0 ? ' is-out' : '') + '" data-card="' + esc(id) + '"' + (first !== r ? ' data-first="' + esc(pid(first.id)) + '"' : '') + '>' +
      // the picture opens the product, as its name does
      '<div class="ls-card__media"><a class="ls-card__link" href="' + esc(r.url) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(sized(r.img, 480)) + '" alt="" loading="lazy" decoding="async">' + pic2(r) + '</a>' + (badge ? '<span class="ls-card__badge">' + esc(badge) + '</span>' : '') +
      '<span class="ls-card__heart">' + heartBtn(id, saved, true) + '</span></div>' +
      '<a class="ls-card__title" dir="auto" href="' + esc(r.url) + '">' + esc(decode(r.title)) + '</a>' + colourDots(r, first) +
      '<div class="ls-card__row">' + priceHtml(r) + (r.ns === 1 ? '' : '<button class="ls-card__similar" data-similar="' + esc(id) + '">' + esc(t('similar')) + '</button>') + '</div>' +
      buyBtn(r, 'ls-btn--sm ls-btn--block') + '<div data-after="' + esc(id) + '"></div></article>';
  }
  // The colours a product comes in, as dots under its name: one model the store sells as a product a colour (the index's
  // cf: "פלדיום מגפיים PAMPA HI" in beige and in brown), a dot each that turns the card to it; or one product's variants'
  // colours, a dot each. Only where there are two or more.
  var MC_HEX = { 'שחור': '#161616', 'לבנ': '#ffffff', 'אדומ': '#cf3a2e', 'כחול': '#2563c9', 'ירוק': '#2f8f4e', 'צהוב': '#f1c21b', 'אפור': '#8e8e94',
    'כתומ': '#ee7a1f', 'סגול': '#7b4bb7', 'ורוד': '#ec8fb3', 'כסופ': 'linear-gradient(135deg,#f4f4f5,#a6a6ac)', 'זהוב': 'linear-gradient(135deg,#f3d27a,#b4842a)',
    'שקופ': 'clear', 'חומ': '#7a4a28', 'בז': '#d9c4a0' };
  var DOTS_MOST = 30, families = null, familiesOf = null;
  function familyOf(r) {
    if (!r || !r.cf) { return null; }
    if (familiesOf !== S.records) {
      families = {}; familiesOf = S.records;
      Object.keys(S.records || {}).forEach(function (k) { var x = S.records[k]; if (x && x.cf) { (families[x.cf] = families[x.cf] || []).push(x); } });
    }
    return families[r.cf] || null;
  }
  function dot(sw, label, attrs) {
    return '<' + (attrs ? 'button' : 'span') + ' class="ls-dot' + (sw === 'clear' ? ' ls-dot--clear' : '') + '"' + (sw === 'clear' ? '' : ' style="--sw:' + esc(sw) + '"') +
      ' title="' + esc(label) + '" aria-label="' + esc(label) + '"' + (attrs || '') + '></' + (attrs ? 'button' : 'span') + '>';
  }
  // a swatch of a version's own picture, where no colour tells it apart
  function imgDot(img, label, attrs) {
    return '<button class="ls-dot ls-dot--img" style="--sw:url(&quot;' + esc(sized(img, 64)) + '&quot;)" title="' + esc(label) + '" aria-label="' + esc(label) + '"' + (attrs || '') + '></button>';
  }
  // the first colour the store's colour filter files a product in, with its swatch: {code, sw, label}, or null
  function filedSwatch(x) {
    var codes = x && x.f || [];
    for (var i = 0; i < codes.length; i++) {
      var f = S.facets[Math.floor(codes[i] / 1000)];
      if (f && /colou?r|צבע/i.test(String(f.k || '') + ' ' + String(f.l || ''))) {
        var v = f.v[codes[i] % 1000], sw = v && (v.h || SWATCH[v.l] || SWATCH[v.k]);
        if (sw) { return { code: codes[i], sw: sw, label: v.l }; }
      }
    }
    return null;
  }
  // a size filter: by its values' own order (35, 36, 37), never by count
  function sizeFacet(f) { return !!f && /size|מידה|מידות/i.test(String(f.k || '') + ' ' + String(f.l || '')); }
  // Two sizes in order: by the number they are (21, 21.5, 22), never by the order the store added them (21.5 after 47:
  // Vans QA, 16.11); a size that is no number ("M (38.5-42)", socks) after every number, in the store's own order.
  function sizeNumber(code) {
    var f = S.facets[Math.floor(code / 1000)], v = f && f.v[code % 1000], m = v && /^\s*(\d+(?:[.,]\d+)?)\s*$/.exec(String(v.l));
    return m ? parseFloat(m[1].replace(',', '.')) : null;
  }
  function bySize(a, b) {
    var x = sizeNumber(a), y = sizeNumber(b);
    if (x !== null && y !== null) { return x - y || a - b; }
    return x !== null ? -1 : y !== null ? 1 : a - b;
  }
  // the chips of a size filter in its own order, in the places they hold among the others
  function sizesInOrder(codes) {
    var places = {}, out = codes.slice();
    codes.forEach(function (code, i) { var g = Math.floor(code / 1000); if (sizeFacet(S.facets[g])) { (places[g] = places[g] || []).push(i); } });
    Object.keys(places).forEach(function (g) { var sorted = places[g].map(function (i) { return codes[i]; }).sort(bySize); places[g].forEach(function (i, k) { out[i] = sorted[k]; }); });
    return out;
  }
  // A version named by what tells it from its siblings, never the whole product name: "Buzz - WEK White Floral Rose Gold"
  // beside "Buzz - SLB Chestnut Copper Combo" is "WEK White Floral Rose Gold", "Julius - 739 Buffallo" is "739 Buffallo"
  // (Naot QA, 16.11). The words every sibling shares are the model's name; with nothing left, the name as it is.
  function versionLabel(x, fam) {
    var title = decode(x.title || ''), others = (fam || []).filter(function (o) { return o !== x; }).map(function (o) { return ' ' + decode(o.title || '').toLowerCase() + ' '; });
    if (!others.length) { return title; }
    var own = title.split(/\s+/).filter(function (w) { return w && !/^[-–|·,/]+$/.test(w) && others.some(function (o) { return o.indexOf(' ' + w.toLowerCase() + ' ') < 0; }); });
    return own.join(' ').replace(/^[-–|·,\s]+|[-–|·,\s]+$/g, '') || title;
  }
  function colourDots(r, first) {
    var out = [], seen = {}, fam = familyOf(r);
    if (fam && fam.length > 1) {
      // the colour the search showed first, then the others in stock, then those sold out; a dot pressed turns the
      // card and is marked where it is, never moved to the front (as Aviad asked, 11.11)
      var lead = first && familyOf(first) === fam ? first : r;
      [lead].concat(fam.filter(function (x) { return x !== lead && x.s !== 0; }), fam.filter(function (x) { return x !== lead && x.s === 0; })).forEach(function (x) {
        var c = x.mc && x.mc[0], sib = ' data-sib="' + esc(pid(x.id)) + '" aria-pressed="' + (x === r) + '"';
        if (c && MC_HEX[c]) {
          // read as two colours ("Port Royale/Black": burgundy canvas, black laces): both, the dot split between them, never
          // the first alone, a black dot on a burgundy shoe (Vans QA, 16.11); and two versions apart from a plain black one
          var c2 = x.mc[1] && x.mc[1] !== c && /^#/.test(MC_HEX[x.mc[1]] || '') && /^#/.test(MC_HEX[c]) ? x.mc[1] : null, key = c + (c2 ? '+' + c2 : '');
          var sw = c2 ? 'linear-gradient(135deg,' + MC_HEX[c] + ' 50%,' + MC_HEX[c2] + ' 50%)' : MC_HEX[c];
          // named in the store's language: its swatch's name, else, in a store not in Hebrew, its colour filter's ("כסוף" on an English store's dot: Naot QA, 16.11)
          if (!seen[key]) { seen[key] = 1; out.push(dot(sw, x.cl || (LANG === 'he' ? withFinals(c) + (c2 ? ' / ' + withFinals(c2) : '') : ((filedSwatch(x) || {}).label || versionLabel(x, fam))), sib)); }
          return;
        }
        // a colour no Hebrew word names ("Black", a store abroad): as the store's colour filter files it, with its swatch
        var filed = filedSwatch(x);
        if (filed && !seen['f' + filed.code]) { seen['f' + filed.code] = 1; out.push(dot(filed.sw, x.cl || filed.label, sib)); return; }
        // no colour to tell it by (a ring in amethyst, in citrine), or one its sibling already showed: its own picture,
        // named as the store's swatch names it ("Citrine"), so every version is a press away (Jean Rachel, 16.11)
        if (x.img && !seen['i' + x.id]) { seen['i' + x.id] = 1; out.push(imgDot(x.img, x.cl || versionLabel(x, fam), sib)); }
      });
    } else if (r && r.v) {
      var at = S.facets.map(function (f) { return f.k; }).indexOf('variant_color');
      if (at < 0) { return ''; }
      // a dot a colour it is sold in: one for each variant's colour, never two for one colour of two words ("Oily Coal
      // Combo" is one colour, not black and grey to choose between: Naot, 15.11)
      r.v.forEach(function (x) {
        var codes = (x[0] || []).filter(function (code) { return Math.floor(code / 1000) === at; }), key = codes.slice().sort().join(',');
        var v = codes.length ? S.facets[at].v[codes[0] % 1000] : null, sw = v && (v.h || SWATCH[v.l] || SWATCH[v.k]);
        if (sw && !seen[key]) { seen[key] = 1; out.push(dot(sw, v.l)); }
      });
    }
    if (out.length < 2) { return ''; }
    // every colour, in one row that scrolls sideways (never "+2" and a second line)
    return '<div class="ls-dots" role="group" aria-label="' + esc(t('colours')) + '">' + out.slice(0, DOTS_MOST).join('') + '</div>';
  }
  // A Shopify picture asked at the size it is shown (its CDN's width): a card is a few hundred pixels wide, the file the
  // store uploaded often thousands (QA, 16.11: 162 pictures, 2.5MB in one visit). Any other address as it is.
  function sized(url, w) {
    var u = String(url || '');
    if (!/^https?:\/\/(?:cdn\.shopify\.com\/|[^/]+\/cdn\/shop\/)/.test(u) || /[?&]width=\d+/.test(u)) { return u; }
    return u + (u.indexOf('?') >= 0 ? '&' : '?') + 'width=' + w;
  }
  // the product's second picture (the index's i2), shown over the first on hover; nothing on touch
  function pic2(r) { return r && r.i2 ? '<img class="ls-pic2" src="' + esc(sized(r.i2, 480)) + '" alt="" loading="lazy" decoding="async" draggable="false">' : ''; }
  function heartBtn(id, on, float) { if (SHORT && SHORT.heartResults === false) { return ''; } return '<button class="ls-ibtn ls-ibtn--sm ls-heart' + (float ? ' ls-ibtn--float' : '') + '" aria-pressed="' + on + '" aria-label="' + esc(t('saved')) + '" data-heart="' + esc(id) + '">' + icon('heart', 'ls-icon--sm') + '</button>'; }
  function buyBtn(r, cls) {
    var id = pid(r.id);
    // no cart: the product's page is the card's one action, as strong as "add to cart" where there is one
    // on a site that tells rather than sells (a tour, an experience): "Read more", as the store chose (search.card_action)
    if (off('cart')) { return r.url ? '<a class="ls-btn ls-btn--primary ' + cls + '" href="' + esc(r.url) + '">' + esc(t(S.config && S.config.cardAction === 'read_more' ? 'readMore' : 'orderPage')) + '</a>' : ''; }
    // sold out: its like in stock, or (none of its kind here) only that it is sold out
    if (r.s === 0) { return r.ns === 1 ? '<button class="ls-btn ls-btn--secondary ' + cls + '" disabled>' + esc(t('outStock')) + '</button>' : '<button class="ls-btn ls-btn--secondary ' + cls + '" data-similar="' + esc(id) + '">' + esc(t('similarInStock')) + '</button>'; }
    // added once, it says so wherever it shows: the results, the saved list, the similar items, an answer (QA, 16.11); a
    // press still adds another
    var added = !!inCart()[id];
    if (r.buy !== 1) { return '<button class="ls-btn ls-btn--secondary ' + cls + '" data-options="' + esc(id) + '">' + (added ? addedMark() + esc(t('added')) : esc(t('options'))) + '</button>'; }
    return '<button class="ls-btn ls-btn--primary ' + cls + ' ls-add" data-add="' + esc(id) + '"' + (added ? ' data-state="done"' : '') + '><span class="ls-add__label">' + esc(t('add')) + '</span><span class="ls-add__busy">' + mark(true) + '</span><span class="ls-add__done"><svg class="ls-icon ls-icon--sm ls-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg>' + esc(t('added')) + '</span></button>';
  }
  // "Similar" beside a product an answer shows (its picks, the row of products under it), as on a result's card: the
  // like of it a press away (as Aviad asked, 16.11). None for a product with no like of its kind (ns), nor one not in
  // the index; a store with similar items off hides it (.no-similar)
  function similarLink(r) { return r && r.id && rec(r.id) && r.ns !== 1 ? '<button class="ls-card__similar" type="button" data-similar="' + esc(pid(r.id)) + '">' + esc(t('similar')) + '</button>' : ''; }
  // the products added to the cart in this visit (this tab), by product
  function inCart() { if (!S.inCart) { S.inCart = store('session', 'ls_in_cart') || {}; } return S.inCart; }
  // drawn, as an added cart button's is: hidden, it took its place and "Added" stood off the button's centre (16.11)
  function addedMark() { return '<svg class="ls-icon ls-icon--sm ls-check ls-check--draw" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg>'; }
  function markAdded(id) {
    var k = String(pid(id));
    inCart()[k] = 1;
    store('session', 'ls_in_cart', S.inCart);
    // the same product's other buttons on screen now, without drawing anything again
    root.querySelectorAll('[data-add="' + k.replace(/["\\]/g, '') + '"]').forEach(function (b) { if (b.dataset.state !== 'busy') { b.dataset.state = 'done'; } });
    root.querySelectorAll('[data-options="' + k.replace(/["\\]/g, '') + '"]').forEach(function (b) { b.innerHTML = addedMark() + esc(t('added')); });
  }
  function you(turn) {
    if (turn.kind === 'photo') { return '<div class="ls-you"><img class="ls-you__photo" src="' + esc(turn.src || '') + '" alt="' + esc(t('yourPhoto')) + '"></div>'; }
    if (turn.kind === 'similar' && turn.of) { return rec(turn.of) ? '<div class="ls-you ls-you--of">' + ofHero(turn.of, turn.size, turn.oos) + '</div>' : ''; }
    return turn.q ? '<div class="ls-you"><div class="ls-you__bubble" dir="auto">' + esc(turn.shown || turn.q) + '</div></div>' : '';
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
  // what the results are is said above them; the card that asks the AI comes under the products, never between the words
  // and the products they searched for (QA, 16.11): turn.askBelow, read by resultsHtml
  function noteHtml(turn) {
    turn.askBelow = false;
    var note = noteText(turn);
    turn.askBelow = !!note && !turn.askShown;
    return note;
  }
  function noteText(turn) {
    var E = S.engine, total = (turn.ids || []).length, held = typeof turn.held === 'number' ? turn.held : null;
    // the words the store has nothing close to: the server's, even none (it read "מתנה לאשתי טבעת יהלום" as "טבעת יהלום",
    // "לאשתי" is whom it is for), or the engine's own while typing
    var words = Array.isArray(turn.unknown) ? turn.unknown : localUnknown(turn.q);
    var typed = [], kept = [], c0 = C(turn), read = [];
    Object.keys(c0.auto || {}).forEach(function (code) { [].push.apply(read, (c0.at && c0.at[code]) || []); });
    // and the words read as chips of their own ("גרב", "בלי לוגו")
    Object.keys(c0.words || {}).forEach(function (k) { [].push.apply(read, c0.words[k].at || []); });
    wordsOf(turn.q).forEach(function (w, i) {
      // a word that only asks for a filter ("בצבע") is neither missing nor what is shown
      if (ASKS_ONLY.test(w)) { return; }
      // whom it is for, where the store says it in any form ("women" for "woman") or sells to it by default: no word missing
      var whom = whomOf(E && E.normalize ? E.normalize(w) : w);
      if (whom && whomKnown(whom)) { return; }
      if (E && E.normalize && words.indexOf(E.normalize(w)) >= 0 && read.indexOf(i) < 0) { typed.push(w); } else if (!STOP.test(w)) { kept.push(w); }
    });
    // "נעלים לגברים מנומרות": a word none of the products shown says, though the store has it elsewhere (a leopard bag):
    // said, never shown as if they were it (as Aviad asked, 2026-10-25)
    // and a value the words named that none of the results has, let go (readTags): said first
    var letGo = (turn.letGo || []).filter(function (w) { return !ASKS_ONLY.test(w) && !STOP.test(w); });
    var shownList = filtered(turn), missing = typed.length ? [] : letGo.concat(unsaid(turn.q, shownList, read, turn.near).filter(function (w) { return letGo.indexOf(w) < 0; }));
    // in a sentence, only words the store has elsewhere are said left out: one no product of it holds ("ומה", "באותו",
    // "תקציב") is no thing it lacks, and listed it showed the search's own workings (QA, 16.11: "…בלי 'ומה', 'שלי', 'באותו',
    // 'תקציב'"). A short search says each word it lacks ("שעון רולקס": no Rolex; "sneakers for kids": none for kids)
    var sentence = wordsOf(turn.q).length > 3;
    missing = missing.filter(function (w) { return !sentence || !E || !E.normalize || storeShare(withFamily(wordForms(E.normalize(w)))) > 0; });
    var rest = missing.length ? kept.filter(function (w) { return missing.indexOf(w) < 0; }) : [];
    // "מה זה ספרי פנטזיה?": the books are shown, and it says so; "פנטזיה" is not on the site
    // nothing as asked: the server searched looser, and says what it left out; the other way it found is a press away
    if (turn.loosened && turn.loosened.left && turn.loosened.left.length) {
      return '<div data-unknown><p class="ls-note__text">' + esc(t('loosened', { q: turn.q, w: turn.loosened.left.join(' ') })) + '</p>' +
        ((turn.loosened.also || []).length ? '<div class="ls-chips" style="margin:6px 0 10px">' + turn.loosened.also.map(function (o) { return '<button class="ls-chip ls-chip--quiet" data-q="' + esc(o.query) + '" data-loose="' + esc(turn.q) + '">' + esc(o.query) + ' <span class="ls-chip__count ls-num">(' + o.count + ')</span></button>'; }).join('') + '</div>' : '') + '</div>';
    }
    // no product holds every word, and the server offers the parts that do (focus): said, with each part a press away
    // ("חולצה קצרה כחולה עם כיס": a long blue shirt was shown as if it were it, Vans 12.11)
    // (never for whom it is for, a word no product holds where the store sells to that side: "woman ring", Jean Rachel)
    var whomAsked = wordsOf(turn.q).some(function (w) { var a = whomOf(E && E.normalize ? E.normalize(w) : w); return !!a && whomKnown(a); });
    if (!missing.length && !typed.length && held === 0 && total && !whomAsked && turn.focus && (turn.focus.options || []).length) {
      return '<div data-unknown><p class="ls-note__text">' + esc(t('noneAll', { q: turn.q })) + '</p><div class="ls-chips" style="margin:6px 0 10px">' +
        turn.focus.options.map(function (o) { return '<button class="ls-chip ls-chip--quiet" data-q="' + esc(o.query) + '">' + esc(o.query) + ' <span class="ls-chip__count ls-num">(' + o.count + ')</span></button>'; }).join('') + '</div>' +
        askCardHtml(turn.q, '', true) + '</div>';
    }
    // the search as typed, and each word left out on its own: "No “sneakers for kids” right now … without “kids”", never
    // "Nothing “kids” in “sneakers”" (QA, 16.11); several, each in its quotes
    var text = missing.length && shownList.length && rest.length ? t('noneSaid', { q: String(turn.q || '').trim(), gone: missing.join(LANG === 'he' ? '", "' : '”, “'), kept: rest.join(' ') })
      : typed.length && total && kept.length ? t('showingFor', { kept: kept.join(' '), gone: typed.join(' ') })
      : typed.length ? t('notHere', { w: typed.join(' ') })
      : held === 0 && total && !whomAsked ? t('meaningOnly', { q: turn.q })
      : held !== null && !whomAsked && held < WEAK_HELD && total >= WEAK_TOTAL ? t('meaningMostly', { n: held }) : '';
    if (!text) { return ''; }
    return '<div data-unknown>' + askCardHtml(turn.q, text, true) + '</div>';
  }
  // The words of a search, as typed, that no product shown says in its title or words (its tags, its picture's words),
  // a word in any of its forms: "לבנה" is "לבן", "בצד" is "צד", "pocket" is "pockets". Only for a search of two words or more.
  function wordForms(w) {
    var out = [w], bare = /^[והבלמשכ][א-ת]{3,}$/.test(w) ? w.slice(1) : null;
    [w, bare].forEach(function (x) { if (x) { out.push(x); if (x.length > 3) { out.push(x.replace(/(ה|ת|ימ|ות|י)$/, '')); } } });
    if (/^[a-z]{3,}$/.test(w)) { out.push(w + 's', w.replace(/(es|s)$/, '')); }
    return out;
  }
  // words that ask for a filter, an order or a price, never what a product says: "בצבע", "במידה", "הכי זולה", "במבצע"
  // (and a currency: "עד 1000 שקל" was told "without שקל", its price applied all the same: Rocks QA, 16.11)
  var ASKS_ONLY = /^([והבל]?(שקל|שקלים|שח|דולר|דולרים|יורו|צבע|גוון|מידה|מידות|מבצע|מחיר|זול|זולה|זולים|זולות|יקר|יקרה|יקרים|יקרות)|הכי|ביותר|עד|מעל|מתחת|shekels?|nis|ils|dollars?|usd|aud|eur|euros?|size|colou?rs?|cheap(er|est)?|expensive|sale|price|under|over|for|with|without|and)$/i;
  // read: where the words that set a filter were typed ("קובע", read as the filter "כובע"): never missing
  // near: the words the server read as the store writes them ([{word, term}]): said where what is shown says the term
  function unsaid(q, list, read, near) {
    var E = S.engine;
    if (!E || !E.normalize || !list.length) { return []; }
    var typed = wordsOf(q).filter(function (w, i) { return (read || []).indexOf(i) < 0 && w.length >= 3 && !STOP.test(w) && !ASKS_ONLY.test(w) && !/\d/.test(w); });
    // one word left over is said too when the rest of the search was read ("neckles men green": the necklaces and green
    // shown, and "men" said, not dropped in silence; 15.11); a search of one word is its own matter
    if (!typed.length || (typed.length < 2 && wordsOf(q).length < 2)) { return []; }
    var said = {}, heard = {};
    var hear = function (w) { return E.sound ? E.sound(w) : ''; };
    list.forEach(function (r) { E.normalize(decode(r.title || '') + ' ' + (r.kw || '')).split(' ').forEach(function (w) { wordForms(w).forEach(function (f) { said[f] = 1; var h = f.length >= 4 && hear(f); if (h) { heard[h] = 1; } }); }); });
    return typed.filter(function (w) {
      var n = E.normalize(w), whom = whomOf(n);
      if (whom) {
        // whom it is for, in any of its forms ("woman", "women", "ladies", "נשים"): said where the results say any form of
        // it; and unsaid only where they say nothing of anyone: a store that marks its few men's pieces "mens" sells the
        // rest to women, and "woman ring" was told "woman is not on the site" (Jean Rachel, 16.11)
        // and the store's own default: where none of its products is marked for this side and a few are marked for the
        // other, the rest are this side's; a store marking all for one side still says the other is not there
        return !WHOM[whom].some(function (f) { return said[f]; }) && !whomByDefault(whom);
      }
      // a word in the other language, read as the store's ("hoop": "חישוק"), is said by what says that: "gold hoop earrings"
      // over the hoops was told "without hoop" (Rocks QA, 16.11)
      var term = (near || []).filter(function (x) { return x && x.word === n && x.term; })[0];
      // ...unless that is another word of the search already: "שעון רולקס", "רולקס" read as "שעון", says nothing of Rolex, and
      // every watch was shown as if it were one (QA, 16.11: no "we have no Rolex")
      var termForms = term ? wordForms(E.normalize(String(term.term))) : [];
      var echoes = termForms.length && wordsOf(q).some(function (o) { var on = E.normalize(o); return on !== n && wordForms(on).some(function (f) { return termForms.indexOf(f) >= 0; }); });
      if (term && !echoes && termForms.some(function (f) { return said[f]; })) { return false; }
      // and a word written as it sounds ("מחנסים" for "מכנסים": ח and כ, as the engine hears them) is said by what sounds
      // the same: the trousers were shown and "מחנסים is not on the site" was said (Miss Nori, 16.11)
      return !wordForms(n).some(function (f) { return said[f] || (f.length >= 4 && heard[hear(f)]); });
    });
  }
  // the words that say whom a product is for, in English and Hebrew, each form
  var WHOM = {
    women: ['woman', 'women', 'womens', 'ladies', 'lady', 'female', 'נשימ', 'נשים', 'אישה', 'לנשימ', 'לנשים', 'לאישה', 'נשי'],
    men: ['man', 'men', 'mens', 'male', 'גבר', 'גברימ', 'גברים', 'לגברימ', 'לגברים', 'לגבר', 'גברי'],
    kids: ['kid', 'kids', 'child', 'children', 'boys', 'girls', 'ילדימ', 'ילדים', 'לילדימ', 'לילדים', 'ילד', 'ילדה']
  };
  // whether a store sells to this side by default: none of its products marked for it, and fewer than a third marked for
  // the other grown-up side (men's or women's)
  var whomCounts = null, whomCountsOf = null;
  function countWhom() {
    if (whomCountsOf !== S.records) {
      whomCountsOf = S.records; whomCounts = { women: 0, men: 0, kids: 0, all: 0 };
      Object.keys(S.records || {}).forEach(function (k) {
        var r = S.records[k];
        if (!r || r.t !== 'product') { return; }
        whomCounts.all++;
        var words = {};
        String(decode(r.title || '') + ' ' + (r.kw || '')).toLowerCase().replace(/['’]s\b/g, '').split(/[^a-z֐-׿]+/).forEach(function (w) { words[w] = 1; });
        ['women', 'men', 'kids'].forEach(function (a) { if (WHOM[a].some(function (f) { return words[f]; })) { whomCounts[a]++; } });
      });
    }
    return whomCounts;
  }
  function whomByDefault(whom) {
    var other = whom === 'women' ? 'men' : whom === 'men' ? 'women' : null;
    if (!other || !S.records) { return false; }
    var n = countWhom();
    return n.all > 0 && n[whom] === 0 && n[other] > 0 && n[other] < n.all / 3;
  }
  // whether the store says this side in any of its forms, or sells to it by default
  function whomKnown(whom) { return !!S.records && (countWhom()[whom] > 0 || whomByDefault(whom)); }
  function whomOf(w) {
    w = String(w || '').toLowerCase().replace(/['’]s$/, '');
    for (var a in WHOM) { if (WHOM[a].indexOf(w) >= 0) { return a; } }
    return null;
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
  // shown: the "ask" row is already above (a question being typed): the words only, never the same button twice
  function askCardHtml(q, text, shown, empty) {
    if (shown) { return text ? '<p class="ls-note__text">' + esc(text) + '</p>' : ''; }
    return (text ? '<p class="ls-note__text">' + esc(text) + '</p>' : '') + askCard(q, false, askFind(q, empty));
  }
  // the AI asked to find the words across the whole catalogue ("תראה לי …": the set finder), from the ask card or the row
  // "חפשו לי באתר": a few words of what is sought are asked to see ("תראה לי עגילי זהב"); a sentence, or a question, is
  // asked as the shopper wrote it, never "הראו לי" before it (as Aviad asked, 11.11); so is anything with markup in it
  // ("<img onerror>": Vans QA, 16.11). "הראו", in the plural: never as a man or as a woman.
  // empty: the words found nothing, so Enter in the field presses it (askCardFor); under results it waits for a press
  function askFind(q, empty) {
    var s = String(q || '').replace(/[?؟.!\s]+$/, ''), asked = isQuestion(s) || s.trim().split(/\s+/).length >= 4 || /[<>{}=\\]/.test(s);
    return 'data-askcard' + (empty ? ' data-empty' : '') + ' data-from="' + esc(q) + '" data-ask="' + esc(asked ? String(q || '').trim() : t('askFindQ', { q: s })) + '"';
  }
  // nothing found by the words nor by their meaning: never "not found" (Tomer's ask card, 8.11), the store's popular
  // searches to start from, then the card that asks the AI
  function popularHtml() {
    var pop = (S.config.popular || []).slice(0, 5);
    return pop.length ? '<div class="ls-sec__title">' + esc(t('popular')) + '</div><div class="ls-chips" style="margin-bottom:14px">' +
      pop.map(function (x) { return '<button class="ls-chip ls-chip--quiet" data-q="' + esc(x) + '">' + icon('trend', 'ls-icon--sm') + esc(x) + '</button>'; }).join('') + '</div>' : '';
  }
  function resultsHtml(turn) {
    if (turn.loading) { return thinking(); }
    if (turn.error) { return errorHtml(turn); }
    var list = filtered(turn), total = (turn.ids || []).length, html = '';
    if (turn.single && rec(turn.single)) {
      var s = rec(turn.single);
      html += '<div class="ls-exact"><a class="ls-pick__media" href="' + esc(s.url) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(s.img) + '" alt=""></a><div><div class="ls-exact__tag">' + icon('check', 'ls-icon--sm') + esc(s.sku && turn.singleBy === 'sku' ? t('exactSku', { sku: s.sku }) : t('exact')) + '</div><a class="ls-pick__title" dir="auto" href="' + esc(s.url) + '" style="color:inherit;text-decoration:none">' + esc(decode(s.title)) + '</a><div class="ls-pick__row">' + priceHtml(s) + buyBtn(s, 'ls-btn--sm') + '</div><div data-after="' + esc(pid(s.id)) + '"></div></div></div>';
      // and the results under it as always, that product among them, as a card like any other (as Aviad asked, 16.11: shown
      // twice is fine). A code typed is that product alone: the grid holds it and nothing else (Rocks QA, 16.11: two bracelets
      // after the SKU's own ring)
      var one = String(pid(s.id));
      if (turn.singleBy === 'sku') {
        var own = list.filter(function (r) { return String(pid(r.id)) === one; });
        list = own.length ? own : [s];
      }
    }
    // no product, and articles or pages found: those, with the quiet way to ask under them, never "nothing found" over
    // what was found (Profimex, 16.11: 49 posts for "השקעה בנדל״ן", the drawer showed only the card that asks the AI)
    var onlyGuides = !total ? guidesFor(turn) : [];
    if (onlyGuides.length) {
      return html + guidesHtml(onlyGuides, turn.q) + (off('ask') || turn.askShown ? '' : askSmall(askFind(turn.q)));
    }
    if (!total) {
      if (turn.focus && turn.focus.options && turn.focus.options.length) {
        html += '<p class="ls-noanswer__text">' + esc(t('didYouMean', { q: turn.q })) + '</p><div class="ls-chips" style="margin-bottom:14px">' +
          turn.focus.options.map(function (o) { return '<button class="ls-chip ls-chip--quiet" data-q="' + esc(o.query) + '">' + esc(o.query) + ' <span class="ls-chip__count ls-num">(' + o.count + ')</span></button>'; }).join('') +
          (turn.focus.category ? '<button class="ls-chip ls-chip--quiet" data-q="' + esc(turn.focus.category.title) + '">' + esc(t('category', { t: turn.focus.category.title })) + '</button>' : '') + '</div>';
      } else if (!turn.askShown) {
        html += popularHtml();
      }
      html += off('ask') ? '' : askCardHtml(turn.q, '', turn.askShown, true);
      return html;
    }
    // a guide or a page comes before the products only for a sentence ("איך בוחרים טבעת אירוסין לפי תקציב"), when it
    // names it almost word for word (90% or more) and better than any product does; "טבעת זהב" keeps the products first
    var guides = guidesFor(turn);
    var g0 = guides[0], gm = g0 && meantWords(turn.q).length >= GUIDE_FIRST_WORDS ? matchOf(turn.q, g0.title, (S.records[g0.id] || {}).kw, 0) : 0;
    // a content site (search.content_first): the articles and pages it found first, its products after them
    var guideFirst = (!!(S.config && S.config.contentFirst) && guides.length > 0) || (gm >= GUIDE_FIRST && (!list.length || gm > matchOf(turn.q, decode(list[0].title), list[0].kw, 0)));
    if (guideFirst) { html += guidesHtml(guides, turn.q); }
    html += noteHtml(turn);
    // no product holds the words at all (only what is near by meaning, "מכניסים"): the card that asks the AI to search the
    // site comes first, above what may not be it (as Aviad asked, 16.11), and not again under the products
    var askTop = !off('ask') && !turn.askShown && turn.held === 0 && !!turn.askBelow;
    if (askTop) { html += '<div style="margin:4px 0 18px">' + askCard(turn.q, false, askFind(turn.q)) + '</div>'; }
    html += '<div class="ls-summary"><div class="ls-summary__count ls-num">' + esc(countText(list.length)) + '</div>' +
      '<button class="ls-btn ls-btn--ghost ls-btn--sm" data-sort="' + turn.id + '">' + esc(sortLabel(sortOf(C(turn)))) + icon('down', 'ls-icon--sm') + '</button></div>';
    html += chipRow(turn);
    // a size asked for that no product here says: told, with the team one press away, never silently ignored
    if (turn.sizeUnknown) {
      html += '<div class="ls-note">' + icon('info') + '<span>' + esc(t('sizeUnknown')) + ' · <button class="ls-btn ls-btn--link" data-ask="' + esc(turn.q) + '">' + esc(t('askWho', { name: CFG.assistant })) + '</button></span></div>';
    }
    if (!list.length) { return html + '<div class="ls-empty-filter">' + esc(t('noFilterMatch')) + '<div style="margin-top:10px"><button class="ls-btn ls-btn--secondary ls-btn--sm" data-clear-filters="' + turn.id + '">' + esc(t('clearAll')) + '</button></div></div>'; }
    var n = gridN(turn);
    // the guides' place among the cards, as the store chose (search.guides_after): after so many cards, so a site's articles
    // are seen without scrolling past every product; 0 keeps them after all the cards
    var ga = !guideFirst && guides.length && S.config && S.config.guidesAfter > 0 && Math.min(n, list.length) > S.config.guidesAfter ? S.config.guidesAfter : 0;
    html += '<div class="ls-grid">' + list.slice(0, ga || n).map(card).join('') + '</div>';
    if (ga) { html += guidesHtml(guides, turn.q) + '<div class="ls-grid">' + list.slice(ga, n).map(card).join('') + '</div>'; }
    if (list.length > n) { html += allBtn(turn, allLabel(list.length, n), true); }
    if (!guideFirst && !ga) { html += guidesHtml(guides, turn.q); }
    // results that may not be it: a quiet way to ask the store's AI to search the site (Tomer's ask card, 8.11), never
    // for a question already asked above (its card is there), and only when the shopper presses it
    // few within the price asked, and more a step above it: said, and one press away
    if (turn.price && turn.price.more && turn.price.more.count > 0) {
      html += '<div style="margin-top:16px"><button class="ls-btn ls-btn--secondary ls-btn--sm" data-q="' + esc(String(turn.price.rest || '').trim() + ' ' + t('priceUpToWords', { max: turn.price.more.max })) + '">' +
        esc(t('morePriced', { n: turn.price.more.count, max: money(turn.price.more.max) })) + '</button></div>';
    }
    if (!off('ask') && !turn.askShown && !askTop) { html += turn.askBelow ? '<div style="margin-top:20px">' + askCard(turn.q, false, askFind(turn.q)) + '</div>' : askSmall(askFind(turn.q)); }
    html += (turn.askOpen ? askAboutHtml(turn) : '') + (turn.aiOpen ? myAi(turn) : '');
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
    var title = similar ? esc(countText(list.length)) + ' <span>' + esc(t('similar')) + '</span>' : esc(countText(list.length)) + ' <span>"' + esc(turn.q) + '"</span>';
    return '<div class="ls-wide-head">' + back + (similar ? '<div style="margin-top:8px">' + ofHero(turn.of, turn.size, turn.oos) + '</div>' + oosNote(turn) : '') +
      '<div class="ls-summary" style="margin-top:' + (similar ? 18 : 8) + 'px"><div class="ls-summary__count ls-num" style="font-size:20px;font-weight:500">' + title + '</div>' +
      '<button class="ls-btn ls-btn--ghost ls-btn--sm" data-sort="' + turn.id + '">' + esc(sortLabel(sortOf(C(turn)))) + icon('down', 'ls-icon--sm') + '</button></div>' + chipRow(turn) + '</div>' +
      (similar && !(turn.ids || []).length ? (othersHtml(turn, turn.othersOf ? t('similarNoneOthersOf', { c: turn.othersOf }) : t('similarNoneOthers')) || '<p class="ls-noanswer__text">' + esc(t('noSimilar')) + '</p>') : list.length ? '<div class="ls-grid">' + shown.map(card).join('') + '</div>' : '<div class="ls-empty-filter">' + esc(t('noFilterMatch')) + '</div>') +
      // no way out to the theme's own search page: the drawer's results are the search (as Aviad asked, 2026-10-21)
      (list.length > shown.length ? '<div class="ls-more"><button class="ls-btn ls-btn--secondary" data-loadmore="' + turn.id + '">' + esc(t('loadMore')) + '</button></div>' : '') +
      (similar && (turn.ids || []).length && list.length <= shown.length ? othersHtml(turn, null) : '');
  }

