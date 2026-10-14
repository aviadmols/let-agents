@php($muted = 'opacity:.65;font-size:12px')
@php($pre = 'white-space:pre-wrap;word-break:break-word;font-size:12px;background:rgba(127,127,127,.08);padding:8px;border-radius:6px;max-height:420px;overflow:auto')
@php($colors = ['passed' => 'success', 'done' => 'success', 'refused' => 'warning', 'failed' => 'danger'])
@foreach ($steps as $step)
    <details style="border:1px solid rgba(127,127,127,.25);border-radius:8px;padding:8px 10px;margin-bottom:8px" data-try-on-step="{{ $step['step'] }}">
        <summary style="cursor:pointer;font-size:13px">
            <strong dir="ltr">{{ $step['step'] }}</strong> #{{ $step['attempt'] }}
            <x-filament::badge size="sm" :color="$colors[$step['status']] ?? 'gray'">{{ $step['status'] }}</x-filament::badge>
            <span style="{{ $muted }}" dir="ltr">{{ $step['provider'] }} {{ $step['model'] }} · {{ $step['tokens'] }} · {{ $step['seconds'] }}s · ${{ number_format($step['cost'], 5) }}</span>
        </summary>
        @if ($step['images'] !== [])
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0">
                @foreach ($step['images'] as $role => $url)
                    @if ($url)<figure style="margin:0"><img src="{{ $url }}" alt="" style="height:120px;border-radius:6px"><figcaption style="{{ $muted }}" dir="ltr">{{ $role }}</figcaption></figure>@endif
                @endforeach
            </div>
        @endif
        @if ($step['prompt'])<pre style="{{ $pre }}" dir="ltr">{{ $step['prompt'] }}</pre>@endif
        @if ($step['input'])<pre style="{{ $pre }}" dir="ltr">{{ json_encode($step['input'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>@endif
        @if ($step['output'])<pre style="{{ $pre }}" dir="ltr">{{ json_encode($step['output'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>@endif
        @if ($step['error'])<p style="color:#b91c1c;font-size:12px" dir="ltr">{{ $step['error'] }}</p>@endif
    </details>
@endforeach
