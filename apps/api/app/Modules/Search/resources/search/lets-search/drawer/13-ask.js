  // ---------------------------------------------------------------- ask turn
  // about: a question on this product (asked from its page's row, or from its questions): page=1
  function askTurn(q, extra, about, mark) {
    var prev = S.turns.slice().reverse().filter(function (x) { return x.kind === 'results' && x.ids; })[0];
    var turn = addTurn(Object.assign({ kind: 'answer', q: q, loading: true, ctx: about && S.ctx ? S.ctx : null, anim: 1 }, mark || {}));
    announce(t('announceSearching'));
    var slow = setTimeout(function () { var el = BODY.querySelector('[data-tid="' + turn.id + '"] .ls-think__text'); if (el) { el.textContent = t('slow'); } }, 8000);
    // about this product: the product page's own ask, so the answer is kept with the product (its questions,
    // "questions shoppers asked", the suggestions, and the gaps the daily review learns from)
    if (turn.ctx) {
      visitStep('ask', { label: String(q).slice(0, 200), item: String(pid(turn.ctx)) });
      again(function () { return json(API + '/widget/' + SITE + '/ask', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ id: String(pid(turn.ctx)), type: 'product', question: q, vid: visitor(), locale: LANG }) }); }).then(function (r) {
        clearTimeout(slow);
        if (!r.ok) { throw new Error('ask'); }
        var d = (r.body && r.body.data) || {};
        LEARN.answered++;
        learn('chat_question', null, null, { length: Math.min(2000, Math.max(1, q.length)), answered_from: d.from === 'bank' ? 'bank' : d.outcome === 'answered' ? 'rag' : 'none' });
        if (d.outcome === 'answered') {
          // a shopper who got an answer is worth asking, once a page, and only where the shop set it up
          var lead = LEARN.bank && LEARN.bank.lead && !LEARN.offered ? { given: {}, step: 0, state: null, busy: true } : null;
          if (lead) { LEARN.offered = true; }
          update(turn, { loading: false, answer: d.answer, picks: [], sources: d.sources || [], general: d.source === 'general', follow: [], leadFlow: lead });
          announce(t('announceReady') + ': ' + d.answer);
          if (lead) { learn('lead_start', null, null, {}); leadStep(turn, {}); }
        } else if (d.outcome === 'quoted' && d.quotes && d.quotes.length) {
          update(turn, { loading: false, quoted: d.quotes, noAnswer: d.no_answer || null });
          announce(t('quotedTitle'));
        } else {
          update(turn, { loading: false, noInfo: true, said: saidOf(d), rel: related(q), h: hours(), noAnswer: d.no_answer || null, notFound: d.not_found || null });
          announce(t('noInfo'));
        }
      }).catch(function () { clearTimeout(slow); update(turn, { loading: false, error: true }); });
      return;
    }
    // as SiteAskController reads it: the visitor (a daily limit), the products shown with the question (the
    // assistant picks from them), the turn before's (for "מה הכי זול?"), and page=1 when it is about this product
    var shown = prev ? filtered(prev).slice(0, 12).map(function (r) { return pid(r.id); }) : [];
    var body = { question: q, vid: visitor(), locale: LANG, products: S.ctx && turn.ctx ? [pid(S.ctx)] : shown };
    if (S.ctx && turn.ctx) { body.page = 1; }
    // the turn before's products, from a product page or after a search still on screen: "מה הכי זול?" or "איזה מהם?"
    // is about them (the server decides by the question's words whether it is); never a search behind the dashed line
    if (shown.length && ((S.ctx && turn.ctx) || !prev.prev)) { body.previous = shown; }
    visitStep('ask', { label: String(q).slice(0, 200) });
    again(function () { return json(API + '/search/' + SITE + '/ask', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(Object.assign(body, extra || {})) }); }).then(function (r) {
      clearTimeout(slow);
      if (!r.ok) { throw new Error('ask'); }
      var d = (r.body && r.body.data) || r.body || {};
      var picks = (d.picks || []).map(function (p) { return { id: pid(p.external_id || p.id), why: p.why, title: p.title, url: p.url, image: p.image }; });
      turn.askId = d.ask_id ? String(d.ask_id) : null;
      // a whole set asked to see ("תציג לי את ספריית שיבולת"): every product of it as a grid, a question to narrow it by, and the search for more
      var set = d.set && d.set.ids && d.set.ids.length ? d.set : null;
      var setIds = set ? set.ids.map(function (id) { return pid(id); }).filter(function (id) { return !!rec(id); }) : null;
      return products(picks.map(function (p) { return p.id; }).concat(setIds ? setIds.slice(0, 24) : [])).then(function () {
        if (d.outcome === 'answered') {
          update(turn, { loading: false, answer: d.answer, picks: picks, sources: d.sources || [], page: d.page || null, general: d.from === 'general', follow: d.follow || [],
            ids: setIds && setIds.length ? setIds : null, setAsk: set && set.ask || null, setSearch: set && set.search || null, corrected: d.corrected || null });
          announce(t('announceReady') + ': ' + d.answer);
          if (!picks.length && !(setIds && setIds.length)) { alsoFor(turn); }
        } else if (d.outcome === 'quoted' && d.quotes && d.quotes.length) {
          // the answer could not be checked: never shown; the store's own words, said to be that
          update(turn, { loading: false, quoted: d.quotes, noAnswer: d.no_answer || null });
          announce(t('quotedTitle'));
        } else {
          var rel = related(q);
          update(turn, { loading: false, noInfo: true, said: saidOf(d), rel: rel, h: hours(), noAnswer: d.no_answer || null, notFound: d.not_found || null });
          announce(t('noInfo'));
        }
      });
    }).catch(function () { clearTimeout(slow); update(turn, { loading: false, error: true }); });
  }
  // the shop's lead flow after an answer (LeadStepController): one field at a time, the server deciding what
  // comes next and whether what was typed is real, then its consent wording; saying no ends it for this page
  function leadStep(turn, extra) {
    var lf = turn.leadFlow;
    lf.busy = true; render();
    json(API + '/widget/' + SITE + '/lead', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ id: String(PAGE.id), type: PAGE.type, vid: visitor(), asked: LEARN.answered, given: lf.given, consent: extra.consent === true, declined: extra.declined === true, at: lf.state || null, enough: !!lf.enough }) }).then(function (r) {
      var d = r.ok && r.body && r.body.data;
      lf.busy = false;
      // a "no thanks" after a phone or an email: what was given is kept once they agree, the rest is not asked
      if (d && d.enough) { lf.enough = true; }
      if (!d) { lf.error = bankLabel('lead_error', t('leadError')); render(); return; }
      lf.error = d.error ? bankLabel('lead_error_' + d.error, bankLabel('lead_error', t('leadError'))) : '';
      if (d.state === 'declined') { learn('lead_declined', null, null, { step: lf.step }); lf.state = 'declined'; }
      else if (d.state === 'done') { learn('lead_done', null, null, { steps: lf.step }); lf.state = 'done'; lf.promise = d.promise || ''; }
      else if (d.state === 'consent') { lf.state = 'consent'; lf.field = null; lf.consentText = d.consent || ''; }
      else if (d.field) { lf.step++; learn('lead_step', null, null, { step: lf.step, field: d.field.type }); lf.state = 'field'; lf.field = d.field; lf.offer = d.offer || ''; }
      render();
      var input = BODY.querySelector('[data-lead-flow="' + turn.id + '"] input[name="v"]');
      if (input) { input.focus({ preventScroll: true }); }
    }).catch(function () { lf.busy = false; lf.error = bankLabel('lead_error', t('leadError')); render(); });
  }
  function leadFlowHtml(turn) {
    var lf = turn.leadFlow;
    if (!lf) { return ''; }
    if (lf.state === 'declined') { return '<p class="ls-noanswer__text" style="margin-top:14px">' + esc(bankLabel('lead_declined', t('leadDeclined'))) + '</p>'; }
    if (lf.state === 'done') { return '<div class="ls-done ls-focus-in" style="margin-top:14px"><span class="ls-done__icon"><svg class="ls-icon ls-check ls-check--draw" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg></span><div style="font-weight:500">' + esc(lf.promise || bankLabel('lead_thanks', t('leadDone', { name: '', when: '' }))) + '</div></div>'; }
    if (!lf.state) { return '<div style="margin-top:14px">' + thinking() + '</div>'; }
    var said = lf.error || (lf.state === 'consent' ? bankLabel('lead_consent_ask', '') : (lf.offer ? lf.offer + ' — ' : '') + bankLabel('lead_ask_field', ':field', { field: lf.field && lf.field.label || '' }));
    var type = lf.field ? (lf.field.type === 'email' ? 'email' : lf.field.type === 'phone' ? 'tel' : 'text') : 'text';
    return '<form class="ls-form ls-lead-flow" data-lead-flow="' + turn.id + '" novalidate><p class="ls-noanswer__text" style="margin:0"' + (lf.error ? ' role="alert"' : '') + '>' + esc(said) + '</p>' +
      (lf.state === 'field' ? '<div class="ls-input"><input name="v" type="' + type + '" maxlength="200" dir="auto" aria-label="' + esc(lf.field.label || '') + '" placeholder="' + esc(lf.field.label || '') + '"' + (lf.error ? ' aria-invalid="true"' : '') + '></div>' : '') +
      (lf.state === 'consent' ? '<label class="ls-check-row"><input type="checkbox" name="consent"><span>' + esc(lf.consentText) + '</span></label>' : '') +
      '<div class="ls-form__actions"><button class="ls-btn ls-btn--primary ls-btn--sm"' + (lf.busy ? ' disabled' : '') + '>' + esc(lf.state === 'consent' ? bankLabel('lead_finish', t('leadSend')) : bankLabel('lead_next', t('leadSend'))) + '</button>' +
      '<button type="button" class="ls-btn ls-btn--link" data-lead-flow-no="' + turn.id + '">' + esc(bankLabel('lead_no', t('leadNo'))) + '</button></div></form>';
  }
  // products worth showing next to a question we couldn't answer: by its meaningful words
  var STOP = /^(יש|לכם|לך|של|את|על|עם|איך|מה|האם|כמה|אפשר|איזה|איזו|אילו|למה|מתי|זה|הזה|הזו|גם|או|the|a|an|is|do|you|have|for|of|with|how|what|can)$/i;
  // An answer with no products of its own (from the store's pages, or kept from before): what the question is about, from
  // the store's own search for its words (code and the question's vector, never a model): a few, and the search for more.
  // "איזה עץ הכי טוב לבניית פרגולה בחוץ?" answered from a guide shows the wood the store sells for it.
  // Never beside an answer from what the store says about itself ("איזה שיטות משלוח יש?"), and only products the question's
  // words found: what is merely near it by meaning (any book, for shipping) is not about it.
  var ALSO_SHOWN = 6;
  function alsoFor(turn) {
    if (!turn || turn.alsoIds) { return; }
    turn.alsoIds = [];
    if ((turn.sources || []).some(function (s) { return s && s.store; })) { return; }
    json(API + '/search/' + SITE + '?counted=1&q=' + encodeURIComponent(turn.q)).then(function (r) {
      var d = (r && r.body) || {};
      if (!(d.held > 0 || d.subject)) { return; }
      var ids = (d.ids || []).filter(function (id) { return /^p:/.test(id); }).slice(0, ALSO_SHOWN);
      if (!ids.length) { return; }
      return products(ids.map(pid)).then(function () { update(turn, { alsoIds: ids.filter(function (id) { return !!rec(id); }) }); });
    }).catch(function () { /* the answer stands alone */ });
  }
  function alsoHtml(turn) {
    var list = (turn.alsoIds || []).map(rec).filter(Boolean);
    if (!list.length) { return ''; }
    return '<div class="ls-block__title" style="margin-top:14px">' + esc(t('alsoRelated')) + '</div>' + miniRow(list.map(function (r) { return { id: r.id, title: r.title, url: r.url, image: r.img, price: r.pr }; })) +
      '<button class="ls-btn ls-btn--link ls-btn--sm" data-q="' + esc(turn.q) + '">' + esc(t('alsoAll')) + '</button>';
  }
  function related(q) {
    var seen = {}, out = [];
    String(q).replace(/[?.!,״"']/g, ' ').split(/\s+/).filter(function (w) { return w.length >= 3 && !STOP.test(w); }).sort(function (a, b) { return b.length - a.length; }).forEach(function (w) {
      var stem = w.replace(/^[והבלמשכ](?=[א-ת]{3})/, '').replace(/(ים|ות)$/, '');
      hits(w).forEach(function (x) { if (x.t === 'product' && x.s === 1 && !seen[x.id] && out.length < 2 && (x.title + ' ' + (x.kw || '')).indexOf(stem) >= 0) { seen[x.id] = 1; out.push(pid(x.id)); } });
    });
    return out;
  }
  function answerHtml(turn) {
    if (turn.loading) { return thinking(); }
    if (turn.error) { return errorHtml(turn); }
    // asked for advice among a search's results: with no clear pick, those results to talk over with another AI, not a form
    // not "not found" but something else (the day's questions used, not the store's to answer, unavailable): said as it is
    if (turn.noInfo && turn.said) { return '<p class="ls-answer__text">' + esc(turn.said) + '</p>' + myAi(turn); }
    if (turn.noInfo && turn.advice) { return '<p class="ls-answer__text">' + esc(t('adviceNone')) + '</p>' + myAi(turn); }
    if (turn.noInfo) { return kitsHtml(turn) + noInfoHtml(turn) + myAi(turn); }
    if (turn.quoted) { return quotedHtml(turn) + myAi(turn); }
    var anim = fresh[turn.id] && turn.anim;
    var n = String(turn.answer || '').split(' ').length;
    // asked with typing mistakes: how the question was read, so the shopper sees what was answered
    var html = kitsHtml(turn) + (turn.corrected ? '<div class="ls-understood" dir="auto">' + esc(t('understood', { q: turn.corrected })) + '</div>' : '') +
      '<div class="ls-answer' + (anim ? ' ls-words' : '') + '">' + answerText(turn.answer, anim) + '</div>';
    // the page the question names, which the answer rests on ("מנויים"): a button to it
    if (turn.page && turn.page.url) { html += '<a class="ls-btn ls-btn--secondary ls-btn--sm ls-answer__page" href="' + esc(turn.page.url) + '">' + esc(t('goPage', { t: turn.page.title })) + '</a>'; }
    if (turn.picks && turn.picks.length) {
      html += '<ol class="ls-picks"' + (anim ? ' style="--lsp-stagger:110ms"' : '') + '>' + turn.picks.map(function (p, i) {
        var r = rec(p.id) || { id: p.id, title: p.title, url: p.url, img: p.image, buy: 0, s: 1 };
        return '<li class="ls-pick' + (anim ? ' ls-focus-in' : '') + '" style="--i:' + (anim ? Math.round(n * 28 / 110) + i : 0) + '"><a class="ls-pick__media" href="' + esc(r.url || p.url) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(r.img || p.image || '') + '" alt=""></a><div>' +
          '<a class="ls-pick__title" dir="auto" href="' + esc(r.url || p.url) + '" style="color:inherit;text-decoration:none">' + esc(decode(r.title || p.title)) + '</a><div class="ls-pick__why">' + esc(p.why || '') + '</div>' +
          '<div class="ls-pick__row">' + priceHtml(r) + '<span style="display:flex;gap:4px;align-items:center">' + heartBtn(pid(r.id), S.saved.indexOf(pid(r.id)) >= 0) + buyBtn(r, 'ls-btn--sm') + '</span></div><div data-after="' + esc(pid(r.id)) + '"></div></div></li>';
      }).join('') + '</ol>';
    }
    html += setHtml(turn);
    html += alsoHtml(turn);
    var src = (turn.sources || []).filter(function (s) { return s && (s.title || s.product); });
    if (src.length) {
      html += '<div class="ls-source">' + icon('doc', 'ls-icon--sm') + esc(t('from')) + ' ' + src.slice(0, 2).map(function (s) { var r = s.product && rec(s.product); return '<a href="' + esc(s.url || (r && r.url) || '#') + '">' + esc(s.title || (r && decode(r.title).split('|')[0]) || '') + '</a>'; }).join(' · ') + '</div>';
    }
    if (turn.general) { html += '<div class="ls-note">' + icon('info') + '<span>' + esc(t('general')) + '</span></div>'; }
    if (turn.follow && turn.follow.length) { html += '<div class="ls-follow">' + turn.follow.map(function (f) { return '<button class="ls-chip ls-chip--quiet" data-ask="' + esc(f) + '">' + esc(f) + '</button>'; }).join('') + '</div>'; }
    html += rateHtml(turn);
    html += leadFlowHtml(turn);
    var h = hours(), link = S.config.askWhatsapp && h.open && wa(t('verify') + ' ' + turn.q);
    if (link) { html += '<div class="ls-verify">' + esc(t('verify')) + ' <a href="' + esc(link) + '" target="_blank" rel="noopener">' + icon('whatsapp', 'ls-icon--sm') + esc(t('verifyLink')) + '</a></div>'; }
    return html + myAi(turn);
  }
  // A set asked to see, under its answer: one question to narrow it by (each option asks again with it, and the server
  // reads the store's two groups together, or chooses anew), the set as a grid ending on a full row with "show all",
  // and the store's search for the same words, for more
  function setHtml(turn) {
    if (turn.kind !== 'answer' || !turn.ids || !turn.ids.length) { return ''; }
    var html = '', base = String(turn.q || '').replace(/[?؟.!\s]+$/, '');
    if (turn.setAsk && turn.setAsk.options && turn.setAsk.options.length) {
      html += '<div class="ls-follow ls-set__ask"><span class="ls-set__q" dir="auto">' + esc(turn.setAsk.question) + '</span>' + turn.setAsk.options.map(function (o) {
        return '<button class="ls-chip ls-chip--quiet" data-ask="' + esc(base + ' ' + o) + '">' + esc(o) + '</button>';
      }).join('') + '</div>';
    }
    var list = filtered(turn), n = gridN(turn);
    if (list.length) { html += '<div class="ls-grid ls-set__grid">' + list.slice(0, n).map(card).join('') + '</div>'; }
    if (list.length > n) { html += allBtn(turn, t('showAll', { n: list.length }), true); }
    if (turn.setSearch) { html += '<div class="ls-set__search"><button class="ls-btn ls-btn--link" data-q="' + esc(turn.setSearch) + '">' + icon('search', 'ls-icon--sm') + esc(t('searchFor', { q: turn.setSearch })) + '</button></div>'; }
    return html;
  }
  // "Helpful?" under an answer the assistant wrote for the search box (search.rate_answers): a thumb up or down,
  // the last press counts, sent with the question's id; one rated down reaches the shop's notices
  function rateHtml(turn) {
    if (!turn.askId || off('rate') || LOOK.rate === false) { return ''; }
    var up = turn.rated === 'up', down = turn.rated === 'down';
    var thumb = function (kind, on) {
      return '<button type="button" class="ls-rate__btn' + (on ? ' is-on' : '') + '" data-rate="' + kind + '" data-turn="' + turn.id + '" aria-pressed="' + on + '" aria-label="' + esc(t(kind === 'up' ? 'helpfulYes' : 'helpfulNo')) + '" title="' + esc(t(kind === 'up' ? 'helpfulYes' : 'helpfulNo')) + '">' + icon('thumb', 'ls-icon--sm' + (kind === 'down' ? ' ls-rate__down' : '')) + '</button>';
    };
    return '<div class="ls-rate" role="group" aria-label="' + esc(t('helpful')) + '"><span class="ls-rate__q">' + esc(t('helpful')) + '</span>' + thumb('up', up) + thumb('down', down) +
      (turn.rated ? '<span class="ls-rate__thanks" role="status">' + esc(t(down ? 'helpfulSorry' : 'helpfulThanks')) + '</span>' : '') + '</div>';
  }
  function rate(turn, kind) {
    if (!turn || !turn.askId || turn.rated === kind) { return; }
    turn.rated = kind;
    sendEvents([{ type: 'ask_rate', q: norm(turn.q || ''), ask_id: turn.askId, rating: kind }]);
    persist(); render();
  }
  // leave your details for the store to come back to you: the question first (what was asked, still editable), then
  // a name, a phone or an email and consent. Sent as the shop's callback about this question (shoppers.callbacks).
  function leadForm(turn, q) {
    var e = turn.errs || {};
    return '<form class="ls-form" data-lead="' + turn.id + '" novalidate>' +
      '<div class="ls-input"><label for="lq-' + turn.id + '">' + esc(t('leadQuestion')) + '</label><textarea id="lq-' + turn.id + '" name="question" rows="2" dir="auto"' + (e.question ? ' aria-invalid="true" aria-describedby="lqe-' + turn.id + '"' : '') + '>' + esc(turn.lq != null ? turn.lq : q || '') + '</textarea>' + (e.question ? '<span class="ls-input__err" id="lqe-' + turn.id + '">' + esc(t('leadNeedQuestion')) + '</span>' : '') + '</div>' +
      '<div class="ls-input"><label for="ln-' + turn.id + '">' + esc(t('name')) + '</label><input id="ln-' + turn.id + '" name="name" autocomplete="name" value="' + esc(turn.ln || '') + '"' + (e.name ? ' aria-invalid="true" aria-describedby="lne-' + turn.id + '"' : '') + '>' + (e.name ? '<span class="ls-input__err" id="lne-' + turn.id + '">' + esc(t('leadNeedName')) + '</span>' : '') + '</div>' +
      '<div class="ls-input"><label for="lc-' + turn.id + '">' + esc(t('contact')) + '</label><input id="lc-' + turn.id + '" name="contact" autocomplete="tel" inputmode="email" dir="ltr" style="text-align:' + (DIR === 'rtl' ? 'right' : 'left') + '" value="' + esc(turn.lc || '') + '"' + (e.contact ? ' aria-invalid="true" aria-describedby="lce-' + turn.id + '"' : '') + '>' + (e.contact ? '<span class="ls-input__err" id="lce-' + turn.id + '">' + esc(t('leadBadContact')) + '</span>' : '') + '</div>' +
      '<label class="ls-check-row"><input type="checkbox" name="consent"' + (turn.lk ? ' checked' : '') + (e.consent ? ' aria-invalid="true"' : '') + '><span>' + esc(t('consent')) + (e.consent ? '<br><span class="ls-input__err">' + esc(t('leadNeedConsent')) + '</span>' : '') + '</span></label>' +
      (turn.leadErr ? '<span class="ls-input__err">' + esc(t('leadError')) + '</span>' : '') +
      '<div class="ls-form__actions"><button class="ls-btn ls-btn--primary">' + esc(t('leadSend')) + '</button><button type="button" class="ls-btn ls-btn--link" data-lead-no="' + turn.id + '">' + esc(t('leadNo')) + '</button></div></form>';
  }
  // an answer that could not be checked is never shown: what the store's pages say, quoted as they are, each
  // with its page, under a line that says so; and the team, for anyone who wants to be sure
  function quotedHtml(turn) {
    var html = '<p class="ls-noanswer__text">' + esc(t('quotedTitle')) + '</p>' + turn.quoted.map(function (qt) {
      return '<blockquote class="ls-quote" dir="auto">' + esc(qt.text) + (qt.title ? '<small>' + icon('doc', 'ls-icon--sm') + (qt.url ? '<a href="' + esc(qt.url) + '">' + esc(qt.title) + '</a>' : esc(qt.title)) + '</small>' : '') + '</blockquote>';
    }).join('');
    // WhatsApp only where the shop offers its team when there is no answer
    var h = hours(), link = noAnswerOf(turn) === 'team' && h.open && wa(t('verify') + ' ' + turn.q);
    if (link) { html += '<div class="ls-verify">' + esc(t('verify')) + ' <a href="' + esc(link) + '" target="_blank" rel="noopener">' + icon('whatsapp', 'ls-icon--sm') + esc(t('verifyLink')) + '</a></div>'; }
    return html;
  }
  // team | details | none: the reply's own word, else the index config's, else as before (the team)
  // the product a turn is about: a question on it (ctx), or its own block (an about turn is named by the product)
  function ctxOf(turn) { return turn ? (turn.kind === 'about' ? turn.id : turn.ctx) || null : null; }
  function bankOf(turn) { var ctx = ctxOf(turn); return ctx ? BANK_READY[String(pid(ctx))] || null : null; }
  function noAnswerOf(turn) {
    var bank = bankOf(turn), n = (turn && turn.noAnswer) || (bank && bank.no_answer) || S.config.noAnswer;
    return n === 'details' || n === 'none' ? n : 'team';
  }
  // the shop's own "leave details" form (Leads' DescribesLeadForm): the product page's bank has it for a question
  // on the product, the index config for the search box; null: the plain form (name, contact) sent to /signup
  function leadFormOf(turn) {
    var bank = bankOf(turn), f = (bank && bank.lead_form) || S.config.leadForm;
    return f && f.fields && f.fields.length ? f : null;
  }
  function shopLeadForm(turn, form, q) {
    var e = turn.lerr || {}, v = turn.lv || {}, id = function (k) { return 'lf-' + turn.id + '-' + String(k).replace(/[^A-Za-z0-9_-]/g, ''); };
    var fields = form.fields.map(function (f) {
      var type = f.type === 'email' ? 'email' : f.type === 'phone' ? 'tel' : 'text', err = e[f.key];
      var auto = f.type === 'email' ? 'email' : f.type === 'phone' ? 'tel' : f.type === 'name' ? 'name' : 'off';
      return '<div class="ls-input"><label for="' + id(f.key) + '">' + esc(f.label) + (f.required ? ' *' : '') + '</label><input id="' + id(f.key) + '" name="f:' + esc(f.key) + '" type="' + type + '" autocomplete="' + auto + '" maxlength="200"' +
        (type === 'text' ? ' dir="auto"' : ' dir="ltr" style="text-align:' + (DIR === 'rtl' ? 'right' : 'left') + '"') + ' value="' + esc(v[f.key] || '') + '"' + (f.required ? ' aria-required="true"' : '') +
        (err ? ' aria-invalid="true" aria-describedby="' + id(f.key) + '-e"' : '') + '>' + (err ? '<span class="ls-input__err" id="' + id(f.key) + '-e">' + esc(err) + '</span>' : '') + '</div>';
    }).join('');
    return '<form class="ls-form" data-shop-lead="' + turn.id + '" novalidate>' +
      (form.title ? '<p class="ls-noanswer__text" style="margin:0;font-weight:500">' + esc(form.title) + '</p>' : '') +
      '<div class="ls-input"><label for="' + id('q') + '">' + esc(t('leadQuestion')) + '</label><textarea id="' + id('q') + '" name="question" rows="2" dir="auto"' + (e.question ? ' aria-invalid="true"' : '') + '>' + esc(turn.lq != null ? turn.lq : q || '') + '</textarea>' + (e.question ? '<span class="ls-input__err">' + esc(t('leadNeedQuestion')) + '</span>' : '') + '</div>' +
      fields +
      '<label class="ls-check-row"><input type="checkbox" name="consent"' + (turn.lk ? ' checked' : '') + (e.consent ? ' aria-invalid="true"' : '') + '><span>' + esc(form.consent || t('consent')) + (e.consent ? '<br><span class="ls-input__err">' + esc(t('leadNeedConsent')) + '</span>' : '') + '</span></label>' +
      (turn.leadErr ? '<span class="ls-input__err">' + esc(t('leadError')) + '</span>' : '') +
      '<div class="ls-form__actions"><button class="ls-btn ls-btn--primary"' + (turn.leadBusy ? ' disabled' : '') + '>' + esc(t('leadSend')) + '</button><button type="button" class="ls-btn ls-btn--link" data-lead-no="' + turn.id + '">' + esc(t('leadNo')) + '</button></div></form>';
  }
  // sent whole to the shop's lead flow (POST /widget/{site}/lead), which checks every field in its order
  function sendShopLead(turn, f) {
    var form = leadFormOf(turn);
    if (!form || turn.leadBusy) { return; }
    var given = {}, errs = {}, question = f.question ? f.question.value.trim() : String(turn.q || '');
    form.fields.forEach(function (fd) {
      var input = f.elements['f:' + fd.key], val = input ? String(input.value || '').trim() : '';
      if (val !== '') { given[fd.key] = val; } else if (fd.required) { errs[fd.key] = t('leadRequired'); }
    });
    if (!question) { errs.question = true; }
    if (!f.consent.checked) { errs.consent = true; }
    turn.lv = given; turn.lq = question; turn.lk = f.consent.checked;
    if (Object.keys(errs).length) { turn.lerr = errs; render(); var bad = BODY.querySelector('[data-shop-lead="' + turn.id + '"] [aria-invalid="true"]'); if (bad) { bad.focus(); } return; }
    turn.lerr = {}; turn.leadErr = false; turn.leadBusy = true; render();
    var ctx = ctxOf(turn), page = ctx ? { type: PAGE && PAGE.type === 'content' && String(PAGE.id) === String(pid(ctx)) ? 'content' : 'product', id: String(pid(ctx)) } : { type: 'search', id: turn.askId || 'search' };
    json(API + '/widget/' + SITE + '/lead', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ question: question, type: page.type, id: page.id, locale: LANG, given: given, consent: true, vid: visitor(), asked: LEARN.answered }) }).then(function (r) {
      var d = r.body && r.body.data;
      turn.leadBusy = false;
      if (d && d.state === 'done') { visitStep('lead'); update(turn, { lead: 'done', leadPromise: d.promise || '', leadName: given.name || '', leadErr: false }); return; }
      if (d && d.state === 'field' && d.field && d.error) { turn.lerr = {}; turn.lerr[d.field.key] = bankLabel('lead_error_' + d.error, t('leadBadField')); render(); return; }
      throw new Error('lead');
    }).catch(function () { turn.leadBusy = false; update(turn, { leadErr: true }); });
  }
  function noInfoHtml(turn) {
    // what the shop offers when there is no answer (assistant.no_answer, sent with the reply): team (WhatsApp in
    // the opening hours, details outside them), details (always the form, never WhatsApp), none ("not found" only)
    var offer = noAnswerOf(turn), h = hours(), link = offer === 'team' ? wa((S.config.whatsappMessage || ':question').replace(':question', turn.q).replace(/:product|:url/g, '').replace(/[ \t]{2,}/g, ' ').trim()) : null, html = '';
    var form = leadFormOf(turn), own = turn.notFound ? String(turn.notFound) : '';
    if (turn.lead === 'done') {
      html = '<div class="ls-done ls-focus-in"><span class="ls-done__icon"><svg class="ls-icon ls-check ls-check--draw" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg></span><div><div style="font-weight:500">' + esc(turn.leadPromise || t('leadDone', { name: turn.leadName || '', when: h.when || '' })) + '</div><div style="font-size:13px;color:var(--ls-fg-muted)">' + esc(t('leadDoneSub', { shop: S.config.shopName || '' })) + '</div></div></div>';
    } else if (turn.lead === 'no') {
      html = '<p class="ls-noanswer__text">' + esc(t('leadDeclined')) + '</p>';
    } else if (offer === 'none') {
      html = '<p class="ls-noanswer__text">' + esc(own || t('noInfo')) + '</p>';
    } else if (offer === 'team' && h.open && link) {
      html = '<div class="ls-hours"><span class="ls-dot"></span>' + esc(t('teamNow', { t: h.until || '' })) + '</div><p class="ls-noanswer__text">' + esc(own || t('waText')) + '</p>' +
        '<a class="ls-btn ls-btn--primary" href="' + esc(link) + '" target="_blank" rel="noopener">' + icon('whatsapp') + esc(t('teamWa')) + '</a>';
    } else if (offer === 'details' || S.config.callbacks !== false) {
      // in the opening hours "the team is back" says nothing: only the question and the form
      html = (offer === 'team' || !h.open ? '<div class="ls-hours"><span class="ls-dot ls-dot--off"></span>' + esc(t('teamBack', { when: h.when || '' })) + '</div>' : '') +
        '<p class="ls-noanswer__text">' + esc(own ? own + ' ' + t('leadAsk') : t('leadText')) + '</p>' + (form ? shopLeadForm(turn, form, turn.q) : leadForm(turn, turn.q));
    } else {
      html = '<p class="ls-noanswer__text">' + esc(own || t('noInfo')) + '</p>';
    }
    // the search box's own question said why it was not answered ("אין באתר המלצה אחת…"): what to ask instead
    if (own && !turn.ctx && turn.lead !== 'done') { html += '<p class="ls-noanswer__hint">' + esc(t('askSpecific')) + '</p>'; }
    if (turn.rel && turn.rel.length) {
      html += '<div class="ls-block"><div class="ls-block__title" style="color:var(--ls-fg-muted);font-weight:500">' + esc(t('meanwhile')) + '</div><div class="ls-panel" style="box-shadow:0 0 0 1px var(--ls-line);padding:4px">' + turn.rel.map(function (id) { return prow(rec(id)); }).join('') + '</div></div>';
    }
    return html;
  }

