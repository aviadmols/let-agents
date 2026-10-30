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
    '<symbol id="ls-i-thumb" viewBox="0 0 24 24"><path d="M7.5 10.5v9H4.8a.8.8 0 0 1-.8-.8v-7.4a.8.8 0 0 1 .8-.8z"/><path d="M7.5 10.5 11 4.2a1.9 1.9 0 0 1 3.5 1.3l-.8 3.9h4.9a2 2 0 0 1 2 2.4l-1.3 6.1a2 2 0 0 1-2 1.6H7.5"/></symbol>' +
    '<symbol id="ls-i-expand" viewBox="0 0 24 24"><path d="M14.5 4.5h5v5M9.5 19.5h-5v-5M19.5 4.5l-6 6M4.5 19.5l6-6"/></symbol>' +
    // the AI services' own marks (simple-icons, CC0), filled
    '<symbol id="ls-i-chatgpt" viewBox="0 0 24 24"><path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z"/></symbol>' +
    '<symbol id="ls-i-claude" viewBox="0 0 24 24"><path d="m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z"/></symbol>' +
    '<symbol id="ls-i-gemini" viewBox="0 0 24 24"><path d="M12 2c.4 5.3 4.7 9.6 10 10-5.3.4-9.6 4.7-10 10-.4-5.3-4.7-9.6-10-10 5.3-.4 9.6-4.7 10-10z"/></symbol>' +
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
  // the photos of this visit's photo searches, by turn, in memory only: "which of the things in it" sends one again
  var PHOTOS = {};
  var LAYER = D.querySelector('.ls-layer'), LSCRIM = D.querySelector('.ls-layer-scrim'), TOAST = D.querySelector('.ls-toast'), MENU = D.querySelector('.ls-menu');
  var TABS = D.querySelector('.ls-tabs');

  function announce(s) { LIVE.textContent = ''; setTimeout(function () { LIVE.textContent = s; }, 40); }
  function placePill() { var p = TABS.querySelector('.ls-tabs__pill'), s = TABS.querySelector('[aria-selected="true"]'); if (p && s && s.offsetWidth) { p.style.left = s.offsetLeft + 'px'; p.style.width = s.offsetWidth + 'px'; } }
  function isPhone() { return win.matchMedia('(max-width: 720px)').matches; }
  var colsTimer = null;
  win.addEventListener('resize', function () { clearTimeout(colsTimer); colsTimer = setTimeout(function () { if (S.open) { fitCols(); } }, 200); });
  // On a phone the whole layer covers exactly what can be seen: with the keyboard up that is the visual
  // viewport, from its own top. innerHeight is never mixed in: on iOS it disagrees with the fixed layer
  // once the keyboard is up, and the sheet was pushed up and cut.
  // The search field is at the sheet's top, above any keyboard: typed in, the sheet keeps its height and the keyboard
  // covers its foot, only following where the phone scrolled what is seen. Fitted to the keyboard, the whole sheet
  // shrank under the shopper's first letter (Aviad, 30.10). A field lower down (a reply) still has it fit above.
  // Both as they were before the keyboard: a site that has its page resized by the keyboard shrinks "100svh" too.
  var fullH = 0, sheetH = 0;
  function fitViewport() {
    var a = root.activeElement, typing = !!a && /^(INPUT|TEXTAREA)$/.test(a.tagName);
    var vv = win.visualViewport, phone = isPhone() && vv && typing, keep = phone && a === INPUT && fullH > 0 && sheetH > 0;
    R.style.top = phone ? vv.offsetTop + 'px' : '';
    R.style.height = keep ? fullH + 'px' : phone ? vv.height + 'px' : '';
    R.style.bottom = phone ? 'auto' : '';
    if (!phone) { fullH = R.offsetHeight || fullH; sheetH = D.offsetHeight || sheetH; }
    // otherwise the sheet keeps the small viewport's height (CSS): the same whether the toolbar is in or out
    if (phone) { D.style.setProperty('--ls-vh', (keep ? sheetH + 8 : vv.height) + 'px'); } else { D.style.removeProperty('--ls-vh'); }
  }

