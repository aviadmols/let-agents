<x-filament-panels::page>
    @php($small = 'font-size:12.5px;opacity:.7')
    @php($gaps = $this->gaps())

    @php($tryOn = $this->tryOn())
    @php($required = $this->requiredGaps())
    @php($comparison = $this->lastComparison())

    <x-filament::section :heading="__('enrichment::gaps.compare_heading')" :description="__('enrichment::gaps.compare_help')" data-compare-readers>
        <x-slot name="afterHeader">
            <x-filament::button size="sm" color="gray" wire:click="compareReaders" wire:confirm="{{ __('enrichment::gaps.compare_confirm') }}">{{ __('enrichment::gaps.compare') }}</x-filament::button>
        </x-slot>
        @if ($comparison === null)
            <p style="{{ $small }}">{{ __('enrichment::gaps.compare_none') }}</p>
        @else
            <p style="font-size:14px">{{ $comparison['summary'] }}</p>
            <p style="{{ $small }}">{{ $comparison['at']?->timezone('Asia/Jerusalem')->format('d.m.Y H:i') }}</p>
            @if (isset($comparison['report']['readers']))
                <div style="overflow-x:auto;margin-top:10px">
                    <table style="width:100%;font-size:13px;border-collapse:collapse">
                        <thead>
                            <tr style="text-align:start">
                                <th style="text-align:start;padding:6px"></th>
                                @foreach ($comparison['report']['readers'] as $reader)
                                    <th style="text-align:start;padding:6px">{{ $reader['model'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (['answered', 'applied', 'rejected', 'facts', 'approved', 'review', 'products_with_facts', 'cost_usd', 'cost_per_product_usd', 'catalog_estimate_usd'] as $row)
                                <tr style="border-top:1px solid rgba(127,127,127,.15)">
                                    <td style="padding:6px">{{ __('enrichment::gaps.compare_rows.'.$row) }}</td>
                                    @foreach ($comparison['report']['readers'] as $reader)
                                        <td style="padding:6px">{{ str_ends_with($row, '_usd') ? '$'.rtrim(rtrim(number_format((float) ($reader[$row] ?? 0), 5), '0'), '.') : number_format((int) ($reader[$row] ?? 0)) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                            <tr style="border-top:1px solid rgba(127,127,127,.15)">
                                <td style="padding:6px">{{ __('enrichment::gaps.compare_rows.by_key') }}</td>
                                @foreach ($comparison['report']['readers'] as $reader)
                                    <td style="padding:6px;{{ $small }}">{{ collect($reader['by_key'] ?? [])->map(fn ($n, $k) => $k.' '.$n)->implode(', ') }}</td>
                                @endforeach
                            </tr>
                        </tbody>
                    </table>
                </div>
                @php($agreement = $comparison['report']['agreement'] ?? [])
                <p style="font-size:13.5px;margin-top:10px">{{ __('enrichment::gaps.compare_agreement', ['same' => $agreement['same'] ?? 0, 'different' => $agreement['different'] ?? 0, 'only_a' => $agreement['only_a'] ?? 0, 'only_b' => $agreement['only_b'] ?? 0]) }}</p>
                @if (($comparison['report']['differences'] ?? []) !== [])
                    <div style="display:grid;gap:2px;margin-top:6px" data-compare-differences>
                        @foreach ($comparison['report']['differences'] as $difference)
                            <div style="font-size:13px;padding:6px 0;border-top:1px solid rgba(127,127,127,.15)">
                                <span style="font-weight:600">{{ $difference['product'] }}</span> · {{ $difference['key'] }}:
                                <span>{{ $difference['a'] }}</span> / <span>{{ $difference['b'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('enrichment::gaps.tryon_heading')" :description="__('enrichment::gaps.tryon_help')">
        <p style="font-size:14px" data-tryon-summary>{{ __('enrichment::gaps.tryon_counts', ['yes' => number_format($tryOn['yes']), 'no' => number_format($tryOn['no']), 'undecided' => number_format($tryOn['undecided'])]) }}</p>
    </x-filament::section>

    @foreach ($required as $gap)
        <x-filament::section wire:key="required-{{ $gap['type'] }}-{{ $gap['attribute'] }}"
            :heading="__('enrichment::gaps.required_heading', ['type' => $gap['type_label'], 'attribute' => $gap['attribute_label'], 'importance' => __('enrichment::gaps.importance.'.$gap['importance'])])"
            :description="__('enrichment::gaps.required_share', ['have' => $gap['have'], 'of' => $gap['of'], 'missing' => count($gap['missing'])]).($gap['why'] ? ' '.$gap['why'] : '')"
            data-required-gap="{{ $gap['type'] }}.{{ $gap['attribute'] }}">
            <div style="display:grid;gap:2px">
                @foreach (array_slice($gap['missing'], 0, 50) as $product)
                    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;padding:8px 0;border-top:1px solid rgba(127,127,127,.15)">
                        @if ($product['url'])
                            <a href="{{ $product['url'] }}" target="_blank" rel="noopener" style="font-weight:600;font-size:14px">{{ $product['title'] }}</a>
                        @else
                            <span style="font-weight:600;font-size:14px">{{ $product['title'] }}</span>
                        @endif
                        @if ($product['waiting'])
                            <span style="font-size:12.5px;padding:2px 10px;border-radius:999px;background:rgba(245,158,11,.15)">{{ __('enrichment::gaps.waiting', ['value' => $product['waiting']]) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach

    @if ($gaps === [])
        <x-filament::section>
            <p style="{{ $small }}">{{ __('enrichment::gaps.none') }}</p>
        </x-filament::section>
    @endif

    @foreach ($gaps as $gap)
        <x-filament::section wire:key="gap-{{ $gap['type'] }}-{{ $gap['attribute'] }}"
            :heading="__('enrichment::gaps.heading', ['type' => $gap['type_label'], 'attribute' => $gap['attribute_label']])"
            :description="__('enrichment::gaps.share', ['have' => $gap['have'], 'of' => $gap['of'], 'missing' => count($gap['missing'])])">
            <x-slot name="afterHeader">
                <x-filament::button size="sm" color="gray" wire:click="reread(@js($gap['type']), @js($gap['attribute']))" wire:confirm="{{ __('enrichment::gaps.reread_confirm', ['count' => min(count($gap['missing']), \App\Modules\Enrichment\Actions\RereadProducts::MAX)]) }}">{{ __('enrichment::gaps.reread') }}</x-filament::button>
            </x-slot>
            <div style="display:grid;gap:2px">
                @foreach ($gap['missing'] as $product)
                    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;padding:8px 0;border-top:1px solid rgba(127,127,127,.15)" data-gap-product="{{ $product['external_id'] }}">
                        <div style="min-width:0">
                            @if ($product['url'])
                                <a href="{{ $product['url'] }}" target="_blank" rel="noopener" style="font-weight:600;font-size:14px">{{ $product['title'] }}</a>
                            @else
                                <span style="font-weight:600;font-size:14px">{{ $product['title'] }}</span>
                            @endif
                            @if ($product['hints'] !== [])
                                <div style="{{ $small }}">{{ __('enrichment::gaps.text_says', ['words' => implode(', ', $product['hints'])]) }}</div>
                            @endif
                        </div>
                        @if ($product['waiting'])
                            <span style="font-size:12.5px;padding:2px 10px;border-radius:999px;background:rgba(245,158,11,.15)">{{ __('enrichment::gaps.waiting', ['value' => $product['waiting']]) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
