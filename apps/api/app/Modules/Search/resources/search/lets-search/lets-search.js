/*!
 * Lets Search — the drawer (studio build).
 *
 * One script tag. It finds the store's search field and header, adds the smart-magnifier launcher,
 * and runs every part of the search inside one drawer: instant suggestions, results with filters,
 * answers with reasoned picks, photo search, similar items, "about this product", saved items and
 * compare, the cart, and the way to the store's team when there's no answer.
 *
 * Talks to the same endpoints as the product scripts (Let Agents API + the store's Store API).
 * Config: window.LetsSearchConfig (see DEFAULTS). Demo switches by URL: ?lsTheme=store, ?lsLang=en,
 * ?lsLauncher=float, ?lsOpen=1, ?lsPreview=1.
 */
(function (win, doc) {
  'use strict';
  if (win.LetsSearch) { return; }

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
    productId: null,                         // set on a product page: the drawer starts with "about this product"
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
  if (URLQ.lsTheme) { CFG.theme = URLQ.lsTheme; }
  if (URLQ.lsLang) { CFG.locale = URLQ.lsLang; }
  if (URLQ.lsLauncher) { CFG.launcher = URLQ.lsLauncher; }
  if (URLQ.lsPreview) { CFG.preview = true; }
  if (URLQ.lsSilent) { CFG.silent = true; }
  var LANG = /^en/i.test(String(CFG.locale || '')) ? 'en' : 'he';
  // the store: WooCommerce's Store API (with the shopper's cookies and its Nonce), or Shopify's own storefront
  var SHOPIFY = CFG.platform === 'shopify';
  var ROOT = String(CFG.root || '/').replace(/\/?$/, '/');
  var STORE = String(CFG.storeApi || '').replace(/\/$/, '');
  var nonce = CFG.nonce || null;
  var DIR = LANG === 'he' ? 'rtl' : 'ltr';

  // ---------------------------------------------------------------- words
  var T = {
    he: {
      open: 'חיפוש חכם', close: 'סגירה', saved: 'שמורים', more: 'עוד אפשרויות', newChat: 'שיחה חדשה', chatCleared: 'התחלנו שיחה חדשה', undo: 'ביטול',
      placeholder: 'חפשו מוצר, או שאלו כל דבר', placeholderProduct: 'שאלו על המוצר הזה, או חפשו כל דבר', clear: 'ניקוי', photo: 'חיפוש לפי תמונה', send: 'שליחה',
      welcomeTitle: 'מה מחפשים היום?', welcomeSub: 'חפשו מוצר, שאלו שאלה, או צלמו משהו ונמצא דומה.',
      recent: 'חיפושים אחרונים', popular: 'פופולרי בחנות', popAll: 'הצגת הכול', popLess: 'פחות', tryAsk: 'אפשר לשאול למשל', removeRecent: 'הסרה',
      askWho: 'שאלו את :name', searchFor: 'חיפוש "‎:q‎"', categories: 'קטגוריות', products: 'מוצרים', guides: 'מדריכים ודפים', quick: 'תשובות מהאתר',
      showAll: 'הצגת כל :n התוצאות', showAllOne: 'הצגת התוצאה', notSure: 'לא בטוחים מה מתאים? שאלו את :name',
      noMatch: 'לא מצאנו את "‎:q‎"', noMatchSub: 'אולי זה מתואר אחרת אצלנו. אפשר לשאול, או להתחיל מקטגוריה.',
      productsCount: ':n מוצרים', oneProduct: 'מוצר אחד', in: 'ב:name', filter: 'סינון', sort: 'מיון', sortRel: 'רלוונטי', sortAsc: 'מחיר: מהזול', sortDesc: 'מחיר: מהיקר', sortNew: 'הכי חדש',
      inStock: 'במלאי', onSale: 'במבצע', clearAll: 'ניקוי', showN: 'הצגת :n מוצרים', noFilterMatch: 'אין מוצרים שמתאימים לכל הסינונים. נסו להסיר אחד.',
      didYouMean: 'לא מצאנו בדיוק את "‎:q‎". למה התכוונתם?', category: 'קטגוריה: :t', exactSku: 'נמצא לפי מק״ט :sku', exact: 'התאמה מדויקת',
      noResults: 'לא מצאנו את "‎:q‎"', noResultsSub: 'נסו מילה אחרת, שאלו את :name, או התחילו מקטגוריה.',
      backToChat: 'חזרה לשיחה', loadMore: 'הצגת עוד', onSite: 'לכל התוצאות בדף החיפוש',
      add: 'הוספה לסל', added: 'נוסף לסל', addFailed: 'לא הצלחנו להוסיף. נסו לפתוח את המוצר.', options: 'בחירת אפשרויות', outStock: 'אזל', similarInStock: 'דומים במלאי', similar: 'דומים',
      onSaleBadge: 'במבצע', outBadge: 'אזל', toCart: 'לעגלה', keepGoing: 'ממשיכים לחפש', goesWith: 'מתאים לקנות יחד', whyShown: 'למה זה מוצג?', whyShownText: 'החנות סימנה את המוצרים האלה כמשלימים למוצר שהוספתם.',
      thinking: 'מחפש בחנות…', thinkingPhoto: 'מחפש מוצרים שנראים כמו התמונה…', slow: 'לוקח יותר זמן מהרגיל…', from: 'מתוך:', verify: 'רוצים לוודא?', verifyLink: 'הצוות זמין בוואטסאפ',
      general: 'זו תשובה כללית למוצרים מהסוג הזה. לפרטים מדויקים כדאי לבדוק בהוראות היצרן.',
      noInfo: 'לא מצאתי את זה באתר.', teamNow: 'הצוות זמין עכשיו · עד :t', teamBack: 'הצוות חוזר :when', teamWa: 'לשאול את הצוות בוואטסאפ',
      waText: 'לא מצאתי את זה באתר. הצוות של החנות יכול לענות על זה ישירות.', leadText: 'לא מצאתי את זה באתר. להשאיר פרטים, והצוות יחזור אליך עם תשובה?', meanwhile: 'בינתיים, אולי זה יעזור',
      leadQuestion: 'מה תרצו לשאול?', leadNeedQuestion: 'כתבו מה תרצו לשאול', name: 'שם', contact: 'טלפון או מייל', consent: 'אני מאשר/ת שהחנות תשמור את פרטיי כדי לחזור אליי עם תשובה לשאלה הזו.', leadSend: 'שליחה לצוות', leadNo: 'לא תודה, רק רציתי לשאול',
      leadNeedName: 'איך קוראים לך?', leadBadContact: 'צריך טלפון (9–10 ספרות) או כתובת מייל', leadNeedConsent: 'צריך לאשר כדי שנוכל לחזור אליך', leadDone: 'קיבלנו, :name. נחזור אליך :when.', leadDoneSub: 'השאלה והפרטים נשלחו לצוות של :shop.', leadDeclined: 'בסדר גמור. אפשר להמשיך לשאול אותי כל דבר.', leadError: 'משהו השתבש בשליחה. נסו שוב.',
      error: 'משהו השתבש בדרך', errorSub: 'החיבור נקטע לפני שהתשובה הגיעה. השאלה נשמרה.', retry: 'נסו שוב',
      photoTitle: 'חיפוש לפי תמונה', photoDrop: 'גררו לכאן תמונה, או בחרו אחת', photoDropMobile: 'צלמו מוצר או בחרו תמונה מהגלריה', photoChoose: 'בחירת תמונה', photoTake: 'צילום', photoPrivate: 'JPG, PNG או WEBP עד :mb · התמונה משמשת רק לחיפוש הזה ולא נשמרת',
      photoBig: 'התמונה גדולה מדי. אפשר עד :mb.', photoType: 'אפשר לחפש לפי JPG, PNG או WEBP.', photoSeen: 'זיהינו:', photoBest: 'הכי דומה', photoTagSite: 'לחפש "‎:t‎" בכל האתר', photoUnclear: 'התמונה לא ברורה מספיק כדי לזהות מה יש בה. תמונה קרובה יותר, באור טוב, של פריט אחד, תעזור.', photoAgain: 'נסו תמונה אחרת', photoWords: 'תארו במילים', photoNothing: 'זיהינו :seen, אבל אין משהו דומה בחנות.', photoNothingPlain: 'לא מצאנו בתמונה משהו שנמכר באתר.', photoOnlyTags: 'לא מצאנו מוצר שנראה בדיוק כך. אפשר לחפש לפי מה שבתמונה:', photoFailed: 'לא הצלחנו לחפש לפי התמונה. נסו שוב.', yourPhoto: 'התמונה שלכם',
      similarTo: 'דומים ל־', noSimilar: 'לא מצאנו פריטים דומים.',
      about: 'על המוצר הזה', askingAbout: 'שואלים על:', removeContext: 'הסרת ההקשר', popular30: 'פופולרי בחנות', specs: 'מפרט בקצרה', faq: 'שאלות שגולשים שאלו', promises: 'מה החנות מבטיחה', alternatives: 'מוצרים דומים', saleAlt: 'דומים במבצע', seeAll: 'הכול', aboutNote: 'התשובות נכתבות מהמידע על המוצר בלבד.',
      compareViewed: 'השוואה למוצר שראיתם קודם', talkTitle: 'רוצים לדבר עם מישהו שמבין?', talkBtn: 'לשיחה בוואטסאפ',
      savedEmptyT: 'עדיין אין כאן מוצרים', savedEmpty: 'לחצו על הלב ליד מוצר כדי לשמור אותו.', savedNote: 'הרשימה נשמרת בדפדפן הזה.', savedPhone: 'שמירה לפי טלפון', savedPhoneHelp: 'נשלח קוד ב־SMS, והרשימה תחכה לכם בכל מכשיר.', phone: 'מספר נייד', sendCode: 'שליחת קוד', code: 'הקוד שקיבלתם', verifyCode: 'אישור', phoneKept: 'הרשימה נשמרת למספר :p', badPhone: 'מספר נייד לא תקין', badCode: 'הקוד לא נכון',
      shareWa: 'שליחה בוואטסאפ', copyLink: 'העתקת קישור', copied: 'הקישור הועתק', savedToast: 'נשמר לשמורים', removedToast: 'הוסר מהשמורים',
      compare: 'השוואה', compareN: 'להשוות (:n)', compareNeed: 'בחרו עוד מוצר אחד לפחות', compareMax: 'אפשר להשוות עד :n מוצרים', compareTitle: 'השוואת מוצרים', onlyDiff: 'רק מה ששונה', price: 'מחיר', stock: 'מלאי', diffAsk: 'מה ההבדל ביניהם?', pickToCompare: 'בחירה להשוואה', remove: 'הסרה',
      choose: 'בחרו :a', chooseTitle: 'בחירת אפשרויות', size: 'מידה', unavailable: 'לא זמין',
      powered: 'Powered by Lets', preview: 'תצוגה מקדימה: רק צוות החנות רואה את זה',
      announceSearching: 'מחפש', announceReady: 'התשובה מוכנה', announceResults: ':n תוצאות', announceAdded: 'נוסף לסל', announceQuestion: 'זוהתה שאלה. Enter שולח אותה',
      days: ['ביום א׳', 'ביום ב׳', 'ביום ג׳', 'ביום ד׳', 'ביום ה׳', 'ביום ו׳', 'בשבת'], today: 'היום', tomorrow: 'מחר', at: 'ב־',
      orderTitle: 'ההזמנה שלך', orderLoading: 'מכינים את ההזמנה…', orderChoose: 'בחרו :field', orderOption: 'אפשרות', orderAdd: 'הוספה לסל', orderAdded: 'נוסף לסל', orderFailed: 'לא נוסף. אפשר להוסיף מעמוד המוצר.', orderOut: 'אזל מהמלאי', orderUnknown: 'לא נמצא בחנות', orderReduced: 'יש במלאי רק :n',
      orderNow: 'נכנס לסל עכשיו: :amount', orderTotal: 'סך ההזמנה: :amount', orderEstimate: 'סך ההזמנה, לפני הבחירות: החל מ-:amount', orderShipping: 'המשלוח מחושב בדף התשלום.', orderToCart: 'לסל ולתשלום', orderPickFirst: 'נשאר לבחור :count. אפשר להוסיף עכשיו את השאר.', orderPage: 'לעמוד המוצר', orderEmpty: 'לא נמצאו מוצרים בהזמנה הזאת.',
      recentTitle: 'המוצרים שראיתם לאחרונה', searchedTitle: 'עוד מהחיפוש ":query"',
      size_gone_banner: 'המידה לא במלאי? לחצו על המידה להצגת פריטים דומים במידה המבוקשת', size_gone_pick: 'המידה לא במלאי? בחרו מידה להצגת פריטים דומים בה:', size_gone_badge: 'אזל. לחצו לפריטים דומים במידה הזו',
      size_similar_title: 'פריטים דומים במידה :size', size_similar_none: 'לא מצאנו כרגע פריטים דומים במידה :size.', size_similar_more: 'פריטים דומים (בלי לבדוק מידה)',
      sharedList: 'רשימה ששיתפו איתך', phoneConsent: 'אפשר לשלוח לי הודעות על מבצעים', phoneOut: 'יציאה',
      loadPrev: 'טעינת השיחה הקודמת', prevChat: 'השיחה הקודמת', slidePrev: 'הקודמים', slideNext: 'הבאים',
      activeFilters: 'סינונים פעילים', clearFilters: 'ניקוי הכול', removeFilter: 'הסרת :t', detected: 'זוהה מהחיפוש', match: ':n% התאמה לחיפוש שלך', guide: 'מדריך', page: 'עמוד',
      expand: 'הגדלת החלון', shrink: 'הקטנת החלון', bigView: 'תצוגה גדולה', allSimilar: 'כל הדומים (:n)', similarHead: 'מוצרים דומים ל־',
      shareSearch: 'שיתוף החיפוש', shareStore: 'שיתוף החנות', shareHelp: 'קישור לתוצאות כפי שהן עכשיו, עם הסינון.', shareStoreHelp: 'קישור לחנות, לשלוח למי שתרצו.', shareMore: 'עוד…', shareCopied: 'הקישור הועתק: פותח את החנות עם החיפוש הזה.',
      shareText: 'תראו מה מצאתי ב:store: :q', shareStoreText: 'תראו את :store:',
      aiAsk: 'לשאול את ה-AI שלי', aiHelp: 'החיפוש והמוצרים נשלחים ל-AI שתבחרו, כדי להתייעץ לפני הקנייה.', aiStoreHelp: 'כל החנות נשלחת ל-AI שתבחרו: הוא ימליץ, ישווה ויכין קישור לתשלום עם המוצרים בסל.',
      aiCopy: 'העתקת קישור', aiCopied: 'הקישור הועתק. אפשר להדביק אותו בכל AI.', aiFailed: 'לא הצלחנו להכין את הקישור. נסו שוב.', aiFor: 'נשלח: ":q" · :n מוצרים',
      kitTitle: 'יש בחנות ערכה מוכנה', myAiTitle: 'רוצים לבדוק לעומק עם ה-AI שלכם?', myAiSub: 'הוא יקבל את השאלה ואת כל המידע מהחנות, ויחזיר הזמנה מוכנה לסל כאן.', aiPromptQ: 'השאלה שלי: ":q". אני רוצה לקנות ב:store. ענה/י מהמידע של החנות וסיים/י בקישור אחד להזמנה עם כל המוצרים. כל המידע וההוראות נמצאים כאן, עבוד/י לפיהם: :url',
      aiPrompt: 'אני רוצה לקנות ב:store. סיים/י תמיד כל המלצה בקישור אחד להזמנה עם כל המוצרים. כל המידע וההוראות נמצאים כאן, עבוד/י לפיהם: :url'
    },
    en: {
      open: 'Smart search', close: 'Close', saved: 'Saved', more: 'More options', newChat: 'New chat', chatCleared: 'Started a new chat', undo: 'Undo',
      placeholder: 'Search for a product, or ask anything', placeholderProduct: 'Ask about this product, or search anything', clear: 'Clear', photo: 'Search by photo', send: 'Send',
      welcomeTitle: 'What are you looking for?', welcomeSub: 'Search, ask a question, or snap a photo and we\'ll find something like it.',
      recent: 'Recent searches', popular: 'Popular in the store', popAll: 'Show all', popLess: 'Less', tryAsk: 'You could ask', removeRecent: 'Remove',
      askWho: 'Ask :name', searchFor: 'Search "‎:q‎"', categories: 'Categories', products: 'Products', guides: 'Guides and pages', quick: 'Answers from the site',
      showAll: 'Show all :n results', showAllOne: 'Show the result', notSure: 'Not sure what fits? Ask :name',
      noMatch: 'No match for "‎:q‎"', noMatchSub: 'We may call it something else. Ask, or start from a category.',
      productsCount: ':n products', oneProduct: '1 product', in: 'in :name', filter: 'Filter', sort: 'Sort', sortRel: 'Relevance', sortAsc: 'Price: low to high', sortDesc: 'Price: high to low', sortNew: 'Newest',
      inStock: 'In stock', onSale: 'On sale', clearAll: 'Clear', showN: 'Show :n products', noFilterMatch: 'Nothing matches every filter. Try removing one.',
      didYouMean: 'No exact match for "‎:q‎". Did you mean?', category: 'Category: :t', exactSku: 'Found by SKU :sku', exact: 'Exact match',
      noResults: 'No results for "‎:q‎"', noResultsSub: 'Try another word, ask :name, or start from a category.',
      backToChat: 'Back to chat', loadMore: 'Show more', onSite: 'All results on the search page',
      add: 'Add to cart', added: 'Added', addFailed: 'Couldn\'t add it. Try opening the product.', options: 'Choose options', outStock: 'Sold out', similarInStock: 'Similar in stock', similar: 'Similar',
      onSaleBadge: 'Sale', outBadge: 'Sold out', toCart: 'View cart', keepGoing: 'Keep searching', goesWith: 'Goes well with', whyShown: 'Why am I seeing this?', whyShownText: 'The store marked these as going with what you added.',
      thinking: 'Looking through the store…', thinkingPhoto: 'Looking for products like your photo…', slow: 'Taking longer than usual…', from: 'From:', verify: 'Want to be sure?', verifyLink: 'The team is on WhatsApp',
      general: 'This is a general answer for products of this kind. Check the maker\'s instructions for exact details.',
      noInfo: 'I couldn\'t find that on the site.', teamNow: 'The team is available now · until :t', teamBack: 'The team is back :when', teamWa: 'Ask the team on WhatsApp',
      waText: 'I couldn\'t find that on the site. The store\'s team can answer it directly.', leadText: 'I couldn\'t find that on the site. Leave your details and the team will get back to you?', meanwhile: 'Meanwhile, this might help',
      leadQuestion: 'What would you like to ask?', leadNeedQuestion: 'Write what you would like to ask', name: 'Name', contact: 'Phone or email', consent: 'I agree that the store keeps my details to get back to me about this question.', leadSend: 'Send to the team', leadNo: 'No thanks, I just wanted to ask',
      leadNeedName: 'What\'s your name?', leadBadContact: 'Enter a phone number or an email address', leadNeedConsent: 'Please agree so we can get back to you', leadDone: 'Got it, :name. We\'ll get back to you :when.', leadDoneSub: 'Your question and details went to the :shop team.', leadDeclined: 'No problem. Ask me anything else.', leadError: 'Something went wrong. Try again.',
      error: 'Something went wrong', errorSub: 'The connection dropped before the answer arrived. Your question is saved.', retry: 'Try again',
      photoTitle: 'Search by photo', photoDrop: 'Drop a photo here, or choose one', photoDropMobile: 'Take a photo or choose one from your gallery', photoChoose: 'Choose a photo', photoTake: 'Take a photo', photoPrivate: 'JPG, PNG or WEBP up to :mb · used only for this search, never stored',
      photoBig: 'That photo is too big. Up to :mb.', photoType: 'Use a JPG, PNG or WEBP photo.', photoSeen: 'We see:', photoBest: 'Closest', photoTagSite: 'Search "‎:t‎" across the site', photoUnclear: 'The photo isn\'t clear enough to tell what\'s in it. A closer, well-lit photo of one item helps.', photoAgain: 'Try another photo', photoWords: 'Describe it', photoNothing: 'We see :seen, but nothing like it in the store.', photoNothingPlain: 'Nothing in the photo is sold here.', photoOnlyTags: 'Nothing looks exactly like it. Search by what\'s in the photo:', photoFailed: 'Couldn\'t search by this photo. Try again.', yourPhoto: 'Your photo',
      similarTo: 'Similar to ', noSimilar: 'No similar items found.',
      about: 'About this product', askingAbout: 'Asking about:', removeContext: 'Remove context', popular30: 'Popular', specs: 'Key specs', faq: 'Questions shoppers asked', promises: 'The store promises', alternatives: 'Similar products', saleAlt: 'Similar on sale', seeAll: 'All', aboutNote: 'Answers come from the product\'s information only.',
      compareViewed: 'Compared with what you viewed', talkTitle: 'Want to talk to someone who knows?', talkBtn: 'Chat on WhatsApp',
      savedEmptyT: 'Nothing saved yet', savedEmpty: 'Tap the heart on a product to keep it here.', savedNote: 'Saved in this browser.', savedPhone: 'Save with your phone', savedPhoneHelp: 'We\'ll text a code and your list will wait on any device.', phone: 'Mobile number', sendCode: 'Send code', code: 'Your code', verifyCode: 'Confirm', phoneKept: 'Saved to :p', badPhone: 'That number doesn\'t look right', badCode: 'Wrong code',
      shareWa: 'Share on WhatsApp', copyLink: 'Copy link', copied: 'Link copied', savedToast: 'Saved', removedToast: 'Removed',
      compare: 'Compare', compareN: 'Compare (:n)', compareNeed: 'Pick at least one more', compareMax: 'Up to :n products', compareTitle: 'Compare products', onlyDiff: 'Only differences', price: 'Price', stock: 'Stock', diffAsk: 'What\'s the difference?', pickToCompare: 'Pick to compare', remove: 'Remove',
      choose: 'Choose :a', chooseTitle: 'Choose options', size: 'Size', unavailable: 'Unavailable',
      powered: 'Powered by Lets', preview: 'Preview: only the store team sees this',
      announceSearching: 'Searching', announceReady: 'Answer ready', announceResults: ':n results', announceAdded: 'Added to cart', announceQuestion: 'Question detected. Enter sends it',
      days: ['on Sunday', 'on Monday', 'on Tuesday', 'on Wednesday', 'on Thursday', 'on Friday', 'on Saturday'], today: 'today', tomorrow: 'tomorrow', at: 'at ',
      orderTitle: 'Your order', orderLoading: 'Preparing your order…', orderChoose: 'Choose :field', orderOption: 'an option', orderAdd: 'Add to cart', orderAdded: 'Added to the cart', orderFailed: 'Not added. You can add it from the product page.', orderOut: 'Out of stock', orderUnknown: 'Not in the store', orderReduced: 'Only :n in stock',
      orderNow: 'Goes into the cart now: :amount', orderTotal: 'Order total: :amount', orderEstimate: 'Order total, before the choices: from :amount', orderShipping: 'Shipping is calculated at checkout.', orderToCart: 'To the cart and checkout', orderPickFirst: ':count still to choose. The rest can be added now.', orderPage: 'Product page', orderEmpty: 'No products were found in this order.',
      recentTitle: 'Products you viewed lately', searchedTitle: 'More from ":query"',
      size_gone_banner: 'Size out of stock? Tap the size for similar items in it', size_gone_pick: 'Size out of stock? Choose a size for similar items in it:', size_gone_badge: 'Sold out. Tap for similar items in this size',
      size_similar_title: 'Similar items in size :size', size_similar_none: 'No similar items in size :size right now.', size_similar_more: 'Similar items (size not checked)',
      sharedList: 'A list shared with you', phoneConsent: 'You may send me offers', phoneOut: 'Sign out',
      loadPrev: 'Load the previous conversation', prevChat: 'Previous conversation', slidePrev: 'Previous', slideNext: 'Next',
      activeFilters: 'Active filters', clearFilters: 'Clear all', removeFilter: 'Remove :t', detected: 'Read from your search', match: ':n% match for your search', guide: 'Guide', page: 'Page',
      expand: 'Enlarge', shrink: 'Make smaller', bigView: 'Large view', allSimilar: 'All similar (:n)', similarHead: 'Similar to ',
      shareSearch: 'Share the search', shareStore: 'Share the store', shareHelp: 'A link to the results as they are now, filters included.', shareStoreHelp: 'A link to the store, to send to anyone.', shareMore: 'More…', shareCopied: 'Link copied: it opens the store with this search.',
      shareText: 'Look what I found at :store: :q', shareStoreText: 'Have a look at :store:',
      aiAsk: 'Ask my AI', aiHelp: 'The search and its products go to the AI you choose, to take advice before buying.', aiStoreHelp: 'The whole store goes to the AI you choose: it recommends, compares and makes a checkout link with the products in the cart.',
      aiCopy: 'Copy link', aiCopied: 'Link copied. Paste it into any AI.', aiFailed: 'We could not prepare the link. Please try again.', aiFor: 'Sending: ":q" · :n products',
      kitTitle: 'The store has a ready-made kit', myAiTitle: 'Want to look deeper with your own AI?', myAiSub: 'It gets the question and all the store\'s information, and brings back an order ready for the cart here.', aiPromptQ: 'My question: ":q". I want to buy from :store. Answer from the store\'s information and end with one order link holding all the products. Everything you need, and your instructions, are here; work by them: :url',
      aiPrompt: 'I want to buy from :store. Always end every recommendation with one order link holding all the products. Everything you need, and your instructions, are here; work by them: :url'
    }
  }[LANG];
  function t(key, vars) {
    var s = T[key] == null ? key : T[key];
    if (vars) { Object.keys(vars).forEach(function (k) { var v = vars[k]; if (k === 'name' || k === 'shop') { v = '\u2068' + v + '\u2069'; } s = s.split(':' + k).join(v); }); }
    return s;
  }

  // ---------------------------------------------------------------- tiny helpers
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c]; }); }
  function decode(s) { var d = doc.createElement('textarea'); d.innerHTML = String(s || ''); return d.value; }
  function icon(n, cls) { if (n === 'fwd' || n === 'back') { cls = (cls || '') + ' ls-icon--dir'; } return '<svg class="ls-icon ' + (cls || '') + '" aria-hidden="true"><use href="#ls-i-' + n + '"/></svg>'; }
  function mark(think) { return '<span class="ls-mark' + (think ? ' ls-mark--think' : '') + '" aria-hidden="true"><i></i><i></i></span>'; }
  // a price as the store writes it: Shopify's money format (or, browsing in another currency, the browser's),
  // WooCommerce's prefix and suffix from its Store API; ₪ until the store has said
  function money(n) {
    if (n == null || isNaN(n)) { return ''; }
    var e = win.LA_ENGINE;
    if (SHOPIFY && e && e.formatMoney && (CFG.moneyFormat || CFG.currency)) {
      var foreign = CFG.currency && CFG.shopCurrency && CFG.currency !== CFG.shopCurrency;
      try { return e.formatMoney(Math.round(Number(n) * 100), foreign ? '' : CFG.moneyFormat, CFG.currency || 'ILS', LANG); } catch (x) { /* plain */ }
    }
    var text = Number(n).toLocaleString(LANG === 'he' ? 'he-IL' : 'en-US', { maximumFractionDigits: 2 }), cur = S && S.cur;
    return cur ? (cur.prefix || '') + text + (cur.suffix || '') : '₪' + text;
  }
  function store(kind, key, val) {
    try {
      var s = kind === 'local' ? win.localStorage : win.sessionStorage;
      if (val === undefined) { var v = s.getItem(key); return v ? JSON.parse(v) : null; }
      if (val === null) { s.removeItem(key); } else { s.setItem(key, JSON.stringify(val)); }
    } catch (e) { return null; }
    return null;
  }
  function uid() { return Math.random().toString(36).slice(2, 10); }
  function json(url, opts) {
    return win.fetch(url, Object.assign({ credentials: 'omit' }, opts || {})).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
    });
  }
  function post(url, body) { return json(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }); }
  var API = String(CFG.api).replace(/\/$/, '');
  var SITE = encodeURIComponent(CFG.site);
  function words(text) { var i = 0; return esc(text).split(' ').map(function (w) { return '<span class="ls-w" style="--i:' + (i++) + '">' + w + ' </span>'; }).join(''); }

  // ---------------------------------------------------------------- counting (as the old search script counted)
  // The same events, keys and payloads the store's reports are built on: /search/{site}/events with
  // {events, ctx:{sid,page,ref}, vid?}. Nothing about the shopper: an anonymous id, only with an add to cart.
  var COUNTED_KEY = 'let_agents_search_counted', FOUND_KEY = 'let_agents_found', PAUSE_MS = 2000;
  function norm(q) {
    var e = win.LA_ENGINE;
    try { if (e && e.normalize) { return e.normalize(q); } } catch (x) { /* plain */ }
    return String(q || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }
  function countedSet() { try { return JSON.parse(win.sessionStorage.getItem(COUNTED_KEY) || '[]'); } catch (e) { return []; } }
  function isCounted(n) { return countedSet().indexOf(n) !== -1; }
  function markCounted(n) {
    try { var list = countedSet(); if (list.indexOf(n) === -1) { list.push(n); win.sessionStorage.setItem(COUNTED_KEY, JSON.stringify(list.slice(-50))); } } catch (e) { /* private mode: may count twice */ }
  }
  // the visit as the store manager reads it beside a search: this tab's session, the page, where the session came from
  function visit() {
    var sid = null, ref = '';
    try {
      sid = win.sessionStorage.getItem('let_agents_sid');
      if (!sid) {
        sid = 's' + Math.random().toString(36).slice(2, 12) + Date.now().toString(36);
        win.sessionStorage.setItem('let_agents_sid', sid);
        var from = doc.referrer || '', external = from && from.indexOf(win.location.protocol + '//' + win.location.host) !== 0;
        win.sessionStorage.setItem('let_agents_ref', external ? from : '');
      }
      ref = win.sessionStorage.getItem('let_agents_ref') || '';
    } catch (e) { /* storage blocked: no session */ }
    return { sid: sid, page: win.location.pathname, ref: ref };
  }
  function visitQuery() { var v = visit(); return (v.sid ? '&sid=' + encodeURIComponent(v.sid) : '') + '&page=' + encodeURIComponent(v.page) + (v.ref ? '&ref=' + encodeURIComponent(v.ref) : ''); }
  // the same anonymous id the product page module used (cookie, else this browser), so daily questions add up once
  var visitorId = null;
  function visitor() {
    if (visitorId) { return visitorId; }
    var ok = function (v) { return /^anon-[A-Za-z0-9_-]{16,64}$/.test(v || ''); };
    var m = doc.cookie.match(/(?:^|; )let_agents_vid=([^;]*)/) || doc.cookie.match(/(?:^|; )rega_vid=([^;]*)/), id = m ? decodeURIComponent(m[1]) : null;
    if (!ok(id)) { try { id = win.localStorage.getItem('let_agents_vid'); } catch (e) { id = null; } }
    if (!ok(id)) {
      var abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_', bytes = new Uint8Array(22);
      (win.crypto || win.msCrypto).getRandomValues(bytes);
      id = 'anon-'; for (var i = 0; i < bytes.length; i++) { id += abc.charAt(bytes[i] % abc.length); }
      try { win.localStorage.setItem('let_agents_vid', id); } catch (e) { /* the cookie still carries it */ }
    }
    visitorId = id;
    return id;
  }
  function sendEvents(events, withVisitor) {
    if (!CFG.api || !CFG.site) { return; }
    var payload = { events: events, ctx: visit() };
    if (withVisitor) { payload.vid = visitor(); }
    var body = JSON.stringify(payload), url = String(CFG.api).replace(/\/$/, '') + '/search/' + encodeURIComponent(CFG.site) + '/events';
    try { if (win.navigator.sendBeacon && win.navigator.sendBeacon(url, new Blob([body], { type: 'text/plain' }))) { return; } } catch (e) { /* fall through */ }
    try { win.fetch(url, { method: 'POST', body: body, keepalive: true, mode: 'cors', credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }); } catch (e) { /* best effort */ }
  }
  // the shopper's own last searches, in this browser only (read later, with consent, to explain a cart)
  function rememberSearch(query) {
    try {
      var key = 'let_agents_searches', list = JSON.parse(win.localStorage.getItem(key) || '[]'), cutoff = Date.now() - 14 * 864e5;
      list = list.filter(function (s) { return s && s.q && Date.parse(s.at) > cutoff && s.q !== query.trim(); });
      list.push({ q: String(query).trim().slice(0, 120), at: new Date().toISOString() });
      win.localStorage.setItem(key, JSON.stringify(list.slice(-20)));
    } catch (e) { /* storage blocked */ }
  }
  function countSearch(query, results) {
    var n = norm(query);
    if (n.length < 2 || isCounted(n)) { return; }
    markCounted(n);
    sendEvents([{ type: 'search', q: n, results: results }]);
    rememberSearch(query);
  }
  function countClick(query, item, shown) {
    var n = norm(query);
    if (!n || !item) { return; }
    var events = [];
    if (!isCounted(n)) { markCounted(n); events.push({ type: 'search', q: n, results: shown || 0 }); }
    events.push({ type: 'click', q: n, id: item.id, title: item.title });
    sendEvents(events);
    rememberFound(n, item);
  }
  function countAdd(query, item) {
    var n = norm(query);
    if (!n || !item) { return; }
    sendEvents([{ type: 'add_to_cart', q: n, id: item.id, title: item.title, path: win.location.pathname }], true);
  }
  function foundProducts() {
    try {
      var kept = JSON.parse(win.localStorage.getItem(FOUND_KEY) || '{}'), cutoff = Date.now() - 7 * 864e5, out = {};
      Object.keys(kept || {}).forEach(function (id) { if (kept[id] && kept[id].t > cutoff && kept[id].q) { out[id] = kept[id]; } });
      return out;
    } catch (e) { return {}; }
  }
  // a product opened from the search, kept a week: added to the cart later from its own page, it still came through the search
  function rememberFound(query, item) {
    var id = String(item && item.id || '');
    if (id.indexOf('p:') !== 0) { return; }
    try {
      var kept = foundProducts();
      kept[id.slice(2)] = { q: query, id: id, title: String(item.title || '').slice(0, 200), t: Date.now() };
      var ids = Object.keys(kept).sort(function (a, b) { return kept[b].t - kept[a].t; }).slice(0, 40), trimmed = {};
      ids.forEach(function (k) { trimmed[k] = kept[k]; });
      win.localStorage.setItem(FOUND_KEY, JSON.stringify(trimmed));
    } catch (e) { /* storage blocked */ }
  }
  // on the page of a product found by the search, the store's own add to cart counts as the search's, once
  function watchFoundProduct() {
    var meta = win.ShopifyAnalytics && win.ShopifyAnalytics.meta && win.ShopifyAnalytics.meta.product;
    var post = String(doc.body && doc.body.className || '').match(/(?:^|\s)postid-([0-9]+)(?:\s|$)/);
    var pageId = meta && meta.id ? String(meta.id) : (post && /(?:^|\s)single-product(?:\s|$)/.test(doc.body.className) ? post[1] : (CFG.productId ? String(CFG.productId) : null));
    var found = pageId ? foundProducts()[pageId] : null;
    if (!found) { return; }
    var counted = false;
    var count = function () { if (!counted) { counted = true; sendEvents([{ type: 'add_to_cart', q: found.q, id: found.id, title: found.title, path: win.location.pathname, via: 'page' }], true); } };
    var isAdd = function (url) { return /\/cart\/add(\.js)?(\?|$)|wc-ajax=add_to_cart|[?&]add-to-cart=/.test(String(url || '')); };
    win.addEventListener('submit', function (event) {
      var form = event.target, action = form && form.getAttribute ? String(form.getAttribute('action') || '') : '';
      if (!event.defaultPrevented && form && (/\/cart\/add/.test(action) || (form.classList && form.classList.contains('cart')))) { count(); }
    });
    if (win.fetch) {
      var fetchBefore = win.fetch;
      win.fetch = function (input) {
        var url = typeof input === 'string' ? input : (input && input.url), answer = fetchBefore.apply(this, arguments);
        if (isAdd(url)) { answer.then(function (r) { if (r && r.ok) { count(); } }, function () {}); }
        return answer;
      };
    }
    if (win.XMLHttpRequest) {
      var openBefore = win.XMLHttpRequest.prototype.open;
      win.XMLHttpRequest.prototype.open = function (method, url) {
        if (isAdd(url)) { this.addEventListener('load', function () { if (this.status >= 200 && this.status < 300) { count(); } }); }
        return openBefore.apply(this, arguments);
      };
    }
  }

  // ---------------------------------------------------------------- the product page's learning (as let-agents.js reported it)
  // The events the page bank's learned order, popularity, attribution, lift and reports are built on:
  // /widget/{site}/events, validated against packages/event-spec (one bad event loses its whole batch,
  // so every field here is exactly what the schema allows). A held-out visitor sees the order the
  // learning never touched, and what they do is kept out of it.
  var ABC64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  function rid(n) { var b = new Uint8Array(n), o = ''; (win.crypto || win.msCrypto).getRandomValues(b); for (var i = 0; i < n; i++) { o += ABC64.charAt(b[i] & 63); } return o; }
  var PAGE = null;     // { type: 'product'|'content', id }: set at boot
  var LEARN = { queue: [], shop: null, bank: null, holdout: false, preview: false, exposed: {}, answered: 0, offered: false, recent: null, searched: null };
  var previewKey = (function () {
    var fromUrl = null;
    try { fromUrl = new URLSearchParams(win.location.search).get('let_agents_preview'); } catch (e) { /* old browser */ }
    if (fromUrl && /^[a-f0-9]{32}$/.test(fromUrl)) { writeCookie('let_agents_preview', fromUrl, 30 * 86400); }
    var m = doc.cookie.match(/(?:^|; )let_agents_preview=([^;]*)/), key = fromUrl || (m ? decodeURIComponent(m[1]) : null);
    return /^[a-f0-9]{32}$/.test(key || '') ? key : null;
  })();
  function writeCookie(name, value, maxAge) {
    try { doc.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; SameSite=Lax' + (win.location.protocol === 'https:' ? '; Secure' : ''); } catch (e) { /* none */ }
  }
  // the store reads let_agents_vid at checkout to link an order to what was used, so it lives in a cookie too
  function keepVisitor() { writeCookie('let_agents_vid', visitor(), 31536000); }
  // which side of the measurement this visitor is on, from their own id alone: the same side on every page
  function heldOut(percent) {
    if (!percent) { return false; }
    var v = visitor(), n = 0;
    for (var i = 0; i < v.length; i++) { n = (n * 31 + v.charCodeAt(i)) % 100000; }
    return (n % 100) < percent;
  }
  function pageCtx() {
    var p = { type: PAGE.type, path: (win.location.pathname || '/').slice(0, 512) };
    if (p.path.charAt(0) !== '/') { p.path = '/'; }
    p[PAGE.type === 'product' ? 'product_id' : 'content_id'] = String(PAGE.id);
    return p;
  }
  function eligible() {
    var ids = (LEARN.bank.sections || []).map(function (s) { return s.candidate; });
    return ids.filter(function (id, i) { return ids.indexOf(id) === i; }).slice(0, 12);
  }
  function sectionOf(candidate) { return LEARN.bank && (LEARN.bank.sections || []).filter(function (s) { return s.candidate === candidate; })[0]; }
  function learn(type, section, slot, data) {
    if (!PAGE || !LEARN.bank) { return; }
    var ev = { id: rid(22), type: type, ts: Date.now(), page: pageCtx() };
    if (section) {
      if (!eligible().length) { return; }   // the schema needs at least one: an event without would lose its batch
      ev.candidate = { id: section.candidate, version: LEARN.bank.bank_version, model: section.model, slot: slot || 'panel' };
      ev.bank_version = LEARN.bank.bank_version;
      ev.eligible = eligible();
      ev.bucket = section.candidate === 'compare' ? 'same_family_seen' : 'none';
    }
    if (data) { ev.data = data; }
    LEARN.queue.push(ev);
    if (LEARN.queue.length >= 20) { learnFlush(false); }
  }
  function learnFlush(leaving) {
    if (!LEARN.shop || !LEARN.queue.length) { return; }
    var url = API + '/widget/' + SITE + '/events', sid = visit().sid || rid(22);
    while (LEARN.queue.length) {
      var body = JSON.stringify({ v: 1, shop: LEARN.shop, vid: visitor(), session: sid, sent_at: Date.now(), holdout: LEARN.holdout, preview: LEARN.preview, events: LEARN.queue.splice(0, 50) });
      var sent = false;
      if (leaving && win.navigator.sendBeacon) { try { sent = win.navigator.sendBeacon(url, new Blob([body], { type: 'text/plain' })); } catch (e) { sent = false; } }
      if (!sent && win.fetch) { win.fetch(url, { method: 'POST', body: body, keepalive: true, mode: 'no-cors', credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }).catch(function () {}); }
    }
  }
  setInterval(function () { learnFlush(false); }, 4000);
  doc.addEventListener('visibilitychange', function () { if (doc.visibilityState === 'hidden') { learnFlush(true); } });
  win.addEventListener('pagehide', function () { learnFlush(true); });
  // the store's own add to cart on a product page, counted once whichever of its signals fired
  var lastStoreAdd = 0;
  function storeAdded(quantity) {
    var now = Date.now();
    if (!PAGE || PAGE.type !== 'product' || now - lastStoreAdd < 1500) { return; }
    lastStoreAdd = now;
    learn('add_to_cart', null, null, { source: 'page', product_id: String(PAGE.id), quantity: quantity, result: 'added' });
    learnFlush(true);
  }
  function watchStoreAdds() {
    if (!PAGE || PAGE.type !== 'product') { return; }
    doc.addEventListener('submit', function (event) {
      var form = event.target;
      if (form && form.matches && form.matches('form.cart')) { var i = form.querySelector('input[name="quantity"]'), q = i ? parseInt(i.value, 10) : 1; storeAdded(q >= 1 && q <= 999 ? q : 1); }
    }, true);
    doc.body.addEventListener('wc-blocks_added_to_cart', function () { storeAdded(1); });
    try { if (win.jQuery) { win.jQuery(doc.body).on('added_to_cart', function () { storeAdded(1); }); } } catch (e) { /* no jQuery */ }
  }
  // the bank arrived for this page: which side of the measurement, the order to show, and the page view
  function learnFrom(b) {
    if (!b || !b.shop || LEARN.bank) { return; }
    LEARN.bank = b; LEARN.shop = b.shop;
    LEARN.preview = !!CFG.preview && b.preview === true;
    LEARN.holdout = heldOut(b.holdout_percent || 0);
    if (LEARN.holdout && b.baseline_order && b.sections) {
      var order = b.baseline_order;
      b.sections = b.sections.slice().sort(function (x, y) { var i = order.indexOf(x.candidate), j = order.indexOf(y.candidate); return (i < 0 ? 99 : i) - (j < 0 ? 99 : j); });
      b.teaser = null;
    }
    learn('page_view');
    setTimeout(function () { learnFlush(false); }, 1500);
    if (PAGE.type === 'product') { rememberCurrent(b); viewedProducts(b); }
  }
  // a section counts as seen once at least half of it was on screen for a second, once a page
  function watchExposure() {
    if (!LEARN.bank || !('IntersectionObserver' in win)) { return; }
    FLOW.querySelectorAll('[data-cand]').forEach(function (node) {
      var cand = node.getAttribute('data-cand');
      if (LEARN.exposed[cand] || node.__watched) { return; }
      node.__watched = true;
      var timer = null, since = 0, ratio = 0;
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          ratio = entry.intersectionRatio;
          if (ratio >= 0.5 && !timer) {
            since = Date.now();
            timer = setTimeout(function () {
              io.disconnect();
              if (LEARN.exposed[cand]) { return; }
              LEARN.exposed[cand] = true;
              var s = sectionOf(cand);
              if (s) { learn('exposure', s, 'panel', { visible_ms: Math.min(3600000, Date.now() - since), ratio: Math.min(1, Math.round(ratio * 100) / 100) }); }
            }, 1000);
          } else if (ratio < 0.5 && timer) { clearTimeout(timer); timer = null; }
        });
      }, { threshold: [0, 0.5, 1] });
      io.observe(node);
    });
  }
  // the product viewed before this one, of the same kind, within 30 days: "compare with what you viewed"
  var SEEN_KEY = 'let_agents_seen';
  function seenProducts() { try { var l = JSON.parse(win.localStorage.getItem(SEEN_KEY) || '[]'); return Array.isArray(l) ? l : []; } catch (e) { return []; } }
  function rememberCurrent(b) {
    var r = rec(PAGE.id), heading = doc.querySelector('h1'), og = doc.querySelector('meta[property="og:image"]');
    var entry = { id: String(PAGE.id), key: b.compare ? b.compare.key : null, title: r ? decode(r.title) : (heading ? heading.textContent.trim().slice(0, 120) : ''), url: win.location.href.split('?')[0],
      rows: b.compare ? b.compare.rows : null, kinds: b.assistant && b.assistant.product ? b.assistant.product.kinds || [] : [], image: og ? String(og.getAttribute('content') || '') : null, at: Date.now() };
    try { var list = seenProducts().filter(function (x) { return x && x.id !== entry.id; }); list.unshift(entry); win.localStorage.setItem(SEEN_KEY, JSON.stringify(list.slice(0, 8))); } catch (e) { /* private mode */ }
  }
  function viewedBefore() {
    var b = LEARN.bank, kinds = b && b.assistant && b.assistant.product ? b.assistant.product.kinds || [] : [];
    if (!kinds.length) { return null; }
    var limit = Date.now() - 30 * 864e5;
    return seenProducts().filter(function (x) { return x && x.id && x.id !== String(PAGE.id) && x.title && x.at > limit && (x.kinds || []).some(function (k) { return kinds.indexOf(k) !== -1; }); })[0] || null;
  }
  // the products this visitor looked at, and more from the search that brought them here (both asked for apart from the bank)
  function viewedProducts(b) {
    var body = function (o) { return { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(o) }; };
    if (b.recent) {
      json(API + '/widget/' + SITE + '/recent', body({ vid: visitor(), id: String(PAGE.id), locale: LANG })).then(function (r) {
        var d = r.ok && r.body && r.body.data;
        if (d && d.products && d.products.length) { LEARN.recent = d; return products(d.products.map(function (p) { return pid(p.id); }).slice(0, 8)).then(render); }
      }).catch(function () { /* the page works without it */ });
    }
    if (b.searched) {
      var last = null;
      try { var l = JSON.parse(win.localStorage.getItem('let_agents_searches') || '[]'); last = Array.isArray(l) && l.length ? l[l.length - 1] : null; } catch (e) { last = null; }
      var q = last && typeof last.q === 'string' ? last.q.trim() : '';
      if (q.length >= 2 && Date.parse(last.at) > Date.now() - 30 * 60000) {
        json(API + '/widget/' + SITE + '/searched', body({ q: q.slice(0, 120), id: String(PAGE.id), locale: LANG })).then(function (r) {
          var d = r.ok && r.body && r.body.data;
          if (d && d.products && d.products.length) { LEARN.searched = d; return products(d.products.map(function (p) { return pid(p.id); }).slice(0, 8)).then(render); }
        }).catch(function () { /* the page works without it */ });
      }
    }
  }
  // the bank's own wording first (the shop's), ours when it has none
  function bankLabel(key, fallback, vars) {
    var s = LEARN.bank && LEARN.bank.labels && LEARN.bank.labels[key] ? LEARN.bank.labels[key] : fallback;
    Object.keys(vars || {}).forEach(function (k) { s = String(s).split(':' + k).join(vars[k]); });
    return s;
  }

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

  // ---------------------------------------------------------------- state
  var S = {
    open: false, tab: 'chat', wide: null, index: null, records: {}, built: null, engine: win.LA_ENGINE || null,
    config: {}, facets: [], turns: store('session', 'ls_turns') || [], saved: store('local', 'ls_saved') || [],
    recent: store('local', 'ls_recent') || [], products: {}, ctx: CFG.productId ? String(CFG.productId) : null,
    phone: WISH.phoneShown, pick: [], onlyDiff: false, typing: false, active: -1,
    expanded: !!store('local', 'ls_expanded'), live: null, menu: ''
  };
  function blankC() { return { f: {}, sort: '', stock: false, sale: false, no: {}, auto: {} }; }
  if (WISH.wish.length) { S.saved = WISH.wish.slice(); } else if (S.saved.length) { WISH.wish = S.saved.slice(); WISH.pending = S.saved.slice(); wishSave(); }
  // what was asked on earlier pages is kept, but hidden on this one until asked for (the dashed line at the bottom)
  S.turns.forEach(function (x) { x.prev = true; });
  var fresh = {}; // turn ids that animate in this render only

  // ---------------------------------------------------------------- index (everything instant comes from here)
  function loadIndex() {
    if (S.indexing) { return S.indexing; }
    S.indexing = json(API + '/search/' + SITE + '/index?locale=' + LANG).then(function (r) {
      var d = r.body || {};
      S.index = d; S.config = d.config || {}; S.facets = d.facets || [];
      (d.items || []).forEach(function (it) { S.records[it.id] = it; });
      if (S.engine && S.engine.build) {
        try { S.built = S.engine.build((d.items || []).map(function (i) { return { id: i.id, title: i.title, keywords: i.kw || '' }; })); } catch (e) { S.built = null; }
      }
      if (!CFG.assistant) { CFG.assistant = (S.config.shopName || 'Lets') + ' AI'; }
      shopSettings();
      render();
      return d;
    });
    return S.indexing;
  }
  // what the shop turned on (the index config): photo search, questions, similar items, the cart, prices,
  // "ask my AI" and sharing. A setting the server did not send stays on.
  function off(key) { return S.config && S.config[key] === false; }
  function shopSettings() {
    R.classList.toggle('no-photo', off('photos'));
    R.classList.toggle('no-similar', off('similar'));
    // product pictures shown whole in a light frame, for a shop whose pictures sit on a white ground (search.image_fit)
    R.classList.toggle('is-frame', S.config.images === 'frame');
    doc.querySelectorAll('.lets-inline').forEach(function (h) { var b = h.shadowRoot && h.shadowRoot.querySelector('[data-photo]'); if (b) { b.hidden = off('photos'); b.previousElementSibling.hidden = off('photos'); } });
    if (S.config.selector && S.config.selector !== CFG.fieldSelector) { CFG.fieldSelector = CFG.fieldSelector + ', ' + S.config.selector; hookFields(); }
  }
  function rec(id) { id = String(id); return S.records[id.indexOf(':') > 0 ? id : 'p:' + id]; }
  function pid(id) { return String(id).replace(/^p:/, ''); }
  function hits(q) {
    if (!q) { return []; }
    if (S.built && S.engine) {
      try { return S.engine.search(S.built, q).map(function (h) { return S.records[h.id || h]; }).filter(Boolean); } catch (e) { /* fall through */ }
    }
    var n = q.toLowerCase();
    return (S.index && S.index.items || []).filter(function (i) { return (i.title + ' ' + (i.kw || '')).toLowerCase().indexOf(n) >= 0; });
  }
  function isQuestion(q) {
    if (!q || off('ask')) { return false; }
    // "תבנה לי הזמנה לבניית פרגולה": a request to put an order together is asked, not searched (the shared
    // engine reads questions alike on the server; this is the drawer's own)
    if (buildAsk(q)) { return true; }
    if (S.engine && S.engine.isQuestion) { try { if (S.engine.isQuestion(q)) { return true; } } catch (e) { /* none */ } }
    return /\?\s*$/.test(q) || /^(איזה|איזו|אילו|איך|כמה|האם|מה|למה|מתי|אפשר|יש לכם|יש לך|which|what|how|can|does|is|do|why)\b/i.test(q.trim()) || q.trim().split(/\s+/).length >= 5;
  }

  // store products (regular price, variations) for the cards on screen
  // live from the store, once a page view: the price and stock of today, not of last night's index
  function products(ids) {
    var need = ids.map(pid).filter(function (id) { return !Object.prototype.hasOwnProperty.call(S.products, id); });
    if (!need.length) { return Promise.resolve(); }
    if (SHOPIFY) { return shopifyProducts(need); }
    if (!STORE) { return Promise.resolve(); }
    return json(STORE + '/products?include=' + need.map(encodeURIComponent).join(',') + '&per_page=' + need.length, { credentials: 'same-origin' }).then(function (r) {
      need.forEach(function (id) { S.products[id] = null; });
      (Array.isArray(r.body) ? r.body : []).forEach(function (p) {
        S.products[String(p.id)] = p;
        if (!S.cur && p.prices) { S.cur = { prefix: p.prices.currency_prefix || '', suffix: p.prices.currency_suffix || '' }; }
      });
    }).catch(function () { /* cards keep the index price */ });
  }
  // Shopify has no "these products by id" for a storefront: each one's public JSON, four at a time, no token
  var SHOP_INFLIGHT = {};
  function handleOf(id) { var e = win.LA_ENGINE, r = rec(id); return e && e.handleFromUrl && r ? e.handleFromUrl(r.url) : null; }
  function shopifyJson(id) {
    var h = handleOf(id);
    if (!h) { return Promise.resolve(null); }
    return win.fetch(ROOT + 'products/' + encodeURIComponent(h) + '.js', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (res) { return res.ok ? res.json() : null; }).catch(function () { return null; });
  }
  // What a Shopify product page asks for besides its variants: a ring size its theme or an app keeps in a line
  // property (properties[Size], required) that the product's JSON does not have. Read from the product page
  // itself, once, when it is added: each field with its values as the page offers them (Rocks and Gold: a
  // ring that can be resized offers the sizes around its own). A value the page sets and does not let change
  // (a ring that cannot be resized keeps its size) goes with the line as it is (fixed). A required field
  // with nothing to choose from here (an engraving to type) sends the shopper to the page (free).
  var PAGE_FIELDS = {};
  function pageFields(id) {
    var h = handleOf(id);
    if (!h || !win.DOMParser) { return Promise.resolve(null); }
    if (PAGE_FIELDS[h]) { return PAGE_FIELDS[h]; }
    PAGE_FIELDS[h] = win.fetch(ROOT + 'products/' + encodeURIComponent(h), { credentials: 'same-origin', headers: { Accept: 'text/html' } }).then(function (res) { return res.ok ? res.text() : ''; }).then(function (html) {
      return html ? readPageFields(new win.DOMParser().parseFromString(html, 'text/html')) : null;
    }).catch(function () { return null; });
    return PAGE_FIELDS[h];
  }
  function readPageFields(page) {
    var forms = [].slice.call(page.querySelectorAll('form[action*="/cart/add"]'));
    // the product's own form, not a quick-add of another product on the page
    // the attribute, not .id: a form holding <input name="id"> (every Shopify one) answers .id with that input
    var idOf = function (f) { return f.getAttribute('id') || ''; };
    // the one the page's fields belong to (a theme puts them outside it, form="…"); a payments widget's form beside it has none
    var score = function (f) { var i = idOf(f); return f.querySelectorAll('input, select, textarea').length + (i ? page.querySelectorAll('[form="' + i + '"]').length * 2 : 0) + (f.closest('main') ? 1 : 0); };
    var main = forms.slice().sort(function (a, b) { return score(b) - score(a); })[0];
    if (!main) { return null; }
    var mine = function (el) { var f = el.getAttribute('form'); return f ? f === idOf(main) : main.contains(el); };
    var named = {}, out = { fields: [], fixed: {}, free: false };
    [].slice.call(page.querySelectorAll('[name^="properties["]')).forEach(function (el) {
      // "properties[_x]" is the store's own, never the shopper's
      if (/^properties\[_/.test(el.name) || !mine(el)) { return; }
      (named[el.name] = named[el.name] || []).push(el);
    });
    var labelOf = function (el) { var l = el.id && page.querySelector('label[for="' + el.id + '"]'); return l ? l.textContent.replace(/\s+/g, ' ').replace(/\s*\*\s*$/, '').trim() : ''; };
    Object.keys(named).forEach(function (name) {
      var els = named[name], el = els[0], key = name.replace(/^properties\[|\]$/g, ''), values = [];
      if (el.tagName === 'SELECT') {
        [].slice.call(el.options).forEach(function (o) { if (o.value.trim()) { values.push({ value: o.value, label: o.textContent.replace(/\s+/g, ' ').trim() }); } });
      } else if (el.type === 'radio') {
        els.forEach(function (r) { if (r.value.trim()) { values.push({ value: r.value, label: labelOf(r) || r.value }); } });
      } else if (el.type === 'hidden') {
        // a picker of the theme writes into it (Rocks and Gold's ring sizes): its own radios are the values
        if (!el.required) { return; }
        var box = el.closest('variant-picker, fieldset, .product-form__input, [class*="picker"], [data-block-type]') || el.parentNode, seen = {};
        [].slice.call(box.querySelectorAll('input[type="radio"]')).forEach(function (r) {
          var v = (r.getAttribute('data-original-value') || r.value).trim();
          if (v && !seen[v]) { seen[v] = 1; values.push({ value: v, label: labelOf(r) || v }); }
        });
        // nothing to choose, but the page set it (a ring that cannot be resized): it goes as it is
        if (!values.length && el.value.trim()) { out.fixed[key] = el.value.trim(); return; }
      } else if (el.required) {
        out.free = true;
        return;
      }
      if (values.length) {
        out.fields.push({ name: key, label: labelOf(el) || (/^size$/i.test(key) ? t('size') : key), values: values.slice(0, 60) });
      } else if (el.required) {
        out.free = true;
      }
    });
    return out;
  }
  function shopifyProducts(need) {
    var waits = [], queue = [];
    need.forEach(function (id) { if (SHOP_INFLIGHT[id]) { waits.push(SHOP_INFLIGHT[id]); } else { queue.push(id); } });
    var at = 0;
    var next = function () {
      if (at >= queue.length) { return null; }
      var id = queue[at++];
      return shopifyJson(id).then(function (j) { var e = win.LA_ENGINE; S.products[id] = j && e && e.fromShopifyProduct ? e.fromShopifyProduct(j) : null; if (j) { S.products[id + ':json'] = j; } }).then(next);
    };
    if (queue.length) {
      var workers = [];
      for (var w = 0; w < Math.min(4, queue.length); w++) { workers.push(next()); }
      var batch = Promise.all(workers).then(function () { queue.forEach(function (id) { delete SHOP_INFLIGHT[id]; }); });
      queue.forEach(function (id) { SHOP_INFLIGHT[id] = batch; });
      waits.push(batch);
    }
    return Promise.all(waits);
  }
  function livePrice(id) {
    var p = S.products[pid(id)];
    if (!p || !p.prices || p.prices.price == null) { return null; }
    return Number(p.prices.price) / Math.pow(10, p.prices.currency_minor_unit || 0);
  }
  function regular(id) {
    var p = S.products[pid(id)];
    if (!p || !p.prices) { return null; }
    var unit = Math.pow(10, p.prices.currency_minor_unit || 0);
    var reg = Number(p.prices.regular_price) / unit;
    var now = Number(p.prices.price) / unit;
    return reg > now ? reg : null;
  }

  // opening hours, as the store set them
  function hours() {
    var h = S.config.hours || {};
    var days = h.days || [];
    if (!days.length) { return { open: true }; }
    var now = new Date();
    try { now = new Date(now.toLocaleString('en-US', { timeZone: h.timezone || 'Asia/Jerusalem' })); } catch (e) { /* local time */ }
    var forced = (S.config.forceHours || '');
    var mins = now.getHours() * 60 + now.getMinutes();
    function span(d) { var m = /^(\d\d):(\d\d)-(\d\d):(\d\d)$/.exec(days[d] || ''); return m ? [m[1] * 60 + +m[2], m[3] * 60 + +m[4], m[3] + ':' + m[4], m[1] + ':' + m[2]] : null; }
    var today = span(now.getDay());
    var open = forced ? forced === 'open' : !!(today && mins >= today[0] && mins < today[1]);
    if (open) { return { open: true, until: today ? today[2] : '' }; }
    for (var k = 0; k < 8; k++) {
      var d = (now.getDay() + k) % 7, s = span(d);
      if (s && (k > 0 || mins < s[0])) {
        var when = k === 0 ? t('today') : k === 1 ? t('tomorrow') : T.days[d];
        return { open: false, when: when + ' ' + t('at') + s[3] };
      }
    }
    return { open: false, when: '' };
  }
  function wa(text) { var n = S.config.whatsapp; return n ? 'https://wa.me/' + n + '?text=' + encodeURIComponent(text) : null; }

  // ---------------------------------------------------------------- the shadow root
  var host = doc.createElement('lets-search');
  host.setAttribute('data-ls-theme', CFG.theme === 'store' ? 'store' : 'lets');
  var root = host.attachShadow({ mode: 'open' });
  var SPRITE = '<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>' +
    '<symbol id="ls-i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></symbol>' +
    '<symbol id="ls-i-camera" viewBox="0 0 24 24"><path d="M4 8.6A2.6 2.6 0 0 1 6.6 6h1.5l1.5-2h4.8l1.5 2h1.5A2.6 2.6 0 0 1 20 8.6v7.8a2.6 2.6 0 0 1-2.6 2.6H6.6A2.6 2.6 0 0 1 4 16.4z"/><circle cx="12" cy="12.4" r="3.4"/></symbol>' +
    '<symbol id="ls-i-x" viewBox="0 0 24 24"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></symbol>' +
    '<symbol id="ls-i-send" viewBox="0 0 24 24"><path d="M12 18.5v-13M6.5 11 12 5.5l5.5 5.5"/></symbol>' +
    '<symbol id="ls-i-back" viewBox="0 0 24 24"><path d="m9.5 6 6 6-6 6"/></symbol>' +
    '<symbol id="ls-i-fwd" viewBox="0 0 24 24"><path d="m14.5 6-6 6 6 6"/></symbol>' +
    '<symbol id="ls-i-down" viewBox="0 0 24 24"><path d="m6.5 9.5 5.5 5.5 5.5-5.5"/></symbol>' +
    '<symbol id="ls-i-heart" viewBox="0 0 24 24"><path d="M12 19.5s-7.5-4.6-7.5-10.2A4.1 4.1 0 0 1 12 7.1a4.1 4.1 0 0 1 7.5 2.2c0 5.6-7.5 10.2-7.5 10.2z"/></symbol>' +
    '<symbol id="ls-i-check" viewBox="0 0 24 24"><path d="M5.5 12.5 10 17l8.5-9.5"/></symbol>' +
    '<symbol id="ls-i-history" viewBox="0 0 24 24"><path d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3L4.5 9"/><path d="M4.5 4.5V9H9"/><path d="M12 8.5v3.8l2.6 1.6"/></symbol>' +
    '<symbol id="ls-i-trend" viewBox="0 0 24 24"><path d="m4 16 5-5 3.5 3.5L20 7"/><path d="M15 7h5v5"/></symbol>' +
    '<symbol id="ls-i-sliders" viewBox="0 0 24 24"><path d="M4 7.5h9M17 7.5h3M4 16.5h3M11 16.5h9"/><circle cx="15" cy="7.5" r="2"/><circle cx="9" cy="16.5" r="2"/></symbol>' +
    '<symbol id="ls-i-more" viewBox="0 0 24 24"><circle cx="6" cy="12" r=".9"/><circle cx="12" cy="12" r=".9"/><circle cx="18" cy="12" r=".9"/></symbol>' +
    '<symbol id="ls-i-grid" viewBox="0 0 24 24"><rect x="4.5" y="4.5" width="6" height="6" rx="1.6"/><rect x="13.5" y="4.5" width="6" height="6" rx="1.6"/><rect x="4.5" y="13.5" width="6" height="6" rx="1.6"/><rect x="13.5" y="13.5" width="6" height="6" rx="1.6"/></symbol>' +
    '<symbol id="ls-i-doc" viewBox="0 0 24 24"><path d="M7 3.8h6.5l4 4V20.2H7z"/><path d="M13.5 3.8v4h4M9.5 12.5h5M9.5 15.5h5"/></symbol>' +
    '<symbol id="ls-i-cart" viewBox="0 0 24 24"><path d="M3.5 4.5h2.2l2 10h10.1l1.9-7H6.6"/><circle cx="9" cy="18.8" r="1.2"/><circle cx="16.8" cy="18.8" r="1.2"/></symbol>' +
    '<symbol id="ls-i-whatsapp" viewBox="0 0 24 24"><path d="M12 3.8a8.2 8.2 0 0 0-7 12.4l-1.2 4 4.1-1.1A8.2 8.2 0 1 0 12 3.8z"/><path d="M9.2 8.6c.2-.4.4-.4.7-.4h.5c.2 0 .4.1.5.4l.6 1.5c.1.2 0 .4-.1.6l-.5.6a6.4 6.4 0 0 0 2.5 2.5l.6-.5c.2-.2.4-.2.6-.1l1.5.6c.3.1.4.3.4.5v.5c0 .3 0 .5-.4.7-.6.3-1.5.4-2.7-.1a8.5 8.5 0 0 1-4.1-4.1c-.5-1.2-.4-2.1-.1-2.7z"/></symbol>' +
    '<symbol id="ls-i-enter" viewBox="0 0 24 24"><path d="M18.5 6v4.5a3 3 0 0 1-3 3h-10"/><path d="m9 9.5-4 4 4 4"/></symbol>' +
    '<symbol id="ls-i-image" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="2.6"/><circle cx="9.2" cy="10" r="1.5"/><path d="m20 15.5-4.6-4.6L7 19"/></symbol>' +
    '<symbol id="ls-i-compare" viewBox="0 0 24 24"><path d="M7 8h11.5M15.5 5l3 3-3 3M17 16H5.5M8.5 13l-3 3 3 3"/></symbol>' +
    '<symbol id="ls-i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 11v5M12 8h.01"/></symbol>' +
    '<symbol id="ls-i-link" viewBox="0 0 24 24"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></symbol>' +
    '<symbol id="ls-i-truck" viewBox="0 0 24 24"><path d="M3.5 6.5h10v9h-10zM13.5 9.5h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.6"/><circle cx="17" cy="17.5" r="1.6"/></symbol>' +
    '<symbol id="ls-i-share" viewBox="0 0 24 24"><path d="M12 14.5V4M8 7.5l4-4 4 4"/><path d="M7.5 10.5h-1A2 2 0 0 0 4.5 12.5v5a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2h-1"/></symbol>' +
    '<symbol id="ls-i-spark" viewBox="0 0 24 24"><path d="M12 3.5c.6 4.4 2.6 6.9 7.5 8.5-4.9 1.6-6.9 4.1-7.5 8.5-.6-4.4-2.6-6.9-7.5-8.5 4.9-1.6 6.9-4.1 7.5-8.5z"/></symbol>' +
    '<symbol id="ls-i-expand" viewBox="0 0 24 24"><path d="M14.5 4.5h5v5M9.5 19.5h-5v-5M19.5 4.5l-6 6M4.5 19.5l6-6"/></symbol>' +
    // the AI services' own marks (simple-icons, CC0), filled
    '<symbol id="ls-i-chatgpt" viewBox="0 0 24 24"><path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z"/></symbol>' +
    '<symbol id="ls-i-claude" viewBox="0 0 24 24"><path d="m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z"/></symbol>' +
    '<symbol id="ls-i-perplexity" viewBox="0 0 24 24"><path d="M22.3977 7.0896h-2.3106V.0676l-7.5094 6.3542V.1577h-1.1554v6.1966L4.4904 0v7.0896H1.6023v10.3976h2.8882V24l6.932-6.3591v6.2005h1.1554v-6.0469l6.9318 6.1807v-6.4879h2.8882V7.0896zm-3.4657-4.531v4.531h-5.355l5.355-4.531zm-13.2862.0676 4.8691 4.4634H5.6458V2.6262zM2.7576 16.332V8.245h7.8476l-6.1149 6.1147v1.9723H2.7576zm2.8882 5.0404v-3.8852h.0001v-2.6488l5.7763-5.7764v7.0111l-5.7764 5.2993zm12.7086.0248-5.7766-5.1509V9.0618l5.7766 5.7766v6.5588zm2.8882-5.0652h-1.733v-1.9723L13.3948 8.245h7.8478v8.087z"/></symbol>' +
    '</defs></svg>';
  function logo(n) { return '<svg class="ls-logo ls-logo--' + n + '" aria-hidden="true"><use href="#ls-i-' + n + '"/></svg>'; }
  root.innerHTML =
    styles(['tokens.css', 'components.css', 'drawer.css']) +
    '<style>:host{all:initial}</style>' + SPRITE +
    '<div class="ls ls-root" dir="' + DIR + '" lang="' + LANG + '">' +
      '<div class="ls-scrim" data-close></div>' +
      '<aside class="ls-drawer" role="dialog" aria-modal="true" aria-label="' + esc(t('open')) + '"></aside>' +
      '<div class="ls-vh" role="status" aria-live="polite" id="ls-live"></div>' +
    '</div>';
  var R = root.querySelector('.ls-root'), D = root.querySelector('.ls-drawer'), SCRIM = root.querySelector('.ls-scrim'), LIVE = root.getElementById('ls-live');

  D.innerHTML =
    (CFG.preview ? '<div class="ls-preview">' + icon('info', 'ls-icon--sm') + esc(t('preview')) + '</div>' : '') +
    '<div class="ls-sheet__handle" data-drag></div>' +
    '<div class="ls-dhead" data-drag><div class="ls-tabs" role="tablist"><span class="ls-tabs__pill"></span>' +
      '<button class="ls-tab" role="tab" aria-selected="true" data-tab="chat">' + mark() + '<span class="ls-tab__name" dir="auto" data-name></span></button>' +
      '<button class="ls-tab" role="tab" aria-selected="false" data-tab="saved">' + esc(t('saved')) + '<span class="ls-tab__count ls-num" data-count>0</span></button>' +
    '</div><div class="ls-dhead__end">' +
      '<button class="ls-ibtn" aria-label="' + esc(t('more')) + '" aria-haspopup="menu" aria-expanded="false" data-menu>' + icon('more') + '</button>' +
      '<button class="ls-ibtn" aria-label="' + esc(t('close')) + '" data-close>' + icon('x') + '</button></div></div>' +
    '<div class="ls-menu" role="menu" hidden></div>' +
    '<button class="ls-edge" type="button" data-expand aria-label="' + esc(t('expand')) + '"><span class="ls-edge__grip"></span><span class="ls-edge__tip">' + esc(t('expand')) + '</span></button>' +
    // the field sits at the top of the main area: on a phone it floats over the results and slides away on scroll
    '<div class="ls-dmain"><div class="ls-dfield">' +
      '<label class="ls-field" data-field>' + icon('search') +
        '<input type="search" enterkeyhint="search" autocomplete="off" autocapitalize="off" spellcheck="false" role="combobox" aria-expanded="false" aria-controls="ls-suggest" aria-autocomplete="list" placeholder="' + esc(t('placeholder')) + '" aria-label="' + esc(t('placeholder')) + '">' +
        '<button class="ls-ibtn ls-ibtn--sm ls-clear" type="button" aria-label="' + esc(t('clear')) + '" data-clear>' + icon('x', 'ls-icon--sm') + '</button>' +
        '<span class="ls-field__sep"></span>' +
        '<button class="ls-ibtn" type="button" aria-label="' + esc(t('photo')) + '" data-photo>' + icon('camera') + '</button>' +
        '<button class="ls-ibtn ls-ibtn--solid ls-ibtn--sm ls-field__send" type="button" aria-label="' + esc(t('send')) + '" data-submit>' + icon('send', 'ls-icon--sm') + '</button>' +
      '</label></div>' +
    '<div class="ls-dbody ls-scroll">' +
        // what's being typed: live results at the top of the conversation, pushing the earlier turns down
        '<div class="ls-suggest" id="ls-suggest" role="listbox" aria-label="' + esc(t('placeholder')) + '"><div class="ls-suggest__in"></div></div>' +
        '<div class="ls-flow" role="log" aria-label="' + esc(t('open')) + '"></div></div>' +
      '<div class="ls-layer-scrim" hidden data-layer-close></div><div class="ls-layer" role="dialog" aria-modal="true" hidden></div>' +
      '<div class="ls-toast" role="status" hidden></div></div>' +
    // "Powered by": always at the drawer's foot, outside the scrolling body
    credit();
  var INPUT = D.querySelector('input'), FIELD = D.querySelector('[data-field]'), SUG = D.querySelector('.ls-suggest'), SUGIN = SUG.firstChild, BODY = D.querySelector('.ls-dbody'), FLOW = D.querySelector('.ls-flow');
  var LAYER = D.querySelector('.ls-layer'), LSCRIM = D.querySelector('.ls-layer-scrim'), TOAST = D.querySelector('.ls-toast'), MENU = D.querySelector('.ls-menu');
  var TABS = D.querySelector('.ls-tabs');

  function announce(s) { LIVE.textContent = ''; setTimeout(function () { LIVE.textContent = s; }, 40); }
  function placePill() { var p = TABS.querySelector('.ls-tabs__pill'), s = TABS.querySelector('[aria-selected="true"]'); if (p && s && s.offsetWidth) { p.style.left = s.offsetLeft + 'px'; p.style.width = s.offsetWidth + 'px'; } }
  function isPhone() { return win.matchMedia('(max-width: 720px)').matches; }
  // On a phone the whole layer covers exactly what can be seen: with the keyboard up that is the visual
  // viewport, from its own top. innerHeight is never mixed in: on iOS it disagrees with the fixed layer
  // once the keyboard is up, and the sheet was pushed up and cut.
  function fitViewport() {
    var a = root.activeElement, typing = !!a && /^(INPUT|TEXTAREA)$/.test(a.tagName);
    var vv = win.visualViewport, phone = isPhone() && vv && typing;
    R.style.top = phone ? vv.offsetTop + 'px' : '';
    R.style.height = phone ? vv.height + 'px' : '';
    R.style.bottom = phone ? 'auto' : '';
    // otherwise the sheet keeps the small viewport's height (CSS): the same whether the toolbar is in or out
    if (phone) { D.style.setProperty('--ls-vh', vv.height + 'px'); } else { D.style.removeProperty('--ls-vh'); }
  }

  // ---------------------------------------------------------------- open / close
  var lastFocus = null, closeTimer = null;
  function open(opts) {
    opts = opts || {};
    loadIndex();
    if (!S.open) {
      lastFocus = doc.activeElement;
      S.open = true;
      clearTimeout(closeTimer);
      if (!doc.body.contains(host)) { doc.body.appendChild(host); }
      R.classList.add('is-open');
      fitViewport();
      if (opts.from && opts.text) { fly(opts.from, opts.text); }
      requestAnimationFrame(function () { R.classList.add('is-open'); SCRIM.classList.add('is-open'); D.classList.add('is-open'); placePill(); });
      doc.documentElement.style.overflow = 'hidden';
      try { win.history.pushState({ lets: 1 }, ''); } catch (e) { /* file:// */ }
      // every opening starts clean: what was searched or asked before waits behind the dashed line
      S.turns.forEach(function (x) { x.prev = true; });
      S.showPrev = false;
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

  // The words typed on the product page travel into the drawer's field while it slides in: a copy of the
  // field flies from where it was typed to where it lands, and the real field shows the words on arrival.
  function fly(from, text) {
    try { if (win.matchMedia('(prefers-reduced-motion: reduce)').matches || !D.animate) { return; } } catch (e) { return; }
    var tr = getComputedStyle(D).transform, m = { e: 0, f: 0 };
    try { if (tr && tr !== 'none') { var mx = new DOMMatrixReadOnly(tr); m = { e: mx.m41, f: mx.m42 }; } } catch (e) { /* no matrix: lands where it is */ }
    var to = FIELD.getBoundingClientRect();
    var end = { left: to.left - m.e, top: to.top - m.f, width: to.width, height: to.height };
    var g = doc.createElement('div');
    g.className = 'ls-ghost';
    g.innerHTML = icon('search') + '<span class="ls-ghost__t" dir="auto">' + esc(text) + '</span>';
    R.appendChild(g);
    FIELD.classList.add('is-arriving');
    var px = function (r) { return { left: r.left + 'px', top: r.top + 'px', width: r.width + 'px', height: r.height + 'px' }; };
    var a = g.animate([Object.assign(px(from), { opacity: 1, boxShadow: '0 0 0 rgb(0 0 0 / 0)' }), Object.assign(px({ left: (from.left + end.left) / 2, top: Math.min(from.top, end.top) - 24, width: (from.width + end.width) / 2, height: end.height }), { opacity: 1, boxShadow: '0 18px 40px -12px rgb(11 11 12 / .28)', offset: 0.55 }), Object.assign(px(end), { opacity: 1, boxShadow: '0 0 0 rgb(0 0 0 / 0)' })],
      { duration: 620, easing: 'cubic-bezier(.32,.72,0,1)', fill: 'forwards' });
    var done = function () {
      FIELD.classList.remove('is-arriving');
      var out = g.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 160, fill: 'forwards' });
      out.onfinish = function () { g.remove(); };
    };
    a.onfinish = done; a.oncancel = done;
  }

  // ---------------------------------------------------------------- render
  // "about this product" belongs to the page it was opened on: it is never kept, and never shown on another page
  function persist() {
    var keep = S.turns.filter(function (x) { return x.kind !== 'compare' && x.kind !== 'about'; }).slice(-30).map(function (x) { var c = Object.assign({}, x); delete c.photo; return c; });
    store('session', 'ls_turns', keep);
  }
  function chatTurns() {
    // one "about this product", the newest, and only this page's (a new chat and its undo can leave two)
    var about = S.turns.filter(function (x) { return x.kind === 'about' && x.id === S.ctx; }).pop();
    return S.turns.filter(function (x) { return x.kind !== 'compare' && (x.kind !== 'about' || x === about); });
  }
  function render() {
    requestAnimationFrame(function () { sliders(); watchExposure(); });
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
      BODY.scrollTop = newest && first ? Math.max(0, first.offsetTop - 8) : keep;
    } else { BODY.scrollTop = newest ? 0 : keep; }
  }
  function credit() { return '<div class="ls-credit">' + mark() + '<a href="https://lets.ai" target="_blank" rel="noopener">' + esc(t('powered')) + '</a></div>'; }
  // On a product page the questions go with the product, invisibly: the field says so, nothing else does.
  function renderCtx() {
    var ph = S.askMode && S.ctx && rec(S.ctx) ? t('placeholderProduct') : t('placeholder');
    if (INPUT.placeholder !== ph) { INPUT.placeholder = ph; INPUT.setAttribute('aria-label', ph); }
  }

  // ---------------------------------------------------------------- welcome (empty drawer)
  function welcome() {
    // the shop's own most searched words (the index config), never anyone else's: none yet, no section
    var pop = S.config.popular || [];
    var asks = off('ask') ? [] : LANG === 'he' ? ['תוך כמה זמן זה מגיע?', 'אפשר להחזיר או להחליף?'] : ['How long does delivery take?', 'Can I return or exchange it?'];
    return '<div class="ls-welcome"><h2 class="ls-welcome__title">' + esc(t('welcomeTitle')) + '</h2><p class="ls-welcome__sub">' + esc(t('welcomeSub')) + '</p>' +
      // one row that scrolls (a swipe, or a drag with the mouse); the small button opens them all
      (pop.length ? '<div class="ls-sec__title">' + esc(t('popular')) + (pop.length > 3 ? '<button class="ls-sec__more" data-pop-all aria-expanded="' + !!S.popAll + '">' + esc(t(S.popAll ? 'popLess' : 'popAll')) + '</button>' : '') + '</div>' +
        '<div class="ls-chips ls-chips--drag' + (S.popAll ? ' is-all' : '') + '" style="margin-bottom:18px">' + pop.map(function (q) { return '<button class="ls-chip ls-chip--quiet" data-q="' + esc(q) + '">' + icon('trend', 'ls-icon--sm') + esc(q) + '</button>'; }).join('') + '</div>' : '') +
      (asks.length ? '<div class="ls-sec__title">' + esc(t('tryAsk')) + '</div>' : '') + '<div class="ls-stack">' + asks.map(function (q) { return '<button class="ls-ask ls-ask--example" data-ask="' + esc(q) + '"><span class="ls-ask__mark">' + mark() + '</span><span class="ls-ask__text"><span class="ls-ask__q" style="font-weight:400">' + esc(q) + '</span></span></button>'; }).join('') +
      '<button class="ls-row" data-photo>' + icon('camera') + '<span class="ls-row__text">' + esc(t('photo')) + '</span></button></div></div>';
  }

  // ---------------------------------------------------------------- suggestions while typing
  // As the shopper types, the results come up the way Enter shows them (count, sort, filters, cards,
  // guides), at the top of the conversation: from the index at once, then the server's list a beat
  // later. Filters chosen here stay while typing, and go with the search when it's sent.
  var liveTimer = null, pauseTimer = null, meaningTimer = null;
  function onType() {
    var q = INPUT.value.trim();
    FIELD.classList.toggle('has-text', !!q);
    var flying = R.querySelector('.ls-ghost__t');
    if (flying) { flying.textContent = INPUT.value; }
    if (!q) { hideLive(); return; }
    if (!S.open) { return; }
    if (!S.index) { loadIndex().then(onType); return; }
    if (S.tab !== 'chat' || S.wide) { S.tab = 'chat'; S.wide = null; render(); }
    // on a product page the words ask about the product, and the product's information stays as it is;
    // only words that read as a product search switch to the results
    if (aboutProduct(q)) { askOnly(q); return; }
    var was = S.live, isNew = !was || !SUG.classList.contains('is-on');
    // a new search starts clean: what was on screen (results, similar items, answers) waits behind the dashed line
    if (!was && S.turns.some(function (x) { return !x.prev; })) { S.turns.forEach(function (x) { x.prev = true; }); S.showPrev = false; persist(); render(); }
    if (!was || was.q !== q) {
      var local = hits(q).filter(function (r) { return r.t === 'product'; });
      S.live = { id: 'live', kind: 'results', q: q, ids: (was && was.q && q.indexOf(was.q) === 0 && was.server && !local.length ? was.ids : local.map(function (r) { return r.id; })), c: was ? was.c : blankC(), content: [], cats: [], server: false };
      readTags(S.live);
      clearTimeout(liveTimer); clearTimeout(pauseTimer); clearTimeout(meaningTimer);
      liveTimer = setTimeout(function () { liveServer(q); }, 180);
    }
    renderLive(isNew);
    announce(isQuestion(q) ? t('announceQuestion') : t('announceResults', { n: filtered(S.live).length }));
  }
  // typing=1: spelling only and nothing counted on the server; the search is counted once the shopper pauses.
  // Half a second after the last key the words are asked again by meaning too (meaning=1), so "מברגה עם בטריה"
  // finds the store's "סוללות" while still typing: one vector per wording, kept on the server.
  function liveServer(q, byMeaning) {
    json(API + '/search/' + SITE + '?q=' + encodeURIComponent(q) + '&typing=1' + (byMeaning ? '&meaning=1' : '') + visitQuery()).then(function (r) {
      var lv = S.live;
      if (!lv || lv.q !== q) { return; }
      var d = r.body || {};
      var ids = (d.ids || []).filter(function (id) { return /^p:/.test(id); });
      if (!ids.length) { ids = ((d.groups && d.groups.product) || []).map(function (p) { return p.id; }); }
      // Nothing by spelling on the server, while the drawer's own index found some: those stay on screen (the
      // meaning pass may add to them), instead of blinking away
      var spelled = ids.length;
      if (!byMeaning && !spelled && (lv.ids || []).length) { ids = lv.ids; }
      lv.ids = ids; lv.server = true; lv.total = d.total || ids.length; lv.focus = d.focus || null;
      lv.single = d.single && (d.single.external_id || d.single.id) ? pid(d.single.external_id || d.single.id) : null;
      lv.content = ((d.groups && d.groups.content) || []).slice(0, 3); lv.cats = ((d.groups && d.groups.category) || []).slice(0, 4);
      lv.actions = d.actions || [];
      // Nothing by spelling, and the meaning pass still to come: still thinking, not "no results" yet
      // (it often finds what the store calls by another word). "No results" only once both found nothing.
      var willMean = !byMeaning && (wordsOf(q).length >= 2 || spelled < 4);
      lv.pending = willMean && !ids.length;
      readTags(lv);
      renderLive(false);
      if (!byMeaning) {
        clearTimeout(pauseTimer);
        pauseTimer = setTimeout(function () { if (S.live === lv) { countSearch(q, lv.ids.length); } }, PAUSE_MS);
        if (willMean) { clearTimeout(meaningTimer); meaningTimer = setTimeout(function () { if (S.live === lv) { liveServer(q, true); } }, 500); }
      }
      return products(filtered(lv).slice(0, 8).map(function (x) { return x.id; })).then(function () { if (S.live === lv) { renderLive(false); } });
    }).catch(function () {
      // the index results stay; a thinking mark never stays forever
      if (S.live && S.live.q === q && S.live.pending) { S.live.pending = false; renderLive(false); }
    });
  }
  function productSearch(q) { return !isQuestion(q) && q.length >= 3 && hits(q).some(function (r) { return r.t === 'product'; }); }
  // only when opened from the product page's own search row; the header's icon opens the regular search
  function aboutProduct(q) { return !!(S.askMode && S.ctx && rec(S.ctx) && !productSearch(q) && !(LEARN.bank && LEARN.bank.ask === false)); }
  function askOnly(q) {
    clearTimeout(liveTimer);
    S.live = null;
    SUGIN.innerHTML = askRow(q, true);
    SUG.classList.add('is-on');
    INPUT.setAttribute('aria-expanded', 'true');
    var o = SUG.querySelector('[data-opt]');
    if (o) { o.id = 'ls-opt-0'; o.setAttribute('role', 'option'); }
    S.active = 0;
    mark_active();
  }
  function hideLive() {
    clearTimeout(liveTimer); clearTimeout(pauseTimer); clearTimeout(meaningTimer);
    S.live = null; S.active = -1;
    if (SUG.classList.contains('is-on')) { SUG.classList.remove('is-on'); SUGIN.innerHTML = ''; }
    INPUT.setAttribute('aria-expanded', 'false'); INPUT.removeAttribute('aria-activedescendant');
  }
  function renderLive(isNew) {
    var lv = S.live;
    if (!lv) { return; }
    // a new search starts with nothing highlighted (Enter searches); the highlight never jumps down the list
    var keepOpt = isNew ? -1 : S.active, wasOn = SUG.classList.contains('is-on');
    if (isNew) { BODY.scrollTop = 0; }
    var y0 = FLOW.getBoundingClientRect().top;
    SUGIN.innerHTML = liveHtml(lv);
    if (isNew && wasOn && FLOW.animate) {
      // from the question row on a product page: the product's information slides down, making room for the results
      var dy = FLOW.getBoundingClientRect().top - y0;
      FLOW.animate([{ transform: 'translateY(' + (-dy) + 'px)' }, { transform: 'none' }], { duration: 560, easing: 'cubic-bezier(.32,.72,0,1)' });
      SUGIN.animate([{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }], { duration: 380, easing: 'ease-out' });
    } else if (isNew) { SUG.classList.remove('is-on'); void SUG.offsetWidth; }
    SUG.classList.add('is-on');
    INPUT.setAttribute('aria-expanded', 'true');
    var opts = SUG.querySelectorAll('[data-opt]');
    opts.forEach(function (o, i) { o.id = 'ls-opt-' + i; o.setAttribute('role', 'option'); });
    S.active = isQuestion(lv.q) ? (isNew || keepOpt < 0 ? 0 : Math.min(keepOpt, opts.length - 1)) : Math.min(keepOpt, opts.length - 1);
    mark_active();
  }
  // re-draw whichever view holds this turn
  function refresh(turn) { if (turn === S.live) { renderLive(false); } else { persist(); render(); } }
  function mark_active() {
    var opts = SUG.querySelectorAll('[data-opt]');
    opts.forEach(function (o, i) { o.classList.toggle('is-active', i === S.active); o.setAttribute('aria-selected', String(i === S.active)); });
    if (opts[S.active]) { INPUT.setAttribute('aria-activedescendant', opts[S.active].id); opts[S.active].scrollIntoView({ block: 'nearest' }); } else { INPUT.removeAttribute('aria-activedescendant'); }
  }
  function hl(title, q) {
    var i = title.toLowerCase().indexOf(q.toLowerCase());
    return i < 0 ? esc(title) : esc(title.slice(0, i)) + '<b>' + esc(title.slice(i, i + q.length)) + '</b>' + esc(title.slice(i + q.length));
  }
  function askRow(q, active) {
    return '<div style="padding:4px 4px 8px"><button class="ls-ask' + (active ? ' is-active' : '') + '" data-opt data-ask="' + esc(q) + '"><span class="ls-ask__mark">' + mark() + '</span><span class="ls-ask__text"><span class="ls-ask__who">' + esc(t('askWho', { name: CFG.assistant })) + '</span><span class="ls-ask__q">' + esc(q) + '</span></span><span class="ls-kbd">' + icon('enter', 'ls-icon--sm') + '</span></button></div>';
  }
  // the live view is the results turn itself, as Enter will keep it, with the words still being typed
  function liveHtml(lv) {
    var html = isQuestion(lv.q) ? askRow(lv.q, true) : '';
    lv.loading = (!lv.server || !!lv.pending) && !(lv.ids || []).length;
    return html + '<section class="ls-turn ls-turn--live"><div class="ls-you"><div class="ls-you__bubble ls-you__bubble--live" dir="auto">' + esc(lv.q) + '<span class="ls-caret" aria-hidden="true"></span></div></div>' + resultsHtml(lv) + '</section>';
  }
  function turnById(id) { return id === 'live' ? S.live : S.turns.filter(function (x) { return x.id === id; })[0]; }

  // ---------------------------------------------------------------- filters read from the words
  // A value the shopper typed ("אלון", "שחור", "במבצע") is chosen for them and marked ✓. Taken off, it stays off.
  function C(turn) { if (!turn.c || !turn.c.no) { turn.c = Object.assign(blankC(), turn.c || {}); } return turn.c; }
  function stem(w) { return w.replace(/^[והבלמשכ](?=[א-ת]{2})/, ''); }
  function wordsOf(s) { return String(s || '').toLowerCase().replace(/[^\p{L}\p{N}\s]/gu, ' ').split(/\s+/).filter(Boolean); }
  function says(text, label) {
    var tw = wordsOf(text), lw = wordsOf(label);
    if (!lw.length || lw.join('').length < 2) { return false; }
    return lw.every(function (w) { return tw.some(function (x) { return x === w || stem(x) === w || (w.length >= 4 && x.indexOf(w) === 0 && x.length - w.length <= 2) || alike(x, w); }); });
  }
  // The same word as the search engine reads it, through a spelling slip: "לבין" is "לבן", "אדומה" is "אדום"
  // (its stem, or how it sounds once its look-alike letters are one). Short words must be exact.
  function alike(x, w) {
    var E = S.engine;
    if (!E || !E.normalize || !E.stem || x.length < 3 || w.length < 3) { return false; }
    try {
      var a = E.normalize(x), b = E.normalize(w);
      return E.stem(a) === E.stem(b) || (b.length >= 4 && !!E.sound && E.sound(a) === E.sound(b));
    } catch (e) { return false; }
  }
  function readTags(turn) {
    var c = C(turn), q = turn.q || '', all = (turn.ids || []).map(rec).filter(Boolean), counts = {}, best = {};
    all.forEach(function (r) { (r.f || []).forEach(function (code) { counts[code] = (counts[code] || 0) + 1; }); });
    Object.keys(counts).forEach(function (code) {
      var facet = S.facets[Math.floor(code / 1000)], value = facet && facet.v[code % 1000];
      if (!value || c.no[code] || /^(אחר|other)$/i.test(value.l) || !says(q, value.l)) { return; }
      // one value name in several attributes ("שחור" as a colour and as a coating): the one most results carry
      var k = value.l.toLowerCase();
      if (!best[k] || counts[code] > counts[best[k]]) { best[k] = code; }
    });
    c.auto = {};
    Object.keys(best).forEach(function (k) { c.auto[best[k]] = true; });
    var acts = (turn.actions || []).map(function (a) { return a.id || a; });
    // in stock by default in every search (a pill the shopper can take off, and it stays off), unless nothing found is;
    // marked as read from the words only when the words said it
    c.saidStock = /(^|\s)(במלאי|in stock)(\s|$)/i.test(q) || acts.indexOf('in_stock') >= 0;
    c.autoStock = !c.no.stock && (c.saidStock || all.some(function (r) { return r.s === 1; }));
    c.autoSale = !c.no.sale && (/(^|\s)(ב?מבצע|on sale)(\s|$)/i.test(q) || acts.indexOf('on_sale') >= 0);
    c.autoSort = c.no.sort ? '' : acts.indexOf('cheapest') >= 0 ? 'asc' : acts.indexOf('dearest') >= 0 ? 'desc' : acts.indexOf('newest') >= 0 ? 'new' : '';
  }
  function onCode(c, code) { return !!(c.f[code] || (c.auto && c.auto[code])); }
  function onStock(c) { return !!(c.stock || c.autoStock); }
  function onSale(c) { return !!(c.sale || c.autoSale); }
  function sortOf(c) { return c.sort || c.autoSort || ''; }
  function codesOn(c) { var o = {}; Object.keys(c.f).concat(Object.keys(c.auto || {})).forEach(function (k) { o[k] = 1; }); return Object.keys(o).map(Number); }
  function valueOf(code) { var f = S.facets[Math.floor(code / 1000)]; return f ? { facet: f, value: f.v[code % 1000] } : null; }
  // the colour and size filters the index reads from products' variants (BuildSearchIndex::variantFacets)
  function variantFacet(g) { var f = S.facets && S.facets[g]; return !!(f && (f.k === 'variant_color' || f.k === 'variant_size')); }

  // colour values get a circle of their colour
  var SWATCH = {
    'שחור': '#161616', black: '#161616', 'לבן': '#ffffff', white: '#ffffff', 'אפור': '#8e8e94', grey: '#8e8e94', gray: '#8e8e94',
    'חום': '#7a4a28', brown: '#7a4a28', 'צהוב': '#f1c21b', yellow: '#f1c21b', yellow_zinc: 'linear-gradient(135deg,#f6e27a,#c9a227)', 'כחול': '#2563c9', blue: '#2563c9',
    'אדום': '#cf3a2e', red: '#cf3a2e', 'ירוק': '#2f8f4e', green: '#2f8f4e', 'כתום': '#ee7a1f', orange: '#ee7a1f', 'ורוד': '#ec8fb3', pink: '#ec8fb3', 'סגול': '#7b4bb7', purple: '#7b4bb7',
    'בז׳': '#d9c4a0', beige: '#d9c4a0', 'זהב': 'linear-gradient(135deg,#f3d27a,#b4842a)', gold: 'linear-gradient(135deg,#f3d27a,#b4842a)',
    'כסף': 'linear-gradient(135deg,#f4f4f5,#a6a6ac)', silver: 'linear-gradient(135deg,#f4f4f5,#a6a6ac)', 'ניקל': 'linear-gradient(135deg,#ebe8df,#9b978b)', nickel: 'linear-gradient(135deg,#ebe8df,#9b978b)',
    'כרום': 'linear-gradient(135deg,#ffffff,#8c9198)', chrome: 'linear-gradient(135deg,#ffffff,#8c9198)', 'נירוסטה': 'linear-gradient(135deg,#eeeff1,#9ea3aa)', stainless: 'linear-gradient(135deg,#eeeff1,#9ea3aa)',
    galvanized: 'linear-gradient(135deg,#dfe3e6,#9aa3aa)', 'מגולבן': 'linear-gradient(135deg,#dfe3e6,#9aa3aa)',
    'שקוף': 'clear', clear: 'clear', transparent: 'clear', custom_tint: 'conic-gradient(#e5484d,#f5d90a,#30a46c,#0090ff,#8e4ec6,#e5484d)', 'גוון לבחירה': 'conic-gradient(#e5484d,#f5d90a,#30a46c,#0090ff,#8e4ec6,#e5484d)'
  };
  function swatch(facet, value) {
    if (!facet || !value || !(/colou?r|coating/i.test(facet.k) || /צבע|גוון/.test(facet.l))) { return ''; }
    // a colour family read from variants carries its own (h); several colours together, a wheel
    var v = value.h || SWATCH[value.l] || SWATCH[value.k] || (value.k === 'multi' ? SWATCH.custom_tint : null);
    if (!v) { return ''; }
    return '<span class="ls-swatch' + (v === 'clear' ? ' ls-swatch--clear' : '') + '"' + (v === 'clear' ? '' : ' style="--sw:' + esc(v) + '"') + ' aria-hidden="true"></span>';
  }

  // active filters, each a dark pill with its ×: what was chosen, and what was read from the words (✦)
  function activeRow(turn) {
    var c = C(turn), pills = [];
    codesOn(c).forEach(function (code) { var x = valueOf(code); if (x && x.value) { pills.push({ key: 'f:' + code, label: x.value.l, auto: !c.f[code], sw: swatch(x.facet, x.value) }); } });
    if (onStock(c)) { pills.push({ key: 'stock', label: t('inStock'), auto: !c.stock && !!c.saidStock }); }
    if (onSale(c)) { pills.push({ key: 'sale', label: t('onSale'), auto: !c.sale }); }
    if (!pills.length) { return ''; }
    return '<div class="ls-active" role="group" aria-label="' + esc(t('activeFilters')) + '">' + pills.map(function (p) {
      return '<button class="ls-pill' + (p.auto ? ' is-auto' : '') + '" data-chip="' + esc(p.key) + '" data-turn="' + turn.id + '" aria-label="' + esc(t('removeFilter', { t: p.label })) + '"' + (p.auto ? ' title="' + esc(t('detected')) + '"' : '') + '>' +
        (p.auto ? icon('spark', 'ls-icon--sm ls-pill__spark') : '') + (p.sw || '') + '<span>' + esc(p.label) + '</span>' + icon('x', 'ls-icon--sm') + '</button>';
    }).join('') + (pills.length > 1 ? '<button class="ls-btn ls-btn--link ls-active__clear" data-clear-filters="' + turn.id + '">' + esc(t('clearFilters')) + '</button>' : '') + '</div>';
  }

  // guides and pages: a real picture, what it is, and how well it answers the search
  function matchOf(q, title, kw, rank) {
    var qw = wordsOf(q).filter(function (w) { return w.length >= 2 && !STOP.test(w); });
    if (!qw.length) { return 60; }
    var tw = wordsOf(title), kws = wordsOf(kw), hit = 0;
    qw.forEach(function (w) {
      var s = stem(w), near = function (x) { return x === w || stem(x) === s || (s.length >= 3 && stem(x).indexOf(s) === 0); };
      if (tw.some(near)) { hit += 1; } else if (kws.some(near)) { hit += 0.6; }
    });
    return Math.max(41, Math.min(98, 52 + Math.round(44 * hit / qw.length) - rank * 3));
  }
  function guidesHtml(list, q) {
    if (!list || !list.length) { return ''; }
    return '<div class="ls-block"><div class="ls-block__title">' + esc(t('guides')) + '</div><div class="ls-guides">' + list.map(function (c, i) {
      var r = S.records[c.id] || {}, img = c.image || c.img || r.img, kind = (c.kind || r.kind) === 'page' ? t('page') : t('guide'), m = matchOf(q, c.title, r.kw, i);
      return '<a class="ls-guide" data-opt href="' + esc(c.url) + '">' + (img ? '<img class="ls-guide__img" src="' + esc(img) + '" alt="" decoding="async">' : '<span class="ls-guide__img ls-guide__img--none">' + icon('doc') + '</span>') +
        '<span class="ls-guide__body"><span class="ls-guide__kind">' + esc(kind) + '</span><span class="ls-guide__t" dir="auto">' + esc(decode(c.title)) + '</span>' +
        '<span class="ls-match" style="--m:' + (m / 100) + '"><span class="ls-match__bar"><i></i></span><span class="ls-num">' + esc(t('match', { n: m })) + '</span></span></span></a>';
    }).join('') + '</div></div>';
  }
  // how many cards a results turn shows before "show all": 12 on a phone or the large view, 8 in the drawer;
  // after "show all" on a phone, every one, in place
  function gridN(turn) { return turn && turn.more ? turn.more : isPhone() || S.expanded ? 12 : 8; }
  // "show all": on a phone the rest load right there, under the cards already seen; elsewhere the large view
  function allBtn(turn, label, opt) { return '<button class="ls-btn ls-btn--secondary ls-btn--block" style="margin-top:20px"' + (opt ? ' data-opt' : '') + (isPhone() ? ' data-more="' : ' data-wide="') + turn.id + '">' + esc(label) + '</button>'; }
  function prow(r) {
    return '<a class="ls-prow" data-opt href="' + esc(r.url) + '" data-goto="' + esc(pid(r.id)) + '"><img class="ls-prow__img" src="' + esc(r.img || '') + '" alt="" decoding="async">' +
      '<div class="ls-prow__body"><div class="ls-prow__title" dir="auto">' + esc(decode(r.title)) + '</div><div class="ls-prow__sub">' + priceHtml(r) + (r.s === 0 ? '<span class="ls-stock ls-stock--out">' + esc(t('outStock')) + '</span>' : '') + '</div></div></a>';
  }
  function priceHtml(r) {
    if (off('price')) { return ''; }
    var now = livePrice(r.id), shown = now != null ? now : r.pr, reg = regular(r.id);
    return shown == null ? '' : '<span class="ls-price" data-price="' + esc(pid(r.id)) + '">' + money(shown) + (reg ? '<s>' + money(reg) + '</s>' : '') + '</span>';
  }

  // ---------------------------------------------------------------- submitting
  function submit(raw, kind, about) {
    var q = String(raw || '').trim();
    if (!q) { return; }
    // what was being looked at live goes with the search: its filters, and its results when they're the server's
    var lv = S.live && S.live.q === q ? S.live : null;
    var carried = lv ? JSON.parse(JSON.stringify(lv.c)) : (S.pendingC || null);
    S.pendingC = null;
    INPUT.value = ''; FIELD.classList.remove('has-text'); hideLive();
    S.tab = 'chat'; S.wide = null;
    remember(q);
    if (kind === 'ask' && off('ask') && !(about || S.askMode)) { kind = 'search'; }
    if (kind === 'ask' || (kind !== 'search' && (isQuestion(q) || aboutProduct(q)))) { askTurn(q, null, about || S.askMode); }
    else if (lv && lv.server) {
      addTurn({ kind: 'results', q: q, loading: false, ids: lv.ids, total: lv.total, focus: lv.focus, single: lv.single, content: lv.content, cats: lv.cats, actions: lv.actions, c: carried });
      // kept without asking the server again, so it is counted here (once per tab, as a pause would)
      countSearch(q, lv.ids.length);
      announce(t('announceResults', { n: lv.ids.length }));
    } else { searchTurn(q, carried); }
    if (isPhone()) { INPUT.blur(); }
  }
  function remember(q) {
    S.recent = [q].concat(S.recent.filter(function (x) { return x !== q; })).slice(0, 6);
    store('local', 'ls_recent', S.recent);
  }
  function addTurn(turn) {
    turn.id = turn.id || uid();
    S.turns.push(turn);
    fresh[turn.id] = 1;
    persist(); render();
    return turn;
  }
  function update(turn, patch) {
    Object.assign(turn, patch);
    fresh[turn.id] = 1;
    persist(); render();
  }

  // ---------------------------------------------------------------- search turn
  function searchTurn(q, carried) {
    var turn = addTurn({ kind: 'results', q: q, loading: true, c: carried || blankC() });
    announce(t('announceSearching'));
    loadIndex().then(function () {
      // the server counts this search, unless this tab already counted it (counted=1)
      var spelling = S.spellingNext; S.spellingNext = false;
      return json(API + '/search/' + SITE + '?q=' + encodeURIComponent(q) + (isCounted(norm(q)) ? '&counted=1' : '') + (spelling ? '&spelling=1' : '') + visitQuery());
    }).then(function (r) {
      markCounted(norm(q)); rememberSearch(q);
      return r;
    }).then(function (r) {
      var d = r.body || {};
      var ids = (d.ids || []).filter(function (id) { return /^p:/.test(id); });
      if (!ids.length) { ids = ((d.groups && d.groups.product) || []).map(function (p) { return p.id; }); }
      var single = d.single && (d.single.external_id || d.single.id) ? pid(d.single.external_id || d.single.id) : null;
      // the words' own asks ("הכי זול", "במבצע") are read with the tags: chosen, marked, and can be taken off
      Object.assign(turn, { ids: ids, actions: d.actions || [] });
      readTags(turn);
      return products(ids.slice(0, 8)).then(function () {
        update(turn, { loading: false, ids: ids, total: d.total || ids.length, focus: d.focus || null, single: single, content: ((d.groups && d.groups.content) || []).slice(0, 3), cats: ((d.groups && d.groups.category) || []).slice(0, 3) });
        announce(t('announceResults', { n: ids.length }));
      });
    }).catch(function () { update(turn, { loading: false, error: true }); });
  }
  function filtered(turn) {
    var c = C(turn), out = (turn.ids || []).map(rec).filter(Boolean);
    var fcodes = codesOn(c);
    if (fcodes.length) {
      // within one attribute any value matches; across attributes all must. A value read from the words
      // also matches a product that says it in its name, so reading it never hides what was found.
      var groups = {};
      fcodes.forEach(function (code) { var g = Math.floor(code / 1000); (groups[g] = groups[g] || []).push(code); });
      // colour and size, read from the variants, are asked of one variant together (and of one in stock when
      // stock is asked): black in M sold out, but black in L and navy in M there, is not "black, M, in stock"
      var vgs = Object.keys(groups).filter(variantFacet), stock = onStock(c);
      out = out.filter(function (r) {
        if (vgs.length && r.v && !r.v.some(function (x) { return (!stock || x[1] === 1) && vgs.every(function (g) { return groups[g].some(function (code) { return x[0].indexOf(code) >= 0; }); }); })) { return false; }
        return Object.keys(groups).every(function (g) {
          if (r.v && vgs.indexOf(g) >= 0) { return true; }
          return groups[g].some(function (code) { if ((r.f || []).indexOf(code) >= 0) { return true; } var x = c.auto[code] && !c.f[code] && valueOf(code); return !!(x && x.value && says(r.title + ' ' + (r.kw || ''), x.value.l)); });
        });
      });
    }
    if (onStock(c)) { out = out.filter(function (r) { return r.s === 1; }); }
    if (onSale(c)) { out = out.filter(function (r) { return r.sl === 1; }); }
    var so = sortOf(c);
    if (so === 'asc' || so === 'desc') { out = out.slice().sort(function (a, b) { return ((a.pr || 0) - (b.pr || 0)) * (so === 'asc' ? 1 : -1); }); }
    else if (so === 'new') { out = out.slice().sort(function (a, b) { return (b.d || 0) - (a.d || 0); }); }
    else { out = out.slice().sort(function (a, b) { return (b.s === 0 ? 0 : 1) - (a.s === 0 ? 0 : 1); }); }
    return out;
  }
  function facetChips(turn, max) {
    var c = C(turn), all = (turn.ids || []).map(rec).filter(Boolean), n = all.length, counts = {};
    all.forEach(function (r) { (r.f || []).forEach(function (code) { counts[code] = (counts[code] || 0) + 1; }); });
    codesOn(c).forEach(function (code) { if (!counts[code]) { counts[code] = 0; } });
    var typeAt = S.facets.map(function (f) { return f.k; }).indexOf('type');
    var codes = Object.keys(counts).map(Number).filter(function (code) {
      var facet = S.facets[Math.floor(code / 1000)], value = facet && facet.v[code % 1000];
      return value && (onCode(c, code) || (counts[code] < n && counts[code] >= (max ? 2 : 1))) && !/^(אחר|other)$/i.test(value.l);
    });
    codes.sort(function (a, b) { return (onCode(c, b) ? 1 : 0) - (onCode(c, a) ? 1 : 0) || (Math.floor(a / 1000) === typeAt) - (Math.floor(b / 1000) === typeAt) || counts[b] - counts[a]; });
    return { codes: max ? codes.slice(0, max) : codes, counts: counts, n: n,
      stock: all.some(function (r) { return r.s === 0; }) && all.some(function (r) { return r.s === 1; }),
      sale: all.some(function (r) { return r.sl === 1; }) && all.some(function (r) { return r.sl !== 1; }) };
  }
  function activeFilters(c) { return codesOn(c).length + (onStock(c) ? 1 : 0) + (onSale(c) ? 1 : 0); }
  function chipRow(turn) {
    var fc = facetChips(turn, 4), c = C(turn), act = activeFilters(c);
    var chips = '<button class="ls-chip ls-chip--quiet' + (act ? ' is-on' : '') + '" data-filters="' + turn.id + '">' + icon('sliders') + esc(t('filter')) + (act ? '<span class="ls-filter-count">' + act + '</span>' : '') + '</button>';
    if (fc.stock || onStock(c)) { chips += chip(t('inStock'), onStock(c), 'stock', turn.id, c.autoStock && !c.stock && !!c.saidStock); }
    if (fc.sale || onSale(c)) { chips += chip(t('onSale'), onSale(c), 'sale', turn.id, c.autoSale && !c.sale); }
    fc.codes.forEach(function (code) { var f = S.facets[Math.floor(code / 1000)], v = f.v[code % 1000]; chips += chip(v.l, onCode(c, code), 'f:' + code, turn.id, c.auto[code] && !c.f[code], swatch(f, v)); });
    return '<div class="ls-chips ls-chips--bar">' + chips + '</div>' + activeRow(turn);
  }
  function chip(label, on, key, tid, auto, sw, count) { return '<button class="ls-chip' + (auto ? ' is-auto' : '') + '" aria-pressed="' + !!on + '" data-chip="' + esc(key) + '" data-turn="' + tid + '"' + (auto ? ' title="' + esc(t('detected')) + '"' : '') + '><span class="ls-chip__check">' + icon('check', 'ls-icon--sm') + '</span>' + (sw || '') + esc(label) + (count != null ? ' <span class="ls-chip__count ls-num">' + count + '</span>' : '') + '</button>'; }
  function sortLabel(s) { return s === 'asc' ? t('sortAsc') : s === 'desc' ? t('sortDesc') : s === 'new' ? t('sortNew') : t('sortRel'); }

  function card(r) {
    var id = pid(r.id), saved = S.saved.indexOf(id) >= 0;
    var badge = r.s === 0 ? t('outBadge') : r.sl === 1 ? t('onSaleBadge') : '';
    return '<article class="ls-card' + (r.s === 0 ? ' is-out' : '') + '" data-card="' + esc(id) + '">' +
      // the picture opens the product, as its name does
      '<div class="ls-card__media"><a class="ls-card__link" href="' + esc(r.url) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(r.img || '') + '" alt="">' + pic2(r) + '</a>' + (badge ? '<span class="ls-card__badge">' + esc(badge) + '</span>' : '') +
      '<span class="ls-card__heart">' + heartBtn(id, saved, true) + '</span></div>' +
      '<a class="ls-card__title" dir="auto" href="' + esc(r.url) + '">' + esc(decode(r.title)) + '</a>' +
      '<div class="ls-card__row">' + priceHtml(r) + '<button class="ls-card__similar" data-similar="' + esc(id) + '">' + esc(t('similar')) + '</button></div>' +
      buyBtn(r, 'ls-btn--sm ls-btn--block') + '<div data-after="' + esc(id) + '"></div></article>';
  }
  // the product's second picture (the index's i2), shown over the first on hover; nothing on touch
  function pic2(r) { return r && r.i2 ? '<img class="ls-pic2" src="' + esc(r.i2) + '" alt="" decoding="async" draggable="false">' : ''; }
  function heartBtn(id, on, float) { return '<button class="ls-ibtn ls-ibtn--sm ls-heart' + (float ? ' ls-ibtn--float' : '') + '" aria-pressed="' + on + '" aria-label="' + esc(t('saved')) + '" data-heart="' + esc(id) + '">' + icon('heart', 'ls-icon--sm') + '</button>'; }
  function buyBtn(r, cls) {
    var id = pid(r.id);
    if (off('cart')) { return r.url ? '<a class="ls-btn ls-btn--secondary ' + cls + '" href="' + esc(r.url) + '">' + esc(t('orderPage')) + '</a>' : ''; }
    if (r.s === 0) { return '<button class="ls-btn ls-btn--secondary ' + cls + '" data-similar="' + esc(id) + '">' + esc(t('similarInStock')) + '</button>'; }
    if (r.buy !== 1) { return '<button class="ls-btn ls-btn--secondary ' + cls + '" data-options="' + esc(id) + '">' + esc(t('options')) + '</button>'; }
    return '<button class="ls-btn ls-btn--primary ' + cls + ' ls-add" data-add="' + esc(id) + '"><span class="ls-add__label">' + esc(t('add')) + '</span><span class="ls-add__busy">' + mark(true) + '</span><span class="ls-add__done"><svg class="ls-icon ls-icon--sm ls-check" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg>' + esc(t('added')) + '</span></button>';
  }
  function you(turn) {
    if (turn.kind === 'photo') { return '<div class="ls-you"><img class="ls-you__photo" src="' + esc(turn.src || '') + '" alt="' + esc(t('yourPhoto')) + '"></div>'; }
    if (turn.kind === 'similar' && turn.of) { return rec(turn.of) ? '<div class="ls-you ls-you--of">' + ofHero(turn.of, turn.size) + '</div>' : ''; }
    return turn.q ? '<div class="ls-you"><div class="ls-you__bubble" dir="auto">' + esc(turn.q) + '</div></div>' : '';
  }
  // the product the similar items are for: big enough to keep, compare against, or buy
  function ofHero(id, size) {
    var r = rec(id);
    if (!r) { return ''; }
    var k = pid(r.id);
    return '<div class="ls-of"><a class="ls-of__media" href="' + esc(r.url) + '" tabindex="-1"><img src="' + esc(r.img || '') + '" alt=""></a>' +
      '<div class="ls-of__body"><div class="ls-of__label">' + esc(size ? sizeWord('size_similar_title', { size: size.shown }) : t('similarHead')) + '</div><a class="ls-of__title" dir="auto" href="' + esc(r.url) + '">' + esc(decode(r.title)) + '</a>' +
      '<div class="ls-of__price">' + priceHtml(r) + (r.s === 0 ? '<span class="ls-stock">' + esc(t('outStock')) + '</span>' : '') + '</div>' +
      '<div class="ls-of__actions">' + (r.s === 0 ? '' : buyBtn(r, 'ls-btn--sm')) + heartBtn(k, S.saved.indexOf(k) >= 0) + '</div><div data-after="' + esc(k) + '"></div></div></div>';
  }
  function thinking(text) { return '<div class="ls-think">' + mark(true) + '<span class="ls-think__text">' + esc(text || t('thinking')) + '</span></div>'; }
  function errorHtml(turn) {
    return '<div class="ls-error" role="alert"><span class="ls-error__icon">' + icon('x', 'ls-icon--sm') + '</span><div><div style="font-weight:500">' + esc(t('error')) + '</div><div style="font-size:14px;color:var(--ls-fg-muted);margin:2px 0 12px">' + esc(t('errorSub')) + '</div><button class="ls-btn ls-btn--secondary ls-btn--sm" data-retry="' + turn.id + '">' + icon('history', 'ls-icon--sm') + esc(t('retry')) + '</button></div></div>';
  }

  function turnHtml(turn) {
    var cls = 'ls-turn' + (fresh[turn.id] ? ' is-new' : '') + (turn.kind === 'about' ? ' ls-about-turn' : '');
    var inner = turn.kind === 'results' ? resultsHtml(turn) : turn.kind === 'answer' ? answerHtml(turn) : turn.kind === 'photo' ? photoHtml(turn)
      : turn.kind === 'similar' ? similarHtml(turn) : turn.kind === 'order' ? orderHtml(turn) : turn.kind === 'shared' ? '<div class="ls-order__title">' + esc(t('sharedList')) + '</div>' + (turn.loading ? thinking() : (turn.ids || []).length ? '<div class="ls-grid">' + turn.ids.map(rec).filter(Boolean).map(card).join('') + '</div>' : '<p class="ls-noanswer__text">' + esc(t('savedEmptyT')) + '</p>') : turn.kind === 'about' ? aboutHtml(turn) : turn.kind === 'note' ? '<p class="ls-noanswer__text">' + esc(turn.text) + '</p>' : '';
    return '<section class="' + cls + '" data-tid="' + turn.id + '">' + (turn.kind === 'about' || turn.kind === 'note' || turn.kind === 'order' || turn.kind === 'shared' ? '' : you(turn)) + inner + '</section>';
  }

  function resultsHtml(turn) {
    if (turn.loading) { return thinking(); }
    if (turn.error) { return errorHtml(turn); }
    var list = filtered(turn), total = (turn.ids || []).length, html = '';
    if (turn.single && rec(turn.single)) {
      var s = rec(turn.single);
      html += '<div class="ls-exact"><a class="ls-pick__media" href="' + esc(s.url) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(s.img) + '" alt=""></a><div><div class="ls-exact__tag">' + icon('check', 'ls-icon--sm') + esc(s.sku ? t('exactSku', { sku: s.sku }) : t('exact')) + '</div><a class="ls-pick__title" dir="auto" href="' + esc(s.url) + '" style="color:inherit;text-decoration:none">' + esc(decode(s.title)) + '</a><div class="ls-pick__row">' + priceHtml(s) + buyBtn(s, 'ls-btn--sm') + '</div><div data-after="' + esc(pid(s.id)) + '"></div></div></div>';
    }
    if (!total) {
      if (turn.focus && turn.focus.options && turn.focus.options.length) {
        html += '<p class="ls-noanswer__text">' + esc(t('didYouMean', { q: turn.q })) + '</p><div class="ls-chips" style="margin-bottom:14px">' +
          turn.focus.options.map(function (o) { return '<button class="ls-chip ls-chip--quiet" data-q="' + esc(o.query) + '">' + esc(o.query) + ' <span class="ls-chip__count ls-num">(' + o.count + ')</span></button>'; }).join('') +
          (turn.focus.category ? '<button class="ls-chip ls-chip--quiet" data-q="' + esc(turn.focus.category.title) + '">' + esc(t('category', { t: turn.focus.category.title })) + '</button>' : '') + '</div>';
      } else {
        html += '<p class="ls-noanswer__text"><b>' + esc(t('noResults', { q: turn.q })) + '</b><br><span style="color:var(--ls-fg-muted)">' + esc(t('noResultsSub', { name: CFG.assistant })) + '</span></p>';
      }
      html += '<button class="ls-row" data-ask="' + esc(turn.q) + '"><span class="ls-mark" style="--s:14px"><i></i><i></i></span><span class="ls-row__text">' + esc(t('askWho', { name: CFG.assistant })) + '</span><span class="ls-row__end" style="opacity:1">' + icon('fwd', 'ls-icon--sm') + '</span></button>';
      return html;
    }
    html += '<div class="ls-summary"><div class="ls-summary__count ls-num">' + esc(list.length === 1 ? t('oneProduct') : t('productsCount', { n: list.length })) + '</div>' +
      '<button class="ls-btn ls-btn--ghost ls-btn--sm" data-sort="' + turn.id + '">' + esc(sortLabel(sortOf(C(turn)))) + icon('down', 'ls-icon--sm') + '</button></div>';
    html += chipRow(turn);
    if (!list.length) { return html + '<div class="ls-empty-filter">' + esc(t('noFilterMatch')) + '<div style="margin-top:10px"><button class="ls-btn ls-btn--secondary ls-btn--sm" data-clear-filters="' + turn.id + '">' + esc(t('clearAll')) + '</button></div></div>'; }
    var n = gridN(turn);
    html += '<div class="ls-grid">' + list.slice(0, n).map(card).join('') + '</div>';
    if (list.length > n) { html += allBtn(turn, t('showAll', { n: list.length }), true); }
    html += guidesHtml(turn.content, turn.q);
    if (!off('ask')) html += '<button class="ls-row" data-opt style="margin-top:8px;color:var(--ls-fg-muted)" data-ask="' + esc(turn.q) + '"><span class="ls-mark" style="--s:14px;color:var(--ls-fg)"><i></i><i></i></span><span class="ls-row__text">' + esc(t('notSure', { name: CFG.assistant })) + '</span></button>';
    return html;
  }

  function wideView() {
    var turn = turnById(S.wide);
    if (!turn) { S.wide = null; return ''; }
    if (turn.kind === 'compare') { return compareHtml(turn); }
    // no "back to chat" here: the tab above and the search field lead back
    var back = '';
    var similar = turn.kind === 'similar';
    if (turn.loading) { return '<div class="ls-wide-head">' + back + (similar ? '<div style="margin-top:8px">' + ofHero(turn.of, turn.size) + '</div>' : '') + '</div><div style="padding:12px 0">' + thinking() + '</div>'; }
    if (turn.error) { return '<div class="ls-wide-head">' + back + '</div>' + errorHtml(turn); }
    var list = filtered(turn), shown = list.slice(0, turn.limit || 24);
    var title = similar ? esc(t('productsCount', { n: list.length })) + ' <span>' + esc(t('similar')) + '</span>' : esc(t('productsCount', { n: list.length })) + ' <span>"' + esc(turn.q) + '"</span>';
    return '<div class="ls-wide-head">' + back + (similar ? '<div style="margin-top:8px">' + ofHero(turn.of, turn.size) + '</div>' : '') +
      '<div class="ls-summary" style="margin-top:' + (similar ? 18 : 8) + 'px"><div class="ls-summary__count ls-num" style="font-size:20px;font-weight:500">' + title + '</div>' +
      '<button class="ls-btn ls-btn--ghost ls-btn--sm" data-sort="' + turn.id + '">' + esc(sortLabel(sortOf(C(turn)))) + icon('down', 'ls-icon--sm') + '</button></div>' + chipRow(turn) + '</div>' +
      (similar && !(turn.ids || []).length ? '<p class="ls-noanswer__text">' + esc(t('noSimilar')) + '</p>' : list.length ? '<div class="ls-grid">' + shown.map(card).join('') + '</div>' : '<div class="ls-empty-filter">' + esc(t('noFilterMatch')) + '</div>') +
      (list.length > shown.length ? '<div class="ls-more"><button class="ls-btn ls-btn--secondary" data-loadmore="' + turn.id + '">' + esc(t('loadMore')) + '</button></div>' : '') +
      (!similar && CFG.searchUrl ? '<div class="ls-more"><a class="ls-btn ls-btn--link" href="' + esc(CFG.searchUrl + '?s=' + encodeURIComponent(turn.q)) + '">' + esc(t('onSite')) + '</a></div>' : '');
  }

  // ---------------------------------------------------------------- ask turn
  // about: a question on this product (asked from its page's row, or from its questions): page=1
  function askTurn(q, extra, about) {
    var prev = S.turns.slice().reverse().filter(function (x) { return x.kind === 'results' && x.ids; })[0];
    var turn = addTurn({ kind: 'answer', q: q, loading: true, ctx: about && S.ctx ? S.ctx : null, anim: 1 });
    announce(t('announceSearching'));
    var slow = setTimeout(function () { var el = BODY.querySelector('[data-tid="' + turn.id + '"] .ls-think__text'); if (el) { el.textContent = t('slow'); } }, 8000);
    // about this product: the product page's own ask, so the answer is kept with the product (its questions,
    // "questions shoppers asked", the suggestions, and the gaps the daily review learns from)
    if (turn.ctx) {
      json(API + '/widget/' + SITE + '/ask', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ id: String(pid(turn.ctx)), type: 'product', question: q, vid: visitor(), locale: LANG }) }).then(function (r) {
        clearTimeout(slow);
        if (!r.ok) { throw new Error('ask'); }
        var d = (r.body && r.body.data) || {};
        LEARN.answered++;
        learn('chat_question', null, null, { length: Math.min(2000, Math.max(1, q.length)), answered_from: d.from === 'bank' ? 'bank' : d.outcome === 'answered' ? 'rag' : 'none' });
        if (d.outcome === 'answered') {
          // a shopper who got an answer is worth asking, once a page, and only where the shop set it up
          var lead = LEARN.bank && LEARN.bank.lead && !LEARN.offered ? { given: {}, step: 0, state: null, busy: true } : null;
          if (lead) { LEARN.offered = true; }
          update(turn, { loading: false, answer: d.answer, picks: [], sources: [], general: d.source === 'general', follow: [], leadFlow: lead });
          announce(t('announceReady') + ': ' + d.answer);
          if (lead) { learn('lead_start', null, null, {}); leadStep(turn, {}); }
        } else {
          update(turn, { loading: false, noInfo: true, rel: related(q), h: hours() });
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
    if (S.ctx && turn.ctx && shown.length) { body.previous = shown; }
    json(API + '/search/' + SITE + '/ask', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(Object.assign(body, extra || {})) }).then(function (r) {
      clearTimeout(slow);
      if (!r.ok) { throw new Error('ask'); }
      var d = (r.body && r.body.data) || r.body || {};
      var picks = (d.picks || []).map(function (p) { return { id: pid(p.external_id || p.id), why: p.why, title: p.title, url: p.url, image: p.image }; });
      turn.askId = d.ask_id ? String(d.ask_id) : null;
      return products(picks.map(function (p) { return p.id; })).then(function () {
        if (d.outcome === 'answered') {
          update(turn, { loading: false, answer: d.answer, picks: picks, sources: d.sources || [], general: d.from === 'general', follow: d.follow || [] });
          announce(t('announceReady') + ': ' + d.answer);
        } else {
          var rel = related(q);
          update(turn, { loading: false, noInfo: true, rel: rel, h: hours() });
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
    json(API + '/widget/' + SITE + '/lead', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ id: String(PAGE.id), type: PAGE.type, vid: visitor(), asked: LEARN.answered, given: lf.given, consent: extra.consent === true, declined: extra.declined === true }) }).then(function (r) {
      var d = r.ok && r.body && r.body.data;
      lf.busy = false;
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
    if (lf.state === 'done') { return '<div class="ls-done ls-focus-in" style="margin-top:14px"><span class="ls-done__icon"><svg class="ls-icon ls-check ls-check--draw" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg></span><div style="font-weight:500">' + esc(lf.promise || bankLabel('lead_thanks', t('leadDone', { name: '', when: '' }))) + '</div></div>'; }
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
    if (turn.noInfo) { return kitsHtml(turn) + noInfoHtml(turn) + myAi(turn); }
    var anim = fresh[turn.id] && turn.anim;
    var n = String(turn.answer || '').split(' ').length;
    var html = kitsHtml(turn) + '<div class="ls-answer' + (anim ? ' ls-words' : '') + '"><p>' + (anim ? words(turn.answer) : esc(turn.answer)) + '</p></div>';
    if (turn.picks && turn.picks.length) {
      html += '<ol class="ls-picks"' + (anim ? ' style="--lsp-stagger:110ms"' : '') + '>' + turn.picks.map(function (p, i) {
        var r = rec(p.id) || { id: p.id, title: p.title, url: p.url, img: p.image, buy: 0, s: 1 };
        return '<li class="ls-pick' + (anim ? ' ls-focus-in' : '') + '" style="--i:' + (anim ? Math.round(n * 28 / 110) + i : 0) + '"><a class="ls-pick__media" href="' + esc(r.url || p.url) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(r.img || p.image || '') + '" alt=""></a><div>' +
          '<a class="ls-pick__title" dir="auto" href="' + esc(r.url || p.url) + '" style="color:inherit;text-decoration:none">' + esc(decode(r.title || p.title)) + '</a><div class="ls-pick__why">' + esc(p.why || '') + '</div>' +
          '<div class="ls-pick__row">' + priceHtml(r) + '<span style="display:flex;gap:4px;align-items:center">' + heartBtn(pid(r.id), S.saved.indexOf(pid(r.id)) >= 0) + buyBtn(r, 'ls-btn--sm') + '</span></div><div data-after="' + esc(pid(r.id)) + '"></div></div></li>';
      }).join('') + '</ol>';
    }
    var src = (turn.sources || []).filter(function (s) { return s && (s.title || s.product); });
    if (src.length) {
      html += '<div class="ls-source">' + icon('doc', 'ls-icon--sm') + esc(t('from')) + ' ' + src.slice(0, 2).map(function (s) { var r = s.product && rec(s.product); return '<a href="' + esc(s.url || (r && r.url) || '#') + '">' + esc(s.title || (r && decode(r.title).split('|')[0]) || '') + '</a>'; }).join(' · ') + '</div>';
    }
    if (turn.general) { html += '<div class="ls-note">' + icon('info') + '<span>' + esc(t('general')) + '</span></div>'; }
    if (turn.follow && turn.follow.length) { html += '<div class="ls-follow">' + turn.follow.map(function (f) { return '<button class="ls-chip ls-chip--quiet" data-ask="' + esc(f) + '">' + esc(f) + '</button>'; }).join('') + '</div>'; }
    html += leadFlowHtml(turn);
    var h = hours(), link = S.config.askWhatsapp && h.open && wa(t('verify') + ' ' + turn.q);
    if (link) { html += '<div class="ls-verify">' + esc(t('verify')) + ' <a href="' + esc(link) + '" target="_blank" rel="noopener">' + icon('whatsapp', 'ls-icon--sm') + esc(t('verifyLink')) + '</a></div>'; }
    return html + myAi(turn);
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
  function noInfoHtml(turn) {
    var h = hours(), link = wa((S.config.whatsappMessage || ':question').replace(':question', turn.q)), html = '';
    if (turn.lead === 'done') {
      html = '<div class="ls-done ls-focus-in"><span class="ls-done__icon"><svg class="ls-icon ls-check ls-check--draw" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg></span><div><div style="font-weight:500">' + esc(t('leadDone', { name: turn.leadName || '', when: h.when || '' })) + '</div><div style="font-size:13px;color:var(--ls-fg-muted)">' + esc(t('leadDoneSub', { shop: S.config.shopName || '' })) + '</div></div></div>';
    } else if (turn.lead === 'no') {
      html = '<p class="ls-noanswer__text">' + esc(t('leadDeclined')) + '</p>';
    } else if (h.open && link) {
      html = '<div class="ls-hours"><span class="ls-dot"></span>' + esc(t('teamNow', { t: h.until || '' })) + '</div><p class="ls-noanswer__text">' + esc(t('waText')) + '</p>' +
        '<a class="ls-btn ls-btn--primary" href="' + esc(link) + '" target="_blank" rel="noopener">' + icon('whatsapp') + esc(t('teamWa')) + '</a>';
    } else if (S.config.callbacks !== false) {
      html = '<div class="ls-hours"><span class="ls-dot ls-dot--off"></span>' + esc(t('teamBack', { when: h.when || '' })) + '</div><p class="ls-noanswer__text">' + esc(t('leadText')) + '</p>' +
        leadForm(turn, turn.q);
    } else {
      html = '<p class="ls-noanswer__text">' + esc(t('noInfo')) + '</p>';
    }
    if (turn.rel && turn.rel.length) {
      html += '<div class="ls-block"><div class="ls-block__title" style="color:var(--ls-fg-muted);font-weight:500">' + esc(t('meanwhile')) + '</div><div class="ls-panel" style="box-shadow:0 0 0 1px var(--ls-line);padding:4px">' + turn.rel.map(function (id) { return prow(rec(id)); }).join('') + '</div></div>';
    }
    return html;
  }

  // ---------------------------------------------------------------- similar turn
  // size: { value, shown } from size help: only the similar items that have it in stock now
  function similarTurn(id, size) {
    // on a desktop the similar items open large, like "show all": the product on top, filters, the full grid
    S.tab = 'chat'; S.wide = null;
    // the similar items stand alone at the top; the search they came from waits behind the dashed line
    S.turns.forEach(function (x) { x.prev = true; });
    S.showPrev = false; hideLive();
    var turn = addTurn({ kind: 'similar', of: String(id), loading: true, c: blankC(), size: size || null });
    if (!isPhone()) { S.wide = turn.id; turn.limit = 24; render(); }
    BODY.scrollTop = 0;
    json(API + '/search/' + SITE + '/similar?id=' + encodeURIComponent(id) + (size ? '&limit=24' : '')).then(function (r) {
      var ids = ((r.body && r.body.products) || []).map(function (p) { return 'p:' + pid(p.external_id || p.id); }).filter(function (x) { return rec(x) && pid(x) !== String(id); });
      return products(ids.slice(0, 24).map(pid)).then(function () {
        var inSize = size ? ids.filter(function (x) { return hasSize(x, size); }) : ids;
        update(turn, { loading: false, ids: size && !inSize.length ? ids.slice(0, 8) : inSize, sizeNone: !!(size && !inSize.length) });
        BODY.scrollTop = 0;
      });
    }).catch(function () { update(turn, { loading: false, error: true }); });
  }
  function similarHtml(turn) {
    if (turn.loading) { return thinking(); }
    if (turn.error) { return errorHtml(turn); }
    if (!(turn.ids || []).length) { return '<p class="ls-noanswer__text">' + esc(t('noSimilar')) + '</p>'; }
    var list = filtered(turn), n = gridN(turn);
    // like the results: how many, the order to show them in, and the filters (the large view has them in its head)
    var head = '<div class="ls-summary"><div class="ls-summary__count ls-num">' + esc(list.length === 1 ? t('oneProduct') : t('productsCount', { n: list.length })) + '</div>' +
      '<button class="ls-btn ls-btn--ghost ls-btn--sm" data-sort="' + turn.id + '">' + esc(sortLabel(sortOf(C(turn)))) + icon('down', 'ls-icon--sm') + '</button></div>' + chipRow(turn);
    if (!list.length) { return head + '<div class="ls-empty-filter">' + esc(t('noFilterMatch')) + '<div style="margin-top:10px"><button class="ls-btn ls-btn--secondary ls-btn--sm" data-clear-filters="' + turn.id + '">' + esc(t('clearAll')) + '</button></div></div>'; }
    return (turn.sizeNone ? '<p class="ls-noanswer__text">' + esc(sizeWord('size_similar_none', { size: turn.size.shown })) + '</p><div class="ls-block__title">' + esc(sizeWord('size_similar_more')) + '</div>' : '') + head + '<div class="ls-grid">' + list.slice(0, n).map(card).join('') + '</div>' +
      (list.length > n ? allBtn(turn, t('allSimilar', { n: list.length })) : '');
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
    // as SearchPhotoController reads it: the photo, and the visit beside it for the reports
    var form = new FormData(), seen = visit();
    form.append('photo', blob, 'photo.jpg');
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
        update(turn, { loading: false, ids: ps.map(function (p) { return p.id; }), tags: d.tags || [], seen: d.seen || '', unclear: !!d.unclear, nothing: !!d.nothing, items: d.items || null, off: {} });
      });
    }).catch(function () { update(turn, { loading: false, failed: true }); });
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
    var html = (turn.seen ? '<div class="ls-seen"><span class="ls-seen__label">' + esc(t('photoSeen')) + ' <b>' + esc(turn.seen) + '</b></span></div>' : '') + tagRow(false);
    if (off.length) { html += '<div class="ls-note">' + icon('info') + '<span>' + esc(t('photoTagSite', { t: (turn.tags || []).filter(function (x, i) { return off.indexOf(i) < 0; }).map(function (x) { return x.title; }).join(' ') })) + ' · <button class="ls-btn ls-btn--link" data-q="' + esc((turn.tags || []).filter(function (x, i) { return off.indexOf(i) < 0; }).map(function (x) { return x.title; }).join(' ')) + '">' + esc(LANG === 'he' ? 'חיפוש' : 'Search') + '</button></span></div>'; }
    html += '<div class="ls-grid" style="margin-top:12px">' + list.slice(0, 6).map(function (r, i) { var c = card(r); return i === 0 ? c.replace('<span class="ls-card__heart">', '<span class="ls-best">' + esc(t('photoBest')) + '</span><span class="ls-card__heart">') : c; }).join('') + '</div>';
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
    // suggested next steps first, as the shop's block offers them; the product's sections follow
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
    if (b.teaser && b.teaser.text) { html += '<p class="ls-teaser">' + esc(b.teaser.text) + '</p>'; }
    // the sections in the order the learning chose (a held-out visitor: the order it never touched)
    html += (b.sections || []).map(function (s) { return sectionHtml(s, turn); }).filter(Boolean).join('');
    // what this visitor looked at, and more from the search that brought them here
    var seen = LEARN.recent && LEARN.recent.products;
    if (seen && seen.length) { html += '<div class="ls-block"><div class="ls-block__title"><span>' + esc(bankLabel('recent_title', t('recentTitle'))) + '</span></div>' + miniRow(seen) + '</div>'; }
    var found = LEARN.searched && LEARN.searched.products;
    if (found && found.length) { html += '<div class="ls-block"><div class="ls-block__title"><span>' + esc(bankLabel('searched_title', t('searchedTitle'), { query: LEARN.searched.query || '' })) + '</span></div>' + miniRow(found) + '</div>'; }
    if (b.answers && b.answers.length) {
      html += '<div class="ls-block"><div class="ls-block__title">' + esc(t('faq')) + '</div>' + b.answers.map(function (a) { return '<details class="ls-faq"><summary>' + esc(a.q) + icon('down', 'ls-icon--sm') + '</summary><p>' + esc(a.a) + '</p></details>'; }).join('') + '</div>';
    }
    if (b.assurances && b.assurances.length) {
      html += '<div class="ls-block"><div class="ls-block__title">' + esc(t('promises')) + '</div>' + b.assurances.map(function (a) { return '<div class="ls-promise">' + icon(a.scope === 'shop' ? 'truck' : 'check', 'ls-icon--sm') + '<div>' + esc(a.text) + (a.note ? '<small>' + esc(a.note) + '</small>' : '') + '</div></div>'; }).join('') + '</div>';
    }
    // someone who knows: WhatsApp only while the store answers it; otherwise (or with no WhatsApp) the details form,
    // with the last question asked about this product already in it
    var c = b.contact, h = hours();
    if (c && c.number && h.open) {
      html += '<div class="ls-block" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap"><div><div style="font-weight:500;font-size:14px">' + esc(b.cta && b.cta.headline || t('talkTitle')) + '</div><div class="ls-hours" style="margin:4px 0 0"><span class="ls-dot"></span>' + esc(c.online_label || '') + '</div></div>' +
        '<a class="ls-btn ls-btn--secondary ls-btn--sm" target="_blank" rel="noopener" href="https://wa.me/' + esc(c.number) + '?text=' + encodeURIComponent(String(c.message || '').replace(':product', decode(r.title)).replace(':url', win.location.href)) + '">' + icon('whatsapp', 'ls-icon--sm') + esc(c.button || t('talkBtn')) + '</a></div>';
    }
    else if (S.config.callbacks !== false) {
      var asked = S.turns.filter(function (x) { return x.kind === 'answer' && String(x.ctx) === String(turn.id) && x.q; }).pop();
      html += '<div class="ls-block"><div style="font-weight:500;font-size:14px">' + esc(b.cta && b.cta.headline || t('talkTitle')) + '</div>' +
        (turn.lead === 'done' ? '<div class="ls-done ls-focus-in" style="margin-top:10px"><span class="ls-done__icon"><svg class="ls-icon ls-check ls-check--draw" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg></span><div><div style="font-weight:500">' + esc(t('leadDone', { name: turn.leadName || '', when: h.when || '' })) + '</div><div style="font-size:13px;color:var(--ls-fg-muted)">' + esc(t('leadDoneSub', { shop: S.config.shopName || '' })) + '</div></div></div>'
          : turn.lead === 'no' ? '' : '<div class="ls-hours" style="margin:4px 0 10px"><span class="ls-dot ls-dot--off"></span>' + esc(h.when ? t('teamBack', { when: h.when }) : t('leadText')) + '</div>' + leadForm(turn, asked ? asked.q : '')) + '</div>';
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
      return '<details class="ls-block ls-faq" style="border:0" data-cand="' + esc(s.candidate) + '"><summary style="padding:0;min-height:36px"><span class="ls-block__title" style="margin:0">' + esc(s.title || t('specs')) + '</span>' + icon('down', 'ls-icon--sm') + '</summary><table class="ls-specs">' + rows.map(function (x) { return '<tr><th>' + esc(x[0]) + '</th><td>' + esc(x[1]) + '</td></tr>'; }).join('') + '</table></details>';
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
  function miniRow(list) {
    return '<div class="ls-slider"><button class="ls-ibtn ls-ibtn--sm ls-ibtn--float ls-slider__btn ls-slider__btn--prev" type="button" data-slide="-1" aria-label="' + esc(t('slidePrev')) + '" disabled>' + icon('back', 'ls-icon--sm') + '</button>' +
      '<div class="ls-mini-row">' + list.slice(0, 6).map(function (p) {
        var r = rec(p.id) || { id: p.id, title: p.title, url: p.url, img: p.image, pr: p.price };
        return '<a class="ls-mini" href="' + esc(r.url || p.url) + '" data-pid="' + esc(pid(p.id)) + '" draggable="false"><img class="ls-mini__img" src="' + esc(r.img || p.image) + '" alt="" decoding="async" draggable="false">' + pic2(r) + '<span class="ls-mini__t" dir="auto">' + esc(decode(r.title || p.title)) + '</span>' + priceHtml(r) + '</a>';
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

  // ---------------------------------------------------------------- saved + compare
  function savedView() {
    if (!S.saved.length) {
      return '<div class="ls-saved-empty"><span class="ls-ibtn">' + icon('heart') + '</span><b>' + esc(t('savedEmptyT')) + '</b>' + esc(t('savedEmpty')) + '</div>';
    }
    var list = S.saved.map(rec).filter(Boolean);
    var html = '<div class="ls-saved-tools" style="padding-top:8px">' +
      (SHORT && SHORT.wishlistWhatsapp === false ? '' : '<button class="ls-btn ls-btn--secondary ls-btn--sm" data-share-wa>' + icon('whatsapp', 'ls-icon--sm') + esc(t('shareWa')) + '</button>') +
      '<button class="ls-btn ls-btn--secondary ls-btn--sm" data-copy>' + icon('link', 'ls-icon--sm') + esc(t('copyLink')) + '</button></div>';
    html += '<div>' + list.map(function (r) {
      var id = pid(r.id), on = S.pick.indexOf(id) >= 0;
      return '<div class="ls-saved-item"><label class="ls-check-row" title="' + esc(t('pickToCompare')) + '"><input type="checkbox" data-pick="' + esc(id) + '"' + (on ? ' checked' : '') + ' aria-label="' + esc(t('pickToCompare')) + '"></label>' +
        '<a class="ls-saved-item__media" href="' + esc(r.url) + '" tabindex="-1"><img src="' + esc(r.img) + '" alt="">' + pic2(r) + '</a>' +
        '<div class="ls-saved-item__body"><a class="ls-saved-item__t" dir="auto" href="' + esc(r.url) + '">' + esc(decode(r.title)) + '</a><div class="ls-saved-item__price">' + priceHtml(r) + (r.s === 0 ? ' <span class="ls-stock">' + esc(t('outStock')) + '</span>' : '') + '</div>' +
          '<div class="ls-saved-item__actions">' + buyBtn(r, 'ls-btn--sm') + (r.s === 0 ? '' : '<button class="ls-btn ls-btn--ghost ls-btn--sm ls-saved-item__sim" data-similar="' + esc(id) + '">' + icon('grid', 'ls-icon--sm') + esc(t('similar')) + '</button>') + '</div><div data-after="' + esc(id) + '"></div></div>' +
        '<button class="ls-ibtn ls-ibtn--sm ls-saved-item__x" aria-label="' + esc(t('remove')) + '" data-heart="' + esc(id) + '">' + icon('x', 'ls-icon--sm') + '</button></div>';
    }).join('') + '</div>';
    var n = S.pick.length;
    html += '<div class="ls-compare-bar"><button class="ls-btn ls-btn--primary" data-compare' + (n < 2 ? ' disabled' : '') + '>' + icon('compare', 'ls-icon--sm') + esc(n < 2 ? t('compareNeed') : t('compareN', { n: n + '/5' })) + '</button></div>';
    html += '<p class="pg-note" style="font-size:12px;color:var(--ls-fg-muted);margin:12px 0 0">' + esc(S.phone ? t('phoneKept', { p: S.phone }) : t('savedNote')) + (S.phone && WISH.phone ? ' · <button class="ls-btn ls-btn--link" style="font-size:12px" data-phone-out>' + esc(shortWord('phone_out', t('phoneOut'))) + '</button>' : '') + '</p>';
    if (!S.phone && !(SHORT && SHORT.phoneLogin === false)) { html += phoneBox(); }
    return html;
  }
  function phoneBox() {
    var st = S.phoneStep || '';
    if (!st) { return '<button class="ls-btn ls-btn--link" style="margin-top:8px" data-phone-start>' + esc(t('savedPhone')) + '</button>'; }
    return '<form class="ls-form" style="margin-top:12px;padding:14px;border-radius:16px;background:var(--ls-bg-sunken)" data-phone-form><div style="font-weight:500;font-size:14px">' + esc(t('savedPhone')) + '</div><div style="font-size:13px;color:var(--ls-fg-muted)">' + esc(t('savedPhoneHelp')) + '</div>' +
      (st === 'num' ? '<div class="ls-input"><label for="lsph">' + esc(t('phone')) + '</label><input id="lsph" name="phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" style="background:var(--ls-bg)"' + (S.phoneErr ? ' aria-invalid="true"' : '') + '>' + (S.phoneErr ? '<span class="ls-input__err">' + esc(S.phoneErr) + '</span>' : '') + '</div><button class="ls-btn ls-btn--primary ls-btn--sm">' + esc(t('sendCode')) + '</button>'
        : '<div class="ls-input"><label for="lscd">' + esc(t('code')) + '</label><input id="lscd" name="code" inputmode="numeric" autocomplete="one-time-code" dir="ltr" style="background:var(--ls-bg)"' + (S.phoneErr ? ' aria-invalid="true"' : '') + '>' + (S.phoneErr ? '<span class="ls-input__err">' + esc(S.phoneErr) + '</span>' : '') + '</div><label class="ls-check-row"><input type="checkbox" name="consent"><span>' + esc(shortWord('phone_consent', t('phoneConsent'))) + '</span></label><button class="ls-btn ls-btn--primary ls-btn--sm">' + esc(t('verifyCode')) + '</button>') + '</form>';
  }
  function compareHtml(turn) {
    if (turn.loading) { return '<div style="padding-top:12px">' + thinking() + '</div>'; }
    var d = turn.data || { products: [], rows: [] };
    var byLabel = {}, merged = [];
    (d.rows || []).forEach(function (r) {
      var m = byLabel[r.label];
      if (!m) { m = byLabel[r.label] = { label: r.label, values: r.values.slice() }; merged.push(m); return; }
      r.values.forEach(function (v, i) { if (m.values[i] == null) { m.values[i] = v; } });
    });
    merged.forEach(function (r) { var vals = r.values.map(function (v) { return v == null ? '' : String(v).toLowerCase(); }); r.differs = vals.some(function (v) { return v !== vals[0]; }); });
    var rows = merged.filter(function (r) { return !S.onlyDiff || r.differs; });
    return '<div class="ls-wide-head"><button class="ls-btn ls-btn--ghost ls-btn--sm" data-narrow style="margin-inline-start:-12px">' + icon('back', 'ls-icon--sm') + esc(t('saved')) + '</button>' +
      '<div class="ls-summary" style="margin-top:8px"><div class="ls-summary__count" style="font-size:20px;font-weight:500">' + esc(t('compareTitle')) + '</div><label class="ls-toggle"><input type="checkbox" data-onlydiff' + (S.onlyDiff ? ' checked' : '') + '>' + esc(t('onlyDiff')) + '</label></div></div>' +
      '<div class="ls-cmp-wrap ls-scroll"><table class="ls-cmp"><thead><tr><th></th>' + d.products.map(function (p) {
        var r = rec(p.id) || { id: p.id, buy: 0, s: p.in_stock ? 1 : 0 };
        return '<td><img class="ls-cmp__img" src="' + esc(p.image) + '" alt=""><a class="ls-cmp__t" dir="auto" href="' + esc(p.url) + '">' + esc(decode(p.title)) + '</a><div style="margin-top:8px">' + buyBtn(Object.assign({}, r, { id: p.id }), 'ls-btn--sm') + '</div></td>';
      }).join('') + '</tr></thead><tbody>' +
      '<tr class="is-diff"><th>' + esc(t('price')) + '</th>' + d.products.map(function (p) { return '<td class="ls-num">' + money(p.price) + (p.regular_price > p.price ? ' <s style="color:var(--ls-fg-muted);font-weight:400">' + money(p.regular_price) + '</s>' : '') + '</td>'; }).join('') + '</tr>' +
      '<tr><th>' + esc(t('stock')) + '</th>' + d.products.map(function (p) { return '<td>' + esc(p.in_stock ? t('inStock') : t('outStock')) + '</td>'; }).join('') + '</tr>' +
      rows.map(function (row) { return '<tr' + (row.differs ? ' class="is-diff"' : '') + '><th>' + esc(row.label) + '</th>' + row.values.map(function (v) { return '<td>' + esc(v == null ? '—' : v) + '</td>'; }).join('') + '</tr>'; }).join('') +
      '</tbody></table></div>';
  }
  function compare() {
    var ids = S.pick.slice(0, 5);
    var turn = { kind: 'compare', id: uid(), loading: true };
    S.turns.push(turn); S.wide = turn.id; S.tab = 'chat'; render();
    json(API + '/shortlist/' + SITE + '/compare?ids=' + ids.join(',')).then(function (r) { turn.loading = false; turn.data = r.body || {}; render(); })
      .catch(function () { turn.loading = false; turn.data = null; render(); });
  }
  function toggleSaved(id) {
    var at = S.saved.indexOf(id);
    if (at >= 0) { S.saved.splice(at, 1); S.pick = S.pick.filter(function (x) { return x !== id; }); } else { S.saved.unshift(id); }
    store('local', 'ls_saved', S.saved);
    var on = at < 0;
    WISH.wish = S.saved.slice();
    WISH.pending = on ? [id].concat(WISH.pending.filter(function (w) { return w !== id; })) : WISH.pending.filter(function (w) { return w !== id; });
    wishSave();
    wishSend(on ? { add: [id] } : { remove: [id] }).then(wishTake).catch(function () { /* kept here; sent with the next change */ });
    root.querySelectorAll('[data-heart="' + id + '"]').forEach(function (b) { b.setAttribute('aria-pressed', String(on)); });
    var c = D.querySelector('[data-count]'); c.textContent = S.saved.length; c.classList.remove('is-bump'); void c.offsetWidth; c.classList.add('is-bump');
    toast(on ? t('savedToast') : t('removedToast'), function () { toggleSaved(id); if (S.tab === 'saved') { render(); } });
    if (S.tab === 'saved') { render(); }
  }

  // ---------------------------------------------------------------- cart
  // q: the search or question it was added from, counted as an add to cart once the store's cart says yes
  // variant: Shopify's variant id, chosen in the options sheet. A cart that does not take it (a variant to
  // choose, a quantity rule, out of stock) sends the shopper on to the product's page, where the store adds it
  // its own way; from the options sheet, the store's own reason is shown instead.
  // properties: what the page asks besides the variant (a ring size), chosen in the same sheet (pageFields).
  function addToCart(btn, id, variation, q, parent, variant, onAdded, properties) {
    if (btn) { btn.dataset.state = 'busy'; btn.setAttribute('aria-busy', 'true'); }
    var done = function (sections) {
      if (btn) { btn.dataset.state = 'done'; btn.removeAttribute('aria-busy'); }
      announce(t('announceAdded'));
      cartChanged(id, sections);
      if (q) { countAdd(q, itemOf(parent || id)); }
      if (typeof onAdded === 'function') { try { onAdded(); } catch (e) { /* theirs */ } }
      afterAdd(parent || id, btn);
    };
    var failed = function (message) {
      if (btn) { btn.dataset.state = ''; btn.removeAttribute('aria-busy'); }
      var page = (rec(parent || id) || {}).url;
      if (!variation && !variant && page && /^https?:/.test(page)) { win.location.href = page; return; }
      var box = afterBox(parent || id, btn) || LAYER.querySelector('.ls-layer__foot');
      if (box) { box.insertAdjacentHTML('beforeend', '<div class="ls-input__err" role="alert" style="margin-top:8px;width:100%">' + esc(message || t('addFailed')) + '</div>'); }
    };
    if (SHOPIFY) {
      var add = function (vid) {
        var item = { id: Number(vid), quantity: 1 };
        if (properties && Object.keys(properties).length) { item.properties = properties; }
        return win.fetch(ROOT + 'cart/add.js', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          // the cart icon and drawer sections come back with the answer, the way Dawn and its relatives ask for them
          body: JSON.stringify({ items: [item], sections: 'cart-icon-bubble,cart-drawer', sections_url: win.location.pathname }) })
          .then(function (res) { if (!res.ok) { throw new Error('add'); } return res.json().catch(function () { return null; }); })
          .then(function (j) { done(j && j.sections); }, function () { failed(); });
      };
      if (variant) { return add(variant); }
      var choose = function () { if (btn) { btn.dataset.state = ''; btn.removeAttribute('aria-busy'); } optionsLayer(id, q); return null; };
      return products([id]).then(function () {
        var p = S.products[pid(id)];
        if (p && p.variants > 1) { return choose(); }
        if (!p || !p.variant || !p.is_in_stock) { failed(); return null; }
        // one variant, but the page may still ask for something (a ring size): chosen first, never left out
        return pageFields(id).then(function (f) {
          if (f && f.free) { failed(); return null; }
          if (f && f.fields.length) { return choose(); }
          if (f && Object.keys(f.fixed).length) { properties = Object.assign({}, f.fixed, properties || {}); }
          return add(p.variant);
        });
      });
    }
    if (!STORE) { failed(); return Promise.resolve(); }
    var body = { id: Number(id), quantity: 1 }, headers = { 'Content-Type': 'application/json' };
    if (variation) { body.variation = variation; }
    if (nonce) { headers.Nonce = nonce; }
    return win.fetch(STORE + '/cart/add-item', { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify(body) }).then(function (res) {
      var fresh = res.headers.get('Nonce');
      if (fresh) { nonce = fresh; }
      return res.json().catch(function () { return {}; }).then(function (b) { if (res.ok) { done(); } else { failed(b && b.message); } });
    }, function () { failed(); });
  }
  // the confirmation goes under the button that was pressed (the same product can be in the live results and a turn)
  // the store's own cart shows what went in: WooCommerce's fragments, and the events a theme listens to
  function cartChanged(id, sections) {
    if (SHOPIFY) { shopifyCartChanged(sections); }
    try { if (!SHOPIFY && win.jQuery) { win.jQuery(doc.body).trigger('wc_fragment_refresh'); } } catch (e) { /* a theme without jQuery */ }
    try {
      doc.body.dispatchEvent(new CustomEvent('let-agents:added_to_cart', { bubbles: true, detail: { id: id } }));
      doc.body.dispatchEvent(new CustomEvent('added_to_cart', { bubbles: true }));
      win.dispatchEvent(new CustomEvent('lets:added', { detail: { id: id } }));
    } catch (e) { /* old browser */ }
  }
  // Shopify themes show the cart in many ways and none is standard: Dawn's cart icon section, counters fed
  // from cart.js, and the events several themes listen to for redrawing their cart drawer
  function shopifyCartChanged(sections) {
    var bubble = doc.getElementById('cart-icon-bubble'), html = sections && sections['cart-icon-bubble'];
    if (bubble && typeof html === 'string' && win.DOMParser) {
      var section = new win.DOMParser().parseFromString(html, 'text/html').querySelector('.shopify-section');
      if (section) { bubble.innerHTML = section.innerHTML; }
    }
    var tell = function (cart) {
      ['cart:refresh', 'cart:updated'].forEach(function (name) {
        try { doc.documentElement.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: { cart: cart } })); doc.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: { cart: cart } })); } catch (e) { /* old */ }
      });
    };
    win.fetch(ROOT + 'cart.js', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (res) { return res.ok ? res.json() : null; }).then(function (cart) {
      if (cart && cart.item_count != null) { doc.querySelectorAll('[data-cart-count], .cart-count-bubble span[aria-hidden="true"]').forEach(function (n) { n.textContent = String(cart.item_count); }); }
      tell(cart);
    }).catch(function () { tell(null); });
  }
  function afterBox(id, btn) {
    var holder = btn && btn.closest && btn.closest('.ls-card, .ls-pick, .ls-exact, .ls-of, .ls-saved-item');
    return (holder && holder.querySelector('[data-after="' + id + '"]')) || BODY.querySelector('[data-after="' + id + '"]');
  }
  function afterAdd(id, btn) {
    var box = afterBox(id, btn);
    if (!box) { return; }
    box.innerHTML = '<div class="ls-added ls-focus-in" style="justify-content:space-between">' + icon('cart', 'ls-icon--sm') + '<span style="flex:1">' + esc(LANG === 'he' ? 'בעגלה שלכם' : 'In your cart') + '</span><a class="ls-btn ls-btn--link" href="' + esc(CFG.cartUrl) + '">' + esc(t('toCart')) + '</a></div>';
    json(API + '/widget/' + SITE + '/page?id=' + encodeURIComponent(id)).then(function (r) {
      var b = (r.body && (r.body.data || r.body)) || {}, comp = null;
      (b.sections || []).forEach(function (s) { if (s.candidate === 'complement' && s.products && s.products.length) { comp = s; } });
      if (!comp) { return; }
      return products(comp.products.map(function (p) { return pid(p.id); })).then(function () {
        box.insertAdjacentHTML('beforeend', '<div class="ls-block ls-focus-in" style="margin-top:12px"><div class="ls-block__title"><span>' + esc(t('goesWith')) + '</span><button class="ls-why" data-why>' + esc(t('whyShown')) + '</button></div>' + miniRow(comp.products) + '</div>');
        requestAnimationFrame(sliders);
      });
    }).catch(function () { /* nothing to add */ });
  }
  function optionsLayer(id, q) {
    showLayer(t('chooseTitle'), '<div style="padding:24px 0">' + thinking() + '</div>', '');
    var source = SHOPIFY ? Promise.all([shopifyJson(id), pageFields(id)]).then(function (both) {
      // Shopify's options and variants in the shape the sheet reads (an option with one value is no choice),
      // and what the page asks besides them (a ring size), each as one more choice the variants do not hold
      var j = both[0], page = both[1];
      if (!j || !j.variants) { return null; }
      var names = (j.options || []).map(function (o) { return typeof o === 'string' ? o : o.name; });
      var props = ((page && page.fields) || []).map(function (f) { return { name: f.label, prop: f.name, has_variations: true, terms: f.values.map(function (v) { return { slug: v.value, name: v.label }; }) }; });
      return { name: j.title, fixed: (page && page.fixed) || {}, images: j.featured_image ? [{ src: String(j.featured_image).replace(/^\/\//, 'https://') }] : [],
        attributes: names.map(function (n, at) { var vals = []; j.variants.forEach(function (v) { var x = v['option' + (at + 1)]; if (x != null && vals.indexOf(x) === -1) { vals.push(x); } }); return { name: n, has_variations: vals.length > 1 || (names.length === 1 && j.variants.length > 1), terms: vals.map(function (x) { return { slug: x, name: x }; }) }; }).concat(props),
        variations: j.variants.map(function (v) { return { id: v.id, available: v.available !== false, attributes: names.map(function (n, at) { return { name: n, value: v['option' + (at + 1)] }; }) }; }) };
    }) : json(STORE + '/products/' + encodeURIComponent(id), { credentials: 'same-origin' }).then(function (r) { return r.body || null; });
    source.then(function (p) {
      if (!p) { throw new Error('none'); }
      var attrs = (p.attributes || []).filter(function (a) { return a.has_variations; }), vars = (p.variations || []).filter(function (v) { return v.available !== false; }), chosen = {};
      var rr = rec(id) || {};
      function slugOf(a, term) { return term.slug; }
      function match() {
        return vars.filter(function (v) { return attrs.every(function (a) { var want = chosen[a.name]; if (!want) { return true; } var x = (v.attributes || []).filter(function (y) { return y.name === a.name; })[0]; return !x || !x.value || x.value === want.slug || decode(x.value) === want.name; }); });
      }
      function draw() {
        var missing = attrs.filter(function (a) { return !chosen[a.name]; });
        var head = '<div class="ls-variant-head"><img class="ls-about__img" style="width:72px;height:72px" src="' + esc(rr.img || (p.images && p.images[0] && p.images[0].src) || '') + '" alt=""><div><div class="ls-pick__title" dir="auto">' + esc(decode(p.name || rr.title)) + '</div><div style="margin-top:4px">' + priceHtml(rr) + '</div></div></div>';
        var groups = attrs.map(function (a) {
          return '<div class="ls-opt-group"><div class="ls-opt-group__t">' + esc(a.name) + (chosen[a.name] ? ' <span>· ' + esc(chosen[a.name].name) + '</span>' : '') + '</div><div class="ls-chips" style="flex-wrap:wrap;overflow:visible;mask-image:none;-webkit-mask-image:none">' +
            a.terms.map(function (term) {
              var test = Object.assign({}, chosen); test[a.name] = term;
              var ok = vars.some(function (v) { return attrs.every(function (b2) { var want = test[b2.name]; if (!want) { return true; } var x = (v.attributes || []).filter(function (y) { return y.name === b2.name; })[0]; return !x || !x.value || x.value === want.slug || decode(x.value) === want.name; }); });
              var on = chosen[a.name] && chosen[a.name].slug === term.slug;
              return '<button class="ls-chip" aria-pressed="' + !!on + '"' + (ok ? '' : ' disabled style="opacity:.4;text-decoration:line-through"') + ' data-var="' + esc(a.name) + '|' + esc(term.slug) + '"><span class="ls-chip__check">' + icon('check', 'ls-icon--sm') + '</span>' + esc(term.name) + '</button>';
            }).join('') + '</div></div>';
        }).join('');
        var foot = '<button class="ls-btn ls-btn--primary ls-btn--lg ls-add" data-var-add' + (missing.length ? ' disabled' : '') + '><span class="ls-add__label">' + esc(missing.length ? t('choose', { a: missing[0].name }) : t('add')) + '</span><span class="ls-add__busy">' + mark(true) + '</span><span class="ls-add__done"><svg class="ls-icon ls-icon--sm ls-check" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-9.5"/></svg>' + esc(t('added')) + '</span></button>';
        LAYER.querySelector('.ls-layer__body').innerHTML = head + groups;
        LAYER.querySelector('.ls-layer__foot').innerHTML = foot;
      }
      LAYER.onclick = function (e) {
        var v = e.target.closest('[data-var]');
        if (v && !v.disabled) { var parts = v.dataset.var.split('|'); var a = attrs.filter(function (x) { return x.name === parts[0]; })[0]; var term = a.terms.filter(function (x) { return x.slug === parts[1]; })[0]; chosen[a.name] = chosen[a.name] && chosen[a.name].slug === term.slug ? null : term; if (!chosen[a.name]) { delete chosen[a.name]; } draw(); return; }
        var add = e.target.closest('[data-var-add]');
        if (add && !add.disabled) {
          var m = match()[0];
          // WooCommerce: each attribute by its taxonomy when it has one (pa_color), as its add-item reads it
          var variation = attrs.map(function (a) { return { attribute: a.taxonomy || a.name, value: chosen[a.name].slug }; });
          // the page's own fields go as the line's properties ("Size": "49"), as its product form posts them
          var properties = Object.assign({}, p.fixed || {});
          attrs.forEach(function (a) { if (a.prop) { properties[a.prop] = chosen[a.name].slug; } });
          var adding = SHOPIFY ? addToCart(add, id, null, q, id, m ? m.id : null, null, properties) : addToCart(add, m ? m.id : id, variation, q, id);
          Promise.resolve(adding).then(function () { if (add.dataset.state === 'done') { setTimeout(hideLayer, 900); } });
        }
      };
      draw();
    }).catch(function () { LAYER.querySelector('.ls-layer__body').innerHTML = '<p class="ls-noanswer__text">' + esc(t('addFailed')) + '</p>'; });
  }

  // ---------------------------------------------------------------- filters + sort layers
  function filtersLayer(turn) {
    function draw() {
      var fc = facetChips(turn, 0), c = C(turn), groups = {}, so = sortOf(c);
      fc.codes.forEach(function (code) { var g = Math.floor(code / 1000); (groups[g] = groups[g] || []).push(code); });
      var html = activeRow(turn) ? '<div class="ls-fgroup ls-fgroup--active"><div class="ls-fgroup__t">' + esc(t('activeFilters')) + '</div>' + activeRow(turn) + '</div>' : '';
      html += '<div class="ls-fgroup"><div class="ls-fgroup__t">' + esc(t('sort')) + '</div><div class="ls-chips">' + ['', 'asc', 'desc', 'new'].map(function (s) { return '<button class="ls-chip" aria-pressed="' + (so === s) + '" data-fsort="' + s + '"><span class="ls-chip__check">' + icon('check', 'ls-icon--sm') + '</span>' + esc(sortLabel(s)) + '</button>'; }).join('') + '</div></div>';
      if (fc.stock || fc.sale || onStock(c) || onSale(c)) {
        html += '<div class="ls-fgroup"><div class="ls-fgroup__t">' + esc(LANG === 'he' ? 'זמינות' : 'Availability') + '</div><div class="ls-chips">' + ((fc.stock || onStock(c)) ? chip(t('inStock'), onStock(c), 'stock', turn.id, c.autoStock && !c.stock && !!c.saidStock) : '') + ((fc.sale || onSale(c)) ? chip(t('onSale'), onSale(c), 'sale', turn.id, c.autoSale && !c.sale) : '') + '</div></div>';
      }
      Object.keys(groups).forEach(function (g) {
        var facet = S.facets[g], colours = groups[g].some(function (code) { return swatch(facet, facet.v[code % 1000]); });
        html += '<div class="ls-fgroup' + (colours ? ' ls-fgroup--colour' : '') + '"><div class="ls-fgroup__t">' + esc(facet.l) + '</div><div class="ls-chips">' + groups[g].map(function (code) {
          var v = facet.v[code % 1000];
          return chip(v.l, onCode(c, code), 'f:' + code, turn.id, c.auto[code] && !c.f[code], swatch(facet, v), fc.counts[code]);
        }).join('') + '</div></div>';
      });
      LAYER.querySelector('.ls-layer__body').innerHTML = html;
      var n = filtered(turn).length;
      LAYER.querySelector('.ls-layer__foot').innerHTML = '<button class="ls-btn ls-btn--secondary" data-fclear>' + esc(t('clearAll')) + '</button><button class="ls-btn ls-btn--primary" data-layer-close' + (n ? '' : ' disabled') + '>' + esc(n ? t('showN', { n: n }) : t('noFilterMatch').split('.')[0]) + '</button>';
    }
    showLayer(t('filter'), '', '');
    LAYER.onclick = function (e) {
      var s = e.target.closest('[data-fsort]');
      // handled here, so the drawer's own click handler doesn't toggle it a second time
      if (s) { e.stopPropagation(); setSort(turn, s.dataset.fsort); draw(); refresh(turn); return; }
      if (e.target.closest('[data-fclear]')) { e.stopPropagation(); clearC(turn, true); draw(); refresh(turn); return; }
      var cl = e.target.closest('[data-clear-filters]');
      if (cl) { e.stopPropagation(); clearC(turn, false); draw(); refresh(turn); return; }
      var ch = e.target.closest('[data-chip]');
      if (ch) { e.stopPropagation(); applyChip(turn, ch.dataset.chip); draw(); }
    };
    draw();
  }
  function setSort(turn, s) { var c = C(turn); c.sort = s; c.autoSort = ''; c.no.sort = true; }
  // clearing takes off what was read from the words too, and keeps it off
  function clearC(turn, withSort) {
    var c = C(turn), n = blankC();
    Object.keys(c.no).forEach(function (k) { n.no[k] = true; });
    Object.keys(c.auto).forEach(function (k) { n.no[k] = true; });
    if (c.autoStock) { n.no.stock = true; }
    if (c.autoSale) { n.no.sale = true; }
    if (withSort) { n.no.sort = true; } else { n.sort = c.sort; n.autoSort = c.autoSort; }
    turn.c = n;
  }
  function applyChip(turn, key) {
    var c = C(turn);
    if (key === 'stock') { if (c.autoStock) { c.autoStock = false; c.no.stock = true; } else { c.stock = !c.stock; } }
    else if (key === 'sale') { if (c.autoSale) { c.autoSale = false; c.no.sale = true; } else { c.sale = !c.sale; } }
    else {
      var code = Number(key.split(':')[1]);
      if (c.auto[code] || c.f[code]) { delete c.auto[code]; delete c.f[code]; c.no[code] = true; } else { c.f[code] = true; delete c.no[code]; }
    }
    refresh(turn);
  }
  function sortLayer(turn) {
    var so = sortOf(C(turn));
    showLayer(t('sort'), '<div class="ls-stack">' + ['', 'asc', 'desc', 'new'].map(function (s) { return '<button class="ls-row" data-fsort="' + s + '" aria-pressed="' + (so === s) + '">' + (so === s ? icon('check') : '<span style="width:20px"></span>') + '<span class="ls-row__text">' + esc(sortLabel(s)) + '</span></button>'; }).join('') + '</div>', null);
    LAYER.onclick = function (e) { var s = e.target.closest('[data-fsort]'); if (s) { e.stopPropagation(); setSort(turn, s.dataset.fsort); hideLayer(); refresh(turn); } };
  }

  // ---------------------------------------------------------------- the menu: new chat, share, ask my AI, large view
  // Share and "Ask my AI" send what's on screen: the live results, the wide grid, or the newest search,
  // as filtered. With no search yet they send the store itself, as the product scripts do.
  var AI_OPEN = { chatgpt: 'https://chatgpt.com/?q={q}', claude: 'https://claude.ai/new?q={q}', perplexity: 'https://www.perplexity.ai/search?q={q}' };
  var AIS = [['chatgpt', 'ChatGPT'], ['claude', 'Claude'], ['perplexity', 'Perplexity']];
  function shareCtx() {
    var turn = (S.live && SUG.classList.contains('is-on') && S.live) || (S.wide && turnById(S.wide)) ||
      S.turns.slice().reverse().filter(function (x) { return (x.kind === 'results' || x.kind === 'similar') && x.ids && x.ids.length; })[0] || null;
    if (turn && (turn.kind === 'compare' || !turn.ids)) { turn = null; }
    var q = !turn ? '' : turn.kind === 'similar' ? decode((rec(turn.of) || {}).title || '').split('|')[0].trim() : turn.q;
    return { q: q || '', ids: turn ? filtered(turn).slice(0, 24).map(function (r) { return pid(r.id); }) : [], turn: turn };
  }
  // the store itself, opening this search with the filters chosen: the link format the product scripts
  // made, so links already shared (and the AI connector's) open the same way (?la_q=…&la_f=…)
  var SORT_LINK = { asc: 'price:asc', desc: 'price:desc' };
  function shareLink(cx) {
    var home = CFG.homeUrl || (win.location.origin + '/');
    if (!cx.q) { return home; }
    var c = cx.turn && cx.turn.kind === 'results' ? C(cx.turn) : null, f = [];
    if (c) {
      Object.keys(c.f).forEach(function (code) { var x = valueOf(Number(code)); if (x && x.value) { f.push(x.facet.k + '.' + x.value.k); } });
      if (c.sale) { f.push('sale'); }
      if (c.stock) { f.push('stock'); }
      if (SORT_LINK[c.sort]) { f.push('sort.' + SORT_LINK[c.sort]); }
    }
    return home + (home.indexOf('?') >= 0 ? '&' : '?') + 'la_q=' + encodeURIComponent(cx.q) + (f.length ? '&la_f=' + encodeURIComponent(f.join(',')) : '');
  }
  // the filters a shared link names, chosen before its results show (facets by key, as the index names them)
  function cFromLink(list) {
    var c = blankC();
    String(list || '').split(',').map(function (k) { return k.trim(); }).filter(Boolean).forEach(function (k) {
      if (k === 'stock' || k === 'sale') { c[k] = true; return; }
      if (k.indexOf('sort.') === 0) { var v = k.slice(5); c.sort = v === 'price:asc' ? 'asc' : v === 'price:desc' ? 'desc' : v === 'new' ? 'new' : ''; return; }
      if (/^\d+$/.test(k)) { c.f[k] = true; return; }
      var dot = k.indexOf('.'), key = k.slice(0, dot), val = k.slice(dot + 1);
      (S.facets || []).forEach(function (facet, at) { if (facet.k === key) { (facet.v || []).forEach(function (v, i) { if (v.k === val) { c.f[at * 1000 + i] = true; } }); } });
    });
    return c;
  }
  // the AI's link: from the server (a snapshot it reads), or, when that fails, the store's own link
  var SHARES = {};
  function shareFor(cx) {
    var key = cx.q + '|' + cx.ids.join(',') + (cx.asked ? '|asked' : '');
    if (!SHARES[key]) {
      // text/plain, so the request needs no preflight; the body is JSON either way
      SHARES[key] = json(API + '/search/' + SITE + '/share', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ q: cx.q, ids: cx.ids, locale: LANG, asked: !!cx.asked }) }).then(function (r) {
        var d = r.ok && r.body && (r.body.data || r.body);
        if (d && d.prompt && d.open) { return d; }
        throw new Error('share');
      }).catch(function () {
        delete SHARES[key];
        var url = shareLink(cx), words = cx.asked ? t('aiPromptQ', { q: cx.q, store: S.config.shopName || '', url: url }) : t('aiPrompt', { store: S.config.shopName || '', url: url }), open = {};
        Object.keys(AI_OPEN).forEach(function (k) { open[k] = AI_OPEN[k].replace('{q}', encodeURIComponent(words)); });
        return { url: url, prompt: words, open: open };
      });
    }
    return SHARES[key];
  }
  function copyText(text, done) {
    var p = win.navigator.clipboard && win.navigator.clipboard.writeText ? win.navigator.clipboard.writeText(text) : Promise.reject();
    p.then(done, function () { win.prompt(t('aiCopy'), text); });
  }
  function menuRow(attrs, ic, label, end) { return '<button class="ls-row" role="menuitem" ' + attrs + '>' + ic + '<span class="ls-row__text">' + esc(label) + '</span>' + (end || '') + '</button>'; }
  function menuHtml() {
    var cx = shareCtx(), whole = !cx.q, chev = icon('fwd', 'ls-icon--sm ls-menu__chev');
    var back = function (title) { return '<button class="ls-menu__back" data-menu-view="">' + icon('back', 'ls-icon--sm') + '<span>' + esc(title) + '</span></button>'; };
    var about = whole ? '' : '<span class="ls-menu__for" dir="auto">' + esc(t('aiFor', { q: cx.q, n: cx.ids.length })) + '</span>';
    if (S.menu === 'ai') {
      return back(t('aiAsk')) + '<p class="ls-menu__help">' + esc(whole ? t('aiStoreHelp') : t('aiHelp')) + about + '</p>' +
        AIS.map(function (a) { return menuRow('data-ai="' + a[0] + '"', '<span class="ls-menu__logo">' + logo(a[0]) + '</span>', a[1], chev); }).join('') +
        menuRow('data-ai="copy"', '<span class="ls-menu__logo">' + icon('link', 'ls-icon--sm') + '</span>', t('aiCopy')) + '<div class="ls-menu__note" role="status" data-menu-note></div>';
    }
    if (S.menu === 'share') {
      return back(t(whole ? 'shareStore' : 'shareSearch')) + '<p class="ls-menu__help">' + esc(t(whole ? 'shareStoreHelp' : 'shareHelp')) + (whole ? '' : '<span class="ls-menu__for" dir="auto">"' + esc(cx.q) + '"</span>') + '</p>' +
        menuRow('data-share="whatsapp"', '<span class="ls-menu__logo ls-menu__logo--wa">' + icon('whatsapp', 'ls-icon--sm') + '</span>', 'WhatsApp') +
        menuRow('data-share="link"', '<span class="ls-menu__logo">' + icon('link', 'ls-icon--sm') + '</span>', t('aiCopy')) +
        (typeof win.navigator.share === 'function' ? menuRow('data-share="more"', '<span class="ls-menu__logo">' + icon('share', 'ls-icon--sm') + '</span>', t('shareMore')) : '') + '<div class="ls-menu__note" role="status" data-menu-note></div>';
    }
    var aiOn = !off(whole ? 'aiAsk' : 'aiShare'), shareOn = !off('shareResults');
    return menuRow('data-new', icon('history'), t('newChat')) + (aiOn || shareOn ? '<div class="ls-menu__sep"></div>' : '') +
      (shareOn ? menuRow('data-menu-view="share"', icon('share'), t(whole ? 'shareStore' : 'shareSearch'), chev) : '') +
      (aiOn ? menuRow('data-menu-view="ai"', icon('spark'), t('aiAsk'), '<span class="ls-menu__logos">' + AIS.map(function (a) { return logo(a[0]); }).join('') + '</span>' + chev) : '') +
      (isPhone() ? '' : '<div class="ls-menu__sep"></div>' + menuRow('data-expand role="menuitemcheckbox" aria-checked="' + S.expanded + '"', icon('expand'), t('bigView'), S.expanded ? icon('check', 'ls-icon--sm') : ''));
  }
  function showMenu(view) {
    S.menu = view || '';
    MENU.innerHTML = menuHtml();
    MENU.hidden = false; MENU.classList.toggle('is-sub', !!S.menu);
    D.querySelector('[data-menu]').setAttribute('aria-expanded', 'true');
    var first = MENU.querySelector(S.menu ? '[data-ai], [data-share]' : 'button');
    if (first) { first.focus({ preventScroll: true }); }
  }
  function hideMenu() { if (MENU.hidden) { return; } MENU.hidden = true; S.menu = ''; D.querySelector('[data-menu]').setAttribute('aria-expanded', 'false'); }
  function menuNote(text) { var n = MENU.querySelector('[data-menu-note]'); if (n) { n.textContent = text; } }
  // A question that asks for planning, quantities or a comparison: long, or in those words. Offered, besides
  // our answer, to the shopper's own AI with the store's whole information, to come back as an order here.
  function complexAsk(q) {
    return wordsOf(q).length >= 6 || buildAsk(q) || /(כמה צריך|כמה יחידות|כמה מטר|לפרויקט|פרויקט|מה ההבדל|להשוות|השוואה|מה עדיף|עדיף|לתכנן|תכנון|רשימת קניות|רשימה|כמות|how many|how much|compare|difference|which is better|project|plan)/i.test(q);
  }
  // "תבנה לי הזמנה לבניית פרגולה": a thing to build or assemble, or an order put together for it
  var BUILD = /(^|\s)(תבנה|תבני|תרכיב|תרכיבי|בנה|בני|לבנות|בניית|לבניית|לבנייה|להרכיב|הרכבה|להרכבת|build|assemble|put together)(\s|$)|הזמנה (ל|עבור)|order for/i;
  function buildAsk(q) { return BUILD.test(String(q || '')); }
  // The store's own ready-made kit of what is to be built: a product named kit / set / ערכה with every word that
  // names the thing ("קיט פרגולה" for "הזמנה לבניית פרגולה"). Words of the store's catalogue only, so any shop.
  var KIT = /(^|[^\p{L}])(קיט|ערכה|ערכת|סט|kit|set)([^\p{L}]|$)/iu;
  var PLAN_WORDS = /^(תבנה|תבני|תרכיב|תרכיבי|בנה|בני|לבנות|בניית|לבניית|לבנייה|להרכיב|הרכבה|להרכבת|הזמנה|להזמין|לי|לנו|עבור|בשביל|צריך|רוצה|רוצים|build|assemble|put|together|order|me|us|need|want|kit|set|קיט|ערכה|סט)$/i;
  function kitsFor(q) {
    if (!buildAsk(q)) { return []; }
    var thing = wordsOf(q).filter(function (w) { return w.length >= 2 && !STOP.test(w) && !PLAN_WORDS.test(w) && !PLAN_WORDS.test(stem(w)); });
    if (!thing.length) { return []; }
    var found = Object.keys(S.records).map(function (k) { return S.records[k]; }).filter(function (r) {
      return r && r.t === 'product' && KIT.test(r.title || '') && thing.every(function (w) { return says(r.title + ' ' + (r.kw || ''), w) || says(r.title + ' ' + (r.kw || ''), stem(w)); });
    });
    found.sort(function (a, b) { return (b.s === 0 ? 0 : 1) - (a.s === 0 ? 0 : 1); });
    return found.slice(0, 3).map(function (r) { return r.id; });
  }
  function kitsHtml(turn) {
    if (turn.kits === undefined) { turn.kits = kitsFor(turn.q); }
    var list = (turn.kits || []).map(rec).filter(Boolean);
    return list.length ? '<div class="ls-kit"><div class="ls-block__title" style="margin-bottom:6px">' + esc(t('kitTitle')) + '</div>' + list.map(prow).join('') + '</div>' : '';
  }
  function myAi(turn) {
    var ask = S.config.aiAsk;
    if (!turn || !turn.q || !ask || !ask.open || off('aiShare') || !complexAsk(turn.q)) { return ''; }
    return '<div class="ls-myai"><div class="ls-myai__t">' + esc(t('myAiTitle')) + '</div><div class="ls-myai__s">' + esc(t('myAiSub')) + '</div><div class="ls-myai__btns">' +
      ['chatgpt', 'claude', 'perplexity'].filter(function (k) { return ask.open[k]; }).map(function (k) { return '<button class="ls-btn ls-btn--secondary ls-btn--sm" data-ai-ask="' + k + '" data-turn="' + turn.id + '">' + logo(k) + esc({ chatgpt: 'ChatGPT', claude: 'Claude', perplexity: 'Perplexity' }[k]) + '</button>'; }).join('') +
      '</div></div>';
  }
  // the question and the products of its answer, handed to the shopper's own AI
  function askAiAbout(turn, choice) {
    if (!turn) { return; }
    var ids = (turn.picks || []).map(function (p) { return pid(p.id); }).slice(0, 24);
    var tab = win.open('about:blank', '_blank');
    shareFor({ q: turn.q, ids: ids, turn: null, asked: true }).then(function (data) {
      if (tab) { try { tab.opener = null; } catch (e) { /* fine */ } tab.location.href = data.open[choice]; } else { win.location.href = data.open[choice]; }
    }, function () { if (tab) { tab.close(); } toast(t('aiFailed')); });
  }
  function askAi(choice) {
    var cx = shareCtx();
    // the tab opens inside the press, so no popup blocker stops it, and goes where it should once the link is ready
    var tab = choice === 'copy' ? null : win.open('about:blank', '_blank');
    shareFor(cx).then(function (data) {
      if (choice === 'copy') { copyText(data.prompt, function () { menuNote(t('aiCopied')); }); return; }
      if (tab) { try { tab.opener = null; } catch (e) { /* fine */ } tab.location.href = data.open[choice]; } else { win.location.href = data.open[choice]; }
    }, function () { if (tab) { tab.close(); } menuNote(t('aiFailed')); });
  }
  function shareGo(choice) {
    var cx = shareCtx(), link = shareLink(cx), shop = S.config.shopName || '';
    var said = cx.q ? t('shareText', { store: shop, q: cx.q }) : t('shareStoreText', { store: shop });
    if (choice === 'whatsapp') { var w = win.open('https://wa.me/?text=' + encodeURIComponent(said + ' ' + link), '_blank'); if (w) { try { w.opener = null; } catch (e) { /* fine */ } } return; }
    if (choice === 'more') { win.navigator.share({ title: t(cx.q ? 'shareSearch' : 'shareStore'), text: said, url: link }).catch(function () { /* closed */ }); return; }
    copyText(link, function () { menuNote(t('shareCopied')); });
  }
  // large view on a desktop: the side of the drawer, or the menu, makes it wide; again makes it narrow
  function toggleExpand() {
    if (S.wide) { narrow(); S.expanded = false; } else { S.expanded = !S.expanded; }
    store('local', 'ls_expanded', S.expanded || null);
    render();
    if (S.live) { renderLive(false); }
  }
  function narrow() {
    var was = turnById(S.wide);
    S.wide = null;
    if (was && was.kind === 'compare') { S.turns = S.turns.filter(function (x) { return x !== was; }); S.tab = 'saved'; }
  }

  // ---------------------------------------------------------------- layer + toast
  var layerReturn = null;
  function showLayer(title, body, foot) {
    layerReturn = root.activeElement;
    LAYER.hidden = false; LSCRIM.hidden = false;
    LAYER.innerHTML = '<div class="ls-sheet__handle" style="display:block"></div><div class="ls-layer__head"><div class="ls-layer__title">' + esc(title) + '</div><button class="ls-ibtn" aria-label="' + esc(t('close')) + '" data-layer-close>' + icon('x') + '</button></div><div class="ls-layer__body ls-scroll">' + body + '</div>' + (foot === null ? '' : '<div class="ls-layer__foot">' + (foot || '') + '</div>');
    LAYER.setAttribute('aria-label', title);
    LAYER.setAttribute('tabindex', '-1');
    requestAnimationFrame(function () { LAYER.classList.add('is-on'); LSCRIM.classList.add('is-on'); LAYER.focus({ preventScroll: true }); });
  }
  function hideLayer() {
    if (LAYER.hidden) { return; }
    LAYER.classList.remove('is-on'); LSCRIM.classList.remove('is-on'); LAYER.onclick = null;
    setTimeout(function () { if (!LAYER.classList.contains('is-on')) { LAYER.hidden = true; LSCRIM.hidden = true; } }, 380);
    if (layerReturn && layerReturn.focus) { try { layerReturn.focus({ preventScroll: true }); } catch (e) { /* gone */ } }
  }
  var toastTimer = null;
  function toast(text, undo) {
    TOAST.hidden = false;
    TOAST.innerHTML = '<span>' + esc(text) + '</span>' + (undo ? '<button data-undo>' + esc(t('undo')) + '</button>' : '');
    TOAST.onclick = function (e) { if (e.target.closest('[data-undo]')) { TOAST.hidden = true; undo(); } };
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { TOAST.hidden = true; }, 4200);
  }

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
      if (o && o.dataset.ask) { submit(INPUT.value, 'ask'); return; }
      if (o) { o.click(); return; }
      submit(INPUT.value);
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
      var errs = { question: !question, name: !name, contact: !(/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(contact) || (digits.length >= 9 && digits.length <= 12 && !/[a-z]/i.test(contact))), consent: !ok };
      turn.ln = name; turn.lc = contact; turn.lk = ok; turn.lq = question;
      if (errs.question || errs.name || errs.contact || errs.consent) { turn.errs = errs; render(); var bad = BODY.querySelector('[data-lead="' + turn.id + '"] [aria-invalid="true"]'); if (bad) { bad.focus(); } return; }
      turn.errs = {};
      // the shop's callback about this question, as the search drawer always sent it (shoppers.callbacks)
      json(API + '/widget/' + SITE + '/signup', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ vid: visitor(), contact: contact, name: name, consent: true, locale: LANG, question: question, type: turn.kind === 'about' ? 'product' : 'search', id: turn.kind === 'about' ? String(turn.id) : turn.askId || 'search' }) }).then(function (r) {
        var status = r.body && r.body.data ? r.body.data.status : null;
        if (status === 'invalid_contact') { turn.errs = { contact: true }; render(); return; }
        if (status !== 'saved' && status !== 'code_sent') { throw new Error('lead'); }
        update(turn, { lead: 'done', leadName: name, leadErr: false });
      }).catch(function () { update(turn, { leadErr: true }); });
      return;
    }
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
    if (!turn || !turn.q) { return null; }
    var card = el.closest('[data-card]'), pick = el.closest('.ls-pick'), after = pick && pick.querySelector('[data-after]');
    var id = card ? card.dataset.card : after ? after.dataset.after : (el.dataset.add || el.dataset.options || el.dataset.goto);
    var opening = (el.tagName === 'A' || el.dataset.add || el.dataset.options) && el.dataset.state !== 'done';
    if (turn.kind === 'results' && id && opening && !el.dataset.heart) { countClick(turn.q, itemOf(id), filtered(turn).length); }
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
    if (d.share && el.closest('.ls-menu')) { shareGo(d.share); return; }
    if (d.expand !== undefined) { hideMenu(); toggleExpand(); return; }
    if (d.new !== undefined) {
      var old = S.turns; hideMenu(); S.turns = []; S.wide = null; S.tab = 'chat'; persist(); render();
      toast(t('chatCleared'), function () { S.turns = old; persist(); render(); });
      return;
    }
    if (d.clear !== undefined) { e.preventDefault(); INPUT.value = ''; INPUT.focus(); onType(); return; }
    if (d.submit !== undefined) { e.preventDefault(); submit(INPUT.value); return; }
    if (d.photo !== undefined) { e.preventDefault(); photoPick(); return; }
    if (d.focus !== undefined) { INPUT.focus(); return; }
    if (d.q) { e.preventDefault(); submit(d.q, 'search'); return; }
    if (d.ask) { e.preventDefault(); submit(d.ask, 'ask', !!el.closest('.ls-about-turn')); return; }
    if (d.savedAnswer) {
      e.preventDefault();
      var a = S.records[d.savedAnswer];
      INPUT.value = ''; hideLive(); FIELD.classList.remove('has-text');
      addTurn({ kind: 'answer', q: a.title, answer: a.ans, picks: [], sources: a.src || [], anim: 1 });
      return;
    }
    if (d.unrecent) { S.recent = S.recent.filter(function (x) { return x !== d.unrecent; }); store('local', 'ls_recent', S.recent); render(); return; }
    if (d.orderPick) { var op = d.orderPick.split(':'), ot = turnById(op[0]); if (ot) { ot.pickedAt[op[1]] = ot.pickedAt[op[1]] === Number(op[2]) ? null : Number(op[2]); render(); } return; }
    if (d.orderAdd) { var oa = turnById(d.orderAdd); if (oa && !oa.adding) { orderAdd(oa); } return; }
    if (d.slide) { e.preventDefault(); slide(el); return; }
    if (el.closest('.ls-mini-row') && dragged) { e.preventDefault(); dragged = false; return; }
    if (d.earlier !== undefined) { S.showAll = true; render(); return; }
    if (d.prev !== undefined) { S.showPrev = true; render(); return; }
    if (d.heart) { e.preventDefault(); toggleSaved(d.heart); return; }
    if (d.add) { e.preventDefault(); if (el.dataset.state === 'done') { win.location.href = CFG.cartUrl; return; } addToCart(el, d.add, null, S.pressQ); return; }
    if (d.options) { e.preventDefault(); optionsLayer(d.options, S.pressQ); return; }
    if (d.similar) { e.preventDefault(); similarTurn(d.similar); return; }
    if (d.more) {
      // the rest of the results, right under the ones seen: nothing moves, nothing jumps to the top
      var mt = turnById(d.more);
      if (!mt) { return; }
      var rest = filtered(mt).map(function (r) { return r.id; }), from = gridN(mt);
      mt.more = rest.length;
      var keepY = BODY.scrollTop, again = function () { if (mt === S.live) { renderLive(false); } else { persist(); render(); } BODY.scrollTop = keepY; };
      again();
      products(rest.slice(from, from + 48)).then(again);
      return;
    }
    if (d.wide) {
      // "show all" from the live results keeps the search first, then opens it large
      if (d.wide === 'live') { if (!S.live) { return; } submit(S.live.q, 'search'); d = { wide: S.turns[S.turns.length - 1].id }; }
      var wt = turnById(d.wide); S.wide = d.wide; wt.limit = 24;
      products(filtered(wt).slice(0, 24).map(function (r) { return r.id; })).then(function () { render(); }); render(); BODY.scrollTop = 0; return;
    }
    if (d.narrow !== undefined) { narrow(); render(); return; }
    if (d.loadmore) { var lt = turnById(d.loadmore); lt.limit = (lt.limit || 24) + 24; products(filtered(lt).slice(0, lt.limit).map(function (r) { return r.id; })).then(render); return; }
    if (d.chip) { var ct = turnById(d.turn); if (ct) { applyChip(ct, d.chip); } return; }
    if (d.filters) { filtersLayer(turnById(d.filters)); return; }
    if (d.sort) { sortLayer(turnById(d.sort)); return; }
    if (d.clearFilters) { var cf = turnById(d.clearFilters); if (cf) { clearC(cf, false); refresh(cf); } return; }
    if (d.retry) {
      var rt = S.turns.filter(function (x) { return x.id === d.retry; })[0];
      S.turns = S.turns.filter(function (x) { return x !== rt; });
      if (rt.kind === 'answer') { askTurn(rt.q, null, !!rt.ctx); } else if (rt.kind === 'results') { searchTurn(rt.q); } else if (rt.kind === 'similar') { similarTurn(rt.of); }
      return;
    }
    if (d.leadFlowNo) { var ln = turnById(d.leadFlowNo); if (ln && ln.leadFlow && !ln.leadFlow.busy) { leadStep(ln, { declined: true }); } return; }
    if (d.leadNo) { var lt2 = S.turns.filter(function (x) { return x.id === d.leadNo; })[0]; update(lt2, { lead: 'no' }); return; }
    if (d.tagOff) { var pr = d.tagOff.split(':'), pt = S.turns.filter(function (x) { return x.id === pr[0]; })[0]; pt.off[pr[1]] = !pt.off[pr[1]]; persist(); render(); return; }
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
    if (d.cmpViewed) { submit(LANG === 'he' ? 'מה ההבדל בין המוצר הזה לבין מה שראיתי קודם?' : 'How does this compare with what I viewed?', 'ask', true); return; }
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

  // ---------------------------------------------------------------- hooking into the store page
  function launcherHtml() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle class="ls-lens" cx="10.5" cy="10.5" r="7"/><path class="ls-handle" d="m16 16 4.5 4.5"/>' +
      '<g class="ls-halves"><path d="M7.4 7.6h6.2a3.1 3.1 0 0 1-6.2 0z" fill="currentColor"/><path d="M7.4 13.4h6.2a3.1 3.1 0 0 0-6.2 0z" fill="currentColor"/></g></svg>';
  }
  function makeLauncher(float) {
    var h = doc.createElement('span');
    h.style.cssText = float ? '' : 'display:inline-flex;align-items:center';
    var sr = h.attachShadow({ mode: 'open' });
    sr.innerHTML = styles(['tokens.css', 'drawer.css']) +
      '<style>:host{all:initial;display:inline-flex}.ls-launch{font:inherit}</style>' +
      '<button class="ls-launch' + (float ? ' ls-launch--float' : '') + '" type="button" aria-label="' + esc(t('open')) + '" aria-haspopup="dialog">' + launcherHtml() + '</button>';
    if (CFG.theme === 'store') { h.setAttribute('data-ls-theme', 'store'); }
    sr.querySelector('button').addEventListener('click', function () { open(); });
    return h;
  }
  // the theme's own search icons open the drawer instead of the theme's search bar, caught before the theme sees the click
  var SEARCH_TRIGGERS = 'a[href$="/search"],a[href*="/search?"],[aria-controls*="search" i],.header__search-link,.header__icon--search,' +
    'details-modal.header__search>summary,[data-action*="search" i],button[aria-label*="search" i],a[aria-label*="search" i],' +
    'button[aria-label*="חיפוש"],a[aria-label*="חיפוש"],.search-toggle,.js-search-toggle';
  function onSearchTrigger(e) {
    var sel = SEARCH_TRIGGERS + (CFG.openSelectors ? ',' + CFG.openSelectors : '');
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
  }
  // the store's own field opens us, carrying what was typed (again once the index names the shop's own selector)
  function hookFields() {
    doc.querySelectorAll(CFG.fieldSelector).forEach(function (input) {
      if (input.__lets) { return; }
      input.__lets = true;
      if (SHOPIFY) { quietThemeSearch(input); }
      var go = function (e) { if (e) { e.preventDefault(); } var v = input.value; input.blur(); open({ text: v }); };
      input.addEventListener('focus', go);
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { go(e); } });
      var form = input.form; if (form) { form.addEventListener('submit', function (e) { e.preventDefault(); open({ text: input.value }); }); }
    });
  }
  // the launcher: in the header when there's room, floating otherwise
  function hookLauncher() {
    if (CFG.launcher === 'none') { return; }
    var into = CFG.launcher !== 'float' && (CFG.launcherInto ? doc.querySelector(CFG.launcherInto) : (doc.querySelector('[data-search-open]') || {}).parentNode);
    if (into && CFG.launcher !== 'float') {
      var mobileToggle = into.querySelector('[data-search-open]');
      var l = makeLauncher(false);
      if (mobileToggle) { mobileToggle.style.display = 'none'; into.insertBefore(l, mobileToggle); } else { into.insertBefore(l, into.firstChild); }
    } else {
      doc.body.appendChild(makeLauncher(true));
    }
  }
  // On a product page: a search row under "add to cart". Typing in it opens the drawer, the words going with it.
  function inlineField() {
    if (!CFG.inlineInto || CFG.inlineInto === 'none' || !S.ctx) { return; }
    var at = doc.querySelector(CFG.inlineInto === 'auto' ? (SHOPIFY ? 'product-form, form[action*="/cart/add"]' : 'form.cart') : CFG.inlineInto);
    if (!at || at.__letsInline) { return; }
    at.__letsInline = true;
    var h = doc.createElement('div');
    h.className = 'lets-inline';
    if (CFG.theme === 'store') { h.setAttribute('data-ls-theme', 'store'); }
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
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(input.value); } });
    sr.querySelector('[data-photo]').addEventListener('click', function (e) { e.preventDefault(); open({ focus: false }); photoPick(); });
    at.parentNode.insertBefore(h, at.nextSibling);
  }
  // ---------------------------------------------------------------- size help on a product page (as before)
  // A size gone in the colour chosen is marked on the theme's own buttons, with a line above them; a tap on it
  // opens similar items that have that size in stock now. Only in a shop that turned it on (/sizes), in its words.
  function sizeOptionIndex(options) {
    for (var i = 0; options && i < options.length; i++) { var n = typeof options[i] === 'string' ? options[i] : (options[i] && options[i].name) || ''; if (/מידה|מידות|גודל|size/i.test(n)) { return i; } }
    return -1;
  }
  function sizeValue(v) { return v == null ? '' : String(v).replace(/\s+/g, ' ').trim().toLowerCase(); }
  var SIZE_WORDS = null;
  function sizeWord(key, vars) { var w = (SIZE_WORDS && SIZE_WORDS[key]) || t(key); Object.keys(vars || {}).forEach(function (k) { w = String(w).split(':' + k).join(vars[k]); }); return w; }
  function sizeHelp() {
    if (!PAGE || PAGE.type !== 'product') { return; }
    var form = doc.querySelector('form[action*="/cart/add"]') || doc.querySelector('form.variations_form') || doc.querySelector('form.cart');
    if (!form) { return; }
    var scope = form.closest('product-info, .product, [id*="MainProduct"], main, section') || doc.body, lastOpen = 0;
    var esc2 = function (v) { return win.CSS && win.CSS.escape ? win.CSS.escape(v) : String(v).replace(/["\\]/g, '\\$&'); };
    function readProduct() {
      if (SHOPIFY) {
        var handle = /\/products\/([^\/?#]+)/.exec(win.location.pathname);
        if (!handle) { return Promise.resolve(null); }
        return win.fetch(ROOT + 'products/' + handle[1] + '.js', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
          var at = j ? sizeOptionIndex(j.options) : -1;
          return at < 0 ? null : { at: 'option' + (at + 1), keys: ['option1', 'option2', 'option3'], variants: (j.variants || []).map(function (v) { return { id: String(v.id), values: { option1: v.option1, option2: v.option2, option3: v.option3 }, available: !!v.available }; }) };
        }).catch(function () { return null; });
      }
      var list = null;
      try { list = JSON.parse(form.getAttribute('data-product_variations') || 'null'); } catch (e) { list = null; }
      if (!list || !list.length) { return Promise.resolve(null); }
      var keys = Object.keys(list[0].attributes || {}), at = null;
      keys.forEach(function (k) {
        if (at) { return; }
        var select = form.querySelector('[name="' + k + '"]'), named = (select && select.getAttribute('data-attribute_name')) || k, cap = select && select.closest('tr') && select.closest('tr').querySelector('label');
        if (/מידה|מידות|גודל|size/i.test(named + ' ' + (cap ? cap.textContent : ''))) { at = k; }
      });
      return Promise.resolve(at ? { at: at, keys: keys, variants: list.map(function (v) { return { id: String(v.variation_id), values: v.attributes || {}, available: !!(v.is_in_stock && v.variation_is_active !== false && v.is_purchasable !== false) }; }) } : null);
    }
    // the other choices made now (the colour): a size gone in black but there in tan is not gone
    function chosen(product) {
      var others = {};
      if (SHOPIFY) {
        var idField = form.querySelector('[name="id"]'), current = product.variants.filter(function (v) { return idField && v.id === String(idField.value); })[0];
        if (!current) { return null; }
        product.keys.forEach(function (k) { if (k !== product.at && current.values[k] != null) { others[k] = current.values[k]; } });
        return others;
      }
      product.keys.forEach(function (k) { var field = form.querySelector('[name="' + k + '"]'); if (k !== product.at && field && field.value) { others[k] = field.value; } });
      return others;
    }
    function goneSizes(product) {
      var others = chosen(product) || {}, seen = {}, gone = [];
      product.variants.forEach(function (v) {
        var size = v.values[product.at];
        if (size == null || size === '') { return; }
        for (var k in others) { if (Object.prototype.hasOwnProperty.call(others, k) && v.values[k] !== '' && String(v.values[k]) !== String(others[k])) { return; } }
        var key = sizeValue(size);
        if (!seen[key]) { seen[key] = { value: String(size), available: false }; }
        seen[key].available = seen[key].available || v.available;
      });
      Object.keys(seen).forEach(function (k) { if (!seen[k].available) { gone.push(seen[k].value); } });
      return gone;
    }
    function sizeLabel(product, value) {
      var select = !SHOPIFY && form.querySelector('select[name="' + product.at + '"]'), option = select && select.querySelector('option[value="' + esc2(value) + '"]');
      return option ? option.textContent.trim() : value;
    }
    function swatches(value) {
      var found = [], v = esc2(value);
      scope.querySelectorAll('input[type="radio"][value="' + v + '"], [data-value="' + v + '"], [data-option-value="' + v + '"], button[value="' + v + '"]').forEach(function (node) {
        if (node.tagName === 'INPUT') {
          var tag = (node.id && scope.querySelector('label[for="' + esc2(node.id) + '"]')) || node.closest('label');
          if (tag && found.indexOf(tag) === -1) { found.push(tag); }
          node.setAttribute('data-la-size-gone', value);
          return;
        }
        if (found.indexOf(node) === -1 && !node.closest('[data-la-size-help]')) { found.push(node); }
      });
      return found;
    }
    function clear() {
      doc.querySelectorAll('[data-la-size-gone]').forEach(function (n) { n.removeAttribute('data-la-size-gone'); });
      doc.querySelectorAll('[data-la-size-badge], [data-la-size-help]').forEach(function (n) { n.parentNode.removeChild(n); });
    }
    // the line above the sizes, in our look; where the theme's sizes were not found, the gone sizes to tap in it
    function banner(product, gone, before, withChips) {
      var host = doc.createElement('div');
      host.setAttribute('data-la-size-help', '');
      host.style.margin = '0 0 10px';
      var sr = host.attachShadow({ mode: 'open' });
      sr.innerHTML = styles(['tokens.css', 'components.css']) + '<style>:host{all:initial;display:block}.b{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px 14px;border-radius:14px;background:var(--ls-bg-sunken);color:var(--ls-fg);font:500 14px/1.45 var(--ls-font)}.b .ls-mark{--s:16px}.b .ls-chips{width:100%;flex-wrap:wrap;overflow:visible;mask-image:none;-webkit-mask-image:none}</style>' +
        '<div class="ls b" dir="' + DIR + '">' + mark() + '<span>' + esc(sizeWord(withChips ? 'size_gone_pick' : 'size_gone_banner')) + '</span>' +
        (withChips ? '<div class="ls-chips">' + gone.map(function (v) { return '<button class="ls-chip" type="button" data-size="' + esc(v) + '">' + esc(sizeLabel(product, v)) + '</button>'; }).join('') + '</div>' : '') + '</div>';
      sr.addEventListener('click', function (e) { var b = e.target.closest('[data-size]'); if (b) { openSize(b.getAttribute('data-size'), b.textContent.trim()); } });
      before.parentNode.insertBefore(host, before);
    }
    function markGone(product) {
      clear();
      var gone = goneSizes(product), first = null;
      if (!gone.length) { return; }
      gone.forEach(function (value) {
        swatches(value).forEach(function (node) {
          node.setAttribute('data-la-size-gone', value);
          node.setAttribute('title', sizeWord('size_gone_badge'));
          if (win.getComputedStyle(node).position === 'static') { node.style.position = 'relative'; }
          var badge = doc.createElement('span');
          badge.setAttribute('data-la-size-badge', ''); badge.setAttribute('aria-hidden', 'true');
          badge.setAttribute('style', 'position:absolute;top:3px;inset-inline-start:3px;width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.55;pointer-events:none');
          node.appendChild(badge);
          first = first || node;
        });
      });
      if (first) { banner(product, gone, first.closest('fieldset, variant-radios, variant-selects, .variant-picker, .product-form__input, .swatch, .variations') || first.parentNode, false); }
      else { banner(product, gone, form, true); }
    }
    function openSize(value, shown) {
      if (Date.now() - lastOpen < 600) { return; }
      lastOpen = Date.now();
      open({ focus: false });
      loadIndex().then(function () { similarTurn(String(PAGE.id), { value: value, shown: shown || value }); });
    }
    // a tap on a marked size: the theme still selects it (and shows its own "sold out"); the drawer opens
    doc.addEventListener('click', function (e) {
      var tg = e.target && e.target.closest ? e.target.closest('[data-la-size-gone]') : null;
      if (tg && SIZE_WORDS) { var v = tg.getAttribute('data-la-size-gone'); openSize(v, tg.textContent.trim() || v); }
    }, true);
    readProduct().then(function (product) {
      if (!product || !goneSizes(product).length) { return; }
      json(API + '/search/' + SITE + '/sizes?locale=' + LANG).then(function (r) {
        var d = r.ok && r.body;
        if (!d || !d.enabled) { return; }
        SIZE_WORDS = d.labels || {};
        markGone(product);
        // another colour chosen: the sizes gone change with it; themes redraw the picker, so a beat later
        form.addEventListener('change', function () { setTimeout(function () { markGone(product); }, 350); });
      }).catch(function () { /* the page stays as the theme drew it */ });
    });
  }
  // the size, among what the store has in stock now: Shopify's sizes, or a WooCommerce attribute's terms
  function hasSize(id, size) {
    var item = S.products[pid(id)], want = sizeValue(size.value), shown = sizeValue(size.shown);
    if (!item || item.is_in_stock === false) { return false; }
    if (SHOPIFY) { return !!item.sizes && (item.sizes.indexOf(want) !== -1 || item.sizes.indexOf(shown) !== -1); }
    return (item.attributes || []).some(function (a) { return (a.terms || []).some(function (term) { return sizeValue(term.slug) === want || sizeValue(term.name) === shown; }); });
  }

  // keyboard shortcut: "/" or ⌘K / Ctrl+K
  doc.addEventListener('keydown', function (e) {
    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((doc.activeElement || {}).tagName || '') || (doc.activeElement && doc.activeElement.isContentEditable);
    if (((e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey)) || (e.key === '/' && !typing && !S.open)) { e.preventDefault(); open(); }
  });

  // store look: read the page's font, colour and radius; failing values fall back to ours
  function applyStoreTheme() {
    if (CFG.theme !== 'store') { return; }
    var cs = getComputedStyle(doc.body);
    // the store's own "add to cart" is its truest primary button: look for it first, in this order
    var btn = null;
    (CFG.accentSelector ? [CFG.accentSelector] : ['.single_add_to_cart_button', 'button[name="add-to-cart"]', 'form[action*="/cart/add"] [type="submit"]', '.product-form__submit', '.add_to_cart_button', '.button.alt']).some(function (sel) {
      var el = doc.querySelector(sel);
      if (el && !/rgba\(0, 0, 0, 0\)|transparent/.test(getComputedStyle(el).backgroundColor)) { btn = el; return true; }
      return false;
    });
    var bcs = btn ? getComputedStyle(btn) : null;
    function rgb(c) { var m = String(c).match(/-?\d*\.?\d+/g); if (!m) { return null; } var v = m.slice(0, 3).map(Number); if (/^color\(/.test(c)) { v = v.map(function (x) { return x * 255; }); } return v; }
    function lum(v) { v = v.map(function (x) { x /= 255; return x <= .03928 ? x / 12.92 : Math.pow((x + .055) / 1.055, 2.4); }); return .2126 * v[0] + .7152 * v[1] + .0722 * v[2]; }
    function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); }
    function hex(v) { return '#' + v.map(function (x) { return ('0' + Math.round(x).toString(16)).slice(-2); }).join(''); }
    host.style.setProperty('--store-font', cs.fontFamily);
    var fg = rgb(cs.color), bg = rgb(cs.backgroundColor);
    if (fg && bg && !/rgba\(0, 0, 0, 0\)|transparent/.test(cs.backgroundColor) && ratio(fg, bg) >= 4.5) { host.style.setProperty('--store-fg', hex(fg)); host.style.setProperty('--store-bg', hex(bg)); }
    if (bcs) {
      var a = rgb(bcs.backgroundColor), n = 0;
      if (a && !/rgba\(0, 0, 0, 0\)/.test(bcs.backgroundColor)) {
        var white = [255, 255, 255];
        while (ratio(a, white) < 4.5 && n < 20) { a = a.map(function (x) { return x * .94; }); n++; }
        if (ratio(a, white) >= 4.5) { host.style.setProperty('--store-accent', hex(a)); }
      }
      var r = parseFloat(bcs.borderRadius);
      if (!isNaN(r)) { host.style.setProperty('--store-radius', Math.min(r, 24) + 'px'); }
    }
  }

  function boot() {
    // silent (a shopper while the store is in preview): nothing is shown, the page still counts, as the
    // product page module counted it before: the page view, the store's own add to cart, the search's attribution
    if (CFG.silent) {
      if (!S.ctx) {
        var smeta = win.ShopifyAnalytics && win.ShopifyAnalytics.meta && win.ShopifyAnalytics.meta.product, scls = String(doc.body.className || ''), spost = scls.match(/(?:^|\s)postid-([0-9]+)(?:\s|$)/);
        var sfound = smeta && smeta.id ? String(smeta.id) : (spost && /(?:^|\s)single-product(?:\s|$)/.test(scls) ? spost[1] : null);
        if (sfound && CFG.pageType !== 'content') { S.ctx = sfound; }
      }
      PAGE = CFG.pageId && CFG.pageType === 'content' ? { type: 'content', id: String(CFG.pageId) } : S.ctx ? { type: 'product', id: String(pid(S.ctx)) } : null;
      keepVisitor(); watchStoreAdds(); watchFoundProduct();
      if (PAGE) { bankFor(PAGE.id); }
      return;
    }
    if (!doc.getElementById('ls-font') && !/Google Sans/.test(getComputedStyle(doc.body).fontFamily)) {
      var link = doc.createElement('link'); link.id = 'ls-font'; link.rel = 'stylesheet'; link.href = 'https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600&display=swap'; doc.head.appendChild(link);
    }
    doc.body.appendChild(host);
    applyStoreTheme();
    hook();
    inlineField();
    // the page: a product (from the config, else the store's own markers) or a content page, for its learning
    if (!S.ctx) {
      var meta = win.ShopifyAnalytics && win.ShopifyAnalytics.meta && win.ShopifyAnalytics.meta.product, cls = String(doc.body.className || ''), post = cls.match(/(?:^|\s)postid-([0-9]+)(?:\s|$)/);
      var found = meta && meta.id ? String(meta.id) : (post && /(?:^|\s)single-product(?:\s|$)/.test(cls) ? post[1] : null);
      if (found && !(CFG.pageType === 'content')) { S.ctx = found; CFG.productId = found; }
    }
    PAGE = CFG.pageId && CFG.pageType === 'content' ? { type: 'content', id: String(CFG.pageId) } : S.ctx ? { type: 'product', id: String(pid(S.ctx)) } : null;
    keepVisitor();
    watchStoreAdds();
    watchFoundProduct();
    if (PAGE) { bankFor(PAGE.id); }
    sizeHelp();
    // the shop's own way in to the list: any link to "#wishlist", or its shortcode's [data-let-agents-wishlist]
    doc.addEventListener('click', function (e) { var l = e.target && e.target.closest ? e.target.closest('a[href$="#wishlist"], [data-let-agents-wishlist]') : null; if (l) { e.preventDefault(); S.tab = 'saved'; open({ focus: false }); } }, true);
    if (win.location.hash === '#wishlist') { S.tab = 'saved'; open({ focus: false }); }
    var shortStart = function () {
      json(API + '/shortlist/' + SITE + '/config?locale=' + LANG).then(function (r) {
        SHORT = r.ok ? r.body || {} : {};
        R.classList.toggle('no-wish', SHORT.wishlist === false); R.classList.toggle('no-compare', SHORT.compare === false);
        var synced = false;
        try { synced = win.sessionStorage.getItem(WISH_KEY + '_synced') === '1'; win.sessionStorage.setItem(WISH_KEY + '_synced', '1'); } catch (e) { synced = false; }
        if (SHORT.wishlist !== false && !synced && (WISH.wish.length || WISH.phone || CFG.customer || CFG.loggedIn)) { wishSync(); }
      }).catch(function () { SHORT = {}; });
    };
    if (win.requestIdleCallback) { win.requestIdleCallback(shortStart, { timeout: 3000 }); } else { setTimeout(shortStart, 1200); }
    inlineField();
    // ?la_order=…: the order a shopper's AI wrote, completed into this store's cart
    var laOrder = String(URLQ.la_order || '').trim().slice(0, 2000);
    if (laOrder) { loadIndex().then(function () { open({ focus: false }); orderTurn(laOrder); }); }
    else if (String(URLQ.la_q || '').trim()) {
      // a shared search: kept as a turn, with the filters it was shared with, by spelling only
      var laQ = String(URLQ.la_q).trim().slice(0, 120);
      loadIndex().then(function () { S.pendingC = cFromLink(URLQ.la_f || URLQ.lsF); S.spellingNext = true; open({ focus: false }); submit(laQ, 'search'); });
    }
    else if (/^[A-Za-z0-9]{8,16}$/.test(String(URLQ.la_list || ''))) {
      loadIndex().then(function () {
        open({ focus: false });
        var turn = addTurn({ kind: 'shared', loading: true });
        json(API + '/shortlist/' + SITE + '/shared/' + encodeURIComponent(URLQ.la_list)).then(function (r) {
          var list = ((r.body && r.body.products) || []).map(function (p) { return 'p:' + pid(p.external_id || p.id); }).filter(function (x) { return rec(x); });
          return products(list.map(pid)).then(function () { update(turn, { loading: false, ids: list }); });
        }).catch(function () { update(turn, { loading: false, ids: [] }); });
      });
    }
    else if (URLQ.lsShared) { S.saved = URLQ.lsShared.split(',').filter(Boolean); store('local', 'ls_saved', S.saved); S.tab = 'saved'; open({ focus: false }); }
    // a shared search: it opens kept, with the filters it was shared with
    else if (URLQ.lsOpen) { open({ focus: !URLQ.lsQ }); if (URLQ.lsQ) { loadIndex().then(function () { S.pendingC = URLQ.lsF ? cFromLink(URLQ.lsF) : null; submit(URLQ.lsQ, 'search'); }); } }
    else {
      // the store's own search page (?s= on WordPress, /search?q= on Shopify): its results open here, when the shop chose so
      var pq = null;
      try { var sp = new URLSearchParams(win.location.search); pq = SHOPIFY ? (/\/search\/?$/.test(win.location.pathname) ? sp.get('q') : null) : sp.get('s'); } catch (e) { pq = null; }
      pq = pq ? String(pq).trim() : '';
      if (pq && pq.length <= 120) { loadIndex().then(function () { if (S.config.resultsPage) { S.spellingNext = true; open({ focus: false }); submit(pq, 'search'); } }); }
    }
  }
  win.LetsSearch = { open: open, close: close, search: function (q) { open({ focus: false }); submit(q, 'search'); }, ask: function (q) { open({ focus: false }); submit(q, 'ask'); }, similar: function (id) { open({ focus: false }); similarTurn(String(id)); }, photo: function () { open({ focus: false }); photoPick(); }, state: S, _photoFile: photoFile, _root: root, _learn: LEARN };
  if (!win.LetAgentsSearch) {
    win.LetAgentsSearch = {
      addToCart: function (externalId, button, onAdded) { loadIndex().then(function () { addToCart(button || null, String(externalId).replace(/^p:/, ''), null, null, null, null, onAdded); }); },
      canSimilar: function () { return S.index ? S.config.similar !== false : true; },
      similar: function (item) { var id = String(item && (item.external_id || item.id) || '').replace(/^p:/, ''); if (id) { open({ focus: false }); loadIndex().then(function () { similarTurn(id); }); } },
      photo: function () { open({ focus: false }); photoPick(); },
      ask: function (question, productIds, about) { var q = String(question || '').trim(); open({ focus: !q }); if (q) { loadIndex().then(function () { submit(q, 'ask', !!about); }); } },
      search: function (query) { var q = String(query || '').trim(); open({ focus: !q }); if (q) { loadIndex().then(function () { submit(q, 'search'); }); } }
    };
  }
  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})(window, document);
