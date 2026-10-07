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
  main{max-width:1000px;margin:0 auto;padding:20px 16px;display:grid;grid-template-columns:minmax(0,240px) minmax(0,1fr);gap:24px;align-items:start}
  @media (max-width:520px){main{grid-template-columns:1fr}.pic{max-width:160px}}
  .pic{width:100%;aspect-ratio:1;max-width:240px;border-radius:12px;background:#f1f1f3 center/cover no-repeat}
  h1{font-size:24px;line-height:1.25;margin:0 0 8px}
  .price{font-size:20px;font-weight:600;margin:0 0 16px}
  .cart{display:inline-block;padding:11px 22px;border-radius:6px;background:#1f2328;color:#fff;font-weight:600}
  .empty{padding:40px 16px;text-align:center;color:#777}
</style>
</head>
<body>
  <div class="bar">
    <a href="?type=product&locale={{ $locale }}" @if ($type === 'product') aria-current="true" @endif>{{ __('widget::preview.product_page') }}</a>
    <a href="?type=content&locale={{ $locale }}" @if ($type === 'content') aria-current="true" @endif>{{ __('widget::preview.guide_page') }}</a>
    @if ($samples->count() > 1)
      <label>
        <span>{{ __('widget::preview.sample') }}</span>
        <select onchange="location.search='?type={{ $type }}&locale={{ $locale }}&id='+encodeURIComponent(this.value)">
          @foreach ($samples as $sample)
            <option value="{{ $sample->external_id }}" @selected($page && $sample->external_id === $page->external_id)>{{ \Illuminate\Support\Str::limit($sample->title, 60) }}</option>
          @endforeach
        </select>
      </label>
    @endif
    <span class="note">{{ __('widget::preview.note') }}</span>
  </div>

  @if ($page === null)
    <p class="empty">{{ __('widget::preview.no_pages') }}</p>
  @elseif (! ($bank['enabled'] ?? false))
    <p class="empty">{{ __('widget::preview.off') }}</p>
  @else
    <main>
      <div class="pic" @if ($page->image_url) style="background-image:url('{{ e($page->image_url) }}')" @endif></div>
      <div>
        <h1>{{ $page->title }}</h1>
        @if ($type === 'product' && $page->price)
          <p class="price">{{ number_format((float) $page->price, 2) }} {{ $page->currency === 'ILS' ? '₪' : $page->currency }}</p>
          <span class="cart">{{ __('widget::preview.add_to_cart') }}</span>
        @endif
        <div id="let-agents-preview-slot"></div>
      </div>
    </main>
    <script>
      (function () {
        // The page bank the storefront would get, placed in this page instead of the store's.
        var bank = @json($bank);
        bank.placement = { selector: '#let-agents-preview-slot', position: 'append', floating: false };
        var ok = function (body) { return Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })); };
        // Nothing leaves the preview: no counted event, no model, no remembered visitor.
        window.fetch = function (url) {
          url = String(url);
          if (url.indexOf('/page?') !== -1) return ok(bank);
          if (url.indexOf('/questions') !== -1) return ok({ data: { enabled: true, suggested: bank.suggested || [], recent: [] } });
          if (url.indexOf('/ask') !== -1) return ok({ data: { outcome: 'answered', answer: @json(__('widget::preview.ask_answer', [], $locale)), from: 'none' } });
          if (url.indexOf('/search/') !== -1) return ok({ groups: { product: [], content: [] } });
          if (url.indexOf('/recent') !== -1) return ok({ data: null });
          return ok({});
        };
        if (navigator.sendBeacon) { navigator.sendBeacon = function () { return true; }; }
        window.LetAgentsContext = { site: 'preview', api: @json($api), page: { type: @json($type), id: @json((string) $page->external_id) }, locale: @json($locale), storeApi: null };
      })();
    </script>
    <script src="{{ $script }}" defer></script>
  @endif
</body>
</html>
