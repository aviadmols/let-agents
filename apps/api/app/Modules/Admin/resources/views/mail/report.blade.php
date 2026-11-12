{{-- The store report (StoreReportMail, StoreReport): Tomer's design, emails/weekly-report.html (12.11). Tables, inline
     styles, no SVG or background images; lime only on ink. Hebrew runs right to left. Empty sections are left out. --}}
@php($r = $report)
@php($l = $r['locale'])
@php($f = $r['frequency'])
@php($m = fn (string $key, array $params = []) => __('admin::report.mail.'.$key, $params, $l))
@php($rtl = $l === 'he')
@php($start = $rtl ? 'right' : 'left')
@php($end = $rtl ? 'left' : 'right')
@php($font = "'Google Sans','Helvetica Neue',Helvetica,Arial,sans-serif")
@php($label = 'margin:0 0 6px;font-size:12px;font-weight:500;letter-spacing:1.4px;text-transform:uppercase;color:#5f5f67;')
@php($change = function (?array $c, bool $pts = false) use ($m): string {
    if ($c === null || $c['dir'] === 0) {
        return '';
    }
    $text = $pts ? $m('pts', ['n' => $c['pts']]) : $c['pct'].'%';

    return $c['dir'] > 0
        ? '<div style="margin-top:6px;font-size:11.5px;font-weight:500;color:#e2ff78;">&#9650; '.e($text).'</div>'
        : '<div style="margin-top:6px;font-size:11.5px;font-weight:500;color:#8a8a94;">&#9660; '.e($text).'</div>';
})
<div dir="{{ $rtl ? 'rtl' : 'ltr' }}" style="margin:0;padding:32px 12px;background:#f6f6f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;margin:0 auto;border-collapse:separate;font-family:{{ $font }};color:#0b0b0c;text-align:{{ $start }};">

