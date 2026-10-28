{{-- To the store's team: a new lead (LeadReceivedMail). --}}
@php($t = fn (string $key, array $params = []) => __('leads::mail.'.$key, $params))
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f4f3ef;font-family:Heebo,Arial,sans-serif;color:#111">
    <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:20px;padding:28px">
        <p style="margin:0 0 6px;font-size:13px;color:#666">{{ $shopName }}</p>
        <h1 style="margin:0 0 16px;font-size:20px;font-weight:600">{{ $t('title') }}</h1>

        <p style="margin:0 0 4px;font-size:13px;color:#666">{{ $t('channel.'.$channel) }}</p>
        <p style="margin:0 0 16px;font-size:18px;font-weight:600" dir="ltr">{{ $contact }}</p>

        @if ($question)
            <p style="margin:0 0 4px;font-size:13px;color:#666">{{ $t('question') }}</p>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6" dir="auto">{{ $question }}</p>
        @endif

        @foreach ($answers as $answer)
            <p style="margin:0 0 4px;font-size:13px;color:#666">{{ $answer['label'] }}</p>
            <p style="margin:0 0 12px;font-size:15px;line-height:1.6" dir="auto">{{ $answer['value'] }}</p>
        @endforeach

        @if ($pageTitle)
            <p style="margin:0 0 4px;font-size:13px;color:#666">{{ $t('page') }}</p>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6" dir="auto">@if ($pageUrl)<a href="{{ $pageUrl }}" style="color:#111">{{ $pageTitle }}</a>@else{{ $pageTitle }}@endif</p>
        @endif

        <p style="margin:0 0 20px;font-size:13px;color:#666">{{ $t('at', ['at' => $at]) }}</p>

        @if ($panelUrl)
            <a href="{{ $panelUrl }}" style="display:inline-block;padding:12px 22px;border-radius:999px;background:#111;color:#fff;text-decoration:none;font-size:15px;font-weight:500">{{ $t('open') }}</a>
        @endif
        <p style="margin:20px 0 0;font-size:12px;color:#888;line-height:1.6">{{ $t('why') }}</p>
    </div>
</body>
</html>
