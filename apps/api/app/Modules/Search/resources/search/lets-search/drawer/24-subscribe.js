  // ---------------------------------------------------------------- search subscriptions
  // Search Subscriptions (the design of 18.11, docs: lets-search-subscriptions): a shopper follows a search and gets one
  // email a week at most with its new products in stock. Only where the store has it (the index's config.subscribe, sent
  // only for a store whose plan carries it and that turned it on), and only under a search sent: never while typing,
  // never for letters at random (resultsHtml decides where: subsHead, subsTail).
  //   1. the offer at the end of the newest search's results, or only in the no-exact-match state (config.subscribe.at);
  //   2. the same card opened in place into its form: the address, an unchecked consent, the button off until both;
  //   3. "Almost there! Check your email" (nothing is sent before the shopper confirms from that email);
  //   4. the bar above the results of a search this browser already follows, confirmed.
  // The offer's X hides it for 30 days in this browser, and for the rest of this visit even where storage is blocked.
  // What this browser remembers per search is the server's token only (it names no address): whether the search is
  // confirmed, and the way to stop it from the bar. The address typed stays in memory for this page, never stored, and is
  // never filled in from a logged-in customer.
  var SUBS_KEY = 'ls_subs', SUBS_HIDE_DAYS = 30;
  var SUBS_UI = {};       // by turn: { mode: 'form' | 'sent', email, ok, busy, err }
  var SUBS_ASKED = {};    // tokens whose state was asked on this page
  var SUBS_OFF = false;   // the server said no (the store turned it off since the index was read)
  var SUBS_EMAIL = '';    // the address typed last on this page, for the next form
  function subsCfg() { return !SUBS_OFF && S.config && S.config.subscribe ? S.config.subscribe : null; }
  // the search as this browser keys it: lower case, no quotes or marks, one space between words
  function subsKey(q) { return String(q || '').toLowerCase().replace(/[׳״'"`’‘“”]/g, '').replace(/[\s.,!?;:()[\]{}\/\\|+*=_~#%^&$-]+/g, ' ').trim(); }
  function subsData() { var d = store('local', SUBS_KEY); return d && typeof d === 'object' ? d : {}; }
  function subsEntry(q) { var d = subsData(); return d.q && d.q[subsKey(q)] || null; }
  function subsRemember(q, token, state) {
    var d = subsData(), k = subsKey(q);
    d.q = d.q || {};
    if (token) { d.q[k] = { t: token, s: state }; } else { delete d.q[k]; }
    store('local', SUBS_KEY, d);
  }
  function subsHidden() {
    if (store('session', 'ls_subs_x')) { return true; }
    var d = subsData();
    return !!d.hide && d.hide > Date.now();
  }
  // words that may be followed, as the server keeps them: no address, no phone, no markup, not too long
  function subsKeepable(q) {
    q = String(q || '').trim();
    return q.length >= 2 && q.length <= 120 && !/[<>{}@]/.test(q) && !/(?:\d[\s-]?){7,}/.test(q) && subsKey(q) !== '';
  }
  function subsEmailOk(e) { return /^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/.test(String(e || '').trim()); }
  // the words in a title: cut at about 30 letters, so a long search never pushes the button off the screen
  function subsShort(q) { var w = String(q || '').replace(/[“”"״]/g, '').trim(); return w.length > 30 ? w.slice(0, 29).replace(/\s+\S*$/, '') + '…' : w; }
  function subsStore() { var c = subsCfg() || {}; return String(c.store || (S.config && S.config.shopName) || '').trim(); }
  // a sentence with the search and the store in it, each its own direction
  function subsSay(key) {
    var s = esc(t(key, { q: '@@q@@', store: '@@store@@' }));
    return function (q) { return s.split('@@q@@').join('<bdi>' + esc(subsShort(q)) + '</bdi>').split('@@store@@').join('<bdi>' + esc(subsStore()) + '</bdi>'); };
  }
  function subsArr(color) { return '<svg class="ls-sub__arr" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="' + color + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + (DIR === 'rtl' ? 'M14.5 6.5 9 12l5.5 5.5' : 'M9.5 6.5 15 12l-5.5 5.5') + '"/></svg>'; }
  function subsX(label, act) { return '<button type="button" class="ls-sub__x" data-ss="' + act + '" aria-label="' + esc(label) + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0b0b0c" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>'; }
  // the newest search sent: the one the offer stands under (one offer on the screen, never one under every search)
  function subsNewest() { for (var i = S.turns.length - 1; i >= 0; i--) { if (S.turns[i].kind === 'results' && !S.turns[i].prev) { return S.turns[i]; } } return null; }

  // 4. above the results of a search followed here, confirmed; one still waiting is asked about, once a page
  function subsAbove(turn) {
    if (!subsCfg() || !turn || turn === S.live || turn.kind !== 'results' || turn.loading || turn.error) { return ''; }
    var e = subsEntry(turn.q);
    if (!e) { return ''; }
    if (e.s !== 'active') { subsCheck(turn.q, e); return ''; }
    return '<div class="ls-subbar" role="status"><span class="ls-subbar__tile" aria-hidden="true"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#0b0b0c" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>' +
      '<span class="ls-subbar__text"><span class="ls-subbar__title">' + esc(t('subBarTitle')) + '</span><span class="ls-subbar__line">' + esc(t('subBarLine')) + '</span></span>' +
      '<button type="button" class="ls-subbar__stop" data-ss="stop">' + esc(t('subStop')) + '</button></div>';
  }
  // 1–3. at the end of the results (noMatch: the no-exact-match state, where the store may want it only)
  function subsAfter(turn, noMatch) {
    var cfg = subsCfg();
    if (!cfg || !turn || turn === S.live || turn.kind !== 'results' || turn.loading || turn.error || !subsKeepable(turn.q)) { return ''; }
    var e = subsEntry(turn.q), ui = SUBS_UI[turn.id];
    if (e && e.s === 'active') { return ''; }
    if (ui && ui.mode === 'sent') { return subsSentHtml(turn, ui); }
    if (ui && ui.mode === 'form') { return subsFormHtml(turn, ui); }
    if ((cfg.at === 'no_match' && !noMatch) || subsHidden() || turn !== subsNewest()) { return ''; }
    return '<div class="ls-sub" data-ss-card>' + subsX(t('subClose'), 'hide') +
      '<span class="ls-sub__tile" aria-hidden="true"><svg class="ls-sub__bell" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0b0b0c" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 1.5h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/></svg></span>' +
      '<div class="ls-sub__text"><p class="ls-sub__title">' + subsSay('subTitle')(turn.q) + '</p><p class="ls-sub__line">' + esc(t('subLine')) + '</p></div>' +
      '<button type="button" class="ls-sub__btn" data-ss="open">' + esc(t('subBtn')) + subsArr('#0b0b0c') + '</button></div>';
  }
  function subsFormHtml(turn, ui) {
    var id = 'ls-ss-' + turn.id, ready = subsEmailOk(ui.email) && ui.ok && !ui.busy;
    return '<form class="ls-sub ls-sub--form" data-ss-form="' + esc(turn.id) + '" novalidate>' + subsX(t('subCancel'), 'cancel') +
      '<div class="ls-sub__text"><p class="ls-sub__title">' + subsSay('subFormTitle')(turn.q) + '</p><p class="ls-sub__line">' + esc(t('subFormLine')) + '</p></div>' +
      '<div class="ls-sub__field"><label class="ls-sub__label" for="' + id + '-em">' + esc(t('subEmail')) + '</label>' +
      '<input class="ls-sub__input" id="' + id + '-em" name="email" type="email" dir="ltr" inputmode="email" autocomplete="email" autocapitalize="off" spellcheck="false" placeholder="name@example.com" data-ss-email value="' + esc(ui.email || '') + '"' + (ui.err ? ' aria-invalid="true" aria-describedby="' + id + '-err"' : '') + '></div>' +
      (ui.err ? '<p class="ls-sub__err" id="' + id + '-err" role="alert">' + esc(ui.err) + '</p>' : '') +
      '<label class="ls-sub__check" for="' + id + '-ok"><input id="' + id + '-ok" name="consent" type="checkbox" data-ss-ok' + (ui.ok ? ' checked' : '') + '><span>' + subsSay('subConsent')(turn.q) + '</span></label>' +
      '<button type="submit" class="ls-sub__go" data-ss-go' + (ready ? '' : ' disabled') + (ui.busy ? ' aria-busy="true"' : '') + '>' + esc(t('subSubmit')) + subsArr('#ffffff') + '</button></form>';
  }
  function subsSentHtml(turn, ui) {
    return '<div class="ls-sub" data-ss-card role="status"><span class="ls-sub__tile" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0b0b0c" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="m4.5 7 7.5 6 7.5-6"/></svg></span>' +
      '<div class="ls-sub__text" style="padding-inline-end:0"><p class="ls-sub__title" style="-webkit-line-clamp:unset;display:block">' + esc(t('subSentTitle')) + '</p>' +
      '<p class="ls-sub__sent">' + esc(t('subSentTo')) + '<span class="ls-sub__mail" dir="ltr">' + esc(ui.email) + '</span>' + subsSay('subSentThen')(turn.q) + '</p></div>' +
      '<p class="ls-sub__links">' + esc(t('subNotGot')) + ' <button type="button" class="ls-sub__link" data-ss="resend"' + (ui.busy ? ' disabled' : '') + '>' + esc(t('subResend')) + '</button> · <button type="button" class="ls-sub__link" data-ss="change">' + esc(t('subChange')) + '</button></p></div>';
  }

  function subsPost(path, body) { return json(API + '/search/' + SITE + path, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(body), wait: 20000 }); }
  // a search this browser subscribed to, not yet known to be confirmed: asked once a page; confirmed, the bar shows
  function subsCheck(q, e) {
    if (SUBS_ASKED[e.t]) { return; }
    SUBS_ASKED[e.t] = 1;
    setTimeout(function () {
      subsPost('/subscription', { token: e.t }).then(function (r) {
        var st = r.ok && r.body ? r.body.status : null;
        if (st === 'active') { subsRemember(q, e.t, 'active'); render(); } else if (st === 'stopped' || st === 'none') { subsRemember(q, null); }
      }).catch(function () { /* asked again on the next page */ });
    }, 0);
  }
  // the sign-up (and "Resend": the same, a minute apart at most on the server): resolves to the words of what went wrong, or ''
  function subsSend(turn, ui) {
    ui.busy = true;
    return subsPost('/subscribe', { email: String(ui.email || '').trim(), query: String(turn.q || '').trim(), consent: true, consent_text: t('subConsent', { store: subsStore() }), locale: LANG }).then(function (r) {
      var b = r.body || {}, st = b.status;
      ui.busy = false;
      if (r.status === 403 && b.error === 'not_enabled') { SUBS_OFF = true; return 'off'; }
      if (r.ok && b.token && (st === 'confirm_sent' || st === 'already')) {
        subsRemember(turn.q, b.token, b.confirmed ? 'active' : 'pending');
        if (b.confirmed) { delete SUBS_UI[turn.id]; } else { ui.mode = 'sent'; }
        return '';
      }
      return st === 'invalid_email' ? t('subErrEmail') : st === 'too_many' ? t('subErrMany') : st === 'not_keepable' ? t('subErrQuery') : t('subErrLater');
    }, function () { ui.busy = false; return t('subErrLater'); });
  }
  function subsFocusEmail(tid) { var el = BODY.querySelector('[data-ss-form="' + tid + '"] [data-ss-email]'); if (el) { el.focus({ preventScroll: true }); } }

  root.addEventListener('click', function (e) {
    var el = e.target.closest && e.target.closest('[data-ss]');
    if (!el) { return; }
    var sec = el.closest('[data-tid]'), turn = sec ? turnById(sec.dataset.tid) : null, act = el.dataset.ss;
    if (!turn) { return; }
    e.preventDefault();
    var ui = SUBS_UI[turn.id];
    if (act === 'open') { SUBS_UI[turn.id] = { mode: 'form', email: SUBS_EMAIL, ok: false, busy: false, err: '' }; render(); subsFocusEmail(turn.id); return; }
    if (act === 'cancel') { delete SUBS_UI[turn.id]; render(); return; }
    if (act === 'hide') {
      var d = subsData(); d.hide = Date.now() + SUBS_HIDE_DAYS * 864e5; store('local', SUBS_KEY, d); store('session', 'ls_subs_x', 1);
      render(); return;
    }
    if (act === 'change' && ui) { ui.mode = 'form'; ui.err = ''; render(); subsFocusEmail(turn.id); return; }
    if (act === 'resend' && ui && !ui.busy) {
      ui.busy = true; render();
      subsSend(turn, ui).then(function (err) { render(); toast(err === 'off' ? t('subErrLater') : err || t('subResent')); });
      return;
    }
    if (act === 'stop') {
      var en = subsEntry(turn.q);
      subsRemember(turn.q, null);
      render(); toast(t('subStopped'));
      if (en && en.t) { subsPost('/subscription/stop', { token: en.t }).catch(function () { /* the email's own link still stops it */ }); }
    }
  });
  // the form's own state as it is typed, so a redraw keeps it; only its button changes, never the focus
  function subsTyped(e) {
    var f = e.target.closest && e.target.closest('[data-ss-form]'), ui = f && SUBS_UI[f.dataset.ssForm];
    if (!ui) { return; }
    ui.email = f.email.value; ui.ok = f.consent.checked; SUBS_EMAIL = ui.email;
    var go = f.querySelector('[data-ss-go]');
    if (go) { go.disabled = !(subsEmailOk(ui.email) && ui.ok) || ui.busy; }
  }
  root.addEventListener('input', subsTyped);
  root.addEventListener('change', subsTyped);
  root.addEventListener('submit', function (e) {
    var f = e.target.closest && e.target.closest('[data-ss-form]');
    if (!f) { return; }
    e.preventDefault();
    var turn = turnById(f.dataset.ssForm), ui = turn && SUBS_UI[turn.id];
    if (!ui || ui.busy) { return; }
    ui.email = String(f.email.value || '').trim(); ui.ok = f.consent.checked; SUBS_EMAIL = ui.email;
    if (!subsEmailOk(ui.email)) { ui.err = t('subErrEmail'); render(); subsFocusEmail(turn.id); return; }
    if (!ui.ok) { return; }
    ui.err = ''; ui.busy = true;
    render();
    subsSend(turn, ui).then(function (err) {
      if (err === 'off') { delete SUBS_UI[turn.id]; render(); return; }
      ui.err = err; render();
      if (err) { subsFocusEmail(turn.id); }
    });
  });
