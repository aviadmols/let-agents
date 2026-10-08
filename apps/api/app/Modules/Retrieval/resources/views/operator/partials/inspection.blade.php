{{-- One picture's vector, readable, and the shop's pictures nearest to it. --}}
@php($small = 'font-size:12px;opacity:.7')
<div style="display:grid;gap:12px;font-size:13px">
    <div style="display:flex;flex-wrap:wrap;gap:16px">
        <span>{{ __('retrieval::ui.scans.inspect.model') }}: <code dir="ltr">{{ $look['model'] }}</code></span>
        <span>{{ __('retrieval::ui.scans.inspect.dimensions') }}: <strong>{{ $look['dimensions'] }}</strong></span>
        <span>{{ __('retrieval::ui.scans.inspect.norm') }}: <strong dir="ltr">{{ $look['norm'] }}</strong></span>
        <span>{{ __('retrieval::ui.scans.inspect.range') }}: <strong dir="ltr">{{ $look['min'] }} … {{ $look['max'] }}</strong></span>
    </div>
    <div>
        <div style="{{ $small }}">{{ __('retrieval::ui.scans.inspect.strip') }}</div>
        <div dir="ltr" style="display:flex;gap:1px;height:28px;align-items:stretch;margin-top:4px" data-vector-strip>
            @foreach ($look['strip'] as $cellValue)
                <span title="{{ $cellValue }}" style="flex:1;background:{{ $cellValue >= 0 ? 'rgba(52,120,235,'.abs($cellValue).')' : 'rgba(235,104,52,'.abs($cellValue).')' }}"></span>
            @endforeach
        </div>
    </div>
    <div>
        <div style="{{ $small }}">{{ __('retrieval::ui.scans.inspect.head') }}</div>
        <code dir="ltr" style="font-size:12px;word-break:break-all">[{{ implode(', ', $look['head']) }}, …]</code>
    </div>
    <div>
        <div style="{{ $small }}">{{ __('retrieval::ui.scans.inspect.nearest') }}</div>
        @if ($look['nearest'] === [])
            <span style="{{ $small }}">{{ __('retrieval::ui.scans.inspect.none') }}</span>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;margin-top:6px">
                @foreach ($look['nearest'] as $near)
                    <div style="display:grid;gap:4px;justify-items:center;text-align:center" data-nearest="{{ $near['external_id'] }}">
                        <img src="{{ $near['image_url'] }}" alt="" loading="lazy" style="width:88px;height:88px;object-fit:cover;border-radius:8px;background:rgba(127,127,127,.12)">
                        <strong>{{ (int) round($near['similarity'] * 100) }}%</strong>
                        <span style="font-size:12px;line-height:1.3">{{ \Illuminate\Support\Str::limit($near['title'], 50) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
