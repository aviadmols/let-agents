{{-- To the team: the system's problems, most urgent first (SystemAlertMail). Ink, white and lime, as lets.co.il. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'he' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f6f6f7;font-family:'Google Sans',Heebo,Arial,sans-serif;color:#0b0b0c">
    <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:24px;overflow:hidden">
        <div style="background:#0b0b0c;color:#fff;padding:22px 28px">
            <p style="margin:0;font-size:15px;font-weight:600;letter-spacing:-.01em">Lets</p>
            <p style="margin:4px 0 0;font-size:13px;color:#b9b9c0">{{ __('alerts::mail.lead', ['at' => now()->timezone('Asia/Jerusalem')->format('j.n.Y H:i')]) }}</p>
        </div>
        <div style="padding:8px 28px 28px">
            @foreach ($problems as $problem)
                <div style="padding:20px 0;{{ $loop->last ? '' : 'border-bottom:1px solid #ececef' }}">
                    @if ($problem->urgent)
                        <p style="margin:0 0 8px"><span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#ffe4e1;color:#a3170a;font-size:12px;font-weight:600">{{ __('alerts::mail.urgent_badge') }}</span></p>
                    @endif
                    <h2 style="margin:0 0 8px;font-size:18px;font-weight:600;letter-spacing:-.01em">{{ $problem->title }}</h2>
                    <p style="margin:0 0 10px;font-size:15px;line-height:1.6;color:#3a3a40">{{ $problem->what }}</p>
                    <p style="margin:0;font-size:14px;line-height:1.6;color:#0b0b0c"><strong>{{ __('alerts::mail.next') }}</strong> {{ $problem->next }}</p>
                    @if ($problem->link)
                        <p style="margin:14px 0 0"><a href="{{ $problem->link }}" style="display:inline-block;padding:10px 20px;border-radius:999px;background:#0b0b0c;color:#e2ff78;text-decoration:none;font-weight:600;font-size:14px">{{ __('alerts::mail.button') }}</a></p>
                    @endif
                </div>
            @endforeach
            <p style="margin:8px 0 0;font-size:12px;color:#5f5f67;line-height:1.6">{{ __('alerts::mail.note') }}</p>
        </div>
    </div>
</body>
</html>
