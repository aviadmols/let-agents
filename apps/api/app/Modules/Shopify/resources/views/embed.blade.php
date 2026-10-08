{{-- App Bridge: the Shopify admin's bridge to this page inside its frame. The key names which of our apps this is. --}}
<meta name="shopify-api-key" content="{{ $key }}">
<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
<script>
// Shopify hands the app its "host" on the first page only; every link inside the frame carries it on.
(function () {
    var host = new URLSearchParams(location.search).get('host');
    try {
        if (host) { sessionStorage.setItem('shopify_host', host); } else { host = sessionStorage.getItem('shopify_host'); }
    } catch (e) {}
    if (!host) { return; }
    document.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (!link || link.target === '_blank' || link.hasAttribute('download')) { return; }
        var url;
        try { url = new URL(link.href, location.href); } catch (e) { return; }
        if (url.origin !== location.origin || url.searchParams.has('host')) { return; }
        url.searchParams.set('host', host);
        url.searchParams.set('embedded', '1');
        link.href = url.toString();
    }, true);
})();
</script>
