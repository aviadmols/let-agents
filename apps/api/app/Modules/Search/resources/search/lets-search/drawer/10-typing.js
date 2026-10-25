  // ---------------------------------------------------------------- suggestions while typing
  // As the shopper types, the results come up the way Enter shows them (count, sort, filters, cards,
  // guides), at the top of the conversation: from the index at once, then the server's list a beat
  // later. Filters chosen here stay while typing, and go with the search when it's sent.
  var liveTimer = null, pauseTimer = null, meaningTimer = null, wordTimer = null;
  // Words, not letters: the results change when a word is finished (a space) or the typing rests a moment, never on
  // every letter, so the cards do not flicker under the shopper's fingers (as Aviad asked, 2026-10-21).
  var WORD_REST_MS = 280;
  function onType() {
    var q = INPUT.value.trim();
    FIELD.classList.toggle('has-text', !!q);
    clearTimeout(wordTimer);
    if (!q) { S.keptQ = null; hideLive(); return; }
    // one letter is not a search yet ("ס" is in an author's initial): the results wait for a second one
    if (q.replace(/[^\p{L}\p{N}]/gu, '').length < 2) { hideLive(); return; }
    if (!S.open) { return; }
    // the search on screen, left in the field: nothing new to show until the words change
    if (q === S.keptQ) { hideLive(); return; }
    if (/\s$/.test(INPUT.value)) { liveFor(q); return; }
    wordTimer = setTimeout(function () { if (INPUT.value.trim() === q) { liveFor(q); } }, WORD_REST_MS);
  }
  function liveFor(q) {
    if (!S.open || q === S.keptQ) { return; }
    if (!S.index) { loadIndex().then(onType); return; }
    if (S.tab !== 'chat' || S.wide) { S.tab = 'chat'; S.wide = null; render(); }
    // on a product page the words ask about the product, and the product's information stays as it is;
    // only words that read as a product search switch to the results
    if (aboutProduct(q)) { askOnly(q); return; }
    var was = S.live, isNew = !was || !SUG.classList.contains('is-on');
    // a new search starts clean: what was on screen (results, similar items, answers) waits behind the dashed line
    if (!was && S.turns.some(function (x) { return !x.prev; })) { S.turns.forEach(function (x) { x.prev = true; }); S.showPrev = false; persist(); render(); }
    if (!was || was.q !== q) {
      var local = hits(q).filter(function (r) { return r.t === 'product'; });
      S.live = { id: 'live', kind: 'results', q: q, ids: (was && was.q && q.indexOf(was.q) === 0 && was.server && !local.length ? was.ids : local.map(function (r) { return r.id; })), c: was ? was.c : blankC(), content: [], cats: [], server: false };
      readTags(S.live);
      clearTimeout(liveTimer); clearTimeout(pauseTimer); clearTimeout(meaningTimer);
      liveTimer = setTimeout(function () { liveServer(q); }, 60);
    }
    renderLive(isNew);
    announce(isQuestion(q) ? t('announceQuestion') : t('announceResults', { n: filtered(S.live).length }));
  }
  // typing=1: spelling only and nothing counted on the server; the search is counted once the shopper pauses.
  // Half a second after the last key the words are asked again by meaning too (meaning=1), so "מברגה עם בטריה"
  // finds the store's "סוללות" while still typing: one vector per wording, kept on the server.
  // words: what is searched, when the filters read from the words took some of them (restWords); q stays what was typed
  function liveServer(q, byMeaning, words) {
    json(API + '/search/' + SITE + '?q=' + encodeURIComponent(words || q) + '&typing=1' + (byMeaning ? '&meaning=1' : '') + visitQuery()).then(function (r) {
      var lv = S.live;
      if (!lv || lv.q !== q) { return; }
      var d = r.body || {};
      var ids = (d.ids || []).filter(function (id) { return /^p:/.test(id); });
      if (!ids.length) { ids = ((d.groups && d.groups.product) || []).map(function (p) { return p.id; }); }
      // "ספרים על חטופים": "ספרים" is the filter "ספר", so the search is for "על חטופים", filtered to books, not every
      // book there is. Asked once more, before anything shows; when the rest finds nothing, the whole words stand.
      if (words && !ids.length && lv.whole) { ids = lv.whole; words = null; }
      if (!words && !byMeaning) {
        lv.ids = ids; lv.actions = d.actions || [];
        readTags(lv);
        var rest = restWords(lv);
        if (rest) { lv.whole = ids; lv.words = rest; liveServer(q, false, rest); return; }
        lv.words = null;
      }
      // Nothing by spelling on the server, while the drawer's own index found some: those stay on screen (the
      // meaning pass may add to them), instead of blinking away
      var spelled = ids.length;
      if (!byMeaning && !spelled && (lv.ids || []).length) { ids = lv.ids; }
      lv.ids = ids; lv.server = true; lv.total = d.total || ids.length; lv.focus = d.focus || null; lv.loosened = d.loosened || null;
      lv.single = d.single && (d.single.external_id || d.single.id) ? pid(d.single.external_id || d.single.id) : null;
      lv.singleBy = lv.single ? d.single.by : null;
      lv.content = ((d.groups && d.groups.content) || []).slice(0, 3); lv.cats = ((d.groups && d.groups.category) || []).slice(0, 4);
      lv.actions = d.actions || [];
      // Nothing by spelling, and the meaning pass still to come: still thinking, not "no results" yet
      // (it often finds what the store calls by another word). "No results" only once both found nothing.
      // The meaning pass follows every search of three letters or more: the index first, then the meaning (as
      // Aviad asked, 2026-10-25); the server keeps it from padding what the words already found (SPELLED_ENOUGH).
      var willMean = !byMeaning && q.replace(/[^\p{L}\p{N}]/gu, '').length >= 3;
      if (byMeaning) { lv.meant = true; }
      lv.willMean = lv.willMean || willMean;
      lv.pending = willMean && !ids.length;
      readTags(lv);
      renderLive(false);
      if (!byMeaning) {
        clearTimeout(pauseTimer);
        pauseTimer = setTimeout(function () { if (S.live === lv) { countSearch(q, lv.ids.length); } }, PAUSE_MS);
        if (willMean) { clearTimeout(meaningTimer); meaningTimer = setTimeout(function () { if (S.live === lv) { liveServer(q, true, lv.words || null); } }, 500); }
      }
      return products(filtered(lv).slice(0, 8).map(function (x) { return x.id; })).then(function () { if (S.live === lv) { renderLive(false); } });
    }).catch(function () {
      // the index results stay; a thinking mark never stays forever
      if (S.live && S.live.q === q && S.live.pending) { S.live.pending = false; renderLive(false); }
    });
  }
  function productSearch(q) { return !isQuestion(q) && q.length >= 3 && hits(q).some(function (r) { return r.t === 'product'; }); }
  // only when opened from the product page's own search row; the header's icon opens the regular search
  // a question about the product on screen: it asks, or it points at the product. A description of what the
  // shopper wants ("יהלום עם זהב צהוב ויהלומים קטנים מסביב") is a search, and shows products, not an answer
  var ABOUT_WORDS = ['זה', 'הזה', 'הזאת', 'הזו', 'זאת', 'שלו', 'שלה', 'אותו', 'אותה', 'בו', 'בה', 'המוצר', 'הפריט', 'אפשר', 'יש', 'מתאים', 'מתאימה', 'כמה', 'it', 'this', 'its', 'does', 'can', 'is'];
  function asksAboutIt(q) {
    if (isQuestion(q)) { return true; }
    var words = String(q || '').toLowerCase().replace(/[^\p{L}\p{N}\s]/gu, ' ').split(/\s+/).filter(Boolean);
    return words.some(function (w) { return ABOUT_WORDS.indexOf(w) !== -1; });
  }
  // opened from the product page's row: the words are about this product, and its information stays, until they
  // plainly search for other products ("טבעת זהב"); a letter or two, or a question, never switch to results
  function aboutProduct(q) { return !!(S.askMode && S.ctx && rec(S.ctx) && (asksAboutIt(q) || !productSearch(q)) && !(LEARN.bank && LEARN.bank.ask === false)); }
  function askOnly(q) {
    clearTimeout(liveTimer);
    S.live = null;
    SUGIN.innerHTML = askRow(q, true); SUGIN.lsHtml = null;
    SUG.classList.add('is-on');
    INPUT.setAttribute('aria-expanded', 'true');
    var o = SUG.querySelector('[data-opt]');
    if (o) { o.id = 'ls-opt-0'; o.setAttribute('role', 'option'); }
    S.active = 0;
    mark_active();
  }
  function hideLive() {
    clearTimeout(liveTimer); clearTimeout(pauseTimer); clearTimeout(meaningTimer); clearTimeout(wordTimer);
    S.live = null; S.active = -1;
    if (SUG.classList.contains('is-on')) { SUG.classList.remove('is-on'); SUGIN.innerHTML = ''; SUGIN.lsHtml = null; }
    INPUT.setAttribute('aria-expanded', 'false'); INPUT.removeAttribute('aria-activedescendant');
  }
  function renderLive(isNew) {
    var lv = S.live;
    if (!lv) { return; }
    // a new search starts with nothing highlighted (Enter searches); the highlight never jumps down the list
    var keepOpt = isNew ? -1 : S.active, wasOn = SUG.classList.contains('is-on');
    if (isNew) { BODY.scrollTop = 0; }
    // the same results drawn again (the server agreeing with the index, the meaning adding nothing): left as they are,
    // so no card blinks
    var html = liveHtml(lv);
    if (!isNew && SUG.classList.contains('is-on') && html === SUGIN.lsHtml) { return; }
    var y0 = FLOW.getBoundingClientRect().top;
    SUGIN.innerHTML = SUGIN.lsHtml = html;
    if (isNew && wasOn && FLOW.animate) {
      // from the question row on a product page: the product's information slides down, making room for the results
      var dy = FLOW.getBoundingClientRect().top - y0;
      FLOW.animate([{ transform: 'translateY(' + (-dy) + 'px)' }, { transform: 'none' }], { duration: 560, easing: 'cubic-bezier(.32,.72,0,1)' });
      SUGIN.animate([{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }], { duration: 380, easing: 'ease-out' });
    } else if (isNew) { SUG.classList.remove('is-on'); void SUG.offsetWidth; }
    SUG.classList.add('is-on');
    INPUT.setAttribute('aria-expanded', 'true');
    var opts = SUG.querySelectorAll('[data-opt]');
    opts.forEach(function (o, i) { o.id = 'ls-opt-' + i; o.setAttribute('role', 'option'); });
    S.active = isQuestion(lv.q) ? (isNew || keepOpt < 0 ? 0 : Math.min(keepOpt, opts.length - 1)) : Math.min(keepOpt, opts.length - 1);
    mark_active();
  }
  // re-draw whichever view holds this turn
  function refresh(turn) { if (turn === S.live) { renderLive(false); } else { persist(); render(); } }
  function mark_active() {
    var opts = SUG.querySelectorAll('[data-opt]');
    opts.forEach(function (o, i) { o.classList.toggle('is-active', i === S.active); o.setAttribute('aria-selected', String(i === S.active)); });
    if (opts[S.active]) { INPUT.setAttribute('aria-activedescendant', opts[S.active].id); opts[S.active].scrollIntoView({ block: 'nearest' }); } else { INPUT.removeAttribute('aria-activedescendant'); }
  }
  function hl(title, q) {
    var i = title.toLowerCase().indexOf(q.toLowerCase());
    return i < 0 ? esc(title) : esc(title.slice(0, i)) + '<b>' + esc(title.slice(i, i + q.length)) + '</b>' + esc(title.slice(i + q.length));
  }
  // A question the store already answered (checked, and kept as the store's): its answer is in the index,
  // so it starts right here as the shopper types, with no model; Enter or a tap opens it whole. Only the
  // store's own answers, never one about a product, and only one close enough (the engine's closeAnswer).
  function savedAnswerFor(q) {
    var e = win.LA_ENGINE;
    if (off('ask') || !e || !e.closeAnswer) { return null; }
    var keys = Object.keys(S.records);
    for (var i = 0; i < keys.length; i++) {
      var r = S.records[keys[i]];
      if (r.t === 'answer' && r.w === 1 && r.ans && e.closeAnswer(q, r.title)) { return r; }
    }
    return null;
  }
  function askRow(q, active) {
    var saved = savedAnswerFor(q);
    // written in once, when it first shows; the next keystrokes keep it still
    var fresh = !!saved && S.savedShown !== saved.id;
    S.savedShown = saved ? saved.id : null;
    if (saved) {
      return '<div style="padding:4px 4px 8px"><button class="ls-ask ls-ask--saved' + (active ? ' is-active' : '') + '" data-opt data-saved-answer="' + esc(saved.id) + '"><span class="ls-ask__mark">' + mark() + '</span><span class="ls-ask__text"><span class="ls-ask__who">' + esc(t('askWho', { name: CFG.assistant })) + '</span><span class="ls-ask__q" dir="auto">' + esc(saved.title) + '</span>' +
        '<span class="ls-ask__ans' + (fresh ? ' ls-words' : '') + '" dir="auto">' + (fresh ? words(saved.ans) : esc(saved.ans)) + '</span></span><span class="ls-kbd">' + icon('enter', 'ls-icon--sm') + '</span></button></div>';
    }
    return '<div style="padding:4px 4px 8px"><button class="ls-ask' + (active ? ' is-active' : '') + '" data-opt data-ask="' + esc(q) + '"><span class="ls-ask__mark">' + mark() + '</span><span class="ls-ask__text"><span class="ls-ask__who">' + esc(t('askWho', { name: CFG.assistant })) + '</span><span class="ls-ask__q">' + esc(q) + '</span></span><span class="ls-kbd">' + icon('enter', 'ls-icon--sm') + '</span></button></div>';
  }
  // the live view is the results turn itself, as Enter will keep it, with the words still being typed
  function liveHtml(lv) {
    var html = isQuestion(lv.q) ? askRow(lv.q, true) : '';
    lv.loading = (!lv.server || !!lv.pending) && !(lv.ids || []).length;
    // no bubble repeating what is being typed: the search line above already says it
    return html + '<section class="ls-turn ls-turn--live">' + resultsHtml(lv) + '</section>';
  }
  function turnById(id) { return id === 'live' ? S.live : S.turns.filter(function (x) { return x.id === id; })[0]; }

