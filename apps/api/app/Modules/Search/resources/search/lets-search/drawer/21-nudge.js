  // ---------------------------------------------------------------- the invitation: "Try our new search", beside the launcher
  // A small dark line that points at the site's search icon (showNudge says which), once in a
  // visitor's life: on a new visitor's first page with something to point at, a few seconds after it is ready, gone a
  // few seconds later, and never again, opened or not. A visitor the site knew before never gets it. The shop turns it
  // off (search.nudge), read from this page's fresh look, so a first page never shows it against the shop's choice.
  // ?lsNudge=1 shows it on every page, for demos; ?lsNudge=0 never.
  var NUDGE_KEY = 'ls_nudge', nudgeOff = null;
  // whether this visitor is still owed it: decided once, on the first page this script meets them, before they can leave
  function nudgeDue() {
    var state = store('local', NUDGE_KEY);
    if (state === null) { state = NEW_VISITOR ? 'due' : 'seen'; store('local', NUDGE_KEY, state); }
    return state === 'due';
  }
  function nudge(looked) {
    var force = URLQ.lsNudge === '1';
    if (URLQ.lsNudge === '0' || (!force && (CFG.preview || !nudgeDue()))) { return; }
    Promise.all([looked, new Promise(function (done) { setTimeout(done, force ? 600 : CFG.nudgeDelay); })]).then(function (r) {
      if (!force) {
        if (!r[0]) { return; } // the shop's choice is unknown on this page: still owed on the next one
        if (LOOK.nudge !== true || LOOK.enabled === false) { store('local', NUDGE_KEY, 'seen'); return; }
      }
      showNudge(force);
    });
  }
  function showNudge(force) {
    if (S.open || nudgeOff) { return; }
    var shown = function (n) { var r = n && n.getBoundingClientRect(); return !!r && r.width > 0 && r.bottom > 0 && r.top < win.innerHeight; };
    var pick = function (sel, top) {
      try { return [].slice.call(doc.querySelectorAll(sel)).filter(function (n) { return shown(n) && (!top || n.getBoundingClientRect().top < win.innerHeight * .3); })[0] || null; } catch (e) { return null; /* a selector the browser cannot read */ }
    };
    var ours = LAUNCHERS.filter(shown), inHeader = ours.filter(function (b) { return !b.classList.contains('ls-launch--float'); })[0];
    var take = CFG.takeOver !== false;
    // where shoppers look for search: the icon the shop named (search.nudge_at), our launcher in the header, the theme's
    // own search icon at the top of the page (while we take its search over), our floating launcher, the store's field
    var anchor = (LOOK.nudgeAt && pick(LOOK.nudgeAt)) || inHeader || (take && pick(SEARCH_TRIGGERS, true)) || ours[0] ||
      pick((take ? CFG.fieldSelector + ', ' : '') + OWN_FIELDS);
    if (!anchor) { return; } // nothing on this page to point at: still owed on the next one
    var lbtn = LAUNCHERS.indexOf(anchor) >= 0 ? anchor : null;
    var h = doc.createElement('lets-nudge');
    h.style.cssText = 'position:fixed;top:0;left:0;width:0;height:0;z-index:2147482998';
    dressHost(h);
    var sr = h.attachShadow({ mode: 'open' });
    sr.innerHTML = styles(['tokens.css', 'components.css', 'drawer.css']) + '<style>:host{all:initial}</style>' +
      '<aside class="ls-nudge ls" dir="' + DIR + '" lang="' + LANG + '" aria-label="' + esc(t('nudgeLabel')) + '">' +
        '<i class="ls-nudge__tip" aria-hidden="true"></i>' +
        '<button class="ls-nudge__go" type="button">' + mark() + '<span class="ls-nudge__t">' + esc(t('nudgeTitle')) + '</span></button>' +
        '<button class="ls-nudge__x" type="button" aria-label="' + esc(t('nudgeClose')) + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg></button>' +
      '</aside>';
    var box = sr.querySelector('.ls-nudge'), tip = sr.querySelector('.ls-nudge__tip'), raf = 0, timers = [];
    function place() {
      raf = 0;
      // measured from the drawn icon, not its tap area, so the line sits close to what it points at (a theme's icon
      // may hold a hidden one too, its "close")
      var ic = [].filter.call(anchor.querySelectorAll('svg'), function (s) { return s.getBoundingClientRect().width > 0; })[0];
      var r = (ic || anchor).getBoundingClientRect(), vw = doc.documentElement.clientWidth, vh = win.innerHeight;
      var off = !r.width || r.bottom < 0 || r.top > vh;
      box.classList.toggle('is-off', off);
      if (off) { return; }
      var w = box.offsetWidth, bh = box.offsetHeight, cx = r.left + r.width / 2, below = r.top < vh * .55;
      var left = Math.min(Math.max(12, cx - w / 2), vw - w - 12);
      box.style.left = left + 'px';
      box.style.top = (below ? r.bottom + 10 : r.top - bh - 10) + 'px';
      tip.style.left = Math.min(Math.max(18, cx - left), w - 18) + 'px';
      box.classList.toggle('is-above', !below);
    }
    function later() { if (!raf) { raf = requestAnimationFrame(place); } }
    function key(e) { if (e.key === 'Escape') { gone(); } }
    function gone() {
      if (nudgeOff !== gone) { return; }
      nudgeOff = null;
      timers.forEach(clearTimeout);
      win.removeEventListener('scroll', later, true); win.removeEventListener('resize', later); doc.removeEventListener('keydown', key);
      if (lbtn) { lbtn.classList.remove('is-hint'); }
      box.classList.remove('is-in');
      setTimeout(function () { h.remove(); }, REDUCED ? 0 : 320);
    }
    nudgeOff = gone;
    sr.querySelector('.ls-nudge__go').addEventListener('click', function () { gone(); open(); });
    sr.querySelector('.ls-nudge__x').addEventListener('click', gone);
    doc.body.appendChild(h);
    place();
    win.addEventListener('scroll', later, { passive: true, capture: true }); win.addEventListener('resize', later); doc.addEventListener('keydown', key);
    requestAnimationFrame(function () { place(); box.classList.add('is-in'); if (lbtn) { lbtn.classList.add('is-hint'); } });
    // shown once is enough: it never comes back, even if nobody touched it
    store('local', NUDGE_KEY, 'seen');
    timers.push(setTimeout(gone, force ? 60000 : CFG.nudgeFor));
  }
  // On a product page: a search row under "add to cart". Typing in it opens the drawer, the words going with it.
  function inlineField() {
    if (!CFG.inlineInto || CFG.inlineInto === 'none' || !S.ctx) { return; }
    var at = doc.querySelector(CFG.inlineInto === 'auto' ? (SHOPIFY ? 'product-form, form[action*="/cart/add"]' : 'form.cart') : CFG.inlineInto);
    if (!at || at.__letsInline) { return; }
    at.__letsInline = true;
    var h = doc.createElement('div');
    h.className = 'lets-inline';
    dressHost(h);
    var sr = h.attachShadow({ mode: 'open' });
    sr.innerHTML = styles(['tokens.css', 'components.css', 'drawer.css']) +
      '<style>:host{all:initial;display:block;margin:18px 0 4px}</style>' + SPRITE +
      '<div class="ls ls-inline" dir="' + DIR + '" lang="' + LANG + '"><label class="ls-field ls-inline__field"><span class="ls-ask__mark ls-inline__mark">' + mark() + '</span>' +
        '<input type="text" enterkeyhint="search" autocomplete="off" spellcheck="false" placeholder="' + esc(t('placeholderProduct')) + '" aria-label="' + esc(t('placeholderProduct')) + '" aria-haspopup="dialog">' +
        '<span class="ls-field__sep"></span><button class="ls-ibtn" type="button" aria-label="' + esc(t('photo')) + '" data-photo>' + icon('camera') + '</button></label></div>';
    var input = sr.querySelector('input'), box = sr.querySelector('.ls-field');
    var go = function (text) {
      var from = box.getBoundingClientRect();
      input.value = '';
      open({ text: text, about: true, from: text ? { left: from.left, top: from.top, width: from.width, height: from.height } : null });
    };
    input.addEventListener('input', function () { if (input.value.trim()) { go(input.value); } });
    // a press on the row opens the product's own view at once: its details, its common questions and actions
    // (not the focus the page gives back as the drawer closes)
    input.addEventListener('focus', function () { if (!S.open && Date.now() - (S.closedAt || 0) > 600) { go(''); } });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(input.value); } });
    sr.querySelector('[data-photo]').addEventListener('click', function (e) { e.preventDefault(); open({ focus: false }); photoPick(); });
    at.parentNode.insertBefore(h, at.nextSibling);
  }
