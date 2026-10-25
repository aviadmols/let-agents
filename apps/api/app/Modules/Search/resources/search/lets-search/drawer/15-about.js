  // ---------------------------------------------------------------- about this product
  // the product's bank is fetched with the page, so the drawer opens with it already there
  var BANKS = {}, BANK_READY = {};
  function bankFor(id) {
    id = String(id);
    if (!BANKS[id]) {
      var mine = PAGE && String(PAGE.id) === id;
      BANKS[id] = json(API + '/widget/' + SITE + '/page?type=' + (mine ? PAGE.type : 'product') + '&id=' + encodeURIComponent(id) + '&locale=' + LANG + (previewKey ? '&preview=' + previewKey : '')).then(function (r) {
        var b = (r.body && (r.body.data || r.body)) || {};
        if (mine) { learnFrom(b); }
        var ids = [];
        (b.sections || []).forEach(function (s) { (s.products || []).forEach(function (p) { ids.push(pid(p.id)); }); });
        return products(ids.concat([id]).slice(0, 12)).then(function () { BANK_READY[id] = b; return b; });
      }).catch(function () { delete BANKS[id]; return null; });
    }
    return BANKS[id];
  }
  function aboutTurn(id) {
    id = String(id);
    var ready = BANK_READY[id];
    var turn = { kind: 'about', id: id, loading: !ready, bank: ready || null };
    S.turns.push(turn); fresh[turn.id] = 1;
    if (!ready) { bankFor(id).then(function (b) { update(turn, { loading: false, bank: b }); }); }
  }
  function aboutHtml(turn) {
    var r = rec(turn.id);
    if (!r) { return ''; }
    var head = '<div class="ls-about__head"><img class="ls-about__img" src="' + esc(r.img) + '" alt=""><div><div style="font-size:12px;color:var(--ls-fg-muted);font-weight:500">' + esc(t('about')) + '</div><div class="ls-about__title" dir="auto">' + esc(decode(r.title)) + '</div>';
    if (turn.loading) { return head + '</div></div>' + thinking(); }
    var b = turn.bank || {};
    head += (b.popularity && b.popularity.popular ? '<span class="ls-pop" title="' + esc(b.popularity.text || '') + '">' + icon('trend', 'ls-icon--sm') + esc(b.popularity.badge || t('popular30')) + '</span>' : '') + '</div></div>';
    var html = head;
    // under the product, as Aviad asked (2026-10-18): its key specs, then what Lets offers to ask, then the
    // products this visitor looked at, then the rest (what the store promises, the product's other sections).
    // The product's tags are not shown here: the specs say the same.
    var sections = b.sections || [];
    html += sections.filter(function (s) { return s.model === 'specs'; }).map(function (s) { return sectionHtml(s, turn); }).filter(Boolean).join('');
    // the comparison only when this shopper viewed a product of the same kind, and named: "להשוות עם X"
    var vb = String(turn.id) === String(PAGE && PAGE.id) ? viewedBefore() : null;
    var qs = ((b.assistant && b.assistant.questions) || []).filter(function (q) { return q.about !== 'compare' || vb; }).slice(0, 5).map(function (q) {
      return q.about === 'compare' && q.named ? Object.assign({}, q, { text: String(q.named).replace(':title', decode(vb.title)) }) : q;
    });
    if (qs.length) {
      html += '<div class="ls-stack" style="margin-bottom:14px">' + qs.map(function (q) {
        var act = q.about === 'similar_items' ? 'data-similar="' + esc(turn.id) + '"' : q.about === 'similar' && q.query ? 'data-q="' + esc(q.query) + '"' : q.about === 'compare' ? 'data-cmp-viewed="' + esc(turn.id) + '"' : 'data-ask="' + esc(q.question || q.text) + '"';
        return '<button class="ls-row" ' + act + ' style="background:var(--ls-bg-sunken);min-height:48px">' + mark() + '<span class="ls-row__text">' + esc(q.text) + '</span><span class="ls-row__end" style="opacity:1">' + icon('fwd', 'ls-icon--sm') + '</span></button>';
      }).join('') + '</div>';
    }
    var seen = LEARN.recent && LEARN.recent.products;
    if (seen && seen.length) { html += '<div class="ls-block"><div class="ls-block__title"><span>' + esc(bankLabel('recent_title', t('recentTitle'))) + '</span></div>' + miniRow(seen) + '</div>'; }
    if (b.assurances && b.assurances.length) {
      html += '<div class="ls-block"><div class="ls-block__title">' + esc(t('promises')) + '</div>' + b.assurances.map(function (a) { return '<div class="ls-promise">' + icon(a.scope === 'shop' ? 'truck' : 'check', 'ls-icon--sm') + '<div>' + esc(a.text) + (a.note ? '<small>' + esc(a.note) + '</small>' : '') + '</div></div>'; }).join('') + '</div>';
    }
    // the specs' teaser is only their name ("מפרט"), and they are shown above already
    if (b.teaser && b.teaser.text && b.teaser.model !== 'specs') { html += '<p class="ls-teaser">' + esc(b.teaser.text) + '</p>'; }
    // the other sections in the order the learning chose (a held-out visitor: the order it never touched)
    html += sections.filter(function (s) { return s.model !== 'specs'; }).map(function (s) { return sectionHtml(s, turn); }).filter(Boolean).join('');
    // more from the search that brought them here
    var found = LEARN.searched && LEARN.searched.products;
    if (found && found.length) { html += '<div class="ls-block"><div class="ls-block__title"><span>' + esc(bankLabel('searched_title', t('searchedTitle'), { query: LEARN.searched.query || '' })) + '</span></div>' + miniRow(found) + '</div>'; }
    if (b.answers && b.answers.length) {
      html += '<div class="ls-block"><div class="ls-block__title">' + esc(t('faq')) + '</div>' + b.answers.map(function (a) { return '<details class="ls-faq"><summary>' + esc(a.q) + icon('down', 'ls-icon--sm') + '</summary><p>' + esc(a.a) + '</p></details>'; }).join('') + '</div>';
    }
    // someone who knows: WhatsApp only while the store answers it; otherwise (or with no WhatsApp) the details form,
    // with the last question asked about this product already in it
    // Outside the hours, as the shop chose (contact.when_offline): show (the button, with the offline note), details
    // (the shop's leave-details form), hide (nothing). The message carries the product and the shopper's last question.
    var c = b.contact, h = hours(), asked = S.turns.filter(function (x) { return x.kind === 'answer' && String(x.ctx) === String(turn.id) && x.q; }).pop();
    var offline = c && c.number && !h.open ? (c.when_offline || (c.hide_when_offline ? 'hide' : 'details')) : null;
    if (c && c.number && (h.open || offline === 'show')) {
      var msg = String(c.message || '').replace(':product', decode(r.title)).replace(':url', win.location.href).replace(':question', asked ? asked.q : '').replace(/[ \t]{2,}/g, ' ').replace(/\s+([.,:;!?])/g, '$1').trim();
      html += '<div class="ls-block" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap"><div><div style="font-weight:500;font-size:14px">' + esc(b.cta && b.cta.headline || c.title || t('talkTitle')) + '</div><div class="ls-hours" style="margin:4px 0 0"><span class="ls-dot' + (h.open ? '' : ' ls-dot--off') + '"></span>' + esc(h.open ? c.online_label || '' : c.offline_label || '') + '</div>' +
        (h.open || !c.offline_note ? '' : '<div style="font-size:13px;color:var(--ls-fg-muted);margin-top:4px">' + esc(c.offline_note) + '</div>') + '</div>' +
        '<a class="ls-btn ls-btn--secondary ls-btn--sm" target="_blank" rel="noopener" href="https://wa.me/' + esc(c.number) + '?text=' + encodeURIComponent(msg) + '">' + icon('whatsapp', 'ls-icon--sm') + esc(c.button || t('talkBtn')) + '</a></div>';
    }
    else if (offline !== 'hide' && (b.lead_form || S.config.callbacks !== false)) {
      var lform = leadFormOf(turn);
      html += '<div class="ls-block"><div style="font-weight:500;font-size:14px">' + esc(b.cta && b.cta.headline || t('talkTitle')) + '</div>' +
        (turn.lead === 'done' ? '<div class="ls-done ls-focus-in" style="margin-top:10px"><span class="ls-done__icon"><svg class="ls-icon ls-check ls-check--draw" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg></span><div><div style="font-weight:500">' + esc(turn.leadPromise || t('leadDone', { name: turn.leadName || '', when: h.when || '' })) + '</div><div style="font-size:13px;color:var(--ls-fg-muted)">' + esc(t('leadDoneSub', { shop: S.config.shopName || '' })) + '</div></div></div>'
          : turn.lead === 'no' ? '' : '<div class="ls-hours" style="margin:4px 0 10px"><span class="ls-dot ls-dot--off"></span>' + esc(h.when ? t('teamBack', { when: h.when }) : t('leadText')) + '</div>' + (lform ? shopLeadForm(turn, lform, asked ? asked.q : '') : leadForm(turn, asked ? asked.q : ''))) + '</div>';
    }
    html += '<div class="ls-note">' + icon('info') + '<span>' + esc(t('aboutNote')) + '</span></div>';
    return html;
  }
  // one section of the bank, by what it holds; data-cand ties what the shopper does to it (exposure, open, click)
  function sectionHtml(s, turn) {
    var wrap = function (inner, title, end) { return '<div class="ls-block" data-cand="' + esc(s.candidate) + '">' + (title ? '<div class="ls-block__title"><span>' + esc(title) + '</span>' + (end || '') + '</div>' : '') + inner + '</div>'; };
    if (s.model === 'explainer' && s.items && s.items.length) {
      return '<div data-cand="' + esc(s.candidate) + '"><ul class="ls-points">' + s.items.slice(0, 4).map(function (i) { return '<li><span><b>' + esc(i.key || '') + '</b> ' + esc(i.text || '') + '</span></li>'; }).join('') + '</ul></div>';
    }
    if (s.model === 'specs') {
      var rows = s.specs && s.specs.length ? s.specs.map(function (x) { return [x.label, x.value]; }) : (LEARN.bank && LEARN.bank.compare && LEARN.bank.compare.rows || []).map(function (x) { return [x[1], x[2]]; });
      if (!rows.length) { return ''; }
      return '<details open class="ls-block ls-faq ls-specs-box" style="border:0" data-cand="' + esc(s.candidate) + '"><summary style="padding:0;min-height:36px"><span class="ls-block__title" style="margin:0">' + esc(s.title || t('specs')) + '</span>' + icon('down', 'ls-icon--sm') + '</summary><table class="ls-specs">' + rows.map(function (x) { return '<tr><th>' + esc(x[0]) + '</th><td>' + esc(x[1]) + '</td></tr>'; }).join('') + '</table></details>';
    }
    if (s.products && s.products.length) {
      var end = s.model === 'complement' ? '<button class="ls-why" data-why>' + esc(t('whyShown')) + '</button>' : s.model === 'alternative' ? '<button data-similar="' + esc(turn.id) + '">' + esc(t('seeAll')) + '</button>' : '';
      var title = s.title || (s.model === 'complement' ? t('goesWith') : s.model === 'alternative' ? t('alternatives') : s.model === 'on_sale' ? t('saleAlt') : '');
      return wrap(miniRow(s.products), title, end);
    }
    if (s.uses && s.uses.length || s.guides && s.guides.length) {
      return wrap((s.uses && s.uses.length ? '<div class="ls-chips" style="flex-wrap:wrap;overflow:visible;mask-image:none;-webkit-mask-image:none">' + s.uses.map(function (u) { return '<span class="ls-chip ls-chip--quiet" style="cursor:default">' + esc(u) + '</span>'; }).join('') + '</div>' : '') +
        (s.guides && s.guides.length ? '<div class="ls-guides" style="margin-top:10px">' + s.guides.slice(0, 3).map(function (g) { return '<a class="ls-guide" href="' + esc(g.url) + '" data-guide="' + esc(g.id || '') + '">' + (g.image ? '<img class="ls-guide__img" src="' + esc(g.image) + '" alt="" decoding="async">' : '<span class="ls-guide__img ls-guide__img--none">' + icon('doc') + '</span>') + '<span class="ls-guide__body"><span class="ls-guide__kind">' + esc(t('guide')) + '</span><span class="ls-guide__t" dir="auto">' + esc(decode(g.title)) + '</span></span></a>'; }).join('') + '</div>' : ''), s.title);
    }
    if (s.items && s.items.length) {
      return wrap('<ul class="ls-points">' + s.items.slice(0, 6).map(function (i) { return '<li><span>' + (i.key ? '<b>' + esc(i.key) + '</b> ' : '') + esc(i.text || '') + '</span></li>'; }).join('') + '</ul>', s.title);
    }
    return '';
  }
  // a row of products that slides: arrows at its sides, a drag with the mouse, a swipe on touch
  // buy: each with its own add to cart under it (the products an answer names: bought where they are read about)
  function miniRow(list, buy) {
    return '<div class="ls-slider"><button class="ls-ibtn ls-ibtn--sm ls-ibtn--float ls-slider__btn ls-slider__btn--prev" type="button" data-slide="-1" aria-label="' + esc(t('slidePrev')) + '" disabled>' + icon('back', 'ls-icon--sm') + '</button>' +
      '<div class="ls-mini-row">' + list.slice(0, 6).map(function (p) {
        var r = rec(p.id) || { id: p.id, title: p.title, url: p.url, img: p.image, pr: p.price };
        var card = '<a class="ls-mini" href="' + esc(r.url || p.url) + '" data-pid="' + esc(pid(p.id)) + '" draggable="false"><img class="ls-mini__img" src="' + esc(r.img || p.image) + '" alt="" decoding="async" draggable="false">' + pic2(r) + '<span class="ls-mini__t" dir="auto">' + esc(decode(r.title || p.title)) + '</span>' + priceHtml(r) + '</a>';
        return buy && rec(p.id) ? '<div class="ls-mini-buy">' + card + buyBtn(r, 'ls-btn--sm') + '</div>' : card;
      }).join('') + '</div>' +
      '<button class="ls-ibtn ls-ibtn--sm ls-ibtn--float ls-slider__btn ls-slider__btn--next" type="button" data-slide="1" aria-label="' + esc(t('slideNext')) + '">' + icon('fwd', 'ls-icon--sm') + '</button></div>';
  }
  // how far along a row is, the same in RTL (where scrollLeft runs negative) and LTR
  function along(row) { return Math.abs(row.scrollLeft); }
  function sliderState(row) {
    var box = row.parentNode, max = row.scrollWidth - row.clientWidth, at = along(row);
    box.classList.toggle('is-static', max <= 2);
    box.querySelector('.ls-slider__btn--prev').disabled = at <= 2;
    box.querySelector('.ls-slider__btn--next').disabled = at >= max - 2;
  }
  function sliders() { root.querySelectorAll('.ls-mini-row').forEach(sliderState); }
  function slide(btn) {
    var row = btn.parentNode.querySelector('.ls-mini-row'), step = Math.max(140, row.clientWidth * 0.8) * Number(btn.dataset.slide);
    row.scrollBy({ left: DIR === 'rtl' ? -step : step, behavior: 'smooth' });
  }

