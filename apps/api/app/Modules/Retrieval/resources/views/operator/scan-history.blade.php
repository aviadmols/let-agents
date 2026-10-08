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
                                    <td style="{{ $cell }}"><span style="{{ $pill }};{{ $tone[$run->status->value] ?? $tone['pending'] }}">{{ __('retrieval::ui.scans.status.'.$run->status->value) }}</span></td>
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
                                    <td style="{{ $cell }};white-space:nowrap">{{ $image->embedded_at?->timezone('Asia/Jerusalem')->format('d/m H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:12px">{{ $images->links() }}</div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
