{{-- App Bridge: the Shopify admin's bridge to this page inside its frame. The key names which of our apps this is. --}}
<meta name="shopify-api-key" content="{{ $key }}">
<script>
// The browser's own fetch, before App Bridge takes it over (below).
window.__letAgentsFetch = window.fetch ? window.fetch.bind(window) : null;
// Shopify hands the app its "host" and "shop" on the first page only. App Bridge reads both from the
// address and stops without them, and with it every request the page makes (saving, buttons) waits
// forever. So both are kept, put back into this page's address before App Bridge loads, and carried
// on by every link inside the frame.
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
        if (url.origin !== location.origin || (url.searchParams.has('host') && url.searchParams.has('shop'))) { return; }
        url.searchParams.set('host', keep.host);
        url.searchParams.set('shop', keep.shop);
        url.searchParams.set('embedded', '1');
        link.href = url.toString();
    }, true);
})();
</script>
<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
<script>
// App Bridge makes every request of this page wait for a Shopify token. The panel signs in with its
// own cookie and needs none, and when the token does not come (the panel lives on the shop's own
// address), every button and every save waits forever, silently. Livewire's requests go as the
// browser sends them; the rest as App Bridge does.
(function () {
    var own = window.__letAgentsFetch;
    var bridged = window.fetch;
    if (!own || !bridged || own === bridged) { return; }
    window.fetch = function (input, init) {
        var url = typeof input === 'string' ? input : (input && input.url) || String(input || '');
        return /\/livewire[^\/]*\/update/.test(url) ? own(input, init) : bridged.apply(this, arguments);
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
