<x-filament-panels::page>
    @php($t = fn (string $key, array $params = []) => __('enrichment::missing.'.$key, $params))
    @php($small = 'font-size:12.5px;opacity:.7')
    @php($gaps = $this->gaps())
    @php($totals = $this->totals())

    <x-filament::section>
        @if ($gaps === [])
            <p data-missing-none>{{ $t('none') }}</p>
        @else
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
                <p data-missing-summary style="font-size:15px;font-weight:600">{{ $t('summary', ['missing' => number_format($totals['missing']), 'groups' => $totals['groups']]) }}</p>
                <x-filament::button wire:click="downloadCsv" color="gray" size="sm" icon="heroicon-o-arrow-down-tray">{{ $t('csv_download') }}</x-filament::button>
            </div>
        @endif
    </x-filament::section>

    @foreach ($gaps as $gap)
        @php($group = $gap['type'].'|'.$gap['attribute'])
        @php($isOpen = $this->openGroup === $group)
        <x-filament::section wire:key="gap-{{ $group }}"
            :heading="$t('heading', ['type' => $gap['type_label'], 'attribute' => $gap['attribute_label'], 'importance' => $t('importance.'.$gap['importance'])])"
            :description="$t('share', ['have' => $gap['have'], 'of' => $gap['of'], 'missing' => count($gap['missing'])]).($gap['why'] ? ' '.$gap['why'] : '')"
            data-missing-group="{{ $group }}">
            <x-slot name="afterHeader">
                <x-filament::button size="sm" color="{{ $isOpen ? 'gray' : 'primary' }}" wire:click="toggleGroup(@js($group))">{{ $isOpen ? $t('close') : $t('open') }}</x-filament::button>
            </x-slot>

            @if ($isOpen)
                @php($field = $this->field($gap))
                @php($web = $this->webOn() ? $this->webResults($gap) : [])
                @php($colors = ['certain' => 'success', 'likely' => 'warning', 'conflicting' => 'danger', 'not_found' => 'gray'])
                @php($shown = array_slice($gap['missing'], 0, 50))
                @php($inputStyle = 'border:1px solid rgba(127,127,127,.35);border-radius:8px;padding:6px 8px;min-width:150px;font-size:13px;background:transparent')

                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px">
                    <strong style="font-size:13px">{{ $t('bulk_title') }}</strong>
                    @if ($field['type'] === 'enum')
                        <select wire:model="bulkValue" style="{{ $inputStyle }}" @if ($field['multiple']) multiple @endif>
                            @unless ($field['multiple'])<option value="">{{ $t('pick_one') }}</option>@endunless
                            @foreach ($field['options'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    @else
                        <input type="text" inputmode="decimal" wire:model="bulkValue" style="{{ $inputStyle }}" placeholder="{{ $field['unit'] }}">
                        <span style="{{ $small }}">{{ $t('unit_range', ['unit' => $field['unit'], 'min' => $field['min'], 'max' => $field['max']]) }}</span>
                    @endif
                    <x-filament::button size="sm" wire:click="saveMany">{{ $t('apply_ticked') }}</x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="markNotApplicable">{{ $t('not_applicable_ticked') }}</x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="$set('picked', @js(array_column($shown, 'id')))">{{ $t('tick_all') }}</x-filament::button>
                    @if ($this->webOn())<x-filament::button size="sm" color="gray" icon="heroicon-o-globe-alt" wire:click="lookUpPicked">{{ $t('web_check_ticked') }}</x-filament::button>@endif
                </div>

                <div style="display:grid;gap:2px">
                    @foreach ($shown as $product)
                        <div wire:key="missing-{{ $group }}-{{ $product['id'] }}" data-missing-product="{{ $product['external_id'] }}"
                             style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:8px 0;border-top:1px solid rgba(127,127,127,.15)">
                            <input type="checkbox" wire:model="picked" value="{{ $product['id'] }}">
                            <div style="min-width:0;flex:1 1 220px">
                                @if ($product['url'])
                                    <a href="{{ $product['url'] }}" target="_blank" rel="noopener" style="font-weight:600;font-size:14px">{{ $product['title'] }}</a>
                                @else
                                    <span style="font-weight:600;font-size:14px">{{ $product['title'] }}</span>
                                @endif
                                @if ($product['waiting'])<div style="{{ $small }}">{{ $t('waiting', ['value' => $product['waiting']]) }}</div>@endif
                            </div>
                            @if ($field['type'] === 'enum')
                                <select wire:model="entries.{{ $product['id'] }}" style="{{ $inputStyle }}" @if ($field['multiple']) multiple @endif>
                                    @unless ($field['multiple'])<option value="">{{ $t('pick_one') }}</option>@endunless
                                    @foreach ($field['options'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                </select>
                            @else
                                <input type="text" inputmode="decimal" wire:model="entries.{{ $product['id'] }}" style="{{ $inputStyle }}" placeholder="{{ $field['unit'] }}">
                            @endif
                            <x-filament::button size="xs" wire:click="saveOne(@js($product['id']))">{{ $t('save') }}</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="markNotApplicable(@js($product['id']))">{{ $t('not_applicable') }}</x-filament::button>
                            @if ($this->webOn())<x-filament::button size="xs" color="gray" icon="heroicon-o-globe-alt" wire:click="lookUp(@js($product['id']))">{{ $t('web_check') }}</x-filament::button>@endif
                            @if (isset($web[$product['id']]))
                                @php($w = $web[$product['id']])
                                @php($r = $w['result'])
                                <div style="flex:1 1 100%;margin-inline-start:24px;padding:8px 10px;border-radius:8px;background:rgba(127,127,127,.08);font-size:13px" data-web-result="{{ $r['certainty'] }}">
                                    <x-filament::badge size="sm" :color="$colors[$r['certainty']] ?? 'gray'">{{ $t('web_certainty.'.$r['certainty']) }}</x-filament::badge>
                                    @if ($r['certainty'] !== 'not_found' && $w['label'] !== '')
                                        <strong style="margin-inline-start:6px">{{ $t('web_found', ['value' => $w['label']]) }}</strong>
                                    @else
                                        <span style="margin-inline-start:6px">{{ $t('web_nothing_sure') }}</span>
                                    @endif
                                    @if ($this->isOperator())<span style="{{ $small }};margin-inline-start:8px">{{ $t('web_cost', ['usd' => number_format((float) $w['cost'], 4)]) }}</span>@endif
                                    @foreach (array_filter($r['sources'], fn ($s) => $s['verified']) as $source)
                                        <div style="margin-top:4px"><a href="{{ $source['url'] }}" target="_blank" rel="noopener" style="text-decoration:underline" dir="ltr">{{ $source['host'] }}</a> <span style="{{ $small }}">“{{ $source['quote'] }}”</span></div>
                                    @endforeach
                                    @if (($r['decision'] ?? 'pending') === 'pending' && $r['certainty'] !== 'not_found')
                                        <div style="display:flex;gap:6px;margin-top:6px">
                                            <x-filament::button size="xs" color="success" wire:click="decideWeb(@js($w['lookup']), @js($gap['attribute']), true)">{{ $t('web_approve') }}</x-filament::button>
                                            <x-filament::button size="xs" color="gray" wire:click="decideWeb(@js($w['lookup']), @js($gap['attribute']), false)">{{ $t('web_reject') }}</x-filament::button>
                                        </div>
                                    @elseif (($r['decision'] ?? 'pending') !== 'pending')
                                        <div style="{{ $small }};margin-top:4px">{{ $t('web_decided.'.$r['decision']) }}</div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if (count($gap['missing']) > count($shown))<p style="{{ $small }};margin-top:8px">{{ $t('shown', ['shown' => count($shown), 'count' => count($gap['missing'])]) }}</p>@endif
            @endif
        </x-filament::section>
    @endforeach

    @php($log = $this->webLog())
    @if ($log !== [])
        <x-filament::section :heading="$t('web_log')" collapsible collapsed data-web-log>
            <table style="width:100%;font-size:12.5px;border-collapse:collapse">
                @foreach ($log as $row)
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <td style="padding:4px 6px">{{ $row['at'] }}</td>
                        <td style="padding:4px 6px">{{ $row['product'] }}</td>
                        <td style="padding:4px 6px">{{ $t('web_certainty.'.$row['certainty']) }}</td>
                        <td style="padding:4px 6px" dir="ltr">{{ $row['details'] }}</td>
                        <td style="padding:4px 6px" dir="ltr">${{ $row['cost'] }}</td>
                    </tr>
                @endforeach
            </table>
        </x-filament::section>
    @endif

    @if ($gaps !== [])
        <x-filament::section :heading="$t('csv_title')" :description="$t('csv_help')" collapsible collapsed>
            <textarea wire:model="csv" rows="6" dir="ltr" placeholder="{{ $t('csv_paste') }}" style="width:100%;border:1px solid rgba(127,127,127,.35);border-radius:8px;padding:8px;font-size:12.5px;background:transparent"></textarea>
            <div style="margin-top:8px"><x-filament::button size="sm" wire:click="importCsv">{{ $t('csv_import') }}</x-filament::button></div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
