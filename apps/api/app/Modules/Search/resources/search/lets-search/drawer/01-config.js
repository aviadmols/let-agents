  // ---------------------------------------------------------------- config
  var here = (doc.currentScript && doc.currentScript.src) || '';
  var VER = (here.match(/[?&]v=([^&#]+)/) || [])[1] || ''; // the script's version, so its stylesheets refresh with it
  // the stylesheets: inlined by the build (dist/lets-search.js), or linked from base next to the script
  var CSS = null; /*@@CSS@@*/
  function styles(files) {
    return files.map(function (f) { return CSS && CSS[f] != null ? '<style>' + CSS[f] + '</style>' : '<link rel="stylesheet" href="' + CFG.base + f + (VER ? '?v=' + VER : '') + '">'; }).join('');
  }
  var URLQ = {};
  try { new URLSearchParams(win.location.search).forEach(function (v, k) { URLQ[k] = v; }); } catch (e) { /* old browser */ }
  var DEFAULTS = {
    site: '', api: '', storeApi: '', cartUrl: '/cart/', nonce: '',
    base: here.replace(/[^/]*$/, ''),       // where tokens.css / components.css / drawer.css live
    locale: 'he',
    assistant: '',                           // the shop's name for the assistant; default ":shop AI"
    theme: 'lets',                           // lets | store
    fieldSelector: 'input[name="s"], input[type="search"]',
    openSelectors: '[data-search-open]',     // store buttons that should open us (mobile search icon)
    launcherInto: '',                        // header container for the launcher ('' = auto)
    launcher: 'auto',                        // auto | header | float | none
    launcherIcon: 'spark',                   // mark | spark | sparks | badge | dot | bubble (see LAUNCHER_ICONS)
    nudgeDelay: 2500,                        // ms after the page is ready before "Try our new search" shows (on/off: search.nudge)
    nudgeFor: 8000,                          // ms it stays before it steps away on its own
    productId: null,                        // set on a product page: the drawer starts with "about this product"
    pageType: null, pageId: null,            // the page being viewed (product | content), for its learning; productId implies a product
    platform: 'woocommerce',                 // woocommerce | shopify
    inlineInto: 'auto',                      // product page: the search row goes after this element; auto = the store's add-to-cart form, '' = none
    preview: false
  };
  // a store still printing the product scripts' contexts (LetAgentsSearchContext, LetAgentsContext) works as it is
  function legacyConfig() {
    var c = win.LetAgentsSearchContext || {}, w = win.LetAgentsContext || {}, out = {};
    if (!c.site && !w.site) { return out; }
    ['site', 'api', 'locale', 'storeApi', 'nonce', 'cartUrl', 'searchUrl', 'platform', 'root', 'currency', 'shopCurrency', 'moneyFormat', 'customer', 'loggedIn'].forEach(function (k) { var v = c[k] != null && c[k] !== '' ? c[k] : w[k]; if (v != null && v !== '') { out[k] = v; } });
    var page = w.page || c.page;
    if (page && page.id) { out.pageType = page.type === 'content' ? 'content' : 'product'; out.pageId = String(page.id); if (out.pageType === 'product') { out.productId = String(page.id); } }
    if (w.mode === 'preview' || c.preview === true) { out.preview = true; }
    if (c.silent === true) { out.silent = true; }   // a shopper while the store is in preview: counted, nothing shown
    if (out.platform === 'shopify') { out.fieldSelector = 'input[name="q"], input[type="search"]'; }
    return out;
  }
  var CFG = Object.assign({}, DEFAULTS, legacyConfig(), win.LetsSearchConfig || {});
  // The shop's look, as its team chose it (/search/{site}/look): Lets' look or the site's, the widget's
  // language, the assistant's name, whether the theme's own search box opens us. The last one this browser
  // read is used at once, so the page never flashes the wrong language; the fresh one follows (applyLook).
  // In the team's preview, what the preview page itself set wins.
  var PAGE_SET = win.LetsSearchConfig || {};
  var SITE_LOCALE = CFG.locale;
  var LOOK_KEY = 'ls_look_' + String(CFG.site || '');
  function lookWins(key) { return !(CFG.preview && PAGE_SET[key] != null); }
  var LOOK = (function () { try { var l = JSON.parse(win.localStorage.getItem(LOOK_KEY) || 'null'); return l && typeof l === 'object' ? l : {}; } catch (e) { return {}; } })();
  if (LOOK.lang && lookWins('locale')) { CFG.locale = LOOK.lang; }
  if (LOOK.theme && lookWins('theme')) { CFG.theme = LOOK.theme; }
  if (LOOK.assistant && lookWins('assistant')) { CFG.assistant = LOOK.assistant; }
  CFG.takeOver = LOOK.takeOver !== false;
  if (URLQ.lsTheme) { CFG.theme = URLQ.lsTheme; }
  if (URLQ.lsLang) { CFG.locale = URLQ.lsLang; }
  if (URLQ.lsLauncher) { CFG.launcher = URLQ.lsLauncher; }
  if (URLQ.lsPreview) { CFG.preview = true; }
  if (URLQ.lsSilent) { CFG.silent = true; }
  // a visitor the site met before carries our visitor id (cookie or storage), read before this page sets one
  var NEW_VISITOR = (function () {
    if (/(?:^|; )(?:let_agents_vid|rega_vid)=/.test(doc.cookie)) { return false; }
    try { return !win.localStorage.getItem('let_agents_vid'); } catch (e) { return true; }
  })();
  var LANG =/^en/i.test(String(CFG.locale || '')) ? 'en' : 'he';
  // the store: WooCommerce's Store API (with the shopper's cookies and its Nonce), or Shopify's own storefront
  var SHOPIFY = CFG.platform === 'shopify';
  var ROOT = String(CFG.root || '/').replace(/\/?$/, '/');
  var STORE = String(CFG.storeApi || '').replace(/\/$/, '');
  var nonce = CFG.nonce || null;
  var DIR = LANG === 'he' ? 'rtl' : 'ltr';

