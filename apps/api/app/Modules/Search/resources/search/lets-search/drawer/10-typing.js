  // ---------------------------------------------------------------- suggestions while typing
  // As the shopper types, the results come up the way Enter shows them (count, sort, filters, cards,
  // guides), at the top of the conversation: from the index at once, then the server's list a beat
  // later. Filters chosen here stay while typing, and go with the search when it's sent.
  var liveTimer = null, pauseTimer = null, meaningTimer = null, wordTimer = null, holdTimer = null;
  // A search waits this long for the server, and for its first products' live details (stock), before anything shows: the server reads
  // colours, kinds and filters the index does not, and its list replaced the index's a moment after ("טבעת יהלום כחול": 124,
  // then 76; "מכנסיים אדומים": the red trousers, then every trouser: QA, 16.11). Slower than this, the index's show first.
  var HOLD_MS = 900;
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
    // the store turned suggestions off (What shoppers see): the results wait for Enter
    if (S.config && S.config.suggest === false) { hideLive(); return; }
    // the search on screen, left in the field: nothing new to show until the words change
    if (q === S.keptQ) { hideLive(); return; }
    if (/\s$/.test(INPUT.value)) { liveFor(q); return; }
    wordTimer = setTimeout(function () { if (INPUT.value.trim() === q) { liveFor(q); } }, WORD_REST_MS);
  }
  // The typing has rested and the ask card is on screen for these very words, which read as a question:
  // the store's AI is asked without the press (as Aviad asked, 17.11). Never on a letter: two seconds after the last one,
  // the words unchanged, the drawer open. A question the store already answered shows its answer as it is typed and is
  // not asked again. A few times a visit at most, then the button is the shopper's to press as before: each ask is paid.
  var REST_ASK_MS = 2000, REST_ASKS = 3, restTimer = null, restAsked = 0;
  function restAsks() { try { return parseInt(win.sessionStorage.getItem('ls_rest_asks') || '0', 10) || 0; } catch (e) { return restAsked; } }
  // a question in words: it reads as one (the engine's isQuestion), has three words or more, and at least one of them is
  // a word the store itself writes ("מה זה גדששד?" names nothing the store knows)
  function asksInWords(q) {
    if (!isQuestion(q)) { return false; }
    var said = String(q || '').replace(/[?؟!.,״"']/g, ' ').split(/\s+/).filter(Boolean);
    if (said.length < 3) { return false; }
    var telling = said.filter(function (w) { return w.length >= 3 && !/\d/.test(w) && !STOP.test(w); });
    return telling.length > localUnknown(q).length;
  }
  // whether these words will be asked of the AI by themselves once the typing rests: then no button is shown for them, only
  // that the store's pages are being checked (Gueta, 18.11: "מה הכי טוב ליונים?" showed the button, and a moment later the
  // answer began on its own beside it)
  function willAskAtRest(q) {
    return S.open && !off('ask') && !(S.config && S.config.suggest === false) && String(q || '').replace(/[^\p{L}\p{N}]/gu, '').length >= 4
      && restAsks() < REST_ASKS && asksInWords(q);
  }
  function askAtRest() {
    clearTimeout(restTimer);
    var q = INPUT.value.trim();
    if (!S.open || off('ask') || q.replace(/[^\p{L}\p{N}]/gu, '').length < 4 || (S.config && S.config.suggest === false)) { return; }
    var tries = 0;
    var go = function () {
      // (no live results: a reply in a conversation, or a question on a product page, shows the ask row alone)
      if (!S.open || INPUT.value.trim() !== q || (S.live && S.live.q !== q) || restAsks() >= REST_ASKS) { return; }
      // the search by meaning still to answer: what it finds may be the answer, so it is waited for (a little)
      if (S.live && (S.live.waiting || S.live.pending || (S.live.willMean && !S.live.meant)) && tries++ < 6) { restTimer = setTimeout(go, 500); return; }
      // only what reads as a question: letters struck at random, a few letters, or words that found nothing ("ווכמקן",
      // "חולצה" in a hardware store) wait for the shopper's own press, as before (as Aviad asked, 17.11)
      if (!asksInWords(q)) { return; }
      var card = askCardFor(q), btn = !card ? SUG.querySelector('.ls-nomatch__btn[data-ask], .ls-askcard__btn[data-ask], [data-ask-pending]') : null;
      if (!card && !btn) { return; }
      restAsked++;
      try { win.sessionStorage.setItem('ls_rest_asks', String(restAsks() + 1)); } catch (e) { /* counted in memory */ }
      if (card) { card.click(); } else { submit(q, 'ask', false, true); }
    };
    restTimer = setTimeout(go, REST_ASK_MS);
  }
  // the words that only wrap a question around what it asks ("מה הכי טוב…", "what is the best…"): until a word of what it asks
  // is typed, nothing is shown for it. "מה הכי טוב " showed eight products and the button, both gone a word later (Gueta, 18.11)
  var WRAPS = /^(יש|לכם|לך|לי|לנו|של|את|על|עם|איך|מה|מהו|מהי|האם|כמה|אפשר|איזה|איזו|אילו|למה|מתי|זה|הזה|הזו|גם|או|הכי|טוב|טובה|טובים|כדאי|מומלץ|מומלצת|מתאים|מתאימה|לקנות|לבחור|בשביל|the|a|an|is|are|do|does|you|have|for|of|with|how|what|which|can|best|good|should|i|to|buy)$/i;
  function asksNothingYet(q) {
    return isQuestion(q) && String(q || '').replace(/[?؟!.,״"']/g, ' ').split(/\s+/).filter(Boolean).every(function (w) { return WRAPS.test(w); });
  }
  function liveFor(q) {
    if (!S.open || q === S.keptQ) { return; }
    if (asksNothingYet(q)) { hideLive(); return; }
    if (!S.index) { loadIndex().then(onType); return; }
    if (S.tab !== 'chat' || S.wide) { S.tab = 'chat'; S.wide = null; render(); }
    // on a product page the words ask about the product, and the product's information stays as it is;
    // only words that read as a product search switch to the results
    if (aboutProduct(q) || repliesToChat(q)) { askOnly(q); return; }
    var was = S.live, isNew = !was || !SUG.classList.contains('is-on');
    // a new search starts clean: what was on screen (results, similar items, answers) waits behind the dashed line
    // ...unless it may be a reply to the conversation still being typed ("ומה…"): the conversation stays where it is
    var mayReply = conversationGoingOn() && (/^ו[֐-׿]{2,}/.test(q) || /^(and|also|plus|what|how)\b/i.test(q));
    if (!was && !mayReply && S.turns.some(function (x) { return !x.prev; })) { S.turns.forEach(function (x) { x.prev = true; }); S.showPrev = false; persist(); render(); }
    if (!was || was.q !== q) {
      // a question waits for the server's products: the drawer's own index matched its words one by one ("הכי", "טוב"), showed
      // products and took them away a moment later (Gueta, 18.11)
      var local = isQuestion(q) ? [] : hits(q).filter(function (r) { return r.t === 'product'; });
      var localWhom = whomAlone(q, local.map(function (r) { return r.id; }));
      if (localWhom) { local = localWhom.map(rec).filter(Boolean); }
      S.live = { id: 'live', kind: 'results', q: q, ids: (was && was.q && q.indexOf(was.q) === 0 && was.server && !local.length ? was.ids : local.map(function (r) { return r.id; })), c: was ? was.c : blankC(), content: [], cats: [], server: false };
      // the results the shopper left through a product, typed again by the opening: their filters and how far they were opened
      var bl = S.backLive;
      if (bl && bl.q === q && !was) { if (bl.c) { S.live.c = bl.c; } if (bl.more) { S.live.more = bl.more; } } else if (bl && bl.q !== q) { S.backLive = null; }
      readTags(S.live);
      S.live.waiting = true;
      clearTimeout(liveTimer); clearTimeout(pauseTimer); clearTimeout(meaningTimer); clearTimeout(holdTimer);
      liveTimer = setTimeout(function () { liveServer(q); }, 60);
      // ...and by meaning at once beside it, not after it: its answer holds the spelling's too, and waiting for the spelling
      // first left "מחפש בחנות…" up for seconds on a new phrase (QA, 16.11). Once a word is whole, never on a letter.
      if (q.replace(/[^\p{L}\p{N}]/gu, '').length >= 3) {
        S.live.meantEarly = true;
        meaningTimer = setTimeout(function () { if (S.live && S.live.q === q) { liveServer(q, true); } }, 60);
      }
    } else if (/\s$/.test(INPUT.value)) {
      // the same words, now whole ("ריישי "): read again, so one word shows its chip
      readTags(S.live);
    }
    if (S.live.waiting) {
      var lvHeld = S.live;
      clearTimeout(holdTimer);
      holdTimer = setTimeout(function () { if (S.live === lvHeld && lvHeld.waiting) { lvHeld.waiting = false; renderLive(!SUG.classList.contains('is-on')); } }, HOLD_MS);
      return;
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
      // the spelling's answer after the meaning's, which holds it: nothing on screen changes, nothing blinks; only where the
      // filters read from the words took some is the rest asked, as always
      if (!byMeaning && !words && lv.meant) {
        var restLate = restWords(lv);
        if (restLate) { lv.whole = lv.ids; lv.words = restLate; liveServer(q, false, restLate); }
        return;
      }
      var d = r.body || {};
      var ids = (d.ids || []).filter(function (id) { return /^p:/.test(id); });
      if (!ids.length) { ids = ((d.groups && d.groups.product) || []).map(function (p) { return p.id; }); }
      // whom alone ("נשים"): every product filed for them, as a search pressed shows (Miss Nori, 17.11: typed, it was 1 of 262)
      if (!words) { ids = whomAlone(q, ids) || ids; }
      // "ספרים על חטופים": "ספרים" is the filter "ספר", so the search is for "על חטופים", filtered to books, not every
      // book there is. Asked once more, before anything shows; when the rest finds nothing, the whole words stand.
      if (words && !ids.length && lv.whole) { ids = lv.whole; words = null; }
      if (!words && !byMeaning) {
        lv.ids = ids; lv.actions = d.actions || []; lv.kept = typeof d.query === 'string' ? d.query + ' ' + ((d.loosened && d.loosened.left) || []).join(' ') : null;
        readTags(lv);
        var rest = restWords(lv);
        if (rest) { lv.whole = ids; lv.words = rest; liveServer(q, false, rest); return; }
        lv.words = null;
      }
      // Nothing by spelling on the server, while the drawer's own index found some: those stay on screen (the
      // meaning pass may add to them), instead of blinking away
      var spelled = ids.length;
      if (!byMeaning && !spelled && (lv.ids || []).length) { ids = lv.ids; }
      lv.ids = ids; lv.server = true; lv.total = d.total || ids.length; lv.price = d.price || null; lv.focus = d.focus || null; lv.loosened = d.loosened || null; if (byMeaning || d.near) { lv.near = d.near || null; }
      lv.single = d.single && (d.single.external_id || d.single.id) ? pid(d.single.external_id || d.single.id) : null;
      lv.singleBy = lv.single ? d.single.by : null;
      lv.content = ((d.groups && d.groups.content) || []).slice(0, contentShown(ids.length)); lv.cats = ((d.groups && d.groups.category) || []).slice(0, 4);
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
      // what was held shows once the server's list and its first products' live details are in, never a count that
      // changes a moment later ("שמלה": 17, then 18)
      var firstShown = !!lv.waiting;
      if (!firstShown) { renderLive(false); }
      if (!byMeaning) {
        clearTimeout(pauseTimer);
        pauseTimer = setTimeout(function () { if (S.live === lv) { countSearch(q, lv.ids.length); } }, PAUSE_MS);
        // asked by meaning already beside it, unless the filters read from the words took some (the rest is asked then)
        if (willMean && !(lv.meantEarly && !lv.words)) { clearTimeout(meaningTimer); meaningTimer = setTimeout(function () { if (S.live === lv) { liveServer(q, true, lv.words || null); } }, 500); }
      }
      return products(filtered(lv).slice(0, 8).map(function (x) { return x.id; })).then(function () {
        if (S.live !== lv) { return; }
        if (firstShown && lv.waiting) { lv.waiting = false; clearTimeout(holdTimer); renderLive(!SUG.classList.contains('is-on')); return; }
        renderLive(false);
      });
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
  function aboutProduct(q) { return !!(S.askMode && S.ctx && rec(S.ctx) && (asksAboutIt(q) || !productSearch(q)) && productAsks()); }
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
    clearTimeout(liveTimer); clearTimeout(pauseTimer); clearTimeout(meaningTimer); clearTimeout(wordTimer); clearTimeout(holdTimer);
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
    var backAt = S.backLive && S.backLive.q === lv.q ? S.backLive : null;
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
    // back where the shopper left these results: at the product they opened (as Aviad asked, 18.11), once it is drawn;
    // the server's answer may draw it a moment after the index's own
    if (backAt) {
      var cd = backAt.card ? SUG.querySelector('[data-card="' + String(backAt.card).replace(/["\\]/g, '') + '"]') : null;
      if (cd && cd.scrollIntoView) { cd.scrollIntoView({ block: 'center' }); S.backLive = null; }
      else if (!backAt.card && backAt.y) { BODY.scrollTop = backAt.y; S.backLive = null; }
      else if (Date.now() - (backAt.seen || (backAt.seen = Date.now())) > 6000) { S.backLive = null; }
    }
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
  // On a product's page, a question about it (its size, making it bigger or smaller, "this one") is that product's: no
  // store answer is offered for it (Rocks 15.11: "אפשר להגדיל למידה 59?" on a ring made 51-57 offered the answer kept
  // from another ring's page)
  var ASKS_SIZE_HERE = /(^|[^֐-׿a-z])[ובלהש]?מיד(ה|ות|ת|תה|תו)([^֐-׿a-z]|$)|(^|[^a-z])sizes?([^a-z]|$)/i;
  function savedAnswerFor(q) {
    var e = win.LA_ENGINE;
    if (off('ask') || !e || !e.closeAnswer) { return null; }
    if (S.ctx && (ASKS_SIZE_HERE.test(q) || RESIZES_IT.test(q) || POINTS_AT_IT.test(q))) { return null; }
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
    // to be asked by itself in a moment: the card says the pages are being checked, as the answer's own turn will
    if (willAskAtRest(q)) {
      return '<div class="ls-askcard" data-ask-pending="' + esc(q) + '"><p class="ls-askcard__kicker"><span class="ls-askcard__mark">' + mark() + '</span>' + esc(t('askKicker')) + '</p>' +
        '<p class="ls-askcard__q" dir="auto">' + (/[֐-׿]/.test(String(q || '')) ? '"' : '“') + esc(String(q || '').replace(/[“”"]/g, '')) + (/[֐-׿]/.test(String(q || '')) ? '"' : '”') + '</p>' +
        thinking(t('thinkingAsk')) + '</div>';
    }
    return askCard(q, active, 'data-ask="' + esc(q) + '"');
  }
  // A question, or a search with nothing in the store (Tomer's ask card, approved 8.11): never "not found", but one clear
  // action, a solid button that sends it to the store's AI. The AI runs only when the shopper asks (the button, or Enter
  // on it as the selected option); nothing is asked on its own, so no search spends anything. act: the button's data-*.
  function askCard(q, active, act) {
    // the design of 18.11 (noMatchCard): a question typed gets the same quiet card as words that found nothing
    return noMatchCard(q, active, act);
  }
  // No exact match for the words (the design of 18.11, docs: lets-no-exact-match): says so plainly, with one quiet action,
  // the store's AI search for the same words. A light card, a white button with the lime sparkle that turns like a coin.
  // The words in the title are cut at about 30 letters and clamped to 3 lines, so the button never leaves the screen; the
  // words and the store's name are their own direction (bdi). act: the button's data-* (askFind). The sparkle's ids are
  // this card's own: two on a page share none.
  var aiCards = 0;
  function noMatchCard(q, active, act) {
    var n = ++aiCards, g = 'lsAiG' + n, m = 'lsAiM' + n, rtl = DIR === 'rtl';
    var words = String(q || '').replace(/[“”"״]/g, '').trim(), short = words.length > 30 ? words.slice(0, 29).replace(/\s+\S*$/, '') + '…' : words;
    var star = 'M12 4c1 3.9 4.1 7 8 8-3.9 1-7 4.1-8 8-1-3.9-4.1-7-8-8 3.9-1 7-4.1 8-8z';
    var place = function (s, k, html) { return esc(s).split('@@' + k + '@@').join(html); };
    return '<div class="ls-nomatch">' +
      '<p class="ls-nomatch__title">' + place(t('noMatchTitle', { q: '@@q@@' }), 'q', '<bdi>' + esc(short) + '</bdi>') + '</p>' +
      '<button type="button" class="ls-nomatch__btn' + (active ? ' is-active' : '') + '" data-opt ' + act + '>' +
        '<span class="ls-nomatch__ic" aria-hidden="true"><svg width="23" height="23" viewBox="0 0 24 24" aria-hidden="true"><defs><linearGradient id="' + g + '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#e2ff78"/><stop offset="1" stop-color="#bfe83a"/></linearGradient>' +
        '<mask id="' + m + '"><path fill="#fff" stroke="#fff" stroke-width="2.6" stroke-linejoin="round" d="' + star + '"/></mask></defs>' +
        '<path fill="url(#' + g + ')" stroke="url(#' + g + ')" stroke-width="2.6" stroke-linejoin="round" d="' + star + '"/><g mask="url(#' + m + ')"><rect class="ls-nomatch__glint" x="0" y="-4" width="4" height="32" fill="#ffffff"/></g></svg></span>' +
        '<span>' + esc(t('noMatchBtn')) + '</span>' +
        '<svg class="ls-nomatch__arr" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + (rtl ? 'M14.5 6.5 9 12l5.5 5.5' : 'M9.5 6.5 15 12l-5.5 5.5') + '"/></svg>' +
      '</button>' +
      '<p class="ls-nomatch__from">' + place(t('noMatchFrom', { store: '@@store@@' }), 'store', '<bdi>' + esc(String((S.config && S.config.shopName) || '').trim() || String(CFG.assistant || '')) + '</bdi>') + '</p></div>';
  }
  // Letters struck at random ("כרקכרקגכרכר", "ferfrere"): one word of three letters or more, no number, that the store has
  // nothing close to. Then no AI card, only that nothing was found and the popular searches. Unsure: it has intent.
  function gibberish(q) {
    var said = String(q || '').replace(/[?؟!.,״"'׳]/g, ' ').split(/\s+/).filter(Boolean);
    if (said.length !== 1 || isQuestion(q) || /\d/.test(said[0]) || said[0].length < 3) { return false; }
    var unknown = localUnknown(q);
    return unknown.length === 1;
  }
  // a quieter way to ask, under results that may not be it: "Not it? Ask Lets · Search the site for me"
  function askSmall(act) {
    return '<div style="padding:4px 0"><button class="ls-row ls-row--ask" data-opt ' + act + '><span class="ls-mark" style="--s:14px;color:var(--ls-fg)"><i></i><i></i></span>' +
      '<span class="ls-row__text">' + esc(t('askOther', { name: CFG.assistant })) + '</span><span class="ls-row__go">' + esc(t('askBtn')) + '</span></button></div>';
  }
  // the Lets mark in a small white circle, at the start of a list row
  function markDot() { return '<span class="ls-markdot" aria-hidden="true">' + mark() + '</span>'; }
  // the live view is the results turn itself, as Enter will keep it, with the words still being typed
  // the words a question of how, why or when opens with, in the drawer's languages: a question for an answer, not a search
  var ASKS_HOW = /^(איך|כיצד|למה|מדוע|מתי|כל כמה|how|why|when)(\s|$)/i;
  function liveHtml(lv) {
    // a question gets its "ask" row here, above the results: the results then say what they say without a second one
    lv.askShown = isQuestion(lv.q);
    var html = lv.askShown ? askRow(lv.q, true) : '';
    // A question of how, why or when asks for an answer, not for the products that hold its words: "איך מנקים דק סינטטי
    // אחרי החורף?" showed four synthetic decks under it, none a cleaner (Gueta, 17.11). Its answer brings the products that
    // answer it; until then the ask card stands alone.
    if (lv.askShown && ASKS_HOW.test(String(lv.q || '').trim())) { lv.loading = false; return html; }
    lv.loading = (!lv.server || !!lv.pending) && !(lv.ids || []).length;
    // no bubble repeating what is being typed: the search line above already says it
    return html + '<section class="ls-turn ls-turn--live">' + resultsHtml(lv) + '</section>';
  }
  function turnById(id) { return id === 'live' ? S.live : S.turns.filter(function (x) { return x.id === id; })[0]; }

