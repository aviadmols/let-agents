{{-- The page a one-tap link of Search Subscriptions' emails lands on: confirmed, stopped, kept. In English, as the emails. --}}
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $store !== '' ? $store : 'Search subscriptions' }}</title>
<style>
body { margin: 0; background: #f6f6f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color: #0b0b0c; }
.card { max-width: 480px; margin: 48px auto; padding: 0 16px; }
.head { background: #0b0b0c; color: #fff; border-radius: 22px 22px 0 0; padding: 26px 28px 30px; }
.store { margin: 0; font-size: 14px; font-weight: 500; letter-spacing: 2px; text-transform: uppercase; }
h1 { margin: 26px 0 0; font-size: 30px; line-height: 1.15; font-weight: 500; letter-spacing: -.8px; }
.body { background: #fff; border-radius: 0 0 22px 22px; padding: 24px 28px 28px; font-size: 15px; line-height: 1.55; color: #3a3a40; overflow-wrap: anywhere; }
a { color: #0b0b0c; }
</style>
</head>
<body>
<div class="card">
<div class="head">@if ($store !== '')<p class="store">{{ $store }}</p>@endif<h1>{{ $title }}</h1></div>
<div class="body"><p style="margin: 0;">{{ $line }}</p>@if ($manage)<p style="margin: 16px 0 0;"><a href="{{ $manage }}">Manage your searches</a></p>@endif</div>
</div>
</body>
</html>
