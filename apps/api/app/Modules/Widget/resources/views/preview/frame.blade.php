<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'he' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('widget::preview.title') }}</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Rubik,Arial,sans-serif;color:#1f2328;background:#fff}
  .bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px 16px;background:#f6f6f7;border-bottom:1px solid #e6e6e9;font-size:13px;color:#555}
  .bar select{font:inherit;padding:5px 8px;border:1px solid #d6d6db;border-radius:8px;background:#fff;max-width:100%}
  .bar a{color:inherit;text-decoration:none;padding:4px 10px;border-radius:999px;border:1px solid #d6d6db;background:#fff}
  .bar a[aria-current="true"]{background:#111;color:#fff;border-color:#111}
  .note{flex-basis:100%;font-size:12px;color:#777}
  .store-head{display:flex;gap:12px;align-items:center;max-width:1000px;margin:0 auto;padding:14px 16px;border-bottom:1px solid #eee}
  .store-head b{font-size:16px;white-space:nowrap}
  .store-head input{flex:1;min-width:0;font:inherit;padding:8px 12px;border:1px solid #d6d6db;border-radius:8px}
  main{max-width:1000px;margin:0 auto;padding:20px 16px;display:grid;grid-template-columns:minmax(0,240px) minmax(0,1fr);gap:24px;align-items:start}
  @media (max-width:520px){main{grid-template-columns:1fr}.pic{max-width:160px}}
  .pic{width:100%;aspect-ratio:1;max-width:240px;border-radius:12px;background:#f1f1f3 center/cover no-repeat}
  h1{font-size:24px;line-height:1.25;margin:0 0 8px}
  .price{font-size:20px;font-weight:600;margin:0 0 16px}
  .cart button{font:inherit;padding:11px 22px;border:0;border-radius:6px;background:#1f2328;color:#fff;font-weight:600}
  .empty{padding:40px 16px;text-align:center;color:#777}
</style>
</head>
<body>
  @php
    $query = fn (array $change): string => '?'.http_build_query(array_merge(['id' => $page?->external_id, 'locale' => $locale, 'theme' => $theme], $change));
  @endphp
  <div class="bar">
    <a href="{{ $query(['locale' => 'he']) }}" @if ($locale === 'he') aria-current="true" @endif>{{ __('widget::preview.lang_he') }}</a>
    <a href="{{ $query(['locale' => 'en']) }}" @if ($locale === 'en') aria-current="true" @endif>{{ __('widget::preview.lang_en') }}</a>
    <a href="{{ $query(['theme' => 'lets']) }}" @if ($theme === 'lets') aria-current="true" @endif>{{ __('widget::preview.theme_lets') }}</a>
    <a href="{{ $query(['theme' => 'store']) }}" @if ($theme === 'store') aria-current="true" @endif>{{ __('widget::preview.theme_store') }}</a>
    @if ($samples->count() > 1)
      <label>
        <span>{{ __('widget::preview.sample') }}</span>
        <select onchange="var q = new URLSearchParams(location.search); q.set('id', this.value); location.search = q.toString()">
          @foreach ($samples as $sample)
            <option value="{{ $sample->external_id }}" @selected($page && $sample->external_id === $page->external_id)>{{ \Illuminate\Support\Str::limit($sample->title, 60) }}</option>
          @endforeach
        </select>
      </label>
    @endif
    <span class="note">{{ __('widget::preview.note') }}@unless ($on) {{ __('widget::preview.off') }}@endunless</span>
  </div>

  @if ($site === null)
    <p class="empty">{{ __('widget::preview.no_connection') }}</p>
  @elseif ($page === null)
    <p class="empty">{{ __('widget::preview.no_pages') }}</p>
  @else
    <header class="store-head">
      <b>{{ $shop->name }}</b>
      <input type="search" name="s" placeholder="{{ __('widget::preview.search') }}" aria-label="{{ __('widget::preview.search') }}">
    </header>
    <main>
      <div class="pic" @if ($page->image_url) style="background-image:url('{{ e($page->image_url) }}')" @endif></div>
      <div>
        <h1>{{ $page->title }}</h1>
        @if ($page->price)
          <p class="price">{{ number_format((float) $page->price, 2) }} {{ $page->currency === 'ILS' ? '₪' : $page->currency }}</p>
        @endif
        {{-- The store's add-to-cart form: the drawer puts its question row under it, as on the store. --}}
        <form class="cart" action="#" onsubmit="return false"><button type="button">{{ __('widget::preview.add_to_cart') }}</button></form>
      </div>
    </main>
    <script>
      (function () {
        var api = @json($api), key = @json($previewKey), real = window.fetch.bind(window);
        var ok = function (body) { return Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })); };
        // What the drawer reads comes from the shop's real information; what a shopper would send stays
        // here: no counted event or search, no model, no remembered visitor, no lead.
        var reads = [/\/widget\/[^/]+\/page\?/, /\/search\/[^/]+\/(index|similar|sizes)\?/, /\/shortlist\/[^/]+\/(config|compare)\?/];
        window.fetch = function (input, opts) {
          var url = String(input && input.url ? input.url : input), method = String((opts && opts.method) || 'GET').toUpperCase();
          if (method === 'GET' && url.indexOf(api + '/') === 0) {
            // The team's own look: the bank says so, and nothing about it is counted as a shopper's.
            if (/\/widget\/[^/]+\/page\?/.test(url) && url.indexOf('&preview=') === -1) { url += '&preview=' + encodeURIComponent(key); }
            if (reads.some(function (r) { return r.test(url); })) { return real(url, opts); }
            // A search by spelling only, never counted and never turned into a vector.
            if (/\/search\/[^/?]+\?q=/.test(url)) { return real(url.replace(/&counted=1|&spelling=1/g, '') + '&typing=1', opts); }
          }
          if (/\/(widget|search)\/[^/]+\/ask$/.test(url)) { return ok({ data: { outcome: 'answered', answer: @json(__('widget::preview.ask_answer', [], $locale)), from: 'none' } }); }
          if (/\/widget\/[^/]+\/lead$/.test(url)) { return ok({ data: { state: 'declined' } }); }
          if (/\/(recent|searched)$/.test(url)) { return ok({ data: null }); }
          return ok({});
        };
        if (navigator.sendBeacon) { navigator.sendBeacon = function () { return true; }; }
        window.LetsSearchConfig = {
          site: @json($site), api: api, preview: true, productId: @json((string) $page->external_id),
          locale: @json($locale), theme: @json($theme), launcher: 'float'
        };
      })();
    </script>
    <script src="{{ $script }}" defer></script>
  @endif
</body>
</html>
