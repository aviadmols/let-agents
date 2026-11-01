<x-filament-panels::page>
    @php($json = fn ($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))
    @php($cell = 'padding:6px 8px;border-top:1px solid #e4e4e7;vertical-align:top')
    @php($pre = 'white-space:pre-wrap;direction:ltr;text-align:left;font-size:12px;background:rgba(0,0,0,.04);padding:8px;border-radius:8px;max-height:320px;overflow:auto')

    <x-filament::section>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('enrichment::ui.scan_log.search') }}"
               style="width:100%;padding:8px 12px;border:1px solid #d4d4d8;border-radius:8px">
        @if ($this->matches()->isNotEmpty())
            <ul style="margin-top:8px;display:grid;gap:4px">
                @foreach ($this->matches() as $match)
                    <li>
                        <button type="button" wire:click="pick(@js($match->id))" style="text-decoration:underline;text-align:start">
                            {{ $match->title }}
                        </button>
                        <span style="opacity:.6;font-size:12px">#{{ $match->external_id }} · {{ $match->shop?->name }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    @php($log = $this->log())

    @if ($log === null)
        <p style="opacity:.7">{{ __('enrichment::ui.scan_log.pick') }}</p>
    @else
        <x-filament::section :heading="$log['product']->title" :description="'#'.$log['product']->external_id.' · '.$log['product']->shop?->name">
            <h3 style="font-weight:600;margin-bottom:6px">{{ __('enrichment::ui.scan_log.code_reading') }}</h3>
            @if ($log['reading'])
                <pre style="{{ $pre }}">{{ $json($log['reading']->reading) }}</pre>
                <p style="font-size:12px;opacity:.6;margin-top:4px">{{ $log['reading']->read_at }}</p>
            @else
                <p style="opacity:.7">{{ __('enrichment::ui.scan_log.no_reading') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('enrichment::ui.scan_log.facts')" collapsible>
            @if ($log['facts']->isEmpty())
                <p style="opacity:.7">{{ __('enrichment::ui.scan_log.none') }}</p>
            @else
                <div style="overflow-x:auto">
                    <table style="width:100%;font-size:13px;border-collapse:collapse">
                        <thead><tr style="opacity:.7;text-align:start">
                            <th style="{{ $cell }};text-align:start">{{ __('enrichment::ui.fields.created_at') }}</th>
                            <th style="{{ $cell }};text-align:start">{{ __('enrichment::ui.fields.kind') }}</th>
                            <th style="{{ $cell }};text-align:start">{{ __('enrichment::ui.fields.claim') }}</th>
                            <th style="{{ $cell }};text-align:start">{{ __('enrichment::ui.fields.origin') }}</th>
                            <th style="{{ $cell }};text-align:start">{{ __('enrichment::ui.fields.status') }}</th>
                            <th style="{{ $cell }};text-align:start">{{ __('enrichment::ui.fields.verdict') }}</th>
                            <th style="{{ $cell }};text-align:start">{{ __('enrichment::ui.fields.model') }}</th>
                            <th style="{{ $cell }};text-align:start">{{ __('enrichment::ui.fields.quote') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($log['facts'] as $fact)
                            <tr style="{{ $fact->status->value === 'superseded' ? 'opacity:.45' : '' }}">
                                <td style="{{ $cell }};white-space:nowrap">{{ $fact->created_at?->format('d/m H:i') }}</td>
                                <td style="{{ $cell }}">{{ $fact->kind->label() }}</td>
                                <td style="{{ $cell }}"><code dir="ltr">{{ $fact->key }} = {{ $fact->value_text ?? (float) $fact->value_number.' '.$fact->unit }}</code></td>
                                <td style="{{ $cell }}">{{ $fact->origin->label() }}</td>
                                <td style="{{ $cell }}">
                                    <x-filament::badge :color="$fact->status->color()">{{ $fact->status->label() }}</x-filament::badge>
                                    @if ($fact->status_reason)
                                        <div style="font-size:11px;opacity:.7" dir="ltr">{{ $fact->status_reason }}</div>
                                    @endif
                                </td>
                                <td style="{{ $cell }}">{{ $fact->review_verdict?->label() }} @if ($fact->review_tier) <span style="opacity:.6">({{ $fact->review_tier }})</span> @endif</td>
                                <td style="{{ $cell }}" dir="ltr">{{ $fact->review_model ?? $fact->model }}</td>
                                <td style="{{ $cell }};max-width:280px">{{ \Illuminate\Support\Str::limit((string) $fact->quote, 120) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('enrichment::ui.scan_log.requests')" collapsible>
            @forelse ($log['items'] as $item)
                <div style="border-top:1px solid #e4e4e7;padding:10px 0">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;font-size:13px">
                        <strong>{{ $item->batch?->task?->label() }}</strong>
                        @if ($item->batch?->review_tier)
                            <span>{{ __('enrichment::ui.fields.tier_short', ['tier' => $item->batch->review_tier]) }}</span>
                        @endif
                        <x-filament::badge>{{ __('enrichment::ui.item_statuses.'.$item->status->value) }}</x-filament::badge>
                        <code dir="ltr" style="font-size:11px;opacity:.7">{{ $item->custom_id }}</code>
                        <span style="opacity:.6" dir="ltr">{{ $item->batch?->model }} · prompt v{{ $item->batch?->prompt_version }} · {{ $item->created_at?->format('d/m H:i') }}</span>
                    </div>
                    @if ($item->problems)
                        <ul style="margin:6px 0;font-size:12px;color:#b45309" dir="ltr">
                            @foreach ($item->problems as $problem)
                                <li>{{ $problem }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:8px;margin-top:6px">
                        <details><summary style="cursor:pointer">{{ __('enrichment::ui.scan_log.request') }}</summary><pre style="{{ $pre }}">{{ $json($item->request) }}</pre></details>
                        <details><summary style="cursor:pointer">{{ __('enrichment::ui.scan_log.answer') }}</summary><pre style="{{ $pre }}">{{ $json($item->result) }}</pre></details>
                    </div>
                </div>
            @empty
                <p style="opacity:.7">{{ __('enrichment::ui.scan_log.none') }}</p>
            @endforelse
        </x-filament::section>

        @foreach (['relations_from' => 'related', 'relations_to' => 'product'] as $key => $side)
            <x-filament::section :heading="__('enrichment::ui.scan_log.'.$key)" collapsible>
                @if ($log[$key]->isEmpty())
                    <p style="opacity:.7">{{ __('enrichment::ui.scan_log.none') }}</p>
                @else
                    <table style="width:100%;font-size:13px;border-collapse:collapse">
                        @foreach ($log[$key] as $relation)
                            <tr>
                                <td style="{{ $cell }}"><x-filament::badge>{{ $relation->kind->label() }}</x-filament::badge></td>
                                <td style="{{ $cell }}">
                                    <button type="button" wire:click="pick('{{ $relation->{$side}?->id }}')" style="text-decoration:underline;text-align:start">{{ $relation->{$side}?->title }}</button>
                                </td>
                                <td style="{{ $cell }}">{{ \App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentProductRelations\EnrichmentProductRelationResource::sourceLabel($relation->source) }}</td>
                                <td style="{{ $cell }}" dir="ltr">{{ $relation->score }}</td>
                                <td style="{{ $cell }};font-size:12px" dir="ltr">{{ implode(' · ', \App\Modules\Enrichment\Filament\Operator\Resources\EnrichmentProductRelations\EnrichmentProductRelationResource::reasonLines($relation->reasons)) }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </x-filament::section>
        @endforeach

        <x-filament::section :heading="__('enrichment::ui.scan_log.quality')" collapsible collapsed>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">
                <div>
                    <h3 style="font-weight:600;margin-bottom:6px">{{ __('enrichment::ui.scan_log.problem_codes') }}</h3>
                    <table style="width:100%;font-size:13px;border-collapse:collapse">
                        @forelse ($log['quality']['problems'] as $code => $count)
                            <tr><td style="{{ $cell }}" dir="ltr">{{ $code }}</td><td style="{{ $cell }}">{{ $count }}</td></tr>
                        @empty
                            <tr><td style="{{ $cell }};opacity:.7">{{ __('enrichment::ui.scan_log.none') }}</td></tr>
                        @endforelse
                    </table>
                </div>
                <div>
                    <h3 style="font-weight:600;margin-bottom:6px">{{ __('enrichment::ui.scan_log.statuses_by_origin') }}</h3>
                    <table style="width:100%;font-size:13px;border-collapse:collapse">
                        @foreach ($log['quality']['statuses'] as $origin => $statuses)
                            <tr>
                                <td style="{{ $cell }}">{{ \App\Modules\Enrichment\Enums\FactOrigin::tryFrom($origin)?->label() ?? $origin }}</td>
                                <td style="{{ $cell }}">
                                    @foreach ($statuses as $status => $count)
                                        <span style="margin-inline-end:8px">{{ \App\Modules\Enrichment\Enums\FactStatus::tryFrom($status)?->label() ?? $status }}: {{ $count }}</span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