<!-- head -->
<tr><td style="background:#0b0b0c;border-radius:22px 22px 0 0;padding:28px 32px 34px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
<td align="{{ $start }}"><img src="https://lets.co.il/wp-content/themes/lets/assets/img/email/logo@2x.png" width="83" height="32" alt="Let's" style="display:block;border:0;width:83px;height:32px;"></td>
<td align="{{ $end }}" style="font-size:12px;letter-spacing:1.2px;color:#a9a9b2;" dir="ltr">{{ $r['range'] }}</td>
</tr></table>
<p style="margin:34px 0 0;font-size:12px;font-weight:500;letter-spacing:1.4px;text-transform:uppercase;color:#a9a9b2;">{{ $m('kicker.'.$f, ['shop' => $r['shop']]) }}</p>
<h1 style="margin:10px 0 0;font-size:30px;line-height:1.18;font-weight:500;letter-spacing:-.8px;color:#ffffff;"><span style="color:#e2ff78;">{{ number_format($r['searches']) }}</span> {{ $m('searched') }}@if ($r['questions'] > 0) <span style="color:#e2ff78;">{{ number_format($r['questions']) }}</span> {{ $m('asked') }}@endif</h1>
<p style="margin:14px 0 0;font-size:15px;line-height:1.55;color:#a9a9b2;">{{ $m('lede') }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#1d1d21;border-radius:16px;margin:24px 0 0;"><tr>
<td width="33%" valign="top" style="padding:16px 6px 16px 16px;"><div style="font-size:21px;font-weight:500;letter-spacing:-.5px;color:#ffffff;">{{ number_format($r['searches']) }}</div><div style="margin-top:3px;font-size:11.5px;line-height:1.35;color:#a9a9b2;">{{ $m('stat.searches') }}</div>{!! $change($r['changes']['searches']) !!}</td>
<td width="33%" valign="top" style="padding:16px 6px;"><div style="font-size:21px;font-weight:500;letter-spacing:-.5px;color:#ffffff;">{{ number_format($r['questions']) }}</div><div style="margin-top:3px;font-size:11.5px;line-height:1.35;color:#a9a9b2;">{{ $m('stat.questions') }}</div>{!! $change($r['changes']['questions']) !!}</td>
<td width="33%" valign="top" style="padding:16px 16px 16px 6px;"><div style="font-size:21px;font-weight:500;letter-spacing:-.5px;color:#ffffff;">{{ $r['found_pct'] }}%</div><div style="margin-top:3px;font-size:11.5px;line-height:1.35;color:#a9a9b2;">{{ $m('stat.found') }}</div>{!! $change($r['changes']['found'], true) !!}</td>
</tr></table>
</td></tr>

@if ($r['top'] !== [])
<!-- searched -->
<tr><td style="background:#ffffff;padding:30px 32px 6px;">
<p style="{{ $label }}">{{ $m('top') }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
@foreach ($r['top'] as $i => $t)
@php($line = $i < count($r['top']) - 1 ? 'border-bottom:1px solid #e2e2e6;' : '')
<tr><td align="{{ $start }}" style="padding:12px 0;{{ $line }}font-size:15px;font-weight:500;" dir="auto">{{ $t['query'] }}@if ($t['found'])<br><span style="font-size:12.5px;font-weight:400;color:#5f5f67;">{{ $m('top_found', ['title' => $t['found']]) }}</span>@endif</td><td align="{{ $end }}" style="padding:12px 0;{{ $line }}font-size:15px;white-space:nowrap;">{{ number_format($t['searches']) }}</td></tr>
@endforeach
</table>
@if ($r['rising'])
@php($q = '“'.$r['rising']['query'].'”')
@php($said = $r['rising']['times'] === null ? $m('rising_new', ['query' => $q, 'n' => $r['rising']['searches']]) : $m('rising_times.'.$f, ['query' => $q, 'n' => $r['rising']['times']]))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0b0b0c;border-radius:16px;margin:14px 0 0;"><tr><td style="padding:16px 20px;font-size:14px;line-height:1.5;color:#a9a9b2;"><span style="color:#e2ff78;font-weight:500;">&#8599; {{ $m('rising.'.$f) }}</span> {!! str_replace(e($q), '<b style="color:#ffffff;font-weight:500;">'.e($q).'</b>', e($said)) !!}</td></tr></table>
@endif
</td></tr>
@endif

@if ($r['missing'] !== [])
<!-- not found -->
<tr><td style="background:#ffffff;padding:26px 32px 6px;">
<p style="{{ $label }}">{{ $m('missing') }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
@foreach ($r['missing'] as $i => $x)
@php($line = $i < count($r['missing']) - 1 ? 'border-bottom:1px solid #e2e2e6;' : '')
<tr><td align="{{ $start }}" style="padding:12px 0;{{ $line }}font-size:15px;line-height:1.45;" dir="auto"><b style="font-weight:500;">{{ $x['title'] }}</b>@if ($x['text'] !== '')<br><span style="font-size:13px;color:#5f5f67;">{{ $x['text'] }}</span>@endif<br><a href="{{ $x['url'] }}" style="font-size:13px;color:#0b0b0c;font-weight:500;text-decoration:underline;">{{ $x['action'] }} {!! $rtl ? '&larr;' : '&rarr;' !!}</a></td></tr>
@endforeach
</table>
</td></tr>
@endif

@if (($r['poor'] ?? []) !== [])
<!-- answers that did not help -->
<tr><td style="background:#ffffff;padding:26px 32px 6px;">
<p style="{{ $label }}">{{ $m('poor') }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
@foreach ($r['poor'] as $i => $p)
@php($line = $i < count($r['poor']) - 1 ? 'border-bottom:1px solid #e2e2e6;' : '')
<tr><td align="{{ $start }}" style="padding:12px 0;{{ $line }}font-size:15px;line-height:1.45;" dir="auto"><b style="font-weight:500;">“{{ $p['question'] }}”</b> <span style="color:#5f5f67;">· {{ trans_choice('admin::report.mail.poor_downs', $p['downs'], ['n' => $p['downs']], $l) }}</span>@if ($p['answer'] !== '')<br><span style="font-size:13px;color:#5f5f67;">{{ $m('poor_said') }} “{{ \Illuminate\Support\Str::limit($p['answer'], 160) }}”</span>@endif</td></tr>
@endforeach
</table>
<p style="margin:10px 0 0;font-size:13.5px;line-height:1.6;color:#3a3a40;">{{ $m('poor_advice') }}@if ($r['urls']['answers'])<br><a href="{{ $r['urls']['answers'] }}" style="color:#0b0b0c;font-weight:500;text-decoration:underline;">{{ $m('poor_edit') }} {!! $rtl ? '&larr;' : '&rarr;' !!}</a>@endif @if ($r['urls']['knowledge'])&nbsp;&nbsp;<a href="{{ $r['urls']['knowledge'] }}" style="color:#0b0b0c;font-weight:500;text-decoration:underline;">{{ $m('poor_add') }} {!! $rtl ? '&larr;' : '&rarr;' !!}</a>@endif</p>
</td></tr>
@endif

@if ($r['sold'] !== [])
<!-- products -->
<tr><td style="background:#ffffff;padding:26px 32px 6px;">
<p style="{{ $label }}">{{ $m('sold') }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
@foreach ($r['sold'] as $i => $s)
@php($line = $i < count($r['sold']) - 1 ? 'border-bottom:1px solid #e2e2e6;' : '')
<tr><td align="{{ $start }}" style="padding:11px 0;{{ $line }}font-size:15px;font-weight:500;" dir="auto"><span style="color:#5f5f67;font-weight:400;">{{ $i + 1 }}&nbsp;&nbsp;</span>{{ $s['title'] }}</td><td align="{{ $end }}" style="padding:11px 0;{{ $line }}font-size:13px;white-space:nowrap;"><b style="color:#0b0b0c;font-weight:500;">{{ $m('to_cart', ['n' => number_format($s['adds'])]) }}</b></td></tr>
@endforeach
</table>
</td></tr>
@endif

<!-- numbers -->
<tr><td style="background:#ffffff;padding:26px 32px 30px;">
@if ($r['money'] !== null || $r['adds'] > 0)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0b0b0c;border-radius:16px;"><tr>
@if ($r['money'] !== null)
<td valign="top" style="padding:20px 8px 20px 20px;"><div style="font-size:24px;font-weight:500;letter-spacing:-.6px;color:#e2ff78;" dir="ltr">{{ $r['money'] }}</div><div style="margin-top:4px;font-size:12px;line-height:1.4;color:#a9a9b2;">{{ $m('money') }}</div></td>
@endif
<td valign="top" style="padding:20px 8px;"><div style="font-size:24px;font-weight:500;letter-spacing:-.6px;color:#ffffff;">{{ number_format($r['adds']) }}</div><div style="margin-top:4px;font-size:12px;line-height:1.4;color:#a9a9b2;">{{ $m('adds') }}</div></td>
@if ($r['orders'] > 0)
<td valign="top" style="padding:20px 20px 20px 8px;"><div style="font-size:24px;font-weight:500;letter-spacing:-.6px;color:#ffffff;">{{ number_format($r['orders']) }}</div><div style="margin-top:4px;font-size:12px;line-height:1.4;color:#a9a9b2;">{{ $m('orders') }}</div></td>
@endif
</tr></table>
@endif
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;"><tr><td style="background:#0b0b0c;border-radius:999px;"><a href="{{ $r['urls']['panel'] }}" style="display:inline-block;padding:14px 26px;font-size:15px;font-weight:500;color:#e2ff78;text-decoration:none;border-radius:999px;">{{ $m('button.'.$f) }} {!! $rtl ? '&larr;' : '&rarr;' !!}</a></td></tr></table>
</td></tr>

<!-- foot -->
<tr><td style="background:#0b0b0c;border-radius:0 0 22px 22px;padding:22px 32px;font-size:13px;line-height:1.6;color:#a9a9b2;">{{ $m('every.'.$f) }} <a href="{{ $r['urls']['settings'] }}" style="color:#e2ff78;text-decoration:none;">{{ $m('change') }}</a><br>{{ $m('questions') }} <a href="mailto:hey@lets.co.il" style="color:#e2ff78;text-decoration:none;">hey@lets.co.il</a><br><span style="font-size:12px;color:#8a8a94;">{{ $m('fine') }}</span></td></tr>
</table>
</div>
