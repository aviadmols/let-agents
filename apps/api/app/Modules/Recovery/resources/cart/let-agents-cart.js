/*!
 * Let Agents — email before payment.
 *
 * When the shop turns it on, pressing "proceed to checkout" first asks for the shopper's email.
 * With it, the store keeps the cart as an order waiting for payment, so the shop can come back
 * to the shopper if they do not pay. The shopper may always go on without it; nothing blocks the
 * way to payment, and a failure on our side never stops it either.
 *
 * Asked once: a browser that left an email, or chose to go on without, is not asked again for
 * a while. The shopper's last searches in this browser go with the email, only with consent.
 */
(function (win, doc) {
  'use strict';

  var ctx = win.LetAgentsCartContext;
  if (!ctx || !ctx.site || !ctx.api || !ctx.capture || win.__letAgentsCart) {
    return;
  }
  win.__letAgentsCart = true;

  var API = String(ctx.api).replace(/\/+$/, '');
  var LOCALE = /^he/i.test(ctx.locale || doc.documentElement.lang || 'he') ? 'he' : 'en';
  var ASKED_KEY = 'let_agents_cart_asked';
  var ASK_AGAIN_DAYS = 30;
  var config = null;

  function store(kind) {
    try {
      return win[kind];
    } catch (e) {
      return null;
    }
  }

  function asked() {
    var local = store('localStorage');
    var session = store('sessionStorage');
    try {
      if (session && session.getItem(ASKED_KEY)) {
        return true;
      }
      var at = local ? Number(local.getItem(ASKED_KEY) || 0) : 0;
      return at > 0 && Date.now() - at < ASK_AGAIN_DAYS * 864e5;
    } catch (e) {
      return false;
    }
  }

  function markAsked(left) {
    try {
      if (left) {
        store('localStorage').setItem(ASKED_KEY, String(Date.now()));
      } else {
        store('sessionStorage').setItem(ASKED_KEY, '1');
      }
    } catch (e) { /* storage blocked: asked again next time */ }
  }

  function searches() {
    try {
      var list = JSON.parse(store('localStorage').getItem('let_agents_searches') || '[]');
      return Array.isArray(list) ? list.slice(-20) : [];
    } catch (e) {
      return [];
    }
  }

  function loadConfig() {
    var key = 'let_agents_cart_config_' + LOCALE;
    try {
      var cached = JSON.parse(store('sessionStorage').getItem(key) || 'null');
      if (cached && Date.now() - cached.at < 300000) {
        return Promise.resolve(cached.config);
      }
    } catch (e) { /* no cache */ }
    return win.fetch(API + '/cart/' + encodeURIComponent(ctx.site) + '/config?locale=' + LOCALE, { mode: 'cors', credentials: 'omit' })
      .then(function (response) { return response.ok ? response.json() : { on: false }; })
      .then(function (data) {
        try {
          store('sessionStorage').setItem(key, JSON.stringify({ at: Date.now(), config: data }));
        } catch (e) { /* no cache */ }
        return data;
      })
      .catch(function () { return { on: false }; });
  }

  // ---------------------------------------------------------------- which clicks go to payment

  function path(url) {
    try {
      return new URL(url, win.location.href).pathname.replace(/\/+$/, '');
    } catch (e) {
      return '';
    }
  }

  var CHECKOUT_PATH = ctx.checkoutUrl ? path(ctx.checkoutUrl) : '';

  /** The link a click goes to payment through, if it does. */
  function checkoutLink(target) {
    var link = target && target.closest ? target.closest('a[href]') : null;
    if (!link) {
      return null;
    }
    if (link.matches('.checkout-button, .wc-proceed-to-checkout a, .wc-block-cart__submit-button, .wc-block-mini-cart__footer-checkout, .widget_shopping_cart a.checkout')) {
      return link;
    }
    return CHECKOUT_PATH && path(link.href) === CHECKOUT_PATH ? link : null;
  }

  function onClick(event) {
    if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey) {
      return;
    }
    var link = checkoutLink(event.target);
    if (!link || !config || !config.on || asked() || path(win.location.href) === CHECKOUT_PATH) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    open(link.href);
  }

  // ---------------------------------------------------------------- the popup

  var CSS = [
    ':host{all:initial}',
    '.back{position:fixed;inset:0;background:rgba(0,0,0,.45);display:grid;place-items:center;z-index:2147483646;padding:16px;font-family:inherit}',
    '.box{width:min(440px,100%);background:#fff;color:#111;border-radius:16px;padding:28px 24px 22px;box-shadow:0 24px 60px rgba(0,0,0,.25);position:relative;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Heebo,Arial,sans-serif}',
    '.box[dir=rtl]{text-align:right}',
    'h2{margin:0 0 6px;font-size:22px;line-height:1.2;font-weight:700}',
    'p{margin:0 0 16px;opacity:.75}',
    'label.f{display:block;font-size:13px;opacity:.7;margin-bottom:4px}',
    'input[type=email]{box-sizing:border-box;width:100%;border:0;border-bottom:1px solid #bcbcbc;padding:10px 2px;font:inherit;font-size:16px;background:transparent;outline:none}',
    'input[type=email]:focus{border-bottom:2px solid #111}',
    '.c{display:flex;gap:8px;align-items:flex-start;margin:14px 0 6px;font-size:13px;line-height:1.45;cursor:pointer}',
    '.c input{margin-top:3px;flex:none}',
    '.err{color:#b42318;font-size:13px;min-height:18px;margin:4px 0}',
    '.go{display:block;width:100%;border:0;border-radius:999px;padding:13px 16px;font:inherit;font-weight:700;background:#111;color:#fff;cursor:pointer;margin-top:6px}',
    '.go[disabled]{opacity:.6;cursor:default}',
    '.skip{display:block;margin:12px auto 0;background:none;border:0;font:inherit;font-size:13px;text-decoration:underline;opacity:.7;cursor:pointer;color:inherit}',
    '.x{position:absolute;top:10px;inset-inline-end:12px;background:none;border:0;font-size:22px;line-height:1;cursor:pointer;color:inherit;opacity:.6}'
  ].join('');

  function el(tag, cls, text) {
    var node = doc.createElement(tag);
    if (cls) {
      node.className = cls;
    }
    if (text !== undefined && text !== null) {
      node.textContent = text;
    }
    return node;
  }

  function open(href) {
    var host = el('div');
    host.setAttribute('data-let-agents-cart', '');
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    var style = el('style');
    style.textContent = CSS;
    root.appendChild(style);

    var back = el('div', 'back');
    var box = el('form', 'box');
    box.setAttribute('dir', LOCALE === 'he' ? 'rtl' : 'ltr');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', 'la-cart-title');
    box.noValidate = true;

    var close = el('button', 'x', '×');
    close.type = 'button';
    close.setAttribute('aria-label', config.labels.close);
    var title = el('h2', null, config.title);
    title.id = 'la-cart-title';
    var label = el('label', 'f', config.labels.email);
    label.setAttribute('for', 'la-cart-email');
    var email = el('input');
    email.type = 'email';
    email.id = 'la-cart-email';
    email.autocomplete = 'email';
    email.required = true;
    email.dir = 'ltr';
    var consentRow = el('label', 'c');
    var consent = el('input');
    consent.type = 'checkbox';
    consentRow.appendChild(consent);
    consentRow.appendChild(el('span', null, config.consent));
    var error = el('div', 'err');
    error.setAttribute('role', 'alert');
    var go = el('button', 'go', config.button);
    go.type = 'submit';
    var skip = el('button', 'skip', config.skip);
    skip.type = 'button';

    [close, title, el('p', null, config.text), label, email, consentRow, error, go, skip].forEach(function (node) { box.appendChild(node); });
    back.appendChild(box);
    root.appendChild(back);
    doc.body.appendChild(host);
    setTimeout(function () { email.focus(); }, 30);

    var done = false;
    function leave(left) {
      if (done) {
        return;
      }
      done = true;
      markAsked(left);
      win.location.href = href;
    }
    function dismiss() {
      host.parentNode && host.parentNode.removeChild(host);
      doc.removeEventListener('keydown', onKey, true);
    }
    function onKey(event) {
      if (event.key === 'Escape') {
        dismiss();
      }
    }
    doc.addEventListener('keydown', onKey, true);
    close.addEventListener('click', dismiss);
    back.addEventListener('click', function (event) {
      if (event.target === back) {
        dismiss();
      }
    });
    skip.addEventListener('click', function () { leave(false); });

    box.addEventListener('submit', function (event) {
      event.preventDefault();
      var value = email.value.trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
        error.textContent = config.labels.invalid;
        email.focus();
        return;
      }
      if (!consent.checked) {
        error.textContent = config.labels.need_consent;
        consent.focus();
        return;
      }
      error.textContent = '';
      go.disabled = true;
      capture(value, config.consent).then(function () { leave(true); }, function () { leave(true); });
    });
  }

  /** Hands the email to the store, which keeps the cart as an order waiting for payment. */
  function capture(email, consentText) {
    var body = JSON.stringify({ email: email, consent: true, consent_text: consentText, searches: searches() });
    var send = function (url) {
      return win.fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.nonce || '' },
        body: body
      });
    };
    // Never hold the shopper: payment opens after at most a few seconds, whatever happened.
    var timeout = new Promise(function (resolve) { setTimeout(resolve, 4000); });
    var request = send(ctx.capture).then(function (response) {
      return response.status === 404 && ctx.captureFallback ? send(ctx.captureFallback) : response;
    });
    return Promise.race([request, timeout]);
  }

  loadConfig().then(function (data) {
    config = data && data.on ? data : null;
    if (config) {
      doc.addEventListener('click', onClick, true);
    }
  });
})(window, document);
