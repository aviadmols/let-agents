{{-- To the operators: a shop's plan ended, its deletion was asked or called off, or it was deleted (TeamNoticeMail). --}}
@php($t = fn (string $key, array $params = []) => __('admin::privacy.team.'.$key, $params))
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f4f3ef;font-family:Heebo,Arial,sans-serif;color:#111">
    <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:20px;padding:28px">
        <p style="margin:0 0 6px;font-size:13px;color:#666">Lets</p>
        <h1 style="margin:0 0 12px;font-size:20px;font-weight:600">{{ $t($kind, ['shop' => $shopName]) }}</h1>
        <p style="margin:0 0 8px;font-size:15px;line-height:1.6" dir="ltr">{{ $domain }}</p>
        @if ($by)<p style="margin:0 0 8px;font-size:15px;line-height:1.6">{{ $t('by', ['name' => $by]) }}</p>@endif
        @if ($due)<p style="margin:0 0 8px;font-size:15px;line-height:1.6">{{ $t('due', ['date' => $due->locale(app()->getLocale())->translatedFormat('j.n.Y')]) }}</p>@endif
        @if ($billedByHand && $kind !== \App\Modules\Admin\Mail\TeamNoticeMail::KEPT && $kind !== \App\Modules\Admin\Mail\TeamNoticeMail::DELETED)
            <p style="margin:0;font-size:15px;line-height:1.6;font-weight:500">{{ $t('by_hand') }}</p>
        @endif
    </div>
</body>
</html>
