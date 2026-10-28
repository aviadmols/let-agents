  // ---------------------------------------------------------------- events
  INPUT.addEventListener('input', onType);
  INPUT.addEventListener('focus', function () { setTimeout(onType, 0); });
  INPUT.addEventListener('keydown', function (e) {
    var opts = SUG.classList.contains('is-on') ? SUG.querySelectorAll('[data-opt]') : [];
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!opts.length) { return; }
      e.preventDefault();
      S.active = e.key === 'ArrowDown' ? Math.min(opts.length - 1, S.active + 1) : Math.max(-1, S.active - 1);
      mark_active();
    } else if (e.key === 'Enter') {
      // Enter keeps the search: the live results become a turn in the conversation, as they are
      e.preventDefault();
      var o = opts[S.active];
      if (o && o.dataset.notSure) { notSure(turnById(o.dataset.notSure)); return; }
      if (o && o.dataset.ask) { submit(INPUT.value, 'ask'); return; }
      if (o) { o.click(); return; }
      submit(INPUT.value, undefined, false, true);
    } else if (e.key === 'Escape') {
      if (SUG.classList.contains('is-on') && INPUT.value) { e.preventDefault(); e.stopPropagation(); INPUT.value = ''; onType(); }
    }
  });
  root.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (!LAYER.hidden) { hideLayer(); return; }
      if (!MENU.hidden) { if (S.menu) { showMenu(''); } else { hideMenu(); D.querySelector('[data-menu]').focus(); } return; }
      if (S.wide) { narrow(); render(); return; }
      close();
    }
    if (e.key === 'Tab' && S.open) {
      // keep focus inside the drawer
      var f = Array.prototype.filter.call(D.querySelectorAll('button:not([disabled]), a[href], input:not([type="hidden"]):not([hidden]), textarea, [tabindex]:not([tabindex="-1"]), summary'), function (x) { return x.offsetParent !== null; });
      if (!f.length) { return; }
      var first = f[0], last = f[f.length - 1], cur = root.activeElement;
      if (e.shiftKey && cur === first) { e.preventDefault(); last.focus(); } else if (!e.shiftKey && cur === last) { e.preventDefault(); first.focus(); }
    }
  });
  root.addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target;
    if (f.matches('[data-lead]')) {
      var turn = S.turns.filter(function (x) { return x.id === f.dataset.lead; })[0];
      var name = f.name.value.trim(), contact = f.contact.value.trim(), ok = f.consent.checked, digits = contact.replace(/\D/g, '');
      var question = f.question ? f.question.value.trim() : String(turn.q || '');
      // a phone (or an email) is enough: the name is welcome, never a reason to lose the details
      var errs = { question: !question, name: false, contact: !(/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(contact) || (digits.length >= 9 && digits.length <= 12 && !/[a-z]/i.test(contact))), consent: !ok };
      turn.ln = name; turn.lc = contact; turn.lk = ok; turn.lq = question;
      if (errs.question || errs.name || errs.contact || errs.consent) { turn.errs = errs; render(); var bad = BODY.querySelector('[data-lead="' + turn.id + '"] [aria-invalid="true"]'); if (bad) { bad.focus(); } return; }
      turn.errs = {};
      // the shop's callback about this question, as the search drawer always sent it (shoppers.callbacks)
      json(API + '/widget/' + SITE + '/signup', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ vid: visitor(), contact: contact, name: name, consent: true, locale: LANG, question: question, type: turn.kind === 'about' ? 'product' : 'search', id: turn.kind === 'about' ? String(turn.id) : turn.askId || 'search' }) }).then(function (r) {
        var status = r.body && r.body.data ? r.body.data.status : null;
        if (status === 'invalid_contact') { turn.errs = { contact: true }; render(); return; }
        if (status !== 'saved' && status !== 'code_sent') { throw new Error('lead'); }
        visitStep('lead');
        update(turn, { lead: 'done', leadName: name, leadErr: false });
      }).catch(function () { update(turn, { leadErr: true }); });
      return;
    }
    if (f.matches('[data-shop-lead]')) { var st = turnById(f.dataset.shopLead); if (st) { sendShopLead(st, f); } return; }
    if (f.matches('[data-reply]')) {
      // a reply to an answer: asked with the questions and answers before it (the server reads it with them, never saved)
      var rt0 = turnById(f.dataset.reply), said = f.querySelector('input');
      if (rt0 && said && said.value.trim()) {
        var thread = (rt0.thread || []).concat([{ q: String(rt0.q || ''), a: String(rt0.answer || '') }]).slice(-3);
        // what the answer showed goes with it: a reply mostly asks more about one of them ("כמה פעמים לקחת אותה?")
        var had = (rt0.picks || []).map(function (p) { return pid(p.id); }).concat((rt0.alsoIds || []).map(pid)).slice(0, 8);
        askTurn(said.value.trim(), had.length ? { thread: thread, products: had, previous: [] } : { thread: thread, previous: [] }, !!rt0.ctx, { thread: thread });
      }
      return;
    }
    if (f.matches('[data-ask-about-form]')) { var at = turnById(f.dataset.askAboutForm), own = f.querySelector('input'); if (at && own && own.value.trim()) { askAbout(at, own.value); } return; }
    if (f.matches('[data-lead-flow]')) {
      var lt = turnById(f.dataset.leadFlow), lf = lt && lt.leadFlow;
      if (!lf || lf.busy || lf.state === 'declined' || lf.state === 'done') { return; }
      if (lf.state === 'field') { lf.given[lf.field.key] = f.v.value; leadStep(lt, {}); return; }
      if (!f.consent.checked) { lf.error = bankLabel('signup_need_consent', t('leadNeedConsent')); render(); return; }
      leadStep(lt, { consent: true });
      return;
    }
    if (f.matches('[data-phone-form]')) {
      if (S.phoneStep === 'num') {
        var ph = f.phone.value.replace(/\D/g, '');
        if (ph.length < 9 || ph.length > 12) { S.phoneErr = t('badPhone'); render(); return; }
        S.phoneNum = f.phone.value.trim();
        wishPhone('code', { phone: S.phoneNum }).then(function (dd) { if (!dd.sent) { S.phoneErr = shortWord('phone_' + (dd.error || 'unavailable'), t('badPhone')); render(); return; } S.phoneStep = 'code'; S.phoneErr = ''; render(); var ci = BODY.querySelector('#lscd'); if (ci) { ci.focus(); } });
      } else {
        wishPhone('verify', { phone: S.phoneNum, code: f.code.value.trim(), token: WISH.token, consent: !!(f.consent && f.consent.checked) }).then(function (dd) {
          if (!dd.ok) { S.phoneErr = shortWord('phone_' + (dd.error || 'bad_code'), t('badCode')); render(); return; }
          WISH.phone = dd.session; WISH.phoneShown = dd.phone; WISH.pending = []; wishSave();
          S.phone = dd.phone || S.phoneNum; S.phoneStep = ''; S.phoneErr = ''; render();
          wishSync();
        });
      }
    }
  });
  root.addEventListener('change', function (e) {
    var pk = e.target.closest('[data-pick]');
    if (pk) {
      var id = pk.dataset.pick;
      if (pk.checked) { if (S.pick.length >= 5) { pk.checked = false; toast(t('compareMax', { n: 5 })); return; } S.pick.push(id); } else { S.pick = S.pick.filter(function (x) { return x !== id; }); }
      // only the compare bar changes: the list (and focus) stays put
      var cb = BODY.querySelector('[data-compare]'), n = S.pick.length;
      if (cb) { cb.disabled = n < 2; cb.innerHTML = icon('compare', 'ls-icon--sm') + esc(n < 2 ? t('compareNeed') : t('compareN', { n: n + '/5' })); }
      return;
    }
    if (e.target.matches('[data-onlydiff]')) { S.onlyDiff = e.target.checked; render(); return; }
    if (e.target.matches('[data-file]') && e.target.files && e.target.files[0]) { photoFile(e.target.files[0]); }
  });
  root.addEventListener('toggle', function (e) { var d = e.target; if (d && d.open && d.matches && d.matches('[data-cand]')) { var s2 = sectionOf(d.getAttribute('data-cand')); if (s2) { learn('open', s2, 'panel'); } } }, true);
  // which search or question a press belongs to: the live results, a turn, or the wide grid
  function turnOf(el) {
    if (el.closest('.ls-turn--live')) { return S.live; }
    var sec = el.closest('[data-tid]');
    if (sec) { return turnById(sec.dataset.tid); }
    return S.wide && FLOW.contains(el) ? turnById(S.wide) : null;
  }
  function itemOf(id) { var r = rec(id); return { id: 'p:' + pid(id), title: r ? decode(r.title) : '' }; }
  // the search's counting, as before: opening or adding a product counts as a click on the search;
  // acting on the assistant's pick, or its WhatsApp, counts on the question
  function countPress(el) {
    var turn = turnOf(el);
    // left the page through one of the products a turn showed (a search's, an answer's, similar products): the next
    // opening comes back to that turn, as it was shown, at that product
    var left = el.tagName === 'A' && !el.dataset.heart ? el.closest('[data-card]') : null;
    if (turn && left) { keepBackTo(turn, left.dataset.card); }
    if (!turn || !turn.q) { return null; }
    var card = el.closest('[data-card]'), pick = el.closest('.ls-pick'), after = pick && pick.querySelector('[data-after]');
    var id = card ? card.dataset.card : after ? after.dataset.after : (el.dataset.add || el.dataset.options || el.dataset.goto);
    var opening = (el.tagName === 'A' || el.dataset.add || el.dataset.options) && el.dataset.state !== 'done';
    if (turn.kind === 'results' && id && opening && !el.dataset.heart) { countClick(turn.q, itemOf(id), filtered(turn).length); }
    // left the page through one of its results: the next opening, on that page or in another tab, comes back to it
    if (el.tagName === 'A' && id && opening && (turn === S.live || turn.kind === 'results')) {
      keepBack(turn.q, turn === S.live ? null : turn.id, turn !== S.live && S.wide === turn.id);
    }
    if (turn.kind === 'answer') {
      if (pick && id && opening) { var ev = { type: 'ask_pick', q: turn.q, id: pid(id) }; if (turn.askId) { ev.ask_id = turn.askId; } sendEvents([ev]); }
      if (el.tagName === 'A' && /wa\.me/.test(el.getAttribute('href') || '')) { var wa = { type: 'ask_whatsapp', q: turn.q }; if (turn.askId) { wa.ask_id = turn.askId; } sendEvents([wa]); }
    }
    return turn.kind === 'results' || turn.kind === 'answer' ? turn.q : null;
  }
  // a picture that fails leaves its frame empty and clean, never the browser's broken-image mark
  root.addEventListener('error', function (e) { if (e.target && e.target.tagName === 'IMG') { e.target.classList.add('is-broken'); } }, true);
  root.addEventListener('click', function (e) {
    var el = e.target.closest('button, a, [data-close]');
    if (el && !(el.closest('.ls-mini-row') && dragged)) {
      S.pressQ = countPress(el);
      var cnode = el.tagName === 'A' && el.closest('[data-cand]'), csec = cnode && sectionOf(cnode.getAttribute('data-cand'));
      if (csec) { var mp = el.closest('.ls-mini'), gd = el.dataset.guide; learn('click', csec, 'panel', mp ? { product_id: pid((el.getAttribute('data-pid') || '')) || undefined } : gd ? { content_id: gd } : undefined); }
    }
    // a press anywhere outside the menu closes it. The path is the one the press took, so a sheet that
    // redrew itself on this press (a filter, an option) still counts as inside.
    var path = e.composedPath ? e.composedPath() : [];
    var inside = function (node) { return path.indexOf(node) >= 0 || node.contains(e.target); };
    if (!MENU.hidden && !inside(MENU) && !e.target.closest('[data-menu]')) { hideMenu(); }
    // and a press outside a sheet (photo, filters, sort, options) closes the sheet; on its dimmed ground, nothing else happens
    if (!LAYER.hidden && LAYER.classList.contains('is-on') && !inside(LAYER)) {
      hideLayer();
      if (e.target.closest('.ls-layer-scrim')) { return; }
    }
    if (!el) { return; }
    var d = el.dataset;
    // the press that ends a drag of a row of chips is not a press on a chip
    if (el.closest('.ls-chips--drag') && dragged) { e.preventDefault(); dragged = false; return; }
    if (d.popAll !== undefined) { S.popAll = !S.popAll; render(); return; }
    if (d.close !== undefined) { e.preventDefault(); close(); return; }
    if (d.layerClose !== undefined) { hideLayer(); return; }
    if (d.tab) { S.tab = d.tab; S.wide = null; if (S.tab !== 'chat') { hideLive(); } render(); if (S.tab === 'chat') { INPUT.focus({ preventScroll: true }); } return; }
    if (d.menu !== undefined) { if (MENU.hidden) { showMenu(''); } else { hideMenu(); } return; }
    if (d.menuView !== undefined) { showMenu(d.menuView); return; }
    if (d.ai) { askAi(d.ai); return; }
    if (d.aiAsk) { askAiAbout(turnById(d.turn), d.aiAsk); return; }
    if (d.geminiGo !== undefined) { geminiGo(); return; }
    if (d.share && el.closest('.ls-menu')) { shareGo(d.share); return; }
    if (d.expand !== undefined) { hideMenu(); toggleExpand(); return; }
    if (d.new !== undefined) {
      var old = S.turns; hideMenu(); S.turns = []; S.wide = null; S.tab = 'chat'; store('local', 'ls_back', null); keepInField(''); persist(); render();
      toast(t('chatCleared'), function () { S.turns = old; persist(); render(); });
      return;
    }
    if (d.clear !== undefined) { e.preventDefault(); INPUT.value = ''; INPUT.focus(); onType(); return; }
    if (d.submit !== undefined) { e.preventDefault(); submit(INPUT.value, undefined, false, true); return; }
    // one of the shopper's own recent searches, run again: still theirs, so it comes first
    if (d.recent) { e.preventDefault(); submit(d.recent, 'search', false, true); return; }
    if (d.photo !== undefined) { e.preventDefault(); photoPick(); return; }
    if (d.focus !== undefined) { INPUT.focus(); return; }
    if (d.q) { e.preventDefault(); if (d.loose) { signalEvent('loose_pick', d.loose, d.q); } submit(d.q, 'search'); return; }
    if (d.notSure) { e.preventDefault(); notSure(turnById(d.notSure)); return; }
    if (d.askAbout) { e.preventDefault(); askAbout(turnById(d.askAbout), d.question); return; }
    if (d.ask) { e.preventDefault(); if (d.askcard !== undefined && d.from) { signalEvent('ask_after', d.from, ''); } submit(d.ask, 'ask', !!el.closest('.ls-about-turn')); return; }
    if (d.savedAnswer) {
      e.preventDefault();
      var a = S.records[d.savedAnswer];
      INPUT.value = ''; hideLive(); FIELD.classList.remove('has-text');
      alsoFor(addTurn({ kind: 'answer', q: a.title, answer: a.ans, picks: [], sources: a.src || [], anim: 1 }));
      return;
    }
    if (d.unrecent) { e.preventDefault(); S.recent = S.recent.filter(function (x) { return x !== d.unrecent; }); store('local', 'ls_searches', S.recent); render(); return; }
    if (d.orderPick) { var op = d.orderPick.split(':'), ot = turnById(op[0]); if (ot) { ot.pickedAt[op[1]] = ot.pickedAt[op[1]] === Number(op[2]) ? null : Number(op[2]); render(); } return; }
    if (d.orderAdd) { var oa = turnById(d.orderAdd); if (oa && !oa.adding) { orderAdd(oa); } return; }
    if (d.slide) { e.preventDefault(); slide(el); return; }
    if (el.closest('.ls-mini-row') && dragged) { e.preventDefault(); dragged = false; return; }
    if (d.earlier !== undefined) { S.showAll = true; render(); return; }
    if (d.prev !== undefined) { S.showPrev = true; render(); return; }
    if (d.heart) { e.preventDefault(); var saving = S.saved.indexOf(d.heart) < 0; toggleSaved(d.heart); if (saving && SHORT && SHORT.heartClick === 'open') { S.tab = 'saved'; S.wide = null; hideLive(); render(); } return; }
    if (d.rate) { e.preventDefault(); rate(turnById(d.turn), d.rate === 'down' ? 'down' : 'up'); return; }
    if (d.add) { e.preventDefault(); if (el.dataset.state === 'done') { win.location.href = CFG.cartUrl; return; } addToCart(el, d.add, null, S.pressQ); return; }
    if (d.options) { e.preventDefault(); optionsLayer(d.options, S.pressQ); return; }
    if (d.similar) { e.preventDefault(); similarTurn(d.similar); return; }
    if (d.more) {
      // the next few results, right under the ones seen, once they arrived: never the whole list at once,
      // and the Lets mark turns in the button while they come; nothing moves, nothing jumps to the top
      var mt = turnById(d.more);
      if (!mt || !busy(el)) { return; }
      var rest = filtered(mt).map(function (r) { return r.id; }), from = gridN(mt), upto = Math.min(rest.length, from + STEP);
      var keepY = BODY.scrollTop, again = function () { mt.more = upto; if (mt === S.live) { renderLive(false); } else { persist(); render(); } BODY.scrollTop = keepY; };
      products(rest.slice(from, upto)).then(again, again);
      return;
    }
    if (d.wide) {
      // "show all" from the live results keeps the search first, then opens it large, with its first
      // few products already there: the mark turns in the button until they are
      if (!busy(el)) { return; }
      if (d.wide === 'live') { if (!S.live) { return; } submit(S.live.q, 'search', false, true); d = { wide: S.turns[S.turns.length - 1].id }; }
      var wt = turnById(d.wide), openWide = function () { S.wide = d.wide; wt.limit = STEP; render(); BODY.scrollTop = 0; };
      products(filtered(wt).slice(0, STEP).map(function (r) { return r.id; })).then(openWide, openWide); return;
    }
    if (d.narrow !== undefined) { narrow(); render(); return; }
    if (d.loadmore) {
      // the Lets mark turns over in the button until the next products arrive; a second press waits
      if (el.getAttribute('aria-busy') === 'true') { return; }
      var lt = turnById(d.loadmore); lt.limit = (lt.limit || STEP) + STEP;
      busy(el);
      products(filtered(lt).slice(0, lt.limit).map(function (r) { return r.id; })).then(render, render); return;
    }
    if (d.chip) { var ct = turnById(d.turn); if (ct) { applyChip(ct, d.chip); } return; }
    if (d.filters) { filtersLayer(turnById(d.filters)); return; }
    if (d.sort) { sortLayer(turnById(d.sort)); return; }
    if (d.clearFilters) { var cf = turnById(d.clearFilters); if (cf) { clearC(cf, false); refresh(cf); } return; }
    if (d.retry) {
      var rt = S.turns.filter(function (x) { return x.id === d.retry; })[0];
      S.turns = S.turns.filter(function (x) { return x !== rt; });
      if (rt.kind === 'answer') { askTurn(rt.q, null, !!rt.ctx); } else if (rt.kind === 'results') { searchTurn(rt.q); } else if (rt.kind === 'similar') { similarTurn(rt.of, rt.oos ? null : rt.size, rt.oos ? { whole: rt.oos.whole, want: rt.oos.want, size: rt.size } : null, rt.other, rt.asked); }
      return;
    }
    if (d.leadFlowNo) { var ln = turnById(d.leadFlowNo); if (ln && ln.leadFlow && !ln.leadFlow.busy) { leadStep(ln, { declined: true }); } return; }
    if (d.leadNo) { var lt2 = S.turns.filter(function (x) { return x.id === d.leadNo; })[0]; update(lt2, { lead: 'no' }); return; }
    if (d.tagOff) { var pr = d.tagOff.split(':'), pt = S.turns.filter(function (x) { return x.id === pr[0]; })[0]; pt.off[pr[1]] = !pt.off[pr[1]]; persist(); render(); return; }
    if (d.photoItem) {
      var pi = d.photoItem.split(':'), ptn = S.turns.filter(function (x) { return x.id === pi[0]; })[0], at = Number(pi[1]);
      if (ptn && PHOTOS[ptn.id] && at !== (ptn.item || 0)) { update(ptn, { loading: true }); photoFetch(ptn, PHOTOS[ptn.id], at); }
      return;
    }
    if (d.compare !== undefined) { compare(); return; }
    if (d.copy !== undefined) {
      wishShare(function (link) { link = link || win.location.origin + win.location.pathname + '?lsShared=' + S.saved.join(','); copyText(link, function () { toast(t('copied')); }); });
      return;
    }
    if (d.shareWa !== undefined) {
      // the tab opens inside the press; the link fills it once the server gave the list its code
      var waTab = win.open('about:blank', '_blank');
      wishShare(function (link) {
        var text = shortWord('share_text', t('saved')) + ' ' + (link || S.saved.map(function (x) { var r = rec(x); return r ? r.url : ''; }).join(' '));
        if (waTab) { try { waTab.opener = null; } catch (e) { /* fine */ } waTab.location.href = 'https://wa.me/?text=' + encodeURIComponent(text); }
      });
      return;
    }
    if (d.phoneOut !== undefined) { var was = WISH.phone; WISH.phone = null; WISH.phoneShown = null; WISH.wish = []; WISH.pending = []; WISH.share = null; wishSave(); S.saved = []; S.phone = null; store('local', 'ls_saved', []); wishPhone('out', { phone: was }); render(); return; }
    if (d.phoneStart !== undefined) { S.phoneStep = 'num'; render(); var pi = BODY.querySelector('#lsph'); if (pi) { pi.focus(); } return; }
    if (d.why !== undefined) { toast(t('whyShownText')); return; }
    if (d.cmpViewed && viewedBefore()) { S.pick = [viewedBefore().id, pid(S.ctx)]; compare(); return; }
    if (d.cmpViewed) { submit((S.config && S.config.cardAction === 'read_more' ? itemized : String)(LANG === 'he' ? 'מה ההבדל בין המוצר הזה לבין מה שראיתי קודם?' : 'How does this compare with what I viewed?'), 'ask', true); return; }
    if (d.goto && el.tagName === 'A') { remember(INPUT.value.trim() || ''); }
  });
  // on a phone the search field slides away while scrolling down, and comes back on the way up (or at the top);
  // never while it is being typed in
  // It moves only after a steady run one way (30px), never on the bounce at either end, so it never flickers.
  var lastY = 0, run = 0;
  BODY.addEventListener('scroll', function () {
    var y = BODY.scrollTop, max = BODY.scrollHeight - BODY.clientHeight, dy = y - lastY;
    D.querySelector('.ls-dhead').classList.toggle('is-scrolled', y > 4 && !isPhone());
    lastY = y;
    D.classList.toggle('is-field-floating', isPhone() && y > 8);
    if (!isPhone() || y < 60 || root.activeElement === INPUT) { D.classList.remove('is-field-away'); run = 0; return; }
    if (y <= 0 || y >= max - 2) { return; }
    run = dy * run < 0 ? dy : run + dy;
    if (run > 30) { D.classList.add('is-field-away'); } else if (run < -30) { D.classList.remove('is-field-away'); }
  }, { passive: true });
  INPUT.addEventListener('focus', function () { D.classList.remove('is-field-away'); });
  root.addEventListener('focusin', fitViewport); root.addEventListener('focusout', function () { setTimeout(fitViewport, 50); });
  // the results start under the floating field, whatever its height (a context line can make it taller)
  (function () {
    var fieldBox = D.querySelector('.ls-dfield'), setH = function () { D.style.setProperty('--ls-fh', fieldBox.offsetHeight + 'px'); };
    if (win.ResizeObserver) { new win.ResizeObserver(setH).observe(fieldBox); } else { win.addEventListener('resize', setH); }
  })();

  // product sliders: the arrows follow the row's position; a mouse can drag it (and a drag isn't a click)
  var dragged = false;
  root.addEventListener('scroll', function (e) { if (e.target.classList && e.target.classList.contains('ls-mini-row')) { sliderState(e.target); } }, { capture: true, passive: true });
  (function () {
    var row = null, x0 = 0, s0 = 0;
    root.addEventListener('pointerdown', function (e) {
      var r = e.target.closest && e.target.closest('.ls-mini-row, .ls-chips--drag:not(.is-all)');
      if (!r || e.pointerType !== 'mouse' || e.button !== 0) { return; }
      row = r; x0 = e.clientX; s0 = r.scrollLeft; dragged = false;
    });
    win.addEventListener('pointermove', function (e) {
      if (!row) { return; }
      var dx = e.clientX - x0;
      if (!dragged && Math.abs(dx) > 5) { dragged = true; row.classList.add('is-dragging'); }
      if (dragged) { row.scrollLeft = s0 - dx; }
    });
    win.addEventListener('pointerup', function () {
      if (!row) { return; }
      row.classList.remove('is-dragging'); row = null;
      // the click that ends a drag is let go; a plain click still opens the product
      setTimeout(function () { dragged = false; }, 0);
    });
  })();
  // drag the phone sheet down to close
  (function () {
    var startY = null, startT = 0, dy = 0;
    D.addEventListener('pointerdown', function (e) {
      if (!isPhone() || !e.target.closest('[data-drag]') || e.target.closest('button')) { return; }
      startY = e.clientY; startT = Date.now(); dy = 0; D.classList.add('is-dragging');
      try { e.target.setPointerCapture(e.pointerId); } catch (x) { /* none */ }
    });
    D.addEventListener('pointermove', function (e) {
      if (startY == null) { return; }
      dy = e.clientY - startY;
      D.style.setProperty('--ls-drag', (dy > 0 ? dy : dy / 6) + 'px');
    });
    function end() {
      if (startY == null) { return; }
      var v = Math.abs(dy) / Math.max(1, Date.now() - startT);
      D.classList.remove('is-dragging'); startY = null;
      if (dy > 120 || (dy > 20 && v > 0.11)) { close(); } else { D.style.setProperty('--ls-drag', '0px'); }
    }
    D.addEventListener('pointerup', end); D.addEventListener('pointercancel', end);
  })();
  // keep the field above the phone keyboard
  if (win.visualViewport) {
    win.visualViewport.addEventListener('resize', fitViewport); win.visualViewport.addEventListener('scroll', fitViewport);
  }
  win.addEventListener('resize', placePill);

