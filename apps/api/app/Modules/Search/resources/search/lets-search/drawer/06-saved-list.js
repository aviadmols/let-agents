  // ---------------------------------------------------------------- the saved list, kept by the store (as let-agents-shortlist.js kept it)
  // Kept on the server too (/shortlist/{site}/wishlist): the store's lists report, a list that waits on any
  // device after a phone sign-in, and a share link. Same browser key as before, so saved lists carry over.
  var WISH_KEY = 'la_shortlist_' + CFG.site, SHORT = null;
  function wishToken() { var abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', b = new Uint8Array(32), o = ''; (win.crypto || win.msCrypto).getRandomValues(b); for (var i = 0; i < 32; i++) { o += abc.charAt(b[i] % 62); } return o; }
  function wishRead() {
    var d = null;
    try { d = JSON.parse(win.localStorage.getItem(WISH_KEY) || 'null'); } catch (e) { d = null; }
    d = d && typeof d === 'object' ? d : {};
    return { compare: Array.isArray(d.compare) ? d.compare : [], wish: Array.isArray(d.wish) ? d.wish.map(String) : [], pending: Array.isArray(d.pending) ? d.pending.map(String) : [],
      token: typeof d.token === 'string' && /^[A-Za-z0-9_-]{24,64}$/.test(d.token) ? d.token : wishToken(), share: typeof d.share === 'string' ? d.share : null, account: !!d.account,
      phone: typeof d.phone === 'string' && /^[A-Za-z0-9]{40}$/.test(d.phone) ? d.phone : null, phoneShown: typeof d.phoneShown === 'string' ? d.phoneShown : null };
  }
  var WISH = wishRead();
  function wishSave() { try { win.localStorage.setItem(WISH_KEY, JSON.stringify(WISH)); } catch (e) { /* private window: the list lives as long as the page */ } }
  var proxyFailed = false;
  function wishSend(body) {
    if (SHORT && SHORT.wishlist === false) { return Promise.reject(new Error('off')); }
    // adds the server has not confirmed go with every request: one that failed is not lost
    var add = (body.add || []).concat(WISH.pending.filter(function (id) { return (body.add || []).indexOf(id) === -1 && (body.remove || []).indexOf(id) === -1; }));
    var payload = { token: WISH.token, add: add, remove: body.remove || [], ctx: visit() };
    if (WISH.phone) { payload.phone = WISH.phone; }
    var direct = function () {
      if (CFG.customer) { payload.customer = CFG.customer; }   // WordPress: the signed-in customer, as a proof the plugin signed
      return win.fetch(API + '/shortlist/' + SITE + '/wishlist', { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(function (r) { if (!r.ok) { throw new Error('wishlist'); } return r.json(); });
    };
    // a Shopify customer signed in: through the store's app proxy, which says who it is
    if (!(SHOPIFY && CFG.loggedIn) || proxyFailed) { return direct(); }
    return win.fetch('/apps/letagents/wishlist', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(payload) })
      .then(function (r) { if (!r.ok || (r.headers.get('Content-Type') || '').indexOf('json') === -1) { throw new Error('proxy'); } return r.json(); })
      .catch(function () { proxyFailed = true; return direct(); });
  }
  function wishTake(data) {
    if (!data) { return null; }
    if (WISH.phone && !data.phone && !data.account) { WISH.phone = null; }   // signed out elsewhere: forgotten here too
    WISH.phoneShown = data.phone || (WISH.phone ? WISH.phoneShown : null);
    WISH.wish = (data.items || []).map(String);
    WISH.pending = [];
    WISH.share = data.share || WISH.share;
    WISH.account = !!data.account;
    wishSave();
    S.saved = WISH.wish.slice(); S.phone = WISH.phoneShown;
    store('local', 'ls_saved', S.saved);
    render();
    return data;
  }
  function wishSync() { return wishSend({}).then(wishTake).catch(function () { return null; }); }
  function wishPhone(step, body) {
    return win.fetch(API + '/shortlist/' + SITE + '/phone/' + step, { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }).catch(function () { return { sent: false, ok: false, error: 'unavailable' }; });
  }
  // the list's share link, on the store's own address: it opens this list for whoever has it (?la_list=)
  function wishShare(done) {
    var go = function () { if (WISH.share) { var home = CFG.homeUrl || (win.location.origin + '/'); done(home + (home.indexOf('?') >= 0 ? '&' : '?') + 'la_list=' + encodeURIComponent(WISH.share)); } else { done(null); } };
    if (WISH.share) { go(); } else { wishSync().then(go); }
  }
  function shortWord(key, fallback) { return SHORT && SHORT.labels && SHORT.labels[key] ? SHORT.labels[key] : fallback; }

