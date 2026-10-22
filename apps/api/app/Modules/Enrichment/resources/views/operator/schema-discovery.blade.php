<x-filament-panels::page>
    @php($t = fn (string $key, array $params = []) => __('enrichment::ui.schema.'.$key, $params))
    @php($cell = 'padding:6px 8px;border-top:1px solid rgba(127,127,127,.25);vertical-align:top;text-align:start')
    @php($table = 'width:100%;font-size:13px;border-collapse:collapse')
    @php($muted = 'opacity:.65;font-size:12px')
    @php($card = 'border:1px solid rgba(127,127,127,.25);border-radius:10px;padding:10px 12px')
    @php($pct = fn ($share) => round(((float) $share) * 100).'%')
    @php($colors = ['discovered' => 'gray', 'proposed' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'failed' => 'danger'])

    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
            <x-filament::button wire:click="buildStructure" icon="heroicon-o-squares-2x2">{{ $t('build_structure') }}</x-filament::button>
            <x-filament::button wire:click="discover" color="gray" icon="heroicon-o-sparkles">{{ $t('discover') }}</x-filament::button>
            <x-filament::button wire:click="readCatalog" color="gray" icon="heroicon-o-arrow-path">{{ $t('read_catalog') }}</x-filament::button>
            <span style="{{ $muted }}">{{ $t('how') }}</span>
        </div>
        @php($m = $this->models())
        <div style="margin-top:10px;font-size:13px">
            {{ $t('writer_model') }}: <code dir="ltr">{{ $m['writer']->model }}</code> ({{ $m['writer']->provider->label() }})
            · {{ $t('reviewer_model') }}: <code dir="ltr">{{ $m['reviewer']->model }}</code> ({{ $m['reviewer']->provider->label() }})
            · <a href="{{ url('/operator/agents?agent=enrichment.schema_discovery') }}" style="text-decoration:underline">{{ $t('change_models') }}</a>
            @if ($m['same_family'])<x-filament::badge color="danger">{{ $t('same_family') }}</x-filament::badge>@endif
        </div>
        @php($proposals = $this->proposals())
        @if ($proposals->isNotEmpty())
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">
                @foreach ($proposals as $item)
                    <x-filament::button size="sm" color="gray" wire:click="open('{{ $item->id }}')">
                        {{ $item->created_at?->format('d/m H:i') }}@if ($item->branch) · {{ $item->branch['name'] }}@endif · {{ $t('statuses.'.$item->status) }}@if ($item->score !== null) · {{ $item->score }}@endif · {{ $t('sampled', ['n' => $item->sampled]) }}
                    </x-filament::button>
                @endforeach
            </div>
        @else
            <p style="{{ $muted }};margin-top:10px">{{ $t('none') }}</p>
        @endif
    </x-filament::section>

    @php($p = $this->current())
    @if ($p)
        @php($s = $p->stats)
        <x-filament::section>
            <x-slot name="heading">
                <x-filament::badge :color="$colors[$p->status] ?? 'gray'">{{ $t('statuses.'.$p->status) }}</x-filament::badge>
                {{ $p->created_at?->format('d/m/Y H:i') }} · {{ $t('sampled', ['n' => $p->sampled]) }}
                @if ($p->template) · {{ $t('from_template', ['template' => $p->template]) }} @endif
                @if ($p->branch) · {{ $t('branch_of', ['name' => $p->branch['name'], 'path' => $p->branch['path'], 'products' => $p->branch['products']]) }} @endif
            </x-slot>
            @if ($p->summary)<p>{{ $p->summary }}</p>@endif
            @php($sc = $this->scoreOf($p))
            @if ($sc)
                <div style="{{ $card }};margin-top:10px" data-schema-score>
                    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:baseline">
                        <span style="font-size:34px;font-weight:700;line-height:1">{{ $sc['score'] }}</span><span style="{{ $muted }}">/ 100 · {{ $t('pass_score', ['pass' => $sc['pass']]) }}</span>
                        <x-filament::badge :color="$sc['gate'] === 'passed' ? 'success' : ($sc['gate'] === 'hard_failure' ? 'danger' : 'warning')">{{ $t('gate.'.$sc['gate']) }}</x-filament::badge>
                        @if ($sc['cost'] !== null)<span style="{{ $muted }}">{{ $t('cost', ['usd' => number_format($sc['cost'], 4)]) }}</span>@endif
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:8px;font-size:13px">
                        @foreach ($sc['parts'] as $part)
                            <span>{{ $t('score_parts.'.$part['key']) }} <strong>{{ $part['value'] }}</strong> <span style="{{ $muted }}">/ {{ $part['weight'] }}</span></span>
                        @endforeach
                    </div>
                    @foreach ($sc['hard'] as $failure)
                        <div style="margin-top:6px"><x-filament::badge color="danger">{{ $t('hard.'.$failure) }}</x-filament::badge></div>
                    @endforeach
                    @if (! empty($sc['pilot']['asked']))
                        <div style="margin-top:8px;font-size:13px"><strong>{{ $t('pilot_found', ['products' => $sc['pilot']['products'], 'on' => $t('measured_on.'.($sc['pilot']['measured_on'] ?? 'asked'))]) }}</strong>
                            @foreach ($sc['pilot']['asked'] as $key)
                                <span style="white-space:nowrap;margin-inline-end:12px"><code dir="ltr">{{ $key }}</code> {{ $sc['pilot']['found'][$key] ?? 0 }}/{{ $sc['pilot']['products'] }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
            @if ($p->status === 'proposed')
                <div style="display:flex;gap:8px;margin-top:10px">
                    <x-filament::button color="success" wire:click="decide('{{ $p->id }}', true)" wire:confirm="{{ $t('approve_confirm') }}">{{ $t('approve') }}</x-filament::button>
                    <x-filament::button color="danger" wire:click="decide('{{ $p->id }}', false)">{{ $t('reject') }}</x-filament::button>
                </div>
            @endif
            @if ($p->status === 'approved')<p style="{{ $muted }};margin-top:8px">{{ $t('approved_hint') }}</p>@endif
            @if (! empty($p->problems))
                <div style="margin-top:10px"><strong>{{ $t('problems') }}</strong>
                    <ul style="{{ $muted }};list-style:disc;padding-inline-start:18px" dir="ltr">@foreach ($p->problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
                </div>
            @endif
        </x-filament::section>

        @if ($p->proposed)
            @php($active = $this->activeProducts())
            @php($decisionColors = ['approved' => 'success', 'proposed' => 'warning', 'rejected' => 'danger', 'removed_by_code' => 'gray', 'removed_by_reviewer' => 'gray'])
            <x-filament::section :heading="$t('final')" :description="$t('final_hint', ['sampled' => $p->sampled, 'active' => $active])">
                <div style="overflow-x:auto">
                    <table style="{{ $table }}">
                        <thead><tr style="opacity:.7">
                            <th style="{{ $cell }}">{{ $t('field') }}</th>
                            <th style="{{ $cell }}">{{ $t('kind') }}</th>
                            <th style="{{ $cell }}">{{ $t('values') }}</th>
                            <th style="{{ $cell }}">{{ $t('coverage') }}</th>
                            <th style="{{ $cell }}">{{ $t('catalog_coverage') }}</th>
                            <th style="{{ $cell }}">{{ $t('source') }}</th>
                            <th style="{{ $cell }}">{{ $t('author') }}</th>
                            <th style="{{ $cell }}">{{ $t('decision') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($this->rows($p) as $row)
                            <tr style="{{ str_starts_with($row['decision'], 'removed') ? 'opacity:.6' : '' }}">
                                <td style="{{ $cell }}">{{ $row['label'] }} <code dir="ltr" style="{{ $muted }}">{{ $row['key'] }}</code>@if ($row['part'] === 'attribute' && $row['why'] !== '' && ! str_starts_with($row['decision'], 'removed'))<div style="{{ $muted }}">{{ $row['why'] }}</div>@endif</td>
                                <td style="{{ $cell }}" dir="ltr">{{ $row['part'] === 'type' ? $t('product_type') : $row['kind'] }}@if ($row['part'] === 'type' && $row['kind'] !== '') <span style="{{ $muted }}">{{ $row['kind'] }}</span>@endif @if (! empty($row['importance']))<x-filament::badge size="sm" :color="$row['importance'] === 'required' ? 'danger' : 'warning'">{{ $t('importance.'.$row['importance']) }}</x-filament::badge>@endif @if (! empty($row['read_by']))<x-filament::badge size="sm" color="info" data-read-by-model>{{ $t('read_by_model') }}</x-filament::badge>@endif</td>
                                <td style="{{ $cell }};font-size:12px">
                                    @foreach ($row['values'] as $value)<span style="white-space:nowrap">{{ $value['label'] }} <span style="{{ $muted }}">{{ $pct($value['share']) }}</span></span>@if (! $loop->last) · @endif @endforeach
                                </td>
                                <td style="{{ $cell }}">{{ $row['sample'] === null ? '' : $pct($row['sample']) }}</td>
                                <td style="{{ $cell }}">{{ $row['catalog'] === null ? ($p->status === 'approved' && ! str_starts_with($row['decision'], 'removed') ? '0%' : '') : $pct($row['catalog']) }}</td>
                                <td style="{{ $cell }}" dir="ltr">{{ $row['source'] }}</td>
                                <td style="{{ $cell }};font-size:12px" dir="ltr">{{ $row['author'] }}</td>
                                <td style="{{ $cell }}">
                                    <x-filament::badge :color="$decisionColors[$row['decision']] ?? 'gray'">{{ $t('decisions.'.$row['decision']) }}</x-filament::badge>
                                    @if ($row['why'] !== '')<div style="{{ $muted }}" dir="ltr">{{ $row['why'] }}</div>@endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        @if ($p->review)
            <x-filament::section :heading="$t('review')" collapsible>
                <div style="display:grid;gap:4px;margin-bottom:10px;font-size:13px">
                    @foreach ($this->lineage($p) as $step)
                        <div><strong>{{ $t('version_n', ['n' => $loop->iteration]) }}</strong> <span style="{{ $muted }}">{{ $step['at'] }}</span> ·
                            @if ($step['operator']){{ $t('operator_edit') }}@else{{ $t('review_hint', ['writer' => $step['writer'], 'reviewer' => $step['reviewer']]) }}@endif
                        </div>
                    @endforeach
                    @foreach ($p->review['rounds'] ?? [] as $r)
                        <div style="{{ $muted }}">{{ $t('round_line', ['n' => $r['round'] + 1, 'verdict' => $t('verdicts.'.$r['verdict']), 'dropped' => $r['dropped'], 'missing' => $r['missing']]) }}</div>
                    @endforeach
                </div>
                <p><x-filament::badge :color="($p->review['verdict'] ?? '') === 'good' ? 'success' : 'warning'">{{ $t('verdicts.'.($p->review['verdict'] ?? 'needs_work')) }}</x-filament::badge></p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;margin-top:10px">
                    <div style="{{ $card }}"><strong>{{ $t('writer_proposed') }}</strong>
                        @forelse ($p->review['writer']['notes'] ?? [] as $note)<div style="font-size:13px" dir="ltr">{{ $note }}</div>@empty<div style="{{ $muted }}">{{ $t('nothing') }}</div>@endforelse
                    </div>
                    <div style="{{ $card }}"><strong>{{ $t('removed_why') }}</strong>
                        @forelse ($p->review['dropped'] ?? [] as $drop)
                            <div style="font-size:13px"><x-filament::badge size="sm" color="gray">{{ $t('by.'.($drop['by'] ?? 'reviewer')) }}</x-filament::badge> <code dir="ltr">{{ $drop['key'] }}</code> — <span dir="ltr">{{ $drop['why'] }}</span></div>
                        @empty<div style="{{ $muted }}">{{ $t('nothing') }}</div>@endforelse
                        @foreach ($p->review['notes'] ?? [] as $note)<div style="font-size:13px;margin-top:4px" dir="ltr">{{ $note }}</div>@endforeach
                    </div>
                    <div style="{{ $card }}"><strong>{{ $t('missing') }}</strong>
                        @forelse ($p->review['missing'] ?? [] as $miss)<div style="font-size:13px"><code dir="ltr">{{ $miss['key'] ?? '' }}</code> — <span dir="ltr">{{ $miss['why'] ?? '' }}</span></div>@empty<div style="{{ $muted }}">{{ $t('nothing') }}</div>@endforelse
                    </div>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section :heading="$t('counted')" :description="$t('counted_hint', ['sampled' => $p->sampled, 'active' => $this->activeProducts()])" collapsible collapsed>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px">
                @foreach (['product_type', 'tags', 'categories'] as $field)
                    <div style="{{ $card }}"><strong>{{ $t('fields.'.$field) }}</strong> <span style="{{ $muted }}">{{ $pct($s['fields'][$field]['share'] ?? 0) }}</span>
                        @foreach (array_slice($s['fields'][$field]['values'] ?? [], 0, 15, true) as $value => $n)<div style="font-size:13px">{{ $value }} <span style="{{ $muted }}">{{ $n }}</span></div>@endforeach
                    </div>
                @endforeach
                <div style="{{ $card }}"><strong>{{ $t('measurements') }}</strong>
                    @foreach ($s['measurements'] ?? [] as $dimension => $m)
                        <div style="font-size:13px;margin-top:4px"><span dir="ltr">{{ $dimension }}</span> {{ $pct($m['share']) }} <span style="{{ $muted }}" dir="ltr">{{ $m['min'] }}–{{ $m['max'] }}</span>
                            <div style="{{ $muted }}">{{ implode(' · ', array_keys(array_slice($m['before'] ?? [], 0, 4, true))) }}</div></div>
                    @endforeach
                </div>
                <div style="{{ $card }}"><strong>{{ $t('attributes_found') }}</strong>
                    @forelse ([...($s['attributes'] ?? []), ...($s['spec_fields'] ?? []), ...($s['key_values'] ?? [])] as $name => $a)<div style="font-size:13px">{{ $name }} <span style="{{ $muted }}">{{ $pct($a['share']) }}</span></div>@empty<div style="{{ $muted }}">{{ $t('nothing') }}</div>@endforelse
                </div>
                <div style="{{ $card }}"><strong>{{ $t('title_words') }}</strong>
                    <div style="font-size:13px">{{ collect($s['title_words'] ?? [])->take(30)->map(fn ($share, $word) => $word.' '.$pct($share))->implode(' · ') }}</div>
                </div>
                <div style="{{ $card }}"><strong>{{ $t('templates') }}</strong>
                    @forelse ($s['templates'] ?? [] as $name => $share)<div style="font-size:13px" dir="ltr">{{ $name }} {{ $pct($share) }}</div>@empty<div style="{{ $muted }}">{{ $t('nothing') }}</div>@endforelse
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
