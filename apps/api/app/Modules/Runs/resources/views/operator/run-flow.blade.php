@php
    $flow = $getState();
    $muted = 'font-size:12.5px;opacity:.7';
    $box = 'margin:6px 0 0;padding:10px;border-radius:10px;background:rgba(127,127,127,.08);font:12px/1.5 ui-monospace,monospace;white-space:pre-wrap;word-break:break-word;max-height:22rem;overflow:auto';
    $dots = ['start' => '#64748b', 'code' => '#0ea5e9', 'call' => '#6366f1', 'end' => '#16a34a'];
@endphp
<div style="display:grid;gap:4px">
    <div style="display:flex;flex-wrap:wrap;gap:8px;{{ $muted }}">
        <span>{{ __('runs::flow.totals', ['calls' => $flow['calls']]) }}</span>
        <span dir="ltr">· ${{ number_format($flow['cost'], 4) }}</span>
    </div>
    <ol style="list-style:none;margin:8px 0 0;padding:0;position:relative">
        @foreach ($flow['steps'] as $step)
            @php($colour = $step['kind'] === 'end' && filled($step['error'] ?? null) ? '#dc2626' : ($step['kind'] === 'call' && ! ($step['ok'] ?? true) ? '#dc2626' : $dots[$step['kind']]))
            <li style="position:relative;padding-inline-start:28px;padding-bottom:{{ $loop->last ? '0' : '18px' }}">
                @unless ($loop->last)<span style="position:absolute;inset-inline-start:7px;top:18px;bottom:0;width:2px;background:rgba(127,127,127,.25)"></span>@endunless
                <span style="position:absolute;inset-inline-start:0;top:3px;width:16px;height:16px;border-radius:50%;background:{{ $colour }};box-shadow:0 0 0 3px rgba(127,127,127,.12)"></span>
                <div style="display:flex;flex-wrap:wrap;gap:6px 10px;align-items:baseline">
                    @if ($step['at'] !== null)<span style="{{ $muted }};min-width:3.5em" dir="ltr">+{{ number_format($step['at'], 1) }}s</span>@endif
                    <strong style="font-size:14px">{{ $step['title'] }}</strong>
                    @if ($step['kind'] === 'end')<span style="{{ $muted }}">· {{ $step['status'] }}</span>@endif
                </div>
                @if ($step['kind'] === 'call')
                    <div style="display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:3px;{{ $muted }}">
                        <span dir="ltr">{{ $step['model'] }}</span>
                        <span>{{ $step['tokens'] }}</span>
                        <span>{{ __('runs::flow.seconds', ['n' => number_format($step['seconds'], 1)]) }}</span>
                        <span dir="ltr" style="font-weight:600;opacity:1">{{ $step['cost'] === null ? '—' : '$'.number_format($step['cost'], 5) }}</span>
                        @if ($step['prompt'])<span dir="ltr">{{ $step['prompt'] }}</span>@endif
                    </div>
                    @if (filled($step['error']))<div style="color:#dc2626;font-size:12.5px;margin-top:4px" dir="ltr">{{ \Illuminate\Support\Str::limit($step['error'], 300) }}</div>@endif
                    <details style="margin-top:6px">
                        <summary style="cursor:pointer;font-size:13px">{{ __('runs::flow.sent') }}</summary>
                        <pre style="{{ $box }}" dir="auto">{{ $step['sent'] }}</pre>
                    </details>
                    <details style="margin-top:4px">
                        <summary style="cursor:pointer;font-size:13px">{{ __('runs::process.received') }}</summary>
                        <pre style="{{ $box }}" dir="auto">{{ $step['received'] ?? '—' }}</pre>
                    </details>
                    <details style="margin-top:4px">
                        <summary style="cursor:pointer;font-size:13px">{{ __('runs::process.prompt') }}</summary>
                        <pre style="{{ $box }}" dir="ltr">{{ $step['system'] ?? '—' }}</pre>
                    </details>
                @elseif (filled($step['text'] ?? null))
                    <div style="margin-top:3px;{{ $muted }}" dir="auto">{{ $step['text'] }}</div>
                @endif
                @if ($step['kind'] === 'end' && filled($step['error'] ?? null))
                    <div style="color:#dc2626;font-size:12.5px;margin-top:4px;white-space:pre-wrap" dir="ltr">{{ \Illuminate\Support\Str::limit($step['error'], 600) }}</div>
                @endif
            </li>
        @endforeach
    </ol>
    @if ($flow['more'])<div style="{{ $muted }};margin-top:8px">{{ __('runs::flow.more', ['n' => \App\Modules\Runs\Support\RunFlow::CALLS]) }}</div>@endif
</div>
