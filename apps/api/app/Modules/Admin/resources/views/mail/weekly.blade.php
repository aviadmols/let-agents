{{--
    The Monday email (WeeklySummaryMail), first sketch: the week on one page in the brand's language (ink, white, lime
    only on ink), with inline styles and tables because mail clients read nothing else. RTL in Hebrew.
--}}
@php($w = $week)
@php($t = fn (string $key, array $params = []) => __('admin::weekly.'.$key, $params))
@php($rtl = app()->getLocale() === 'he')
@php($start = $rtl ? 'right' : 'left')
@php($end = $rtl ? 'left' : 'right')
@php($font = "'Google Sans', 'Heebo', Arial, sans-serif")
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $t('subject', ['shop' => $w['shop'], 'week' => $w['week']]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f6f7;color:#0b0b0c;font-family:{{ $font }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f7;">
<tr><td align="center" style="padding:28px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;">

    {{-- the brand and the week --}}
    <tr><td style="padding:0 6px 18px;text-align:{{ $start }};font-size:13px;color:#5f5f67;">
        <span style="display:inline-block;vertical-align:middle;width:18px;height:18px;">
            <svg viewBox="0 0 225 225" width="18" height="18" fill="#0b0b0c"><path d="m0,225c0-62.13,50.37-112.5,112.5-112.5s112.5,50.37,112.5,112.5H0Z"/><path d="m225,0c0,62.13-50.37,112.5-112.5,112.5S0,62.13,0,0h225Z"/></svg>
        </span>
        <b style="color:#0b0b0c;font-size:15px;vertical-align:middle;">Let’s</b>
        <span style="vertical-align:middle;"> · {{ $w['shop'] }} · {{ $w['week'] }}</span>
    </td></tr>

    {{-- the money, on ink --}}
    <tr><td style="background:#0b0b0c;border-radius:28px;padding:28px 30px;color:#ffffff;text-align:{{ $start }};">
        <p style="margin:0;font-size:13px;color:#a9a9b2;">{{ $t('money_label') }}
            @if ($w['revenue']['dir'] === 'up')
                <span style="display:inline-block;margin-inline-start:8px;padding:2px 9px;border-radius:999px;background:#e2ff78;color:#0b0b0c;font-size:12px;font-weight:600;">▲ {{ $w['revenue']['pct'] }}%</span>
            @elseif ($w['revenue']['dir'] === 'down')
                <span style="display:inline-block;margin-inline-start:8px;padding:2px 9px;border-radius:999px;border:1px solid rgba(255,255,255,.35);color:#ffffff;font-size:12px;">▼ {{ $w['revenue']['pct'] }}%</span>
            @endif
        </p>
        <p style="margin:12px 0 10px;font-size:52px;line-height:1;font-weight:500;letter-spacing:-.04em;"><bdi>{{ $w['money'] }}</bdi></p>
        <p style="margin:0;font-size:15px;color:#d9d9df;">
            {!! trans_choice('admin::weekly.orders', $w['orders'], ['n' => '<b style="color:#fff;font-weight:500;">'.number_format($w['orders']).'</b>']) !!}
            {!! trans_choice('admin::weekly.adds', $w['adds'], ['n' => '<b style="color:#fff;font-weight:500;">'.number_format($w['adds']).'</b>']) !!}
        </p>
        @if ($w['found_pct'] !== null)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;">
                <tr><td style="font-size:13px;color:#a9a9b2;">{{ trans_choice('admin::weekly.asked', $w['asked'], ['n' => number_format($w['asked'])]) }}</td>
                    <td style="font-size:14px;color:#ffffff;text-align:{{ $end }};font-weight:500;">{{ $t('found', ['pct' => $w['found_pct']]) }}</td></tr>
                <tr><td colspan="2" style="padding-top:8px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;background:rgba(255,255,255,.08);"><tr>
                        <td width="{{ $w['found_pct'] }}%" style="height:8px;background:#e2ff78;font-size:0;line-height:0;">&nbsp;</td>
                        <td style="height:8px;background:#ffb547;font-size:0;line-height:0;">&nbsp;</td>
                    </tr></table>
                </td></tr>
            </table>
        @endif
    </td></tr>

    {{-- what waits for them --}}
    <tr><td style="padding:18px 0 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:24px;">
            <tr><td style="padding:24px 24px 8px;text-align:{{ $start }};">
                <p style="margin:0;font-size:17px;font-weight:500;">{{ $t('waiting_title') }}</p>
                <p style="margin:2px 0 0;font-size:13.5px;color:#5f5f67;">{{ $w['waiting'] > 0 ? trans_choice('admin::weekly.waiting_sub', $w['waiting'], ['n' => $w['waiting']]) : $t('waiting_none') }}</p>
            </td></tr>
            @foreach ($w['fixes'] as $fix)
                <tr><td style="padding:8px 24px;text-align:{{ $start }};">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f7;border-radius:18px;"><tr><td style="padding:14px 16px;">
                        @php($chip = ['quick' => ['#e2ff78', '#0b0b0c'], 'asked' => ['#ffb547', '#0b0b0c'], 'nice' => ['#ececef', '#3a3a40'], 'bigger' => ['#0b0b0c', '#ffffff']][$fix['chip']] ?? ['#ececef', '#3a3a40'])
                        <span style="display:inline-block;padding:2px 9px;border-radius:999px;background:{{ $chip[0] }};color:{{ $chip[1] }};font-size:12px;font-weight:500;">{{ __('admin::today.chips.'.$fix['chip']) }}</span>
                        <p style="margin:6px 0 0;font-size:15px;font-weight:500;" dir="auto">{{ $fix['title'] }}</p>
                        <p style="margin:4px 0 0;font-size:13.5px;color:#5f5f67;">{{ $fix['text'] }}</p>
                    </td></tr></table>
                </td></tr>
            @endforeach
            <tr><td style="padding:12px 24px 24px;text-align:{{ $start }};">
                <a href="{{ $w['url'] }}" style="display:inline-block;padding:12px 20px;border-radius:999px;background:#0b0b0c;color:#ffffff;font-size:14px;font-weight:500;text-decoration:none;">{{ $t('open') }}</a>
            </td></tr>
        </table>
    </td></tr>

    {{-- what they searched --}}
    @if ($w['top'] !== [])
        <tr><td style="padding:18px 0 0;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:24px;">
                <tr><td colspan="3" style="padding:24px 24px 10px;text-align:{{ $start }};"><p style="margin:0;font-size:17px;font-weight:500;">{{ $t('searched_title') }}</p></td></tr>
                @foreach ($w['top'] as $i => $term)
                    <tr>
                        <td width="28" style="padding:8px 0;padding-{{ $start }}:24px;font-size:13px;color:#5f5f67;text-align:center;">{{ $i + 1 }}</td>
                        <td style="padding:8px 12px;text-align:{{ $start }};">
                            <span style="display:block;font-size:15px;font-weight:500;" dir="auto">{{ $term['query'] }}</span>
                            @if ($term['found'])<span style="display:block;font-size:12.5px;color:#5f5f67;">{{ $rtl ? '←' : '→' }} <bdi>{{ $term['found'] }}</bdi></span>@endif
                        </td>
                        <td style="padding:8px 24px;text-align:{{ $end }};font-size:14px;font-weight:500;white-space:nowrap;">{{ number_format($term['searches']) }}</td>
                    </tr>
                @endforeach
                <tr><td colspan="3" style="height:14px;font-size:0;line-height:0;">&nbsp;</td></tr>
            </table>
        </td></tr>
    @endif

    {{-- what sold --}}
    @if ($w['sold'] !== [])
        <tr><td style="padding:18px 0 0;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:24px;">
                <tr><td colspan="{{ count($w['sold']) }}" style="padding:24px 24px 12px;text-align:{{ $start }};"><p style="margin:0;font-size:17px;font-weight:500;">{{ $t('sold_title') }}</p></td></tr>
                <tr>
                    @foreach ($w['sold'] as $product)
                        <td width="{{ (int) floor(100 / count($w['sold'])) }}%" valign="top" style="padding:0 8px 24px;{{ $loop->first ? 'padding-'.$start.':24px;' : '' }}{{ $loop->last ? 'padding-'.$end.':24px;' : '' }}text-align:{{ $start }};">
                            @if ($product['picture'])<img src="{{ $product['picture'] }}" alt="" width="160" height="160" style="display:block;width:100%;max-width:160px;height:160px;object-fit:cover;border-radius:14px;">@endif
                            <p style="margin:10px 0 0;font-size:13.5px;font-weight:500;" dir="auto">{{ \Illuminate\Support\Str::limit($product['title'], 48) }}</p>
                            <p style="margin:2px 0 0;font-size:12.5px;color:#5f5f67;">{{ trans_choice('admin::weekly.to_cart', $product['adds'], ['n' => $product['adds']]) }}</p>
                        </td>
                    @endforeach
                </tr>
            </table>
        </td></tr>
    @endif

    <tr><td style="padding:22px 6px 0;text-align:{{ $start }};font-size:12px;color:#5f5f67;">{{ $t('footer') }}</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
