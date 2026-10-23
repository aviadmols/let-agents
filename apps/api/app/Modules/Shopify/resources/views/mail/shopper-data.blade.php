{{-- To a shop's owners: a shopper asked for what the store keeps about them, and where to download it (ShopperDataRequestMail). --}}
@php($t = fn (string $key, array $params = []) => __('shopify::privacy.mail.'.$key, $params))
@php($date = $asked->locale(app()->getLocale())->translatedFormat('j.n.Y'))
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f4f3ef;font-family:Heebo,Arial,sans-serif;color:#111">
    <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:20px;padding:28px">
        <p style="margin:0 0 6px;font-size:13px;color:#666">Lets</p>
        <h1 style="margin:0 0 12px;font-size:20px;font-weight:600">{{ $t('title', ['shop' => $shopName]) }}</h1>
        <p style="margin:0 0 20px;font-size:15px;line-height:1.6">{{ $t('lead', ['shop' => $shopName, 'date' => $date]) }}</p>
        <p style="margin:0 0 20px"><a href="{{ $link }}" style="display:inline-block;padding:12px 22px;border-radius:999px;background:#111;color:#fff;text-decoration:none;font-weight:500">{{ $t('button') }}</a></p>
        <p style="margin:0;font-size:13px;color:#666;line-height:1.6">{{ $t('note') }}</p>
    </div>
</body>
</html>
