{{-- The Lets email frame (Search Subscriptions, 18.11): a 560px column on #f6f6f7, the ink head with the store's name
     (or its logo) and one lime word, the white body, the ink foot. Tables and inline styles only: no SVG, no background
     image. Always left to right, whatever the store's language: a Hebrew search sits inside it, aligned left. --}}
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $store }}</title>
</head>
<body style="margin: 0; padding: 0; background: #f6f6f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color: #0b0b0c;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background: #f6f6f7;"><tr><td style="padding: 24px 12px;" dir="ltr" align="left">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 560px; margin: 0 auto; border-collapse: separate;">
<tr><td style="background: #0b0b0c; border-radius: 22px 22px 0 0; padding: 28px 32px 34px; text-align: left;">
@if ($logo !== '')
<img src="{{ $logo }}" alt="{{ $store }}" height="28" style="display: block; height: 28px; max-width: 200px; border: 0;">
@else
<p style="margin: 0; font-size: 15px; font-weight: 500; letter-spacing: 2px; text-transform: uppercase; color: #ffffff;">{{ $store }}</p>
@endif
<h1 style="margin: 34px 0 0; font-size: 34px; line-height: 1.12; font-weight: 500; letter-spacing: -1px; color: #ffffff;">{!! $headline !!}</h1>
<p style="margin: 12px 0 0; font-size: 16px; line-height: 1.55; color: #a9a9b2;">{{ $lead }}</p>
</td></tr>
{!! $body !!}
<tr><td style="background: #0b0b0c; border-radius: 0 0 22px 22px; padding: 22px 32px; font-size: 13px; line-height: 1.6; color: #a9a9b2; text-align: left;">{!! $foot !!}<br><span style="font-size: 12px; color: #8a8a94;">Sent for {{ $store }} by Let’s · Search Beyond</span></td></tr>
</table>
@isset($pixel)<img src="{{ $pixel }}" width="1" height="1" alt="" style="display: block; width: 1px; height: 1px; border: 0;">@endisset
</td></tr></table>
</body>
</html>
