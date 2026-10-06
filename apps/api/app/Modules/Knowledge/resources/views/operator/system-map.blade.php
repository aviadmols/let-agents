<x-filament-panels::page>
    @php($arrow = $this->arrow())
    @php($small = 'font-size:12px;opacity:.7')
    @php($node = 'flex:1 1 170px;min-width:170px;border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:12px 14px;background:rgba(127,127,127,.04)')
    @php($lane = 'flex:1 1 190px;min-width:190px;display:flex;flex-direction:column;gap:8px')
    @php($flow = 'display:flex;flex-wrap:wrap;gap:10px;align-items:stretch')
    @php($link = 'flex:0 0 auto;align-self:center;font-size:22px;opacity:.45;padding:0 2px')
    @php($big = 'font-size:26px;font-weight:700;line-height:1.1;font-variant-numeric:tabular-nums')
    @php($label = 'font-size:11px;letter-spacing:.04em;text-transform:uppercase;opacity:.6;margin-bottom:4px')
    @php($cell = 'padding:6px 8px;border-bottom:1px solid rgba(127,127,127,.15);vertical-align:top')
    @php($bar = fn (float $share, string $color) => 'height:6px;border-radius:3px;background:linear-gradient(to '.(app()->getLocale() === 'he' ? 'left' : 'right').', '.$color.' '.max(0, min(100, $share * 100)).'%, rgba(127,127,127,.18) 0)')
    @php($n = fn ($v) => number_format((float) $v))
    @php($sourceName = fn (string $key, string $group) => ($t = __("knowledge::map.{$group}.{$key}")) !== "knowledge::map.{$group}.{$key}" ? $t : $key)

    <x-filament::tabs>
        @foreach (\App\Modules\Knowledge\Filament\Operator\Pages\SystemMap::TABS as $key)
            <x-filament::tabs.item :active="$tab === $key" wire:click="show('{{ $key }}')">
                {{ __('knowledge::map.tabs.'.$key) }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <p style="{{ $small }}">{{ __('knowledge::map.tabs_help.'.$tab) }}</p>

    {{-- ─────────────────────────────── 1. Building the stores ─────────────────────────────── --}}
    @if ($tab === 'index')
        @php($map = $this->indexMap())

        <x-filament::section :heading="__('knowledge::map.index.flow')">
            <div style="{{ $flow }}">
                {{-- What the store has. --}}
                <div style="{{ $lane }}">
                    <div style="{{ $label }}">{{ __('knowledge::map.index.store') }}</div>
                    @foreach (['products', 'pages', 'posts'] as $what)
                        <div style="{{ $node }}">
                            <div style="{{ $big }}">{{ $n($map['store'][$what]) }}</div>
                            <div style="{{ $small }}">{{ __('knowledge::map.index.'.$what) }}</div>
                        </div>
                    @endforeach
                    <div style="{{ $node }}">
                        <div style="{{ $big }}">{{ $n($map['store']['orders_live'] + $map['store']['orders_history']) }}</div>
                        <div style="{{ $small }}">{{ __('knowledge::map.index.orders', ['live' => $n($map['store']['orders_live']), 'history' => $n($map['store']['orders_history'])]) }}</div>
                        @php($history = $map['history'])
                        <div style="margin-top:8px;font-size:12px">
                            @if ($history === null)
                                <span style="opacity:.7">{{ __('knowledge::map.index.history_none') }}</span>
                            @else
                                @php($expected = max(1, (int) ($history['expected'] ?? $history['received'])))
                                <div style="{{ $bar($history['received'] / $expected, '#16a34a') }}"></div>
                                <div style="margin-top:4px">
                                    {{ __($history['finished'] ? 'knowledge::map.index.history_done' : 'knowledge::map.index.history_running', [
                                        'received' => $n($history['received']),
                                        'expected' => $history['expected'] === null ? '?' : $n($history['expected']),
                                        'oldest' => $history['oldest'] ?? '—',
                                    ]) }}
                                </div>
                                @if ($history['refused'] > 0)
                                    <div style="color:#b45309">{{ __('knowledge::map.index.history_refused', ['n' => $n($history['refused'])]) }}</div>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>

                <div style="{{ $link }}">{{ $arrow }}</div>

                {{-- One lane per source the index reads, whoever registered it. --}}
                <div style="{{ $lane }}">
                    <div style="{{ $label }}">{{ __('knowledge::map.index.documents') }}</div>
                    @foreach ($map['sources'] as $key => $source)
                        <div style="{{ $node }}">
                            <div style="font-weight:600">{{ $sourceName($key, 'sources') }}</div>
                            <div style="{{ $big }}">{{ $n($source['documents']) }}</div>
                            <div style="{{ $small }}">{{ __('knowledge::map.index.source_line', ['chunks' => $n($source['chunks']), 'average' => $n($source['average'])]) }}</div>
                        </div>
                    @endforeach
                </div>

                <div style="{{ $link }}">{{ $arrow }}</div>

                <div style="{{ $node }}">
                    <div style="{{ $label }}">{{ __('knowledge::map.index.pieces') }}</div>
                    <div style="{{ $big }}">{{ $n($map['chunks']) }}</div>
                    <div style="{{ $small }}">{{ __('knowledge::map.index.pieces_help') }}</div>
                </div>

                <div style="{{ $link }}">{{ $arrow }}</div>

                <div style="{{ $node }}">
                    <div style="{{ $label }}">{{ __('knowledge::map.index.vectors') }}</div>
                    <div style="{{ $big }}">{{ $n($map['embedded']) }}</div>
                    <div style="{{ $bar($map['chunks'] > 0 ? $map['embedded'] / $map['chunks'] : 0, '#2563eb') }};margin:6px 0"></div>
                    <div style="{{ $small }}">{{ __('knowledge::map.index.pending', ['n' => $n($map['pending'])]) }}</div>
                    <div style="font-size:12px;margin-top:6px" dir="ltr">{{ $map['model'] }}@if ($map['dimensions']) · {{ $map['dimensions'] }}d @endif</div>
                </div>

                <div style="{{ $link }}">{{ $arrow }}</div>

                <div style="{{ $node }}">
                    <div style="{{ $label }}">{{ __('knowledge::map.index.ready') }}</div>
                    @if ($map['embedded'] > 0)
                        <x-filament::badge color="success">{{ __('knowledge::map.index.ready_yes') }}</x-filament::badge>
                    @else
                        <x-filament::badge color="gray">{{ __('knowledge::map.index.ready_no') }}</x-filament::badge>
                    @endif
                    <div style="{{ $small }};margin-top:8px">{{ __('knowledge::map.index.ready_help') }}</div>
                </div>
            </div>
        </x-filament::section>

        @include('knowledge::operator.partials.map-run', ['last' => $map['last'], 'spend' => $map['spend']])
    @endif

    {{-- ─────────────────────────────── 2. Matching ─────────────────────────────── --}}
    @if ($tab === 'matching')
        @php($map = $this->matchingMap())
        @php($output = $map['last']['output'] ?? [])

        <x-filament::section :heading="__('knowledge::map.matching.flow')">
            <div style="{{ $flow }}">
                <div style="{{ $lane }}">
                    <div style="{{ $label }}">{{ __('knowledge::map.matching.evidence') }}</div>
                    @foreach (['orders', 'vectors', 'guides'] as $what)
                        <div style="{{ $node }}">
                            <div style="{{ $big }}">{{ $n($map['evidence'][$what]) }}</div>
                            <div style="{{ $small }}">{{ __('knowledge::map.matching.evidence_'.$what) }}</div>
                        </div>
                    @endforeach
                </div>

                <div style="{{ $link }}">{{ $arrow }}</div>

                <div style="{{ $lane }}">
                    <div style="{{ $label }}">{{ __('knowledge::map.matching.candidates') }}</div>
                    @foreach (($output['offered'] ?? ['bought_together' => 0, 'similar' => 0, 'mentioned_together' => 0]) as $key => $count)
                        <div style="{{ $node }}">
                            <div style="font-weight:600">{{ $sourceName($key, 'candidate_sources') }}</div>
                            <div style="{{ $big }}">{{ $n($count) }}</div>
                            <div style="{{ $small }}">{{ __('knowledge::map.matching.offered') }}</div>
                        </div>
                    @endforeach
                </div>

                <div style="{{ $link }}">{{ $arrow }}</div>

                <div style="{{ $node }}">
                    <div style="{{ $label }}">{{ __('knowledge::map.matching.model') }}</div>
                    <div style="font-size:13px;font-weight:600" dir="ltr">{{ $map['model'] }}</div>
                    <div style="{{ $big }};margin-top:6px">{{ $n($output['asked'] ?? 0) }}</div>
                    <div style="{{ $small }}">{{ __('knowledge::map.matching.asked', ['unchanged' => $n($output['unchanged'] ?? 0)]) }}</div>
                    @if (! empty($output['stopped']))
                        <div style="margin-top:6px"><x-filament::badge color="warning">{{ __('knowledge::map.stopped.'.$output['stopped']) }}</x-filament::badge></div>
                    @endif
                </div>

                <div style="{{ $link }}">{{ $arrow }}</div>

                <div style="{{ $node }}">
                    <div style="{{ $label }}">{{ __('knowledge::map.matching.check') }}</div>
                    <div style="display:flex;gap:14px">
                        <div><div style="{{ $big }};color:#16a34a">{{ $n($map['accepted']['complement']) }}</div><div style="{{ $small }}">{{ __('knowledge::map.kinds.complement') }}</div></div>
                        <div><div style="{{ $big }};color:#16a34a">{{ $n($map['accepted']['alternative']) }}</div><div style="{{ $small }}">{{ __('knowledge::map.kinds.alternative') }}</div></div>
                    </div>
                    @if ($map['rejected'] !== [])
                        <div style="margin-top:8px;font-size:12px">
                            <div style="opacity:.7">{{ __('knowledge::map.matching.rejected') }}</div>
                            @foreach ($map['rejected'] as $because => $count)
                                <div style="color:#b91c1c">{{ $n($count) }} · {{ __('knowledge::map.because.'.$because) }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div style="{{ $link }}">{{ $arrow }}</div>

                <div style="{{ $node }};flex-basis:230px">
                    <div style="{{ $label }}">{{ __('knowledge::map.matching.relations') }}</div>
                    <table style="width:100%;border-collapse:collapse;font-size:12px">
                        @forelse ($map['relations'] as $source => $kinds)
                            <tr style="{{ $source === 'ai_match' ? 'font-weight:700' : '' }}">
                                <td style="padding:2px 0">{{ $sourceName($source, 'relation_sources') }}</td>
                                <td style="padding:2px 0;text-align:end;font-variant-numeric:tabular-nums">{{ $n(array_sum($kinds)) }}</td>
                            </tr>
                        @empty
                            <tr><td style="opacity:.7">{{ __('knowledge::map.matching.no_relations') }}</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>

            {{-- How much of the shop the model has seen. --}}
            @php($total = max(1, $map['products']))
            <div style="margin-top:16px">
                <div style="{{ $label }}">{{ __('knowledge::map.matching.coverage') }}</div>
                <div style="display:flex;height:10px;border-radius:5px;overflow:hidden;background:rgba(127,127,127,.15)">
                    @foreach (['answered' => '#16a34a', 'too_few' => '#a3a3a3', 'failed' => '#dc2626'] as $state => $color)
                        <div style="width:{{ $map['requests'][$state] / $total * 100 }}%;background:{{ $color }}"></div>
                    @endforeach
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:6px;font-size:12px">
                    @foreach (['answered', 'too_few', 'failed', 'never'] as $state)
                        <span>{{ __('knowledge::map.coverage.'.$state, ['n' => $n($map['requests'][$state])]) }}</span>
                    @endforeach
                </div>
            </div>
        </x-filament::section>

        @include('knowledge::operator.partials.map-run', ['last' => $map['last'], 'spend' => $map['spend']])

        @include('knowledge::operator.partials.map-product-picker')

        @php($trail = $this->trail())
        @if ($trail !== null)
            <x-filament::section :heading="__('knowledge::map.product.trail', ['title' => $trail['product']['title']])">
                <x-slot name="headerEnd">
                    <x-filament::button size="sm" wire:click="askAgain" wire:loading.attr="disabled">{{ __('knowledge::map.product.ask_again') }}</x-filament::button>
                </x-slot>

                @if ($trail['request'] === null)
                    <p style="opacity:.7">{{ __('knowledge::map.product.not_asked') }}</p>
                @else
                    <p style="{{ $small }}">
                        {{ __('knowledge::map.product.request', [
                            'status' => __('knowledge::map.request_status.'.$trail['request']['status']),
                            'model' => $trail['request']['model'] ?? '—',
                            'at' => $trail['request']['asked_at'] ?? '—',
                            'cost' => number_format($trail['request']['cost'], 4),
                        ]) }}
                        @if ($trail['invented'] > 0) · <span style="color:#b91c1c">{{ __('knowledge::map.product.invented', ['n' => $trail['invented']]) }}</span>@endif
                    </p>
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;font-size:13px">
                            <tr style="{{ $small }}">
                                @foreach (['product', 'sources', 'evidence', 'choice', 'verdict', 'why'] as $column)
                                    <th style="{{ $cell }};text-align:start;font-weight:600">{{ __('knowledge::map.product.columns.'.$column) }}</th>
                                @endforeach
                            </tr>
                            @foreach ($trail['candidates'] as $candidate)
                                <tr style="{{ $candidate['picked'] === null ? 'opacity:.55' : '' }}">
                                    <td style="{{ $cell }}"><span style="opacity:.5" dir="ltr">{{ $candidate['ref'] }}</span> {{ $candidate['title'] }}</td>
                                    <td style="{{ $cell }}">
                                        @foreach ($candidate['sources'] as $source)
                                            <x-filament::badge size="sm" color="gray" style="display:inline-flex;margin:1px">{{ $sourceName($source, 'candidate_sources') }}</x-filament::badge>
                                        @endforeach
                                    </td>
                                    <td style="{{ $cell }};font-size:12px;white-space:nowrap">
                                        @foreach ($candidate['signals'] as $signal => $value)
                                            <div>{{ __('knowledge::map.signals.'.$signal, ['v' => $value]) }}</div>
                                        @endforeach
                                    </td>
                                    <td style="{{ $cell }}">{{ $candidate['picked'] ? __('knowledge::map.kinds.'.$candidate['picked']) : '—' }}</td>
                                    <td style="{{ $cell }}">
                                        @if ($candidate['status'] === 'accepted')
                                            <x-filament::badge color="success">{{ __('knowledge::map.product.accepted') }}</x-filament::badge>
                                        @elseif ($candidate['status'] === 'rejected')
                                            <x-filament::badge color="danger">{{ __('knowledge::map.because.'.$candidate['because']) }}</x-filament::badge>
                                        @endif
                                    </td>
                                    <td style="{{ $cell }};font-size:12px">{{ $candidate['why'] }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endif

                <h3 style="font-weight:600;margin:18px 0 6px">{{ __('knowledge::map.product.relations') }}</h3>
                @if ($trail['relations'] === [])
                    <p style="opacity:.7">{{ __('knowledge::map.matching.no_relations') }}</p>
                @else
                    <table style="width:100%;border-collapse:collapse;font-size:13px">
                        @foreach ($trail['relations'] as $relation)
                            <tr style="{{ $relation['source'] === 'ai_match' || in_array('ai_match', $relation['also'], true) ? 'font-weight:600' : '' }}">
                                <td style="{{ $cell }};width:1%;white-space:nowrap">{{ __('knowledge::map.kinds.'.$relation['kind']) }}</td>
                                <td style="{{ $cell }}">{{ $relation['title'] }}</td>
                                <td style="{{ $cell }};font-size:12px">
                                    {{ $sourceName($relation['source'], 'relation_sources') }}
                                    @foreach ($relation['also'] as $also) + {{ $sourceName($also, 'relation_sources') }} @endforeach
                                </td>
                                <td style="{{ $cell }};text-align:end;font-variant-numeric:tabular-nums">{{ $relation['score'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </x-filament::section>
        @endif
    @endif

    {{-- ─────────────────────────────── 3. A product page, stage by stage ─────────────────────────────── --}}
    @if ($tab === 'page')
        <x-filament::section :heading="__('knowledge::map.page.flow')">
            <div style="{{ $flow }}">
                @foreach (\App\Modules\Knowledge\Support\SystemMap::PAGE_STAGES as $i => $stage)
                    @if ($i > 0)<div style="{{ $link }}">{{ $arrow }}</div>@endif
                    <div style="{{ $node }}">
                        <div style="font-weight:600">{{ ($i + 1).'. '.__('knowledge::map.page.stages.'.$stage.'.title') }}</div>
                        <div style="{{ $small }};margin-top:4px">{{ __('knowledge::map.page.stages.'.$stage.'.help') }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @include('knowledge::operator.partials.map-product-picker')

        @php($trace = $this->pageTrace())
        @if ($trace !== null)
            @if (! ($trace['bank']['enabled'] ?? false))
                <x-filament::section><p style="opacity:.7">{{ __('knowledge::map.page.disabled') }}</p></x-filament::section>
            @else
                <div style="display:flex;gap:10px;overflow-x:auto;padding-bottom:6px;align-items:flex-start">
                    @foreach ($trace['stages'] as $i => $stage)
                        @if ($i > 0)<div style="{{ $link }};padding-top:18px">{{ $arrow }}</div>@endif
                        <div style="{{ $node }};flex:0 0 260px;min-width:260px">
                            <div style="font-weight:700;margin-bottom:8px">{{ ($i + 1).'. '.__('knowledge::map.page.stages.'.$stage['stage'].'.title') }}</div>
                            @foreach ($stage['sections'] as $section)
                                <div style="border-top:1px solid rgba(127,127,127,.18);padding:6px 0">
                                    <div style="display:flex;justify-content:space-between;gap:6px;font-size:13px">
                                        <span style="font-weight:600">{{ $section['position'] }}. {{ $section['title'] }}</span>
                                        <span style="white-space:nowrap;font-size:11px">
                                            @if ($section['new'])<x-filament::badge size="sm" color="info">{{ __('knowledge::map.page.new') }}</x-filament::badge>@endif
                                            @if ($section['moved'] > 0)<span style="color:#16a34a">▲{{ $section['moved'] }}</span>@endif
                                            @if ($section['moved'] < 0)<span style="color:#b45309">▼{{ abs($section['moved']) }}</span>@endif
                                            <span style="opacity:.6">{{ $section['count'] }}</span>
                                        </span>
                                    </div>
                                    @if ($section['items'] !== [] || $section['dropped'] !== [])
                                        <div style="display:flex;flex-wrap:wrap;gap:3px;margin-top:4px">
                                            @foreach ($section['items'] as $item)
                                                @php($why = $trace['bank']['explain'][$section['candidate']][$item['id']] ?? null)
                                                <span title="{{ $why ? $sourceName((string) ($why['source'] ?? ''), 'relation_sources').(isset($why['score']) ? ' · '.$why['score'] : '') : '' }}"
                                                      style="font-size:11px;padding:1px 6px;border-radius:999px;border:1px solid {{ in_array($item['id'], $section['added'], true) ? '#16a34a' : 'rgba(127,127,127,.3)' }};{{ ($why['source'] ?? null) === 'ai_match' ? 'background:rgba(37,99,235,.12)' : '' }}">
                                                    {{ \Illuminate\Support\Str::limit($item['title'], 28) }}
                                                </span>
                                            @endforeach
                                            @foreach ($section['dropped'] as $item)
                                                <span style="font-size:11px;padding:1px 6px;border-radius:999px;border:1px dashed #dc2626;color:#dc2626;text-decoration:line-through">{{ \Illuminate\Support\Str::limit($item['title'], 28) }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                            @foreach ($stage['gone'] as $gone)
                                <div style="border-top:1px solid rgba(127,127,127,.18);padding:6px 0;font-size:13px;color:#dc2626;text-decoration:line-through">{{ $gone }}</div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <p style="{{ $small }};margin-top:8px">{{ __('knowledge::map.page.legend') }}</p>
            @endif
        @endif
    @endif
</x-filament-panels::page>
