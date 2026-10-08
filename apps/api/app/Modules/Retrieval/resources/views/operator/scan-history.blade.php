<x-filament-panels::page>
    @php($small = 'font-size:12px;opacity:.7')
    @php($cell = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.15);vertical-align:top;text-align:start')
    @php($pill = 'display:inline-flex;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600')
    @php($tone = ['succeeded' => 'background:#e2ff78;color:#111', 'scanned' => 'background:#e2ff78;color:#111', 'failed' => 'background:rgba(235,104,52,.18)', 'running' => 'background:rgba(80,140,255,.18)', 'pending' => 'background:rgba(127,127,127,.15)'])
    @php($counts = $this->counts())
    @php($runs = $this->runs())

    <div @if ($runs->contains(fn ($run) => $run->status->value === 'running')) wire:poll.10s @endif style="display:grid;gap:16px">
        <x-filament::section :heading="__('retrieval::ui.scans.runs')" :description="__('retrieval::ui.scans.runs_about')">
            @if ($runs->isEmpty())
                <p style="{{ $small }}">{{ __('retrieval::ui.scans.no_runs') }}</p>
            @else
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:13.5px">
                        <thead>
                            <tr>
                                @foreach (['when', 'what', 'status', 'result', 'took'] as $head)
                                    <th style="{{ $cell }};{{ $small }};font-weight:600">{{ __('retrieval::ui.scans.cols.'.$head) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($runs as $run)
                                <tr data-run="{{ $run->agent }}">
                                    <td style="{{ $cell }};white-space:nowrap">{{ $run->started_at?->timezone('Asia/Jerusalem')->format('d/m H:i') }}<br><span style="{{ $small }}">{{ $run->started_at?->diffForHumans() }}</span></td>
                                    <td style="{{ $cell }}">{{ __('retrieval::ui.scans.kinds.'.str_replace('.', '_', $run->agent)) }}<br><span style="{{ $small }}">{{ __('retrieval::ui.scans.trigger.'.$run->trigger->value) }}</span></td>
                                    @php($runState = \App\Modules\Retrieval\Filament\Operator\Pages\ScanHistory::runState($run))
                                    <td style="{{ $cell }}"><span style="{{ $pill }};{{ $tone[$runState] ?? $tone['failed'] }}">{{ __('retrieval::ui.scans.status.'.$runState) }}</span></td>
                                    <td style="{{ $cell }}">
                                        {{ $run->summary() ?? '—' }}
                                        @if (is_string($run->output['stopped'] ?? null))
                                            <br><span style="{{ $small }}">⚠ {{ __('retrieval::ui.scans.stopped', ['reason' => $run->output['stopped']]) }}</span>
                                        @endif
                                        @if ($run->error)
                                            <br><span style="{{ $small }}" dir="ltr">{{ \Illuminate\Support\Str::limit($run->error, 200) }}</span>
                                        @endif
                                    </td>
                                    <td style="{{ $cell }};white-space:nowrap">{{ $run->durationForHumans() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('retrieval::ui.scans.pictures')" :description="__('retrieval::ui.scans.pictures_about', ['scanned' => number_format($counts['scanned']), 'total' => number_format($counts['total']), 'pending' => number_format($counts['pending']), 'failed' => number_format(array_sum($counts['failed']))])">
            @php($hosts = $this->hosts())
            @if (count($hosts) > 0)
                <p style="margin:0 0 8px;font-size:13px">
                    {{ __('retrieval::ui.scans.hosts') }}
                    @foreach ($hosts as $host => $n)
                        <span style="{{ $pill }};{{ $tone['pending'] }};margin-inline-end:6px" dir="ltr">{{ $host ?: '?' }} · {{ number_format($n) }}</span>
                    @endforeach
                </p>
            @endif
            @if ($counts['failed'] !== [])
                <p style="margin:0 0 12px;font-size:13px">
                    @foreach ($counts['failed'] as $reason => $n)
                        <span style="{{ $pill }};{{ $tone['failed'] }};margin-inline-end:6px">{{ __('retrieval::ui.scans.reasons.'.\App\Modules\Retrieval\Filament\Operator\Pages\ScanHistory::reason($reason)) }} · {{ number_format($n) }}</span>
                    @endforeach
                </p>
            @endif

            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px">
                @foreach (\App\Modules\Retrieval\Filament\Operator\Pages\ScanHistory::STATES as $key)
                    <x-filament::button size="sm" :color="$state === $key ? 'primary' : 'gray'" wire:click="$set('state', '{{ $key }}')">{{ __('retrieval::ui.scans.filter.'.$key) }}</x-filament::button>
                @endforeach
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('retrieval::ui.scans.search') }}" style="flex:1;min-width:180px;padding:6px 12px;border:1px solid rgba(127,127,127,.35);border-radius:10px;background:transparent;color:inherit;font:inherit">
            </div>

            @php($images = $this->images())
            @if ($images->isEmpty())
                <p style="{{ $small }}">{{ __('retrieval::ui.scans.no_pictures') }}</p>
            @else
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:13.5px">
                        <thead>
                            <tr>
                                @foreach (['picture', 'product', 'state', 'scanned_at'] as $head)
                                    <th style="{{ $cell }};{{ $small }};font-weight:600">{{ __('retrieval::ui.scans.cols.'.$head) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($images as $image)
                                @php($is = $this->stateOf($image))
                                <tr data-image="{{ $image->external_id }}">
                                    <td style="{{ $cell }};width:64px"><a href="{{ $image->image_url }}" target="_blank" rel="noopener"><img src="{{ $image->image_url }}" alt="" loading="lazy" style="width:52px;height:52px;object-fit:cover;border-radius:8px;background:rgba(127,127,127,.12)"></a></td>
                                    <td style="{{ $cell }}">{{ $image->title }}<br><span style="{{ $small }}" dir="ltr">#{{ $image->external_id }}</span></td>
                                    <td style="{{ $cell }}">
                                        <span style="{{ $pill }};{{ $tone[$is] }}">{{ __('retrieval::ui.scans.state.'.$is) }}</span>
                                        @if ($image->error)
                                            <br><span style="{{ $small }}">{{ __('retrieval::ui.scans.reasons.'.\App\Modules\Retrieval\Filament\Operator\Pages\ScanHistory::reason($image->error)) }}</span>
                                            <br><span style="{{ $small }};word-break:break-all" dir="ltr">{{ \Illuminate\Support\Str::limit($image->image_url, 90) }}</span>
                                        @endif
                                    </td>
                                    <td style="{{ $cell }};white-space:nowrap">
                                        {{ $image->embedded_at?->timezone('Asia/Jerusalem')->format('d/m H:i') ?? '—' }}
                                        @if ($is === 'scanned')
                                            <br><button type="button" wire:click="toggleInspect('{{ $image->id }}')" style="all:unset;cursor:pointer;font-size:12px;font-weight:600;text-decoration:underline" data-inspect="{{ $image->external_id }}">{{ __('retrieval::ui.scans.inspect.'.($inspect === $image->id ? 'close' : 'open')) }}</button>
                                        @endif
                                    </td>
                                </tr>
                                @if ($inspect === $image->id && ($look = $this->inspection()))
                                    <tr>
                                        <td colspan="4" style="{{ $cell }};background:rgba(127,127,127,.06)">
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
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:12px">{{ $images->links() }}</div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
