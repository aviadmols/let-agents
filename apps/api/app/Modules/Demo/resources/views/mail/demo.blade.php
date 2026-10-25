{{-- To whoever asked for a demo: the code ('code') or the demo's link ('ready') (DemoMail). Ink, white and lime, as lets.co.il. --}}
@php($t = fn (string $key, array $params = []) => __('demo::mail.'.$kind.'.'.$key, $params))
@php($name = $site->title ?? $site->host)
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f6f6f7;font-family:'Google Sans',Heebo,Arial,sans-serif;color:#0b0b0c">
    <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:24px;overflow:hidden">
        <div style="background:#0b0b0c;color:#fff;padding:22px 28px">
            <p style="margin:0;font-size:15px;font-weight:600;letter-spacing:-.01em">Lets</p>
        </div>
        <div style="padding:28px">
            <h1 style="margin:0 0 12px;font-size:22px;font-weight:500;letter-spacing:-.02em">{{ $t('title', ['site' => $name]) }}</h1>
            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#3a3a40">{{ $t('lead', ['site' => $name, 'date' => $site->expires_at?->locale(app()->getLocale())->translatedFormat('j.n.Y') ?? '']) }}</p>
            @if ($kind === 'code')
                <p style="margin:0 0 20px;font-size:34px;font-weight:600;letter-spacing:.3em;direction:ltr;text-align:center;background:#0b0b0c;color:#e2ff78;border-radius:16px;padding:16px 0">{{ $data['code'] ?? '' }}</p>
                <p style="margin:0;font-size:13px;color:#5f5f67;line-height:1.6">{{ $t('note') }}</p>
            @else
                <p style="margin:0 0 20px"><a href="{{ $link }}" style="display:inline-block;padding:13px 24px;border-radius:999px;background:#0b0b0c;color:#e2ff78;text-decoration:none;font-weight:600">{{ $t('button') }}</a></p>
                <p style="margin:0;font-size:13px;color:#5f5f67;line-height:1.6">{{ $t('note', ['date' => $site->expires_at?->locale(app()->getLocale())->translatedFormat('j.n.Y') ?? '']) }}</p>
            @endif
        </div>
    </div>
</body>
</html>
