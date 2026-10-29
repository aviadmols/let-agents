  // ---------------------------------------------------------------- similar turn
  // size: { value, shown } from size help: only the similar items that have it in stock now
  // oos: from the product page's row for a product or a variant sold out ({ whole, want: [{ value, shown, size }], size }):
  // only the similar ones in stock, and in the option that is gone (the variant filters, asked of one variant in stock)
  function similarTurn(id, size, oos, otherColour, asked) {
    // on a desktop the similar items open large, like "show all": the product on top, filters, the full grid
    S.tab = 'chat'; S.wide = null;
    // the similar items stand alone at the top; the search they came from waits behind the dashed line
    S.turns.forEach(function (x) { x.prev = true; });
    S.showPrev = false; hideLive();
    var turn = addTurn({ kind: 'similar', of: String(id), loading: true, c: blankC(), size: size || (oos && oos.size) || null, oos: oos ? { whole: !!oos.whole, want: oos.want || [] } : null, other: !!otherColour, asked: asked || null });
    if (!isPhone()) { S.wide = turn.id; turn.limit = 24; render(); }
    BODY.scrollTop = 0;
    json(API + '/search/' + SITE + '/similar?id=' + encodeURIComponent(id) + (size || oos ? '&limit=24' : '') + (oos ? '&for=oos' : '') + (otherColour ? '&colour=other' : '') + (asked ? '&q=' + encodeURIComponent(String(asked).slice(0, 200)) : ''))
      // the alike are kept by the index's records: an answer that came before the index (the drawer just opened on a
      // new page) waits for it, or every one of them went and "0 similar items" stood (Gil Travel, 30.10)
      .then(function (r) { return loadIndex().then(function () { return r; }, function () { return r; }); }).then(function (r) {
      var ids = ((r.body && r.body.products) || []).map(function (p) { return 'p:' + pid(p.external_id || p.id); }).filter(function (x) { return rec(x) && pid(x) !== String(id); });
      // few or none look like it: those of its kind its look left out (another colour, a print), shown apart as "maybe these"
      var others = ((r.body && r.body.others) || []).map(function (p) { return 'p:' + pid(p.external_id || p.id); }).filter(function (x) { return rec(x) && pid(x) !== String(id) && ids.indexOf(x) < 0; });
      return products(ids.slice(0, 24).map(pid).concat(others.map(pid))).then(function () {
        if (oos) { update(turn, oosPatch(turn, ids, oos)); BODY.scrollTop = 0; return; }
        var inSize = size ? ids.filter(function (x) { return hasSize(x, size); }) : ids;
        update(turn, { loading: false, ids: size && !inSize.length ? ids.slice(0, 8) : inSize, sizeNone: !!(size && !inSize.length), wants: wantsOf(r.body && r.body.asked), others: others });
        BODY.scrollTop = 0;
      });
    }).catch(function () { update(turn, { loading: false, error: true }); });
  }
  // the similar ones in stock, in the size (or colour) that is gone here: the index's variant filters turned on for
  // it, so one variant must have both the value and stock; where the index has no such value, the store's live sizes.
  // None has it: the similar ones in stock, under a line that says so.
  function oosPatch(turn, ids, oos) {
    var c = C(turn), want = oos.want || [], codes = oosCodes(want), shown = want.map(function (w) { return w.shown; }).join(' · ');
    c.stock = true;
    turn.ids = ids;
    codes.forEach(function (code) { c.f[code] = true; });
    if (codes.length && !filtered(turn).length) { c.f = {}; return { loading: false, ids: ids, oosNone: shown }; }
    if (!codes.length && oos.size) {
      var inSize = ids.filter(function (x) { return hasSize(x, oos.size); });
      return inSize.length ? { loading: false, ids: inSize, oosNone: null } : { loading: false, ids: ids, oosNone: shown };
    }
    return { loading: false, ids: ids, oosNone: null };
  }
  // the index's variant filter values (colour, size) that name the options chosen on the product page
  function oosCodes(want) {
    var out = [], squash = function (v) { return String(v == null ? '' : v).toLowerCase().replace(/[\s"'״׳]+/g, ''); };
    (S.facets || []).forEach(function (f, g) {
      if (!variantFacet(g)) { return; }
      want.forEach(function (w) {
        if (f.k === 'variant_size' && w.size === false) { return; }
        (f.v || []).forEach(function (v, i) {
          var hit = f.k === 'variant_size'
            ? [w.value, w.shown].some(function (x) { var a = squash(x); return a !== '' && (a === squash(v.k) || a === squash(v.l)); })
            : [w.value, w.shown].some(function (x) { return String(x || '').length > 1 && (squash(x) === squash(v.k) || says(String(x), v.l)); });
          if (hit && out.indexOf(g * 1000 + i) === -1) { out.push(g * 1000 + i); }
        });
      });
    });
    return out;
  }
  // what the server understood the question to ask of the alike, in the shopper's words: "יהלום · יקר יותר"
  function wantsOf(a) {
    if (!a) { return null; }
    var parts = (a.words || []).slice();
    if (a.price) { parts.push(t(a.price === 'up' ? (a.much ? 'muchDearer' : 'dearer') : (a.much ? 'muchCheaper' : 'cheaper'))); }
    return parts.length ? parts.join(' · ') : null;
  }
  function oosNote(turn) {
    return turn && turn.oosNone ? '<p class="ls-noanswer__text" style="margin:12px 0 0">' + esc(t('oosNone', { v: turn.oosNone })) + '</p>' : '';
  }
  function similarHtml(turn) {
    if (turn.loading) { return thinking(); }
    if (turn.error) { return errorHtml(turn); }
    if (!(turn.ids || []).length) {
      // asked for more than its alike and none is all of it: said so, and its alike are a choice, never shown in its place
      if (turn.wants) { return '<p class="ls-noanswer__text">' + esc(t('similarAskedNone', { w: turn.wants })) + '</p><button class="ls-btn ls-btn--secondary ls-btn--sm" data-similar="' + esc(turn.of) + '">' + esc(t('similarAll')) + '</button>'; }
      return othersHtml(turn, t('similarNoneOthers')) || '<p class="ls-noanswer__text">' + esc(t('noSimilar')) + '</p>';
    }
    var list = filtered(turn), n = gridN(turn);
    // like the results: how many, the order to show them in, and the filters (the large view has them in its head)
    var head = '<div class="ls-summary"><div class="ls-summary__count ls-num">' + esc(countText(list.length)) + '</div>' +
      '<button class="ls-btn ls-btn--ghost ls-btn--sm" data-sort="' + turn.id + '">' + esc(sortLabel(sortOf(C(turn)))) + icon('down', 'ls-icon--sm') + '</button></div>' + chipRow(turn);
    if (!list.length) { return head + '<div class="ls-empty-filter">' + esc(t('noFilterMatch')) + '<div style="margin-top:10px"><button class="ls-btn ls-btn--secondary ls-btn--sm" data-clear-filters="' + turn.id + '">' + esc(t('clearAll')) + '</button></div></div>'; }
    return oosNote(turn) + (turn.wants ? '<p class="ls-noanswer__text" style="margin:0 0 8px">' + esc(t('similarAsked', { w: turn.wants })) + '</p>' : '') + (turn.sizeNone ? '<p class="ls-noanswer__text">' + esc(sizeWord('size_similar_none', { size: turn.size.shown })) + '</p><div class="ls-block__title">' + esc(sizeWord('size_similar_more')) + '</div>' : '') + head + '<div class="ls-grid">' + list.slice(0, n).map(card).join('') + '</div>' +
      (list.length > n ? allBtn(turn, t('allSimilar', { n: list.length })) : '') + othersHtml(turn, null);
  }
  // those of its kind that do not look like it, apart and said to be so: a line when none is alike, a title when few are
  function othersHtml(turn, line) {
    var list = (turn.others || []).map(rec).filter(Boolean);
    if (!list.length) { return ''; }
    return (line ? '<p class="ls-noanswer__text">' + esc(line) + '</p>' : '<div class="ls-block__title" style="margin-top:18px">' + esc(t('similarMaybe')) + '</div>') +
      '<div class="ls-grid">' + list.slice(0, 8).map(card).join('') + '</div>';
  }

  // ---------------------------------------------------------------- photo
  var MAXKB = 5120;
  // the camera button and "search by photo": the sheet below, to choose a picture or take one
  function photoPick() { photoLayer(); }
  function photoLayer() {
    MAXKB = S.config.photoMaxKb || 5120;
    var mb = Math.round(MAXKB / 1024) + 'MB';
    showLayer(t('photoTitle'),
      '<div class="ls-upload" data-drop><span class="ls-ibtn ls-ibtn--solid">' + icon('camera') + '</span><div class="ls-upload__title">' + esc(t('photoTitle')) + '</div><div style="font-size:14px;color:var(--ls-fg-muted)">' + esc(isPhone() ? t('photoDropMobile') : t('photoDrop')) + '</div>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center"><label class="ls-btn ls-btn--primary ls-btn--sm">' + icon('image', 'ls-icon--sm') + esc(t('photoChoose')) + '<input type="file" accept="image/*" class="ls-vh" tabindex="-1" data-file></label>' +
      '<label class="ls-btn ls-btn--secondary ls-btn--sm">' + icon('camera', 'ls-icon--sm') + esc(t('photoTake')) + '<input type="file" accept="image/*" capture="environment" class="ls-vh" tabindex="-1" data-file></label></div>' +
      '<div class="ls-upload__note" data-photo-msg>' + esc(t('photoPrivate', { mb: mb })) + '</div></div>', '');
  }
  function photoFile(file) {
    var msg = LAYER.querySelector('[data-photo-msg]'), mb = Math.round(MAXKB / 1024) + 'MB';
    // said in the sheet when it is open, else in a toast (the phone's picker has no sheet)
    var say = function (text) { if (msg) { msg.textContent = text; msg.style.color = 'var(--ls-danger)'; } else { toast(text); } };
    if (!/^image\//.test(file.type || 'image/')) { say(t('photoType')); return; }
    // shrunk in the browser first (as before: 1600px, JPEG), so a large phone photo still fits; an iPhone's
    // HEIC becomes a JPEG here, and only a picture the browser could not read is refused
    shrink(file).then(function (blob) {
      if (!/^image\/(jpeg|png|webp)$/.test(blob.type)) { say(t('photoType')); return; }
      if (blob.size > MAXKB * 1024) { say(t('photoBig', { mb: mb })); return; }
      hideLayer();
      var reader = new FileReader();
      reader.onload = function () { photoSend(blob, String(reader.result)); };
      reader.readAsDataURL(blob);
    });
  }
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!win.createImageBitmap || !win.HTMLCanvasElement) { resolve(file); return; }
      win.createImageBitmap(file).then(function (bitmap) {
        var scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height)), canvas = doc.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale)); canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (b) { resolve(b || file); }, 'image/jpeg', 0.9);
      }).catch(function () { resolve(file); });
    });
  }
  function photoSend(blob, src) {
    S.tab = 'chat'; S.wide = null;
    // the photo search stands first, alone: what was on screen waits behind the dashed line
    S.turns.forEach(function (x) { x.prev = true; }); S.showPrev = false; hideLive(); INPUT.value = ''; FIELD.classList.remove('has-text');
    var turn = addTurn({ kind: 'photo', src: src, loading: true });
    BODY.scrollTop = 0;
    // kept in memory (never stored) for "which of the things in the photo": the same photo is sent again
    PHOTOS[turn.id] = blob;
    photoFetch(turn, blob, 0);
  }
  // the photo, read for one of the things in it (0: the one it saw first); its first reading is remembered
  // on the server by the photo itself, so choosing another thing asks no model to look again
  function photoFetch(turn, blob, item) {
    var form = new FormData(), seen = visit();
    form.append('photo', blob, 'photo.jpg');
    if (item) { form.append('item', String(item)); }
    if (seen.sid) { form.append('sid', seen.sid); }
    form.append('page', seen.page);
    if (seen.ref) { form.append('ref', seen.ref); }
    win.fetch(API + '/search/' + SITE + '/photo', { method: 'POST', body: form, mode: 'cors', credentials: 'omit' }).then(function (r) {
      if (r.status === 413) { update(turn, { loading: false, failed: true, big: true }); return null; }
      if (!r.ok) { throw new Error('photo'); }
      return r.json();
    }).then(function (d) {
      if (!d) { return; }
      d = d.data || d;
      var ps = ((d.groups && d.groups.product) || []).map(function (p) { return { id: pid(p.external_id || p.id), match: p.match }; }).filter(function (p) { return rec(p.id); });
      return products(ps.slice(0, 6).map(function (p) { return p.id; })).then(function () {
        update(turn, { loading: false, ids: ps.map(function (p) { return p.id; }), tags: d.tags || [], seen: d.seen || '', unclear: !!d.unclear, nothing: !!d.nothing, items: d.items || null, item: d.item || 0, off: {}, close: d.close !== false });
        visitStep('photo', { label: String(d.seen || '').slice(0, 200), n: ps.length });
      });
    }).catch(function () { update(turn, { loading: false, failed: true }); });
  }
  // several separate things in the photo (a shirt and jeans, two necklaces): which one to search, the one
  // shown first chosen; only while the photo is still in memory to send again
  function photoItems(turn) {
    if (!turn.items || turn.items.length < 2 || !PHOTOS[turn.id]) { return ''; }
    return '<div class="ls-which" role="group" aria-label="' + esc(t('photoWhich')) + '"><div class="ls-which__t">' + esc(t('photoWhich')) + '</div><div class="ls-chips">' +
      turn.items.map(function (name, i) {
        return '<button class="ls-chip" aria-pressed="' + (i === (turn.item || 0)) + '" data-photo-item="' + esc(turn.id + ':' + i) + '"><span class="ls-chip__check">' + icon('check', 'ls-icon--sm') + '</span>' + esc(name) + '</button>';
      }).join('') + '</div></div>';
  }
  function photoHtml(turn) {
    if (turn.loading) { return thinking(t('thinkingPhoto')); }
    if (turn.failed) { return '<p class="ls-noanswer__text">' + esc(turn.big ? t('photoBig', { mb: Math.round(MAXKB / 1024) + 'MB' }) : t('photoFailed')) + '</p><button class="ls-btn ls-btn--secondary ls-btn--sm" data-photo>' + esc(t('photoAgain')) + '</button>'; }
    if (turn.unclear) { return '<p class="ls-noanswer__text">' + esc(t('photoUnclear')) + '</p><div class="ls-form__actions"><button class="ls-btn ls-btn--primary ls-btn--sm" data-photo>' + icon('image', 'ls-icon--sm') + esc(t('photoAgain')) + '</button><button class="ls-btn ls-btn--secondary ls-btn--sm" data-focus>' + esc(t('photoWords')) + '</button></div>'; }
    var tagRow = function (clickToSearch) {
      return '<div class="ls-seen">' + (turn.tags || []).map(function (tg, i) {
        return clickToSearch ? '<button class="ls-chip ls-chip--quiet" data-q="' + esc(tg.title) + '" title="' + esc(t('photoTagSite', { t: tg.title })) + '">' + icon('search', 'ls-icon--sm') + esc(tg.title) + '</button>'
          : '<span class="ls-tag" style="' + (turn.off[i] ? 'opacity:.45;text-decoration:line-through' : '') + '">' + esc(tg.title) + '<button aria-label="' + esc(t('remove')) + ' ' + esc(tg.title) + '" aria-pressed="' + !!turn.off[i] + '" data-tag-off="' + turn.id + ':' + i + '">' + icon('x', 'ls-icon--sm') + '</button></span>';
      }).join('') + '</div>';
    };
    if (turn.nothing || !turn.ids.length) {
      if (turn.tags && turn.tags.length) { return '<p class="ls-noanswer__text">' + esc(t('photoOnlyTags')) + '</p>' + tagRow(true); }
      return '<p class="ls-noanswer__text">' + (turn.seen ? esc(t('photoNothing', { seen: turn.seen })) : esc(t('photoNothingPlain'))) + '</p>' +
        '<div class="ls-form__actions"><button class="ls-btn ls-btn--secondary ls-btn--sm" data-photo>' + esc(t('photoAgain')) + '</button><button class="ls-btn ls-btn--secondary ls-btn--sm" data-ask="' + esc(LANG === 'he' ? 'מה מתאים לי מהחנות?' : 'What would suit me?') + '">' + esc(t('askWho', { name: CFG.assistant })) + '</button></div>';
    }
    var off = Object.keys(turn.off).filter(function (k) { return turn.off[k]; }).map(Number);
    var list = turn.ids.map(rec).filter(Boolean);
    var html = photoItems(turn) + (turn.seen ? '<div class="ls-seen"><span class="ls-seen__label">' + esc(t('photoSeen')) + ' <b>' + esc(turn.seen) + '</b></span></div>' : '') + tagRow(false);
    if (off.length) { html += '<div class="ls-note">' + icon('info') + '<span>' + esc(t('photoTagSite', { t: (turn.tags || []).filter(function (x, i) { return off.indexOf(i) < 0; }).map(function (x) { return x.title; }).join(' ') })) + ' · <button class="ls-btn ls-btn--link" data-q="' + esc((turn.tags || []).filter(function (x, i) { return off.indexOf(i) < 0; }).map(function (x) { return x.title; }).join(' ')) + '">' + esc(LANG === 'he' ? 'חיפוש' : 'Search') + '</button></span></div>'; }
    // the first is the most alike only when it truly looks like the photo; else what the store has, said to be the nearest
    if (!turn.close) { html += '<div class="ls-note" data-photo-far>' + icon('info') + '<span>' + esc(t('photoNotClose')) + '</span></div>'; }
    html += '<div class="ls-grid" style="margin-top:12px">' + list.slice(0, 6).map(function (r, i) { var c = card(r); return i === 0 && turn.close ? c.replace('<span class="ls-card__heart">', '<span class="ls-best">' + esc(t('photoBest')) + '</span><span class="ls-card__heart">') : c; }).join('') + '</div>';
    return html;
  }

  // ---------------------------------------------------------------- the order a shopper's AI wrote (?la_order=…)
  // Checked on the server line by line (price, stock, the option or length), shown in the drawer, and put in
  // the store's own cart from here: Shopify in one request (/cart/add.js), WooCommerce a line at a time the
  // way its product form posts. A line still to choose gets its choices, each priced. Then to the cart.
  function orderTurn(items) {
    S.tab = 'chat'; S.wide = null;
    var turn = addTurn({ kind: 'order', loading: true, pickedAt: {}, done: {}, failed: {} });
    json(API + '/search/' + SITE + '/order?items=' + encodeURIComponent(items)).then(function (r) {
      var o = r.ok ? r.body : null;
      update(turn, { loading: false, order: o && o.lines && o.lines.length ? o : null });
    }).catch(function () { update(turn, { loading: false, order: null }); });
  }
  function orderRows(turn) {
    return (turn.order.lines || []).map(function (line, i) {
      var at = turn.pickedAt[i], choice = at != null && line.choose ? line.choose[at] : null;
      var add = line.add || (choice && choice.in_stock ? choice.add : null);
      var price = line.auto ? line.line_total : choice && choice.price != null ? choice.price * (line.quantity || 1) : 0;
      return { line: line, i: i, add: add, price: price, done: !!turn.done[i], failed: !!turn.failed[i] };
    });
  }
  function orderHtml(turn) {
    var head = '<div class="ls-order__title">' + esc(t('orderTitle')) + '</div>';
    if (turn.loading) { return head + thinking(t('orderLoading')); }
    if (!turn.order) { return head + '<p class="ls-noanswer__text">' + esc(t('orderEmpty')) + '</p>'; }
    var rows = orderRows(turn), o = turn.order;
    var html = head + '<div class="ls-order">' + rows.map(function (r) {
      var line = r.line, sub = [];
      if (line.option && line.option.title) { sub.push(line.option.title); }
      if (line.length && line.length.label) { sub.push((line.field ? line.field + ': ' : '') + line.length.label); }
      if (line.quantity) { sub.push('× ' + line.quantity + (line.unit_price != null ? ' · ' + money(line.unit_price) : '')); }
      var state = r.done ? '<div class="ls-order__state is-ok">' + icon('check', 'ls-icon--sm') + esc(t('orderAdded')) + '</div>'
        : r.failed ? '<div class="ls-order__state is-bad">' + esc(t('orderFailed')) + '</div>'
        : line.status === 'quantity_reduced' ? '<div class="ls-order__state">' + esc(t('orderReduced', { n: line.quantity })) + '</div>'
        : line.status === 'out_of_stock' ? '<div class="ls-order__state is-bad">' + esc(t('orderOut')) + '</div>'
        : line.status === 'unknown_product' ? '<div class="ls-order__state is-bad">' + esc(t('orderUnknown')) + '</div>' : '';
      var choose = line.status === 'requires_manual_option_selection' && line.choose && !r.done ? '<div class="ls-chips ls-order__choose">' + line.choose.map(function (c, at) {
        return '<button class="ls-chip" aria-pressed="' + (turn.pickedAt[r.i] === at) + '" data-order-pick="' + turn.id + ':' + r.i + ':' + at + '"' + (c.in_stock ? '' : ' disabled') + '><span class="ls-chip__check">' + icon('check', 'ls-icon--sm') + '</span>' + esc(c.label || '') + (c.price != null ? ' <span class="ls-chip__count ls-num">' + money(c.price) + '</span>' : '') + '</button>';
      }).join('') + '</div><div class="ls-order__hint">' + esc(t('orderChoose', { field: line.field || t('orderOption') })) + '</div>' : '';
      return '<div class="ls-order__line' + (r.done ? ' is-done' : '') + '">' + (line.image ? '<img class="ls-order__img" src="' + esc(line.image) + '" alt="" decoding="async">' : '<span class="ls-order__img"></span>') +
        '<div class="ls-order__main"><div class="ls-order__name" dir="auto">' + esc(decode(line.title || line.product || '')) + '</div>' + (sub.length ? '<div class="ls-order__sub">' + esc(sub.join(' · ')) + '</div>' : '') + choose + state +
        (line.page_url && line.status !== 'unknown_product' ? '<a class="ls-order__page" href="' + esc(line.page_url) + '">' + esc(t('orderPage')) + '</a>' : '') + '</div>' +
        (line.auto ? '<div class="ls-order__price ls-num">' + money(line.line_total) + '</div>' : '') + '</div>';
    }).join('') + '</div>';
    var ready = rows.filter(function (r) { return r.add && !r.done; }), sum = rows.reduce(function (s, r) { return s + (r.add ? r.price : 0); }, 0);
    var waiting = rows.filter(function (r) { return !r.add && r.line.status === 'requires_manual_option_selection'; });
    var allIn = rows.some(function (r) { return r.done; }) && !ready.length;
    html += '<div class="ls-order__foot"><div class="ls-order__total ls-num">' + esc(t('orderNow', { amount: money(sum) })) + '</div>' +
      '<div class="ls-order__note ls-num">' + esc(waiting.length && o.totals && o.totals.order_total_is_estimate ? t('orderEstimate', { amount: money(sum + waiting.reduce(function (s, r) { return s + (r.line.from_total || 0); }, 0)) }) : t('orderTotal', { amount: money(sum) })) + '</div>' +
      (waiting.length ? '<div class="ls-order__note">' + esc(t('orderPickFirst', { count: waiting.length })) + '</div>' : '') +
      '<div class="ls-order__note">' + esc(t('orderShipping')) + '</div>' +
      '<div class="ls-form__actions" style="margin-top:6px">' + (ready.length ? '<button class="ls-btn ls-btn--primary ls-add" data-order-add="' + turn.id + '"' + (turn.adding ? ' data-state="busy" disabled' : '') + '><span class="ls-add__label">' + esc(t('orderAdd')) + '</span><span class="ls-add__busy">' + mark(true) + '</span></button>' : '') +
      (allIn || rows.some(function (r) { return r.done; }) ? '<a class="ls-btn ' + (ready.length ? 'ls-btn--secondary' : 'ls-btn--primary') + '" href="' + esc(o.cart_url || CFG.cartUrl) + '">' + icon('cart', 'ls-icon--sm') + esc(t('orderToCart')) + '</a>' : '') + '</div></div>';
    return html;
  }
  function orderAdd(turn) {
    var rows = orderRows(turn).filter(function (r) { return r.add && !r.done; });
    if (!rows.length) { return; }
    turn.adding = true; render();
    var markRow = function (r, ok) { if (ok) { turn.done[r.i] = true; delete turn.failed[r.i]; } else { turn.failed[r.i] = true; } };
    var origin = win.location.origin, run;
    if (turn.order.platform === 'shopify') {
      run = win.fetch(origin + '/cart/add.js', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ items: rows.map(function (r) { return { id: Number(r.add.variant), quantity: r.add.quantity }; }), attributes: { la_source: 'ai' } }) })
        .then(function (res) { rows.forEach(function (r) { markRow(r, res.ok); }); }, function () { rows.forEach(function (r) { markRow(r, false); }); });
    } else {
      // WooCommerce: what its product form posts, to the product's page on this very address; the cart then says what went in
      run = rows.reduce(function (chain, r) {
        return chain.then(function () {
          var target;
          try { var at = new URL(r.add.post_url, origin); target = origin + at.pathname + at.search; } catch (e) { target = r.add.post_url; }
          var form = new URLSearchParams();
          Object.keys(r.add.fields || {}).forEach(function (name) { form.append(name, r.add.fields[name]); });
          return win.fetch(target, { method: 'POST', credentials: 'same-origin', body: form, redirect: 'follow' }).then(function (res) { markRow(r, res.ok); }, function () { markRow(r, false); });
        });
      }, Promise.resolve()).then(function () {
        return win.fetch(origin + '/wp-json/wc/store/v1/cart', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (res) { return res.ok ? res.json() : null; }).then(function (cart) {
          if (!cart || !cart.items) { return; }
          var held = {};
          cart.items.forEach(function (item) { held[String(item.id)] = true; if (item.parent) { held[String(item.parent)] = true; } });
          rows.forEach(function (r) { var f = r.add.fields || {}; markRow(r, !!(held[String(f.variation_id || '')] || held[String(f['add-to-cart'] || '')])); });
        }).catch(function () { /* the store has no cart API: the pages' answers stand */ });
      });
    }
    run.then(function () {
      turn.adding = false; render(); cartChanged();
      var all = orderRows(turn);
      // everything in: straight to the cart; a choice left, the shopper chooses and adds again
      if (all.every(function (r) { return r.done || !r.add; }) && !all.some(function (r) { return !r.add && r.line.status === 'requires_manual_option_selection'; })) {
        setTimeout(function () { win.location.href = turn.order.cart_url || CFG.cartUrl; }, 900);
      }
    });
  }

