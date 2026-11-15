{{-- App Bridge: the Shopify admin's bridge to this page inside its frame. The key names which of our apps this is.
     App Bridge is the first script of the page, before any other (App Store 2.2.3). A page reached without Shopify's
     "host" in its address is sent back with it by the server before it loads (FrameInsideShopify), so nothing has to
     run before App Bridge to put it there. --}}
<meta name="shopify-api-key" content="{{ $key }}">
<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
<script>
// The browser's own fetch, which App Bridge has taken over by now: from an empty frame of this same address, whose
// fetch is the browser's (same origin, same cookies). Made when first needed, once the page has a body.
window.__letAgentsFetch = (function () {
    var own = null, bridgedAtLoad = window.fetch;
    return function (input, init) {
        if (!own) {
            try {
                var frame = document.createElement('iframe');
                frame.style.display = 'none';
                frame.setAttribute('aria-hidden', 'true');
                frame.setAttribute('tabindex', '-1');
                (document.body || document.documentElement).appendChild(frame);
                own = frame.contentWindow.fetch.bind(frame.contentWindow);
            } catch (e) {
                own = null;
            }
        }
        // the frame could not be made: App Bridge's fetch as it was at load (never the wrapper below, which calls this)
        return own ? own(input, init) : bridgedAtLoad.call(window, input, init);
    };
})();
// Shopify hands the app its "host" and "shop" on the first page only. The server keeps them and puts them back on a
// page reached without them; here they are kept as well, and carried on by every link inside the frame.
(function () {
    var params = new URLSearchParams(location.search);
    var keep = { host: params.get('host'), shop: params.get('shop') || @js($shop) };
    try {
        ['host', 'shop'].forEach(function (name) {
            if (keep[name]) { sessionStorage.setItem('shopify_' + name, keep[name]); } else { keep[name] = sessionStorage.getItem('shopify_' + name); }
        });
    } catch (e) {}
    if (!keep.host || !keep.shop) { return; }
    if (!params.get('host') || !params.get('shop')) {
        params.set('host', keep.host);
        params.set('shop', keep.shop);
        params.set('embedded', '1');
        history.replaceState(history.state, '', location.pathname + '?' + params.toString() + location.hash);
    }
    document.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (!link || link.target === '_blank' || link.hasAttribute('download')) { return; }
        var url;
        try { url = new URL(link.href, location.href); } catch (e) { return; }
        // this page's address, or the app's own (the step to Shopify's approval lives there, where App Bridge reaches the admin)
        var app = null;
        try { app = new URL(@js((string) config('app.url'))).origin; } catch (e) {}
        if ((url.origin !== location.origin && url.origin !== app) || (url.searchParams.has('host') && url.searchParams.has('shop'))) { return; }
        url.searchParams.set('host', keep.host);
        url.searchParams.set('shop', keep.shop);
        url.searchParams.set('embedded', '1');
        link.href = url.toString();
    }, true);
})();
</script>
<script>
// App Bridge makes every request of this page wait for a Shopify token, and when the token does not
// come (the panel lives on the shop's own address), every button and every save waits forever,
// silently. So Livewire's requests ask for the session token themselves, wait for it a moment at
// most, and go with it (Authorization: Bearer, checked by CheckShopifySessionToken, as Shopify asks
// of embedded apps); a token that did not come in time leaves the request as the browser sends it,
// signed in by its cookie. The rest go as App Bridge sends them.
(function () {
    var own = window.__letAgentsFetch;
    var bridged = window.fetch;
    if (!own || !bridged || own === bridged) { return; }
    var WAIT_MS = 1500;
    function sessionToken() {
        return new Promise(function (resolve) {
            var timer = setTimeout(function () { resolve(null); }, WAIT_MS);
            var done = function (token) { clearTimeout(timer); resolve(typeof token === 'string' && token ? token : null); };
            try {
                window.shopify && window.shopify.idToken ? window.shopify.idToken().then(done, function () { done(null); }) : done(null);
            } catch (e) {
                done(null);
            }
        });
    }
    window.fetch = function (input, init) {
        var url = typeof input === 'string' ? input : (input && input.url) || String(input || '');
        if (!/\/livewire[^\/]*\/update/.test(url)) { return bridged.apply(this, arguments); }
        return sessionToken().then(function (token) {
            if (!token) { return own(input, init); }
            var headers = new Headers((init && init.headers) || (input && input.headers) || {});
            headers.set('Authorization', 'Bearer ' + token);
            return own(input, Object.assign({}, init || {}, { headers: headers }));
        });
    };
})();
// An expired page (a deploy, a long pause, a session that is gone) asks to reload with confirm(),
// which the frame does not show. It goes back in through the app's own entry with a fresh session
// token from the Shopify admin, which signs the shop's manager in again and returns to this same
// screen; a plain reload of the panel's address would land on its sign-in page.
function letAgentsEnterAgain() {
    var go = function (token) {
        if (!token) { location.reload(); return; }
        var params = new URLSearchParams(location.search);
        var url = new URL(@js(rtrim((string) config('app.url'), '/').'/shopify/app') + location.pathname, location.href);
        ['host', 'shop'].forEach(function (name) {
            var value = params.get(name);
            try { value = value || sessionStorage.getItem('shopify_' + name); } catch (e) {}
            if (value) { url.searchParams.set(name, value); }
        });
        url.searchParams.set('embedded', '1');
        url.searchParams.set('id_token', token);
        location.href = url.toString();
    };
    try {
        window.shopify && window.shopify.idToken ? window.shopify.idToken().then(go, function () { go(null); }) : go(null);
    } catch (e) {
        go(null);
    }
}
document.addEventListener('livewire:init', function () {
    Livewire.hook('request', function (request) {
        request.fail(function (failed) {
            if (failed.status === 419 || failed.status === 401) {
                failed.preventDefault();
                letAgentsEnterAgain();
            }
        });
    });
});
</script>
