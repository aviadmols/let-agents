<tr><td style="background: #ffffff; padding: 30px 32px 8px; text-align: left;">
@foreach ($searches as $i => $search)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $i > 0 ? 'margin: 26px 0 0; border-top: 1px solid #e2e2e6;' : '' }}"><tr>
<td style="{{ $i > 0 ? 'padding: 24px 0 0; ' : '' }}font-size: 12px; font-weight: 500; letter-spacing: 1.4px; text-transform: uppercase; color: #5f5f67;">Your search</td>
<td align="right" style="{{ $i > 0 ? 'padding: 24px 0 0; ' : '' }}font-size: 12px; color: #5f5f67;">{{ count($search['products']) }} new</td>
</tr></table>
<p style="text-align: left; margin: 6px 0 16px; font-size: 20px; line-height: 1.35; font-weight: 500; letter-spacing: -.3px; color: #0b0b0c; overflow-wrap: anywhere;">{{ $search['query'] }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
@foreach (array_chunk($search['products'], 2) as $r => $pair)
<tr>
@foreach ([0, 1] as $c)
@php($p = $pair[$c] ?? null)
<td width="50%" valign="top" style="padding: {{ $c === 0 ? '0 6px' : '0 0' }} {{ $r < intdiv(count($search['products']) - 1, 2) ? '18px' : '6px' }} {{ $c === 0 ? '0' : '6px' }};">
@if ($p)
<a href="{{ $p['url'] }}" style="text-decoration: none; color: #0b0b0c;">@if ($p['image'] !== '')<img src="{{ $p['image'] }}" alt="" width="242" style="display: block; width: 100%; max-width: 242px; height: 170px; object-fit: cover; border-radius: 14px; border: 0; background: #f0f0f1;">@else<div style="height: 170px; border-radius: 14px; background: #f0f0f1;"></div>@endif</a>
<p style="margin: 10px 0 2px; font-size: 14px; line-height: 1.4; color: #0b0b0c;">{{ $p['title'] }}</p>
@if ($p['price'] !== '')<p style="margin: 0 0 6px; font-size: 15px; font-weight: 500; color: #0b0b0c;">{{ $p['price'] }}</p>@endif
<a href="{{ $p['url'] }}" style="font-size: 13.5px; font-weight: 500; color: #0b0b0c;">View &rarr;</a>
@endif
</td>
@endforeach
</tr>
@endforeach
</table>
<p style="margin: 8px 0 0; font-size: 12.5px; color: #5f5f67;"><a href="{{ $search['stop'] }}" style="color: #5f5f67;">Stop this search</a></p>
@endforeach
@if ($storeUrl !== '')
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 28px 0 0;"><tr><td style="background: #0b0b0c; border-radius: 999px;"><a href="{{ $storeUrl }}" style="display: inline-block; padding: 14px 26px; font-size: 15px; font-weight: 500; color: #ffffff; text-decoration: none; border-radius: 999px;">Visit {{ $store }} &rarr;</a></td></tr></table>
@endif
</td></tr>
<tr><td style="background: #ffffff; padding: 26px 32px 30px; text-align: left;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top: 1px solid #e2e2e6;"><tr><td style="padding: 22px 0 0; font-size: 13.5px; line-height: 1.55; color: #3a3a40;"><b style="font-weight: 500; color: #0b0b0c;">Why you’re getting this:</b> you subscribed to these searches at {{ $store }}. We email once a week at most, and only when something new arrives.</td></tr></table>
</td></tr>
