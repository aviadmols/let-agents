{{-- To the team after a store's scan was checked (ScanCheckMail): what was tried, failed, mended, asked, and its cost. --}}
@php($t = fn (string $key, array $params = []) => __('search::mail.scan_check.'.$key, $params))
@php($r = $report)
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f6f6f7;font-family:'Google Sans',Heebo,Arial,sans-serif;color:#0b0b0c">
    <div style="max-width:640px;margin:0 auto;background:#fff;border-radius:24px;overflow:hidden">
        <div style="background:#0b0b0c;color:#fff;padding:22px 28px">
            <p style="margin:0;font-size:15px;font-weight:600;letter-spacing:-.01em">Lets</p>
        </div>
        <div style="padding:28px;font-size:14px;line-height:1.6;color:#3a3a40">
            <h1 style="margin:0 0 6px;font-size:22px;font-weight:500;letter-spacing:-.02em;color:#0b0b0c">{{ $t('title', ['store' => $store]) }}</h1>
            <p style="margin:0 0 18px;color:#5f5f67">{{ $domain }} · {{ $t($r['mode'] === 'apply' ? 'mode_apply' : 'mode_dry') }}</p>

            <p style="margin:0 0 18px">{{ $t('summary', ['tried' => $r['tried'], 'failed' => $r['failed'], 'cost' => number_format((float) $r['cost_usd'], 3), 'cap' => number_format((float) $r['cap_usd'], 2)]) }}</p>

            @php($failedCases = array_values(array_filter($r['cases'], fn ($c) => ! $c['passed'])))
            @if ($failedCases !== [])
                <h2 style="margin:0 0 8px;font-size:16px;font-weight:600;color:#0b0b0c">{{ $t('failed_title') }}</h2>
                <ul style="margin:0 0 18px;padding-inline-start:20px">
                    @foreach ($failedCases as $c)
                        <li dir="auto">«{{ $c['q'] }}» <span style="color:#5f5f67">· {{ $t('why.'.$c['why']) }} · {{ $t('found', ['n' => $c['total']]) }}@isset($c['after']) · {{ $c['after'] ? $t('after_ok') : $t('after_still') }}@endisset</span></li>
                    @endforeach
                </ul>
            @endif

            @if ($r['tags'] !== [] || $r['resolved'] !== [])
                <h2 style="margin:0 0 8px;font-size:16px;font-weight:600;color:#0b0b0c">{{ $t($r['mode'] === 'apply' ? 'mended_title' : 'would_title') }}</h2>
                <ul style="margin:0 0 18px;padding-inline-start:20px">
                    @foreach ($r['tags'] as $tag)
                        <li dir="auto">{{ $t('tag', ['tag' => $tag['tag'], 'n' => $tag['products']]) }}</li>
                    @endforeach
                    @foreach ($r['resolved'] as $item)
                        <li dir="auto">«{{ $item['query'] }}»: {{ $t('outcome.'.($item['outcome'] ?? 'none')) }}@if (! empty($item['shows'])) · {{ implode(', ', $item['shows']) }}@endif @if (! empty($item['synonym'])) · {{ $t('synonym', ['word' => $item['synonym']]) }}@endif</li>
                    @endforeach
                </ul>
            @endif

            @if ($r['questions'] !== [])
                <h2 style="margin:0 0 8px;font-size:16px;font-weight:600;color:#0b0b0c">{{ $t('questions_title') }}</h2>
                <ul style="margin:0 0 18px;padding-inline-start:20px">
                    @foreach ($r['questions'] as $q)
                        <li dir="auto">«{{ $q['q'] }}» <span style="color:#5f5f67">· {{ $t('answer.'.($q['outcome'] === 'answered' ? 'answered' : 'not')) }}@if ($q['picks'] !== []) · {{ implode(', ', $q['picks']) }}@endif</span></li>
                    @endforeach
                </ul>
            @endif

            <p style="margin:0"><a href="{{ $link }}" style="display:inline-block;padding:12px 22px;border-radius:999px;background:#0b0b0c;color:#e2ff78;text-decoration:none;font-weight:600">{{ $t('button') }}</a></p>
        </div>
    </div>
</body>
</html>
