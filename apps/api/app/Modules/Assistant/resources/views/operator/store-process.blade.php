<x-filament-panels::page>
    @php($t = fn (string $key, array $params = []) => __('assistant::ui.process.'.$key, $params))
    @php($label = fn (string $group, string $key) => \Illuminate\Support\Facades\Lang::has("assistant::ui.process.{$group}.{$key}") ? __("assistant::ui.process.{$group}.{$key}") : $key)
    @php($cell = 'padding:6px 8px;border-top:1px solid rgba(127,127,127,.25);vertical-align:top;text-align:start')
    @php($table = 'width:100%;font-size:13px;border-collapse:collapse')
    @php($muted = 'opacity:.65;font-size:12px')
    @php($card = 'border:1px solid rgba(127,127,127,.25);border-radius:10px;padding:10px 12px')
    @php($scanned = $this->scanned())

    {{-- 1. What was read from the store --}}
    <x-filament::section :heading="$t('scanned')" :description="$t('scanned_hint')">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
            <div style="{{ $card }}">
                <div style="font-weight:600">{{ $t('products') }}</div>
                <div>{{ $t('products_line', ['all' => $scanned['products']['all'], 'active' => $scanned['products']['active'], 'in_stock' => $scanned['products']['in_stock']]) }}</div>
            </div>
            <div style="{{ $card }}">
                <div style="font-weight:600">{{ $t('content') }}</div>
                @forelse ($scanned['content'] as $type => $n)
                    <div>{{ $type }}: {{ $n }}</div>
                @empty
                    <div style="{{ $muted }}">{{ $t('none') }}</div>
                @endforelse
            </div>
            <div style="{{ $card }}">
                <div style="font-weight:600">{{ $t('index') }}</div>
                @forelse ($scanned['index'] as $row)
                    <div>{{ $row['source'] }}: {{ $row['n'] }} <span style="{{ $muted }}" dir="ltr">{{ $row['model'] }}</span></div>
                @empty
                    <div style="{{ $muted }}">{{ $t('none') }}</div>
                @endforelse
            </div>
            <div style="{{ $card }}">
                <div style="font-weight:600">{{ $t('vocabularies') }}</div>
                @forelse ($scanned['vocabularies'] as $vocabulary)
                    <div><span dir="ltr">{{ $vocabulary->key }}</span> v{{ $vocabulary->version }} <span style="{{ $muted }}">{{ $vocabulary->created_at?->format('d/m/Y') }}</span></div>
                @empty
                    <div style="{{ $muted }}">{{ $t('no_vocabulary') }}</div>
                @endforelse
            </div>
            <div style="{{ $card }}">
                <div style="font-weight:600">{{ $t('facts') }}</div>
                <div>{{ $t('products_with_facts', ['n' => $scanned['products_with_facts'], 'readings' => $scanned['code_readings']]) }}</div>
                @foreach ($scanned['facts_by_status'] as $status => $n)
                    <div>{{ \App\Modules\Enrichment\Enums\FactStatus::tryFrom($status)?->label() ?? $status }}: {{ $n }}</div>
                @endforeach
            </div>
            <div style="{{ $card }}">
                <div style="font-weight:600">{{ $t('approved_facts') }}</div>
                @forelse ($scanned['approved_by_kind'] as $kind => $n)
                    <div>{{ \App\Modules\Enrichment\Enums\FactKind::tryFrom($kind)?->label() ?? $kind }}: {{ $n }}</div>
                @empty
                    <div style="{{ $muted }}">{{ $t('none') }}</div>
                @endforelse
                @if ($scanned['approved_by_origin'] !== [])
                    <div style="{{ $muted }};margin-top:4px">
                        {{ collect($scanned['approved_by_origin'])->map(fn ($n, $origin) => (\App\Modules\Enrichment\Enums\FactOrigin::tryFrom($origin)?->label() ?? $origin).': '.$n)->implode(' · ') }}
                    </div>
                @endif
            </div>
        </div>
    </x-filament::section>

    {{-- 2. The last run of every agent on this shop --}}
    <x-filament::section :heading="$t('steps')" :description="$t('steps_hint')" collapsible>
        <div style="overflow-x:auto">
            <table style="{{ $table }}">
                <thead><tr style="opacity:.7">
                    <th style="{{ $cell }}">{{ $t('agent') }}</th>
                    <th style="{{ $cell }}">{{ $t('status') }}</th>
                    <th style="{{ $cell }}">{{ $t('summary') }}</th>
                    <th style="{{ $cell }}">{{ $t('when') }}</th>
                    <th style="{{ $cell }}">{{ $t('cost') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($this->steps() as $run)
                    <tr>
                        <td style="{{ $cell }}">
                            <a href="{{ $this->runUrl($run->id) }}" style="text-decoration:underline">{{ $run->agentLabel() }}</a>
                            <div style="{{ $muted }}">{{ $run->actionLabel() }}</div>
                        </td>
                        <td style="{{ $cell }}"><x-filament::badge :color="$run->status->color()">{{ $run->status->label() }}</x-filament::badge></td>
                        <td style="{{ $cell }}">{{ $run->summary() }}</td>
                        <td style="{{ $cell }};white-space:nowrap">{{ $run->created_at?->format('d/m H:i') }}</td>
                        <td style="{{ $cell }}" dir="ltr">{{ $run->cost_usd !== null ? '$'.rtrim(rtrim((string) $run->cost_usd, '0'), '.') : '' }}</td>
                    </tr>
                @empty
                    <tr><td style="{{ $cell }}" colspan="5">{{ $t('none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- 3. The benchmarks' reports --}}
    <x-filament::section :heading="$t('reports')" :description="$t('reports_hint')">
        @php($reports = $this->reports())
        @if ($reports->isEmpty())
            <p style="{{ $muted }}">{{ $t('no_reports') }}</p>
        @else
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                @foreach ($reports as $item)
                    <x-filament::button size="sm" :color="$this->report === $item->id ? 'primary' : 'gray'" wire:click="open('{{ $item->id }}')">
                        {{ $label('kinds', $item->kind) }} · <span dir="ltr">{{ $item->label }}</span> · {{ $t('questions_n', ['n' => $item->questions]) }} · {{ $item->updated_at?->format('d/m H:i') }}
                    </x-filament::button>
                @endforeach
            </div>
        @endif

        @php($open = $this->openReport())
        @if ($open)
            @php($s = $open->summary)
            <div style="margin-top:14px">
                @if ($open->kind === \App\Modules\Assistant\Models\AssistantBenchReport::FACTSHEET)
                    @php($writers = $s['writers'] ?? [])
                    <p>{{ $t('factsheet_line', ['questions' => $s['questions'] ?? 0, 'answerable' => $s['answerable'] ?? 0]) }}</p>
                    <div style="overflow-x:auto">
                        <table style="{{ $table }}">
                            <thead><tr style="opacity:.7"><th style="{{ $cell }}"></th>
                                @foreach (array_keys($writers) as $writer)<th style="{{ $cell }}" dir="ltr">{{ $writer }}</th>@endforeach
                            </tr></thead>
                            <tbody>
                            @foreach (['final_accuracy_after_checker_pct', 'raw_accuracy_pct', 'hallucination_pct', 'wrong_attribution_pct', 'code_wrong_owner', 'coverage_pct', 'missed_answer_pct', 'correct_product_selection_pct', 'internal_id_leak_pct', 'checker_intervention_pct', 'checker_rejection_pct', 'best_by_judge'] as $metric)
                                <tr><td style="{{ $cell }}">{{ $label('metrics', $metric) }}</td>
                                    @foreach ($writers as $w)<td style="{{ $cell }}" dir="ltr">{{ $w[$metric] ?? '-' }}</td>@endforeach
                                </tr>
                            @endforeach
                            <tr><td style="{{ $cell }}">{{ $label('metrics', 'latency') }}</td>
                                @foreach ($writers as $w)<td style="{{ $cell }}" dir="ltr">{{ isset($w['latency_ms']['avg']) ? round($w['latency_ms']['avg'] / 1000, 1).' / '.round($w['latency_ms']['p95'] / 1000, 1).'s' : '-' }}</td>@endforeach
                            </tr>
                            <tr><td style="{{ $cell }}">{{ $label('metrics', 'tokens') }}</td>
                                @foreach ($writers as $w)<td style="{{ $cell }}" dir="ltr">{{ ($w['tokens_avg']['in'] ?? '-').' / '.($w['tokens_avg']['out'] ?? '-') }}</td>@endforeach
                            </tr>
                            <tr><td style="{{ $cell }}">{{ $label('metrics', 'cost') }}</td>
                                @foreach ($writers as $w)<td style="{{ $cell }}" dir="ltr">${{ $w['cost_per_question_usd']['total'] ?? '-' }}</td>@endforeach
                            </tr>
                            </tbody>
                        </table>
                    </div>

                    <h3 style="font-weight:600;margin:14px 0 6px">{{ $t('per_question') }}</h3>
                    <div style="overflow-x:auto">
                        <table style="{{ $table }}">
                            <thead><tr style="opacity:.7"><th style="{{ $cell }}">{{ $t('question') }}</th>
                                @foreach (array_keys($writers) as $writer)<th style="{{ $cell }}" dir="ltr">{{ $writer }}</th>@endforeach
                            </tr></thead>
                            <tbody>
                            @foreach ($open->rows as $row)
                                <tr>
                                    <td style="{{ $cell }};min-width:220px">
                                        {{ $row['question'] }}
                                        <div style="{{ $muted }}">{{ $row['answerable'] ? $t('answerable') : $t('not_answerable') }} — <span dir="ltr">{{ $row['answerable_note'] }}</span></div>
                                    </td>
                                    @foreach (array_keys($writers) as $writer)
                                        @php($side = $row['writers'][$writer] ?? null)
                                        <td style="{{ $cell }};min-width:180px">
                                            @if ($side === null)
                                                -
                                            @elseif (isset($side['error']))
                                                <x-filament::badge color="gray">{{ $side['error'] }}</x-filament::badge>
                                            @else
                                                <x-filament::badge :color="$side['correct'] ? 'success' : 'danger'">{{ $side['correct'] ? $t('right') : $t('wrong') }}</x-filament::badge>
                                                @if ($side['hallucination'])<x-filament::badge color="warning">{{ $t('made_up') }}</x-filament::badge>@endif
                                                @if ($side['wrong_attribution'])<x-filament::badge color="warning">{{ $t('misattributed') }}</x-filament::badge>@endif
                                                <div style="font-size:12px;margin-top:4px">{{ $side['shown'] ? $side['answer'] : $t('nothing_shown') }}</div>
                                                @if ($side['picks'] !== [])<div style="{{ $muted }}">{{ implode(' · ', $side['picks']) }}</div>@endif
                                                @if ($side['note'] !== '')<div style="{{ $muted }}" dir="ltr">{{ $side['note'] }}</div>@endif
                                                @if ($side['refusal'])<div style="{{ $muted }}" dir="ltr">code: {{ $side['refusal'] }}</div>@endif
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p>{{ $t('retrieval_line', ['questions' => $s['questions'] ?? 0, 'answerable' => $s['answerable_in_store'] ?? 0, 'not' => $s['not_answerable_in_store'] ?? 0]) }}</p>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px">
                        @foreach (['reached', 'products', 'pages'] as $part)
                            <div style="{{ $card }}">
                                <div style="font-weight:600;margin-bottom:4px">{{ $label('parts', $part) }}</div>
                                @foreach ($s[$part] ?? [] as $key => $value)
                                    <div style="display:flex;justify-content:space-between;gap:8px"><span dir="ltr">{{ $label('reached', $key) }}</span><span dir="ltr">{{ $value }}</span></div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>

                    @if (! empty($s['attribution']))
                        @php($layers = [...\App\Modules\Assistant\Benchmark\RetrievalBench::LAYERS, 'correct_from_partial'])
                        <h3 style="font-weight:600;margin:14px 0 6px">{{ $t('attribution') }}</h3>
                        <div style="overflow-x:auto">
                            <table style="{{ $table }}">
                                <thead><tr style="opacity:.7"><th style="{{ $cell }}"></th>
                                    @foreach ($layers as $layer)<th style="{{ $cell }}">{{ $label('layers', $layer) }}</th>@endforeach
                                </tr></thead>
                                <tbody>
                                @foreach ($s['attribution'] as $writer => $counts)
                                    <tr><td style="{{ $cell }}" dir="ltr">{{ $writer }}</td>
                                        @foreach ($layers as $layer)<td style="{{ $cell }}">{{ $counts[$layer] ?? 0 }}</td>@endforeach
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <h3 style="font-weight:600;margin:14px 0 6px">{{ $t('per_question') }}</h3>
                    <div style="overflow-x:auto">
                        <table style="{{ $table }}">
                            <thead><tr style="opacity:.7">
                                <th style="{{ $cell }}">{{ $t('question') }}</th>
                                <th style="{{ $cell }}">{{ $t('in_store') }}</th>
                                <th style="{{ $cell }}">{{ $t('reached_col') }}</th>
                                <th style="{{ $cell }}">{{ $t('missed') }}</th>
                                <th style="{{ $cell }}">{{ $t('layers_col') }}</th>
                            </tr></thead>
                            <tbody>
                            @foreach ($open->rows as $row)
                                <tr>
                                    <td style="{{ $cell }};min-width:200px">{{ $row['question'] }}<div style="{{ $muted }}" dir="ltr">{{ $row['note'] }}</div></td>
                                    <td style="{{ $cell }}">{{ $row['answerable_in_store'] ? $t('answerable') : $t('not_answerable') }}</td>
                                    <td style="{{ $cell }};white-space:nowrap">{{ $row['reached'] }} / {{ $row['relevant'] }}</td>
                                    <td style="{{ $cell }};font-size:12px">{{ implode(' · ', $row['missed']) }}</td>
                                    <td style="{{ $cell }};font-size:12px">
                                        @foreach ($row['layers'] as $writer => $layer)
                                            <div><span dir="ltr">{{ $writer }}</span>: {{ $label('layers', $layer) }}</div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>

    {{-- 4. The shop's log --}}
    <x-filament::section :heading="$t('log')" :description="$t('log_hint')" collapsible>
        <select wire:model.live="agent" style="padding:6px 10px;border:1px solid rgba(127,127,127,.4);border-radius:8px;margin-bottom:8px;background:transparent">
            <option value="">{{ $t('every_agent') }}</option>
            @foreach ($this->steps() as $run)
                <option value="{{ $run->agent }}">{{ $run->agentLabel() }}</option>
            @endforeach
        </select>
        <div style="overflow-x:auto">
            <table style="{{ $table }}">
                <thead><tr style="opacity:.7">
                    <th style="{{ $cell }}">{{ $t('when') }}</th>
                    <th style="{{ $cell }}">{{ $t('agent') }}</th>
                    <th style="{{ $cell }}">{{ $t('status') }}</th>
                    <th style="{{ $cell }}">{{ $t('summary') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($this->log() as $run)
                    <tr>
                        <td style="{{ $cell }};white-space:nowrap"><a href="{{ $this->runUrl($run->id) }}" style="text-decoration:underline">{{ $run->created_at?->format('d/m H:i') }}</a></td>
                        <td style="{{ $cell }}">{{ $run->agentLabel() }}<div style="{{ $muted }}">{{ $run->actionLabel() }}</div></td>
                        <td style="{{ $cell }}"><x-filament::badge :color="$run->status->color()">{{ $run->status->label() }}</x-filament::badge></td>
                        <td style="{{ $cell }}">{{ $run->summary() }}@if ($run->error)<div style="{{ $muted }}" dir="ltr">{{ \Illuminate\Support\Str::limit($run->error, 200) }}</div>@endif</td>
                    </tr>
                @empty
                    <tr><td style="{{ $cell }}" colspan="4">{{ $t('none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
