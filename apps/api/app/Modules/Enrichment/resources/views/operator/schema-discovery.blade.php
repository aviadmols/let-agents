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
            <x-filament::button wire:click="discover" icon="heroicon-o-sparkles">{{ $t('discover') }}</x-filament::button>
            <x-filament::button wire:click="readCatalog" color="gray" icon="heroicon-o-arrow-path">{{ $t('read_catalog') }}</x-filament::button>
            <span style="{{ $muted }}">{{ $t('how') }}</span>
        </div>
        @php($proposals = $this->proposals())
        @if ($proposals->isNotEmpty())
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">
                @foreach ($proposals as $item)
                    <x-filament::button size="sm" color="gray" wire:click="open('{{ $item->id }}')">
                        {{ $item->created_at?->format('d/m H:i') }} · {{ $t('statuses.'.$item->status) }} · {{ $t('sampled', ['n' => $item->sampled]) }}
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
            </x-slot>
            @if ($p->summary)<p>{{ $p->summary }}</p>@endif
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
            @php($coverage = $p->coverage ?? [])
            <x-filament::section :heading="$t('types')">
                <table style="{{ $table }}">
                    <thead><tr style="opacity:.7"><th style="{{ $cell }}">{{ $t('key') }}</th><th style="{{ $cell }}">{{ $t('label') }}</th><th style="{{ $cell }}">{{ $t('patterns') }}</th><th style="{{ $cell }}">{{ $t('coverage') }}</th></tr></thead>
                    <tbody>
                    @foreach ($p->proposed['product_types'] ?? [] as $type)
                        <tr>
                            <td style="{{ $cell }}" dir="ltr">{{ $type['key'] }}</td>
                            <td style="{{ $cell }}">{{ $type['label']['he'] ?? '' }}</td>
                            <td style="{{ $cell }};font-size:12px" dir="ltr"><code>{{ implode('  |  ', (array) ($type['patterns'] ?? [])) }}</code></td>
                            <td style="{{ $cell }}">{{ $pct($coverage['product_types'][$type['key']] ?? 0) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::section :heading="$t('attributes')">
                <table style="{{ $table }}">
                    <thead><tr style="opacity:.7"><th style="{{ $cell }}">{{ $t('key') }}</th><th style="{{ $cell }}">{{ $t('label') }}</th><th style="{{ $cell }}">{{ $t('kind') }}</th><th style="{{ $cell }}">{{ $t('values') }}</th><th style="{{ $cell }}">{{ $t('coverage') }}</th><th style="{{ $cell }}">{{ $t('source') }}</th></tr></thead>
                    <tbody>
                    @foreach ($p->proposed['attributes'] ?? [] as $attribute)
                        @php($c = $coverage['attributes'][$attribute['key']] ?? [])
                        <tr>
                            <td style="{{ $cell }}" dir="ltr">{{ $attribute['key'] }}</td>
                            <td style="{{ $cell }}">{{ $attribute['label']['he'] ?? '' }}</td>
                            <td style="{{ $cell }}" dir="ltr">{{ $attribute['type'] ?? 'number' }}@if (($attribute['type'] ?? 'number') === 'number') · {{ $attribute['dimension'] ?? '' }} {{ $attribute['min'] ?? '' }}–{{ $attribute['max'] ?? '' }}@endif</td>
                            <td style="{{ $cell }};font-size:12px">
                                @foreach ((array) ($attribute['values'] ?? []) as $value)
                                    <div>{{ $value['label']['he'] ?? $value['key'] }} <span style="{{ $muted }}">{{ $pct($c['values'][$value['key']] ?? 0) }}</span></div>
                                @endforeach
                                @if (isset($attribute['title_capture']['pattern']))<code dir="ltr">{{ $attribute['title_capture']['pattern'] }}</code>@endif
                                @if (($attribute['type'] ?? '') === 'boolean')<code dir="ltr">{{ implode(' | ', (array) ($attribute['patterns'] ?? [])) }}</code>@endif
                            </td>
                            <td style="{{ $cell }}">{{ $pct($c['share'] ?? 0) }}@if (($c['read_by'] ?? null) === 'model')<div style="{{ $muted }}">{{ $t('read_by_model') }}</div>@endif</td>
                            <td style="{{ $cell }}" dir="ltr">{{ $p->review['writer']['sources'][$attribute['key']] ?? '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif

        @if ($p->review)
            <x-filament::section :heading="$t('review')" :description="$t('review_hint', ['writer' => $p->review['writer']['model'] ?? '', 'reviewer' => $p->review['reviewer'] ?? ''])" collapsible>
                <p><x-filament::badge :color="($p->review['verdict'] ?? '') === 'good' ? 'success' : 'warning'">{{ $t('verdicts.'.($p->review['verdict'] ?? 'needs_work')) }}</x-filament::badge></p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;margin-top:10px">
                    <div style="{{ $card }}"><strong>{{ $t('dropped') }}</strong>
                        @forelse ($p->review['dropped'] ?? [] as $drop)<div style="font-size:13px"><code dir="ltr">{{ $drop['key'] }}</code> — <span dir="ltr">{{ $drop['why'] }}</span></div>@empty<div style="{{ $muted }}">{{ $t('nothing') }}</div>@endforelse
                    </div>
                    <div style="{{ $card }}"><strong>{{ $t('missing') }}</strong>
                        @forelse ($p->review['missing'] ?? [] as $miss)<div style="font-size:13px"><code dir="ltr">{{ $miss['key'] ?? '' }}</code> — <span dir="ltr">{{ $miss['why'] ?? '' }}</span></div>@empty<div style="{{ $muted }}">{{ $t('nothing') }}</div>@endforelse
                    </div>
                    <div style="{{ $card }}"><strong>{{ $t('notes') }}</strong>
                        @foreach ([...($p->review['writer']['notes'] ?? []), ...($p->review['notes'] ?? [])] as $note)<div style="font-size:13px" dir="ltr">{{ $note }}</div>@endforeach
                    </div>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section :heading="$t('counted')" :description="$t('counted_hint')" collapsible collapsed>
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
