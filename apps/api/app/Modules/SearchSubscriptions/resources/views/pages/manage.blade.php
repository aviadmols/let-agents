{{-- Manage your searches: every search of the address in this store, each with its own stop. Signed link, no login. --}}
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Your searches{{ $store !== '' ? ' · '.$store : '' }}</title>
<style>
body { margin: 0; background: #f6f6f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color: #0b0b0c; }
.card { max-width: 480px; margin: 48px auto; padding: 0 16px; }
.head { background: #0b0b0c; color: #fff; border-radius: 22px 22px 0 0; padding: 26px 28px 30px; }
.store { margin: 0; font-size: 14px; font-weight: 500; letter-spacing: 2px; text-transform: uppercase; }
h1 { margin: 26px 0 0; font-size: 30px; line-height: 1.15; font-weight: 500; letter-spacing: -.8px; }
.body { background: #fff; border-radius: 0 0 22px 22px; padding: 10px 28px 26px; }
.row { display: flex; align-items: center; gap: 12px; padding: 16px 0; border-bottom: 1px solid #e2e2e6; }
.q { flex: 1; min-width: 0; font-size: 17px; font-weight: 500; overflow-wrap: anywhere; text-align: left; }
.meta { display: block; font-size: 12.5px; font-weight: 400; color: #5f5f67; margin-top: 2px; }
.stop { flex: none; font-size: 13.5px; color: #5f5f67; }
.all { display: inline-block; margin-top: 18px; font-size: 13.5px; color: #5f5f67; }
.none { margin: 16px 0 0; font-size: 15px; color: #3a3a40; }
</style>
</head>
<body>
<div class="card">
<div class="head">@if ($store !== '')<p class="store">{{ $store }}</p>@endif<h1>Your searches</h1></div>
<div class="body">
@forelse ($searches as $s)
<div class="row"><span class="q">{{ $s['query'] }}<span class="meta">{{ $s['pending'] ? 'Waiting for you to confirm it from your email' : ($s['ends'] ? 'Until '.$s['ends'] : '') }}</span></span><a class="stop" href="{{ $s['stop'] }}">Delete</a></div>
@empty
<p class="none">You have no active searches in this store.</p>
@endforelse
@if (count($searches) > 1)<a class="all" href="{{ $stopAll }}">Unsubscribe from all</a>@endif
</div>
</div>
</body>
</html>
