  // ---------------------------------------------------------------- open / close
  var lastFocus = null, closeTimer = null;
  // the search a result was opened from, for half an hour: on the next page, or in another tab
  var BACK_MS = 30 * 60 * 1000;
  // only a search for products is come back to: a question, its answer or "not found" is done once read,
  // so the next opening after one starts clean (as Aviad asked, 2026-10-19)
  // (and the similar products a product was opened from, which have no words); wide: the list was open in the large view,
  // and comes back in it
  function keepBack(q, tid, wide) {
    store('local', 'ls_back', q && !isQuestion(q) ? { q: q, tid: tid || null, wide: !!wide, at: Date.now() } : null);
  }
  function keepBackTo(turn, card) { store('local', 'ls_back', { q: null, tid: turn.id, wide: S.wide === turn.id, card: card || null, at: Date.now() }); }
  function backTo() {
    var b = store('local', 'ls_back');
    return b && b.at && Date.now() - b.at < BACK_MS && (b.q || b.tid) ? b : null;
  }
  function open(opts) {
    opts = opts || {};
    if (nudgeOff) { nudgeOff(); }
    loadIndex();
    if (!S.open) {
      lastFocus = doc.activeElement;
      S.open = true;
      visitStep('open');
      clearTimeout(closeTimer);
      if (!doc.body.contains(host)) { doc.body.appendChild(host); }
      R.classList.add('is-open');
      fitViewport();
      // (typed on the page: no flight of the field into the drawer, the drawer opens and the words go on there)
      requestAnimationFrame(function () { R.classList.add('is-open'); SCRIM.classList.add('is-open'); D.classList.add('is-open'); placePill(); });
      doc.documentElement.style.overflow = 'hidden';
      try { win.history.pushState({ lets: 1 }, ''); } catch (e) { /* file:// */ }
      // every opening starts clean: what was searched or asked before waits behind the dashed line
      S.turns.forEach(function (x) { x.prev = true; });
      S.showPrev = false;
      // except the search the shopper left through one of its results: opened again, it is there as it was
      // (a search sent stays its turn; one only typed comes back in the field, its live results with it)
      // (the theme's own field opens us with what it holds, often nothing: "" is no words of its own)
      var back = !opts.about && !opts.text ? backTo() : null;
      if (back) {
        var bt = back.tid ? turnById(back.tid) : null;
        if (bt) {
          bt.prev = false; S.turns = S.turns.filter(function (x) { return x !== bt; }).concat([bt]); S.tab = 'chat'; S.wide = back.wide && !isPhone() ? bt.id : null;
          // a search comes back with its words in the field, as they were left
          if (bt.kind === 'results' && bt.q) { keepInField(bt.q); }
        }
        else if (back.q) { opts.text = back.q; }
      }
      // "about this product" only from the product page's own search row; the header's search is the store's search
      if (opts.about && S.ctx && !S.turns.some(function (x) { return x.kind === 'about' && x.id === S.ctx; })) { aboutTurn(S.ctx); }
    }
    S.askMode = !!(opts.about && S.ctx);
    // opened from the product page's own search row: only the product's information, and under it only
    // the line that loads the previous conversation (everything asked before, on this page too, waits there)
    if (opts.about && S.ctx) {
      var ab = S.turns.filter(function (x) { return x.kind === 'about' && x.id === S.ctx; }).pop();
      S.turns.forEach(function (x) { x.prev = x !== ab; });
      if (ab) { S.turns = S.turns.filter(function (x) { return x !== ab; }).concat([ab]); }
      S.showPrev = false; S.tab = 'chat'; S.wide = null; BODY.scrollTop = 0;
    }
    if (opts.text != null) { INPUT.value = opts.text; }
    render();
    // back at the product the shopper left the list through, not at its top
    if (back && back.card) { requestAnimationFrame(function () { var cd = D.querySelector('[data-card="' + String(back.card).replace(/["\\]/g, '') + '"]'); if (cd && cd.scrollIntoView) { cd.scrollIntoView({ block: 'center' }); } }); }
    var focusField = function () {
      if (opts.focus === false) { return; }
      INPUT.focus({ preventScroll: true });
      try { var end = INPUT.value.length; INPUT.setSelectionRange(end, end); } catch (e) { /* not a text field */ }
      onType();
    };
    // typed on the page: the field takes the focus at once, inside the keystroke, so the next letters
    // land in it (and a phone keeps its keyboard up)
    if (opts.from) { focusField(); } else { setTimeout(focusField, isPhone() ? 60 : 30); }
  }
  function close(fromHistory) {
    if (!S.open) { return; }
    S.open = false;
    S.closedAt = Date.now();
    // words typed and left in the field come back with the next opening, as a search sent does
    var left = INPUT.value.trim();
    // (the words of the search on screen are that search, already kept with it)
    if (left && !S.askMode && left !== S.keptQ) { keepBack(left, null); }
    hideLayer(); MENU.hidden = true;
    SCRIM.classList.remove('is-open'); D.classList.remove('is-open'); D.classList.remove('is-wide'); S.wide = null; S.menu = '';
    D.style.removeProperty('--ls-drag');
    closeTimer = setTimeout(function () { R.classList.remove('is-open'); }, 460);
    doc.documentElement.style.overflow = '';
    // our own history.back() fires popstate later; that one must not close a drawer reopened meanwhile
    if (!fromHistory) { try { if (win.history.state && win.history.state.lets) { S.ownBack = true; win.history.back(); } } catch (e) { /* none */ } }
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) { /* gone */ } }
  }
  win.addEventListener('popstate', function () { if (S.ownBack) { S.ownBack = false; return; } if (S.open) { close(true); } });

  // ---------------------------------------------------------------- render
  // "about this product" belongs to the page it was opened on: it is never kept, and never shown on another page
  function persist() {
    var keep = S.turns.filter(function (x) { return x.kind !== 'compare' && x.kind !== 'about'; }).slice(-30).map(function (x) { var c = Object.assign({}, x); delete c.photo; return c; });
    store('session', 'ls_turns', keep);
    // and for the next tab of this browser: a result opened in a new tab opens the search where it was
    store('local', 'ls_turns_last', { at: Date.now(), turns: keep });
  }
  // the last search of this browser, for a tab that has none of its own; a couple of hours, then a fresh start
  function lastTurns() {
    var last = store('local', 'ls_turns_last');
    return last && last.turns && Date.now() - (last.at || 0) < 2 * 3600e3 ? last.turns : [];
  }
  function chatTurns() {
    // one "about this product", the newest, and only this page's (a new chat and its undo can leave two)
    var about = S.turns.filter(function (x) { return x.kind === 'about' && x.id === S.ctx; }).pop();
    return S.turns.filter(function (x) { return x.kind !== 'compare' && (x.kind !== 'about' || x === about); });
  }
  function render() {
    // the columns once the drawer has its width (it widens with a transition into the large view)
    requestAnimationFrame(function () { sliders(); watchExposure(); fitCols(); });
    clearTimeout(fitTimer); fitTimer = setTimeout(fitCols, 450);
    D.querySelector('[data-name]').textContent = CFG.assistant || 'Lets AI';
    D.querySelector('[data-count]').textContent = S.saved.length;
    TABS.querySelectorAll('[role="tab"]').forEach(function (b) { b.setAttribute('aria-selected', String(b.dataset.tab === S.tab)); });
    placePill();
    renderCtx();
    D.classList.toggle('is-wide', !!S.wide);
    D.classList.toggle('is-expanded', S.expanded);
    // the saved list has no search field: nothing to search there
    D.classList.toggle('is-saved', S.tab === 'saved');
    var edge = D.querySelector('.ls-edge'), big = S.expanded || !!S.wide;
    edge.setAttribute('aria-label', t(big ? 'shrink' : 'expand')); edge.querySelector('.ls-edge__tip').textContent = t(big ? 'shrink' : 'expand'); edge.setAttribute('aria-pressed', String(big));
    // the live results sit over the conversation only; the wide grid and the saved list have the whole body
    SUG.classList.toggle('is-away', S.tab !== 'chat' || !!S.wide);
    if (S.tab === 'saved') { FLOW.innerHTML = savedView(); return; }
    if (S.wide) { FLOW.innerHTML = wideView(); return; }
    var all = chatTurns(), list = all.filter(function (x) { return !x.prev; }), before = all.filter(function (x) { return x.prev; });
    var prevHtml = before.length ? '<div class="ls-prev">' + (S.showPrev ? '<span class="ls-prev__label">' + esc(t('prevChat')) + '</span>'
      : '<button class="ls-prev__btn" data-prev>' + icon('history', 'ls-icon--sm') + esc(t('loadPrev')) + ' <span class="ls-num">(' + before.length + ')</span></button>') + '</div>' +
      (S.showPrev ? '<div class="ls-turns ls-turns--prev">' + before.slice(-8).map(turnHtml).reverse().join('') + '</div>' : '') : '';
    if (!list.length) { FLOW.innerHTML = welcome() + prevHtml; return; }
    var shown = S.showAll ? list : list.slice(-8);
    var html = shown.map(turnHtml).reverse().join('');
    if (list.length > shown.length) { html += '<div class="ls-earlier"><button class="ls-btn ls-btn--ghost ls-btn--sm" data-earlier>' + esc(LANG === 'he' ? 'הצגת ' + (list.length - shown.length) + ' פניות קודמות' : 'Show ' + (list.length - shown.length) + ' earlier') + '</button></div>'; }
    var newest = Object.keys(fresh).length, keep = BODY.scrollTop;
    FLOW.innerHTML = '<div class="ls-turns">' + html + '</div>' + prevHtml;
    fresh = {};
    if (isPhone()) {
      var first = BODY.querySelector('.ls-turns > .ls-turn');
      // the newest turn is last on a phone (oldest first): with the view's height it can come up to the top
      if (first && list.length > 1) { first.style.minHeight = Math.max(0, BODY.clientHeight - 16) + 'px'; }
      BODY.scrollTop = newest && first ? Math.max(0, first.offsetTop - 8) : keep;
    } else { BODY.scrollTop = newest ? 0 : keep; }
  }
  function credit() { return '<div class="ls-credit">' + mark() + '<a href="https://lets.co.il/" target="_blank" rel="noopener">' + esc(t('powered')) + '</a></div>'; }
  // On a product page the questions go with the product, invisibly: the field says so, nothing else does.
  function renderCtx() {
    var ph = S.askMode && S.ctx && rec(S.ctx) ? t('placeholderProduct') : t('placeholder');
    if (INPUT.placeholder !== ph) { INPUT.placeholder = ph; INPUT.setAttribute('aria-label', ph); }
  }

  // ---------------------------------------------------------------- welcome (empty drawer)
  // "What people ask here": only questions with an answer shoppers already got, shared in the store's bank (it
  // keeps an answer only once the daily review scored it well and flagged nothing), shown as they are on a click.
  // Those asked most come first (asked at least twice); the rest of the five are of each kind: three about products,
  // one about the store's service (shipping, returns), one about a guide or post. Never an example the assistant
  // may not be able to answer.
  var ASK_SHOWN = 5, ASK_PRODUCT = 1;
  // What people ask here: questions about the store first (shipping, returns, warranty), the most asked of them first, then
  // about its guides and pages; a question about one product at most ASK_PRODUCT, and it says which product. Only what reads
  // as a question: a product's name typed into the box ("טבעת וינטג' משובצת יהלומים…") is not one.
  function peopleAsk() {
    var E = S.engine;
    var asks = function (q) { q = String(q || '').trim(); return /\?\s*$/.test(q) || !!(E && E.isQuestion && E.isQuestion(q)); };
    var all = Object.keys(S.records).map(function (k) { return S.records[k]; }).filter(function (r) { return r.t === 'answer' && r.ans && asks(r.title); });
    // an index from before kinds were kept: its store answers, as before
    if (!all.some(function (r) { return r.ak; })) { return all.filter(function (r) { return r.w === 1; }).slice(0, 2); }
    var rank = { store: 0, content: 1, product: 2 };
    all.sort(function (a, b) { return (rank[a.ak] === undefined ? 3 : rank[a.ak]) - (rank[b.ak] === undefined ? 3 : rank[b.ak]) || (b.an || 0) - (a.an || 0); });
    var out = [], products = 0;
    all.forEach(function (r) {
      if (out.length >= ASK_SHOWN) { return; }
      if (r.ak === 'product') { if (products >= ASK_PRODUCT || !(r.src && r.src[0] && r.src[0].title)) { return; } products++; }
      out.push(r);
    });
    return out;
  }
  // the shop's most added to the cart lately, under what people ask (the index config's wanted); none, no section
  function wantedHtml() {
    var list = (S.config.wanted || []).map(rec).filter(Boolean);
    if (!list.length) { return ''; }
    return '<div class="ls-sec__title" style="margin-top:18px">' + esc(t('wanted')) + '</div>' + miniRow(list.map(function (r) { return { id: r.id, title: r.title, url: r.url, image: r.img, price: r.pr }; }));
  }
  function welcome() {
    // the shop's own most searched words (the index config), never anyone else's: none yet, no section
    var pop = S.config.popular || [];
    var asks = off('ask') ? [] : peopleAsk();
    return '<div class="ls-welcome"><h2 class="ls-welcome__title">' + esc(t('welcomeTitle')) + '</h2><p class="ls-welcome__sub">' + esc(t(welcomeSub())) + '</p>' +
      // one row that scrolls (a swipe, or a drag with the mouse); the small button opens them all
      (pop.length ? '<div class="ls-sec__title">' + esc(t('popular')) + (pop.length > 3 ? '<button class="ls-sec__more" data-pop-all aria-expanded="' + !!S.popAll + '">' + esc(t(S.popAll ? 'popLess' : 'popAll')) + '</button>' : '') + '</div>' +
        '<div class="ls-chips ls-chips--drag' + (S.popAll ? ' is-all' : '') + '" style="margin-bottom:18px">' + pop.map(function (q) { return '<button class="ls-chip ls-chip--quiet" data-q="' + esc(q) + '">' + icon('trend', 'ls-icon--sm') + esc(q) + '</button>'; }).join('') + '</div>' : '') +
      // the shopper's own recent searches, from this browser only (remember): none yet, no section
      (S.recent.length ? '<div class="ls-sec__title">' + esc(t('recent')) + '</div><div class="ls-chips ls-chips--drag" style="margin-bottom:18px">' + S.recent.map(function (q) {
        return '<span class="ls-chip ls-chip--quiet ls-recent"><button class="ls-recent__q" data-recent="' + esc(q) + '">' + icon('history', 'ls-icon--sm') + '<span dir="auto">' + esc(q) + '</span></button>' +
          '<button class="ls-recent__x" data-unrecent="' + esc(q) + '" aria-label="' + esc(t('removeRecent') + ': ' + q) + '">' + icon('x', 'ls-icon--sm') + '</button></span>';
      }).join('') + '</div>' : '') +
      (asks.length ? '<div class="ls-sec__title">' + esc(t('tryAsk')) + '</div>' : '') + '<div class="ls-stack">' + asks.map(function (a) {
        // a question about one product or guide says which: "על מה הספר?" alone says nothing here
        var about = a.ak && a.ak !== 'store' && a.src && a.src[0] && a.src[0].title ? '<span class="ls-ask__about" dir="auto">' + esc(t(a.ak === 'product' ? 'askedAboutProduct' : 'askedAbout', { t: decode(a.src[0].title) })) + '</span>' : '';
        return '<button class="ls-ask ls-ask--example" data-saved-answer="' + esc(a.id) + '"><span class="ls-ask__mark">' + mark() + '</span><span class="ls-ask__text"><span class="ls-ask__q" style="font-weight:400" dir="auto">' + esc(a.title) + '</span>' + about + '</span></button>';
      }).join('') +
      '</div>' + wantedHtml() + '<div class="ls-stack"><button class="ls-row" data-photo>' + icon('camera') + '<span class="ls-row__text">' + esc(t('photo')) + '</span></button></div></div>';
  }

