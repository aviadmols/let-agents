  // ---------------------------------------------------------------- hooking into the store page
  // The launcher's icon (CFG.launcherIcon, or ?lsIcon=): a magnifier, and beside it, never inside, what makes it smart
  // (shoppers read the mark inside the lens as an X). The heart of a save from the page appears in the lens.
  var SPARK = 'M18.9.5c.4 3 1.5 4.1 4.5 4.5-3 .4-4.1 1.5-4.5 4.5-.4-3-1.5-4.1-4.5-4.5 3-.4 4.1-1.5 4.5-4.5z';
  var LENS = '<circle class="ls-lens" cx="9.5" cy="12" r="6.2"/><path class="ls-handle" d="m14.2 16.7 4.6 4.6"/>';
  var LAUNCHER_ICONS = {
    // the original: the mark inside the lens
    mark: '<circle class="ls-lens" cx="10.5" cy="10.5" r="7"/><path class="ls-handle" d="m16 16 4.5 4.5"/>' +
      '<g class="ls-halves"><path d="M7.4 7.6h6.2a3.1 3.1 0 0 1-6.2 0z" fill="currentColor"/><path d="M7.4 13.4h6.2a3.1 3.1 0 0 0-6.2 0z" fill="currentColor"/></g>',
    // a magnifier and a spark: "smart search"
    spark: LENS + '<path class="ls-spark" d="' + SPARK + '" fill="currentColor"/>',
    // a magnifier and two sparks
    sparks: LENS + '<path class="ls-spark" d="' + SPARK + '" fill="currentColor"/>' +
      '<path class="ls-spark ls-spark--2" d="M21.4 9.6c.15 1.1.6 1.55 1.7 1.7-1.1.15-1.55.6-1.7 1.7-.15-1.1-.6-1.55-1.7-1.7 1.1-.15 1.55-.6 1.7-1.7z" fill="currentColor"/>',
    // a magnifier with the Lets mark on a small badge at its corner
    badge: LENS + '<g class="ls-badge"><circle cx="18.6" cy="5.4" r="4.6" fill="currentColor"/><path d="M16.6 3.2h4a2 2 0 0 1-4 0zM16.6 7.6h4a2 2 0 0 0-4 0z" fill="var(--ls-bg, #fff)"/></g>',
    // a magnifier and a lime dot: "something new here"
    dot: '<circle class="ls-lens" cx="10.5" cy="10.5" r="7"/><path class="ls-handle" d="m16 16 4.5 4.5"/><circle class="ls-dot" cx="18.8" cy="4.6" r="3.2" fill="#e2ff78" stroke="currentColor" stroke-width="1.4"/>',
    // a lens that is also a speech bubble: "you can ask"
    bubble: '<path class="ls-lens" d="M9.5 3.6a6.8 6.8 0 1 1-3.9 12.4L2.8 17l.9-2.8A6.8 6.8 0 0 1 9.5 3.6z" stroke-linejoin="round"/><path class="ls-handle" d="m14.8 15.4 5.2 5.2"/><path class="ls-spark" d="' + SPARK + '" fill="currentColor"/>'
  };
  function launcherIcon() { return LAUNCHER_ICONS[URLQ.lsIcon] ? URLQ.lsIcon : LAUNCHER_ICONS[CFG.launcherIcon] ? CFG.launcherIcon : 'spark'; }
  function launcherHtml() {
    var key = launcherIcon();
    var heart = '<path class="ls-mini-heart" d="M10.5 13.6s-3.3-2-3.3-4.4a1.8 1.8 0 0 1 3.3-1 1.8 1.8 0 0 1 3.3 1c0 2.4-3.3 4.4-3.3 4.4z" fill="currentColor"/>';
    // the heart sits in the lens: the smaller lens of the icons with something beside it is lower and to the left
    var at = { spark: '-1 1.5', sparks: '-1 1.5', badge: '-1 1.5', bubble: '-1 0' }[key];
    // the saved dot sits in the lens too, where the heart lands: the heart takes its place for a moment (is-receiving).
    // The mark's lens holds the mark, so its dot stays at the corner (the badge below)
    var dot = key === 'mark' ? '' : '<circle class="ls-lens-dot" cx="10.5" cy="10.5" r="2.3" fill="currentColor"/>';
    if (at) { heart = '<g transform="translate(' + at + ')">' + heart + dot + '</g>'; } else { heart += dot; }
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' + LAUNCHER_ICONS[key] + heart + '</svg>' +
      '<span class="ls-launch__badge" hidden aria-hidden="true"></span>';
  }
  function makeLauncher(float) {
    var h = doc.createElement('span');
    h.style.cssText = float ? '' : 'display:inline-flex;align-items:center';
    var sr = h.attachShadow({ mode: 'open' });
    sr.innerHTML = styles(['tokens.css', 'drawer.css']) +
      '<style>:host{all:initial;display:inline-flex}.ls-launch{font:inherit}</style>' +
      '<button class="ls-launch' + (float ? ' ls-launch--float' : '') + (launcherIcon() !== 'mark' ? ' ls-launch--corner' : '') + '" type="button" aria-label="' + esc(t('open')) + '" aria-haspopup="dialog">' + launcherHtml() + '</button>';
    h.setAttribute('data-lets-launcher', '');
    dressHost(h);
    var btn = sr.querySelector('button');
    btn.addEventListener('click', function () { open(); });
    LAUNCHERS.push(btn);
    return h;
  }
  // the theme's own search icons open the drawer instead of the theme's search bar, caught before the theme sees the click
  var SEARCH_TRIGGERS = 'a[href$="/search"],a[href*="/search?"],[aria-controls*="search" i],.header__search-link,.header__icon--search,' +
    'details-modal.header__search>summary,[data-action*="search" i],button[aria-label*="search" i],a[aria-label*="search" i],' +
    'button[aria-label*="חיפוש"],a[aria-label*="חיפוש"],.search-toggle,.js-search-toggle';
  // what opens us whatever the shop chose: a button the theme marked for us (data-lets-open), and the store's own
  // ones named in the config; the theme's own search icons only while we take over its search (search.take_over_field)
  var OWN_TRIGGERS = '[data-lets-open]';
  function onSearchTrigger(e) {
    var sel = (CFG.takeOver !== false ? SEARCH_TRIGGERS + ',' : '') + OWN_TRIGGERS + (CFG.openSelectors ? ',' + CFG.openSelectors : '');
    var node = e.target && e.target.closest ? e.target.closest(sel) : null;
    if (!node || node.closest('form') || e.defaultPrevented || e.button > 0 || e.ctrlKey || e.metaKey || e.shiftKey) { return; }
    e.preventDefault(); e.stopImmediatePropagation();
    open();
  }
  // Shopify's predictive search would open beside ours: hidden, and its keys kept from the theme
  function quietThemeSearch(input) {
    var theirs = input.closest ? input.closest('predictive-search') : null;
    for (var node = input.parentElement, depth = 0; !theirs && node && node !== doc.body && depth < 5; node = node.parentElement, depth++) {
      theirs = node.querySelector('predictive-search');
      if (theirs) { theirs.setAttribute('data-let-agents-search-hidden', ''); }
    }
    [theirs, input.form].forEach(function (o) { if (o) { o.setAttribute('data-let-agents-search', ''); } });
    if (!doc.getElementById('let-agents-search-quiet')) {
      var style = doc.createElement('style');
      style.id = 'let-agents-search-quiet';
      style.textContent = '[data-let-agents-search] .predictive-search,[data-let-agents-search] [data-predictive-search],[data-let-agents-search] [id^="predictive-search-results"],[data-let-agents-search-hidden]{display:none!important}';
      (doc.head || doc.documentElement).appendChild(style);
    }
  }
  // the index is fetched when it is first needed: a focus or a press on a text field, or the drawer opening
  function wake(e) {
    var tg = e.target;
    if (tg && tg.tagName === 'INPUT' && /^(text|search)$/i.test(tg.type || 'text')) { loadIndex(); }
  }
  function hook() {
    hookFields();
    win.addEventListener('click', onSearchTrigger, true);
    doc.addEventListener('focusin', wake, true);
    doc.addEventListener('pointerdown', wake, true);
    hookLauncher();
    placeHeart();
  }
  // the store's own field opens us, carrying what was typed (again once the index names the shop's own selector).
  // A field the theme marked for us (data-lets-search) always does; the theme's own search box only while we take
  // over its search (search.take_over_field): off, it is let go and searches the site as it did before us.
  var OWN_FIELDS = 'input[data-lets-search], [data-lets-search] input';
  function hookFields() {
    var sel = (CFG.takeOver !== false ? CFG.fieldSelector + ', ' : '') + OWN_FIELDS, hooked = [];
    try { hooked = [].slice.call(doc.querySelectorAll(sel)); } catch (e) { hooked = [].slice.call(doc.querySelectorAll(OWN_FIELDS)); /* a selector the browser cannot read */ }
    hooked.forEach(function (input) {
      if (input.__lets) { return; }
      var go = function (e) { if (e) { e.preventDefault(); } var v = input.value; input.blur(); open({ text: v }); };
      var key = function (e) { if (e.key === 'Enter') { go(e); } };
      var form = input.form, sent = function (e) { e.preventDefault(); open({ text: input.value }); };
      if (SHOPIFY) { quietThemeSearch(input); }
      input.addEventListener('focus', go);
      input.addEventListener('keydown', key);
      if (form) { form.addEventListener('submit', sent); }
      input.__lets = function () {
        input.removeEventListener('focus', go); input.removeEventListener('keydown', key);
        if (form) { form.removeEventListener('submit', sent); form.removeAttribute('data-let-agents-search'); }
        doc.querySelectorAll('[data-let-agents-search-hidden]').forEach(function (n) { n.removeAttribute('data-let-agents-search-hidden'); });
        input.__lets = null;
      };
    });
  }
  // the shop turned the theme's search box over to us, or took it back: its fields hooked again by the new choice
  function rehook() {
    if (!BOOTED) { return; }
    doc.querySelectorAll('input').forEach(function (input) { if (typeof input.__lets === 'function' && !(input.matches && input.matches(OWN_FIELDS))) { input.__lets(); } });
    hookFields();
  }
  // the launcher: in the header when there's room, floating otherwise
  var FLOATS = false, FLOAT = null;
  function hookLauncher() {
    if (CFG.launcher === 'none') { return; }
    // a place the theme marked for our button (<span data-lets-button></span>, in its header or wherever it likes): the
    // launcher goes there, as in a header, and no floating one is added (Aviad, 29.10: a Shopify theme asked where)
    var slot = doc.querySelector('[data-lets-button]');
    if (slot && CFG.launcher !== 'float') { slot.appendChild(makeLauncher(false)); return; }
    var into = CFG.launcher !== 'float' && (CFG.launcherInto ? doc.querySelector(CFG.launcherInto) : (doc.querySelector('[data-search-open]') || {}).parentNode);
    if (into && CFG.launcher !== 'float') {
      var mobileToggle = into.querySelector('[data-search-open]');
      var l = makeLauncher(false);
      if (mobileToggle) { mobileToggle.style.display = 'none'; into.insertBefore(l, mobileToggle); } else { into.insertBefore(l, into.firstChild); }
    } else {
      FLOATS = true;
      placeFloat();
    }
  }
  // The floating launcher as the shop set it (search.float_button, float_side, float_bottom), from the look this browser
  // kept and again from the fresh one: on the screen's left or right whatever the page's direction ('auto': the page's
  // end, as before), as high as the shop chose. Hidden only while the page has another way in that a shopper can see:
  // a store with no search icon or box of its own keeps the button, so the search can always be opened.
  function placeFloat() {
    if (!FLOATS) { return; }
    if (LOOK.float === false && opensElsewhere()) {
      if (FLOAT) { LAUNCHERS.splice(LAUNCHERS.indexOf(FLOAT.shadowRoot.querySelector('button')), 1); FLOAT.remove(); FLOAT = null; }
      return;
    }
    if (!FLOAT) { FLOAT = makeLauncher(true); doc.body.appendChild(FLOAT); refreshLauncher(); }
    var btn = FLOAT.shadowRoot.querySelector('button');
    btn.classList.toggle('ls-launch--left', LOOK.floatSide === 'left');
    btn.classList.toggle('ls-launch--right', LOOK.floatSide === 'right');
    btn.style.setProperty('--ls-float-y', floatY(LOOK.floatBottom) + 'px');
  }
  // its height from the bottom of the screen, in pixels: 20 unless the shop chose (0 to 200, as the setting allows)
  function floatY(v) {
    var n = v == null || v === '' ? NaN : Math.round(Number(v));
    return isFinite(n) && n >= 0 ? Math.min(n, 200) : 20;
  }
  // The saved list's heart in the site's header, beside the search, where the shop turned it on (search.header_heart, off by
  // default, as Aviad asked 17.11): an outline in the colour of the site's own icons, full once something is saved, and a
  // press opens the list. Beside Lets' own button in the header, else the theme's search icon; nowhere to stand, none.
  var HEADER_HEART = null;
  function placeHeart() {
    var wanted = LOOK.heart === true && !(SHORT && SHORT.wishlist === false);
    if (!wanted) { if (HEADER_HEART) { HEADER_HEART.remove(); HEADER_HEART = null; } return; }
    if (HEADER_HEART && HEADER_HEART.isConnected) { paintHeart(); return; }
    var at = heartAnchor();
    if (!at) { return; }
    var svg = at.querySelector && at.querySelector('svg'), size = Math.round(svg ? svg.getBoundingClientRect().height : 0);
    size = size >= 14 && size <= 34 ? size : 22;
    var h = doc.createElement('span');
    h.setAttribute('data-lets-heart', '');
    var sr = h.attachShadow({ mode: 'open' });
    sr.innerHTML = '<style>:host{all:initial;display:inline-flex;align-items:center;color:inherit;vertical-align:middle}' +
      'button{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;width:' + (size + 18) + 'px;height:' + (size + 18) + 'px;cursor:pointer;color:inherit;border-radius:50%;-webkit-tap-highlight-color:transparent}' +
      'button:focus-visible{outline:2px solid currentColor;outline-offset:2px}' +
      'svg{width:' + size + 'px;height:' + size + 'px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linejoin:round;transition:fill 160ms ease,transform 260ms cubic-bezier(.2,.8,.2,1)}' +
      'button.is-full svg{fill:currentColor}button.is-pop svg{transform:scale(1.18)}@media (prefers-reduced-motion:reduce){svg{transition:none}}</style>' +
      '<button type="button" aria-haspopup="dialog"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19.5s-7.5-4.6-7.5-10.2A4.1 4.1 0 0 1 12 7.1a4.1 4.1 0 0 1 7.5 2.2c0 5.6-7.5 10.2-7.5 10.2z"/></svg></button>';
    sr.querySelector('button').addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); open({ focus: false }); S.tab = 'saved'; S.wide = null; render(); });
    at.parentNode.insertBefore(h, at.nextSibling);
    HEADER_HEART = h;
    paintHeart();
  }
  // where it stands: after Lets' own button in the header, else after the theme's first search icon a shopper can see
  function heartAnchor() {
    var own = LAUNCHERS.map(function (b) { return b.getRootNode && b.getRootNode().host; }).filter(function (host) { return host && host !== FLOAT && host.isConnected && host.getClientRects().length > 0; })[0];
    if (own) { return own; }
    var found = [];
    try { found = [].slice.call(doc.querySelectorAll(SEARCH_TRIGGERS + ',' + OWN_TRIGGERS + (CFG.openSelectors ? ',' + CFG.openSelectors : ''))); } catch (e) { found = [].slice.call(doc.querySelectorAll(SEARCH_TRIGGERS)); /* a selector the browser cannot read */ }
    return found.filter(function (n) { return !n.closest('form') && !n.closest('[data-lets-launcher]') && n.parentNode && n.getClientRects().length > 0; })[0] || null;
  }
  // full once something is saved; a save makes it beat once
  function paintHeart(pop) {
    if (!HEADER_HEART) { return; }
    var b = HEADER_HEART.shadowRoot.querySelector('button'), n = savedCount(), label = n ? t('saved') + ' · ' + n : t('saved');
    b.classList.toggle('is-full', n > 0);
    b.setAttribute('aria-label', label); b.setAttribute('title', label);
    if (pop && !REDUCED) { b.classList.remove('is-pop'); void b.offsetWidth; b.classList.add('is-pop'); setTimeout(function () { b.classList.remove('is-pop'); }, 260); }
  }
  // a way in to the search on this page that a shopper can see, besides the floating button: the theme's own search
  // icon or box (while we take its search over), a button or field the theme marked for us, the store's own named ones
  function opensElsewhere() {
    var sel = OWN_TRIGGERS + ', ' + OWN_FIELDS + (CFG.openSelectors ? ', ' + CFG.openSelectors : '') + (CFG.takeOver !== false ? ', ' + SEARCH_TRIGGERS + ', ' + CFG.fieldSelector : ''), found = [];
    try { found = [].slice.call(doc.querySelectorAll(sel)); } catch (e) { found = [].slice.call(doc.querySelectorAll(OWN_TRIGGERS + ', ' + OWN_FIELDS)); /* a selector the browser cannot read */ }
    return found.some(function (n) { return !n.closest('[data-lets-launcher]') && n.getClientRects().length > 0; });
  }

