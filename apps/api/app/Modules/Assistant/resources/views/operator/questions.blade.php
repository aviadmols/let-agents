<x-filament-panels::page>
    @php($q = $this->questions())
    @php($small = 'font-size:12px;opacity:.7')
    @php($btn = 'font-size:12px;padding:2px 8px;border:1px solid #d4d4d8;border-radius:999px;background:#fff')
    @php($box = 'padding:10px 12px;border:1px solid #e4e4e7;border-radius:10px;background:#fff')

    <div>
        @if ($this->picksShop())
        <select wire:model.live="shop" style="padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;min-width:220px">
            @foreach ($this->shops() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        @endif
    </div>

    @if ($q === null)
        <x-filament::section>{{ __('assistant::ui.questions.no_shop') }}</x-filament::section>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px">
            @foreach ([
                'questions' => number_format($q['totals']['questions']),
                'distinct' => number_format($q['totals']['distinct']),
                'open' => number_format($q['totals']['open']),
                'from_team' => number_format($q['totals']['from_team']),
                'cost' => '$'.number_format($q['totals']['cost'], 3),
            ] as $key => $value)
                @continue($key === 'cost' && ! $this->operatorView())
                <x-filament::section compact>
                    <div style="{{ $small }}">{{ __("assistant::ui.questions.totals.{$key}") }}</div>
                    <div style="font-size:22px;font-weight:600;margin-top:4px" dir="ltr">{{ $value }}</div>
                </x-filament::section>
            @endforeach
        </div>

        @if ($a = $this->searchAsks())
            <x-filament::section :heading="__('assistant::ui.asks.heading')" :description="__('assistant::ui.asks.description')">
                @if ($a['latest'])
                    @php($latest = $a['latest'])
                    @php($tone = $latest->score === null ? 'rgba(127,127,127,.15)' : ($latest->score >= 80 ? 'rgba(27,175,122,.16)' : ($latest->score >= 60 ? 'rgba(235,170,52,.2)' : 'rgba(220,60,60,.15)')))
                    <div style="display:grid;grid-template-columns:auto minmax(0,1fr);gap:16px;align-items:start">
                        <div style="display:grid;place-items:center;min-width:96px;padding:12px;border-radius:16px;background:{{ $tone }}">
                            <div style="font-size:32px;font-weight:700;line-height:1" dir="ltr">{{ $latest->score ?? '—' }}</div>
                            <div style="{{ $small }}">{{ __('assistant::ui.asks.out_of') }}</div>
                        </div>
                        <div style="display:grid;gap:8px;min-width:0">
                            <div style="{{ $small }}">{{ __('assistant::ui.asks.report_for', ['day' => $latest->day->format('d/m/Y')]) }}</div>
                            @if ($latest->summary)<p style="margin:0">{{ $latest->summary }}</p>@endif
                            <div style="display:flex;flex-wrap:wrap;gap:6px">
                                @foreach (['asks', 'answered', 'no_match', 'whatsapp_shown', 'whatsapp_clicked', 'picked'] as $count)
                                    <span style="font-size:12.5px;padding:2px 10px;border-radius:999px;background:rgba(127,127,127,.12)">{{ __('assistant::ui.asks.counts.'.$count, ['count' => number_format((int) ($latest->counts[$count] ?? 0))]) }}</span>
                                @endforeach
                            </div>
                            @if ($latest->improvements)
                                <div>
                                    <strong style="font-size:13px">{{ __('assistant::ui.asks.improvements') }}</strong>
                                    <ul style="margin:4px 0 0;padding-inline-start:20px">
                                        @foreach ($latest->improvements as $line)<li>{{ $line }}</li>@endforeach
                                    </ul>
                                </div>
                            @endif
                            @if (count($a['trend']) > 1)
                                <div style="{{ $small }}">{{ __('assistant::ui.asks.trend') }}: @foreach ($a['trend'] as $point)<span dir="ltr">{{ $point['day'] }} · {{ $point['score'] ?? '—' }}</span>@if (! $loop->last) &nbsp;|&nbsp; @endif @endforeach</div>
                            @endif
                        </div>
                    </div>
                @else
                    <p style="margin:0;opacity:.75">{{ __('assistant::ui.asks.no_report') }}</p>
                @endif
                @if ($this->operatorView())
                    <div style="margin-top:12px"><button type="button" wire:click="reviewNow" wire:loading.attr="disabled" style="{{ $btn }}">{{ __('assistant::ui.asks.review_now') }}</button></div>
                @endif
            </x-filament::section>

            <x-filament::section :heading="__('assistant::ui.asks.list_heading')" :description="__('assistant::ui.asks.list_description')" collapsible>
                @if ($a['asks']->isEmpty())
                    <p style="margin:0;opacity:.75">{{ __('assistant::ui.asks.none') }}</p>
                @else
                    <div style="display:grid;gap:10px">
                        @foreach ($a['asks'] as $ask)
                            @php($note = $a['notes'][$ask->id] ?? null)
                            <div style="{{ $box }};display:grid;gap:6px">
                                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;justify-content:space-between">
                                    <strong>{{ $ask->question }}</strong>
                                    <span style="{{ $small }}">{{ $ask->created_at->diffForHumans() }}</span>
                                </div>
                                <div style="display:flex;flex-wrap:wrap;gap:6px;font-size:12.5px">
                                    <span style="padding:1px 9px;border-radius:999px;{{ $ask->outcome === 'answered' ? 'background:rgba(27,175,122,.16)' : 'background:rgba(235,104,52,.14)' }}">{{ __('assistant::ui.asks.outcomes.'.$ask->outcome) }}</span>
                                    @if ($ask->whatsapp_shown)<span style="padding:1px 9px;border-radius:999px;background:rgba(31,157,85,.14)">{{ __('assistant::ui.asks.'.($ask->whatsapp_clicked ? 'whatsapp_clicked' : 'whatsapp_shown')) }}</span>@endif
                                    @if ($ask->reason && $this->operatorView())<span style="padding:1px 9px;border-radius:999px;background:rgba(127,127,127,.14)" dir="ltr" title="{{ __('assistant::ui.asks.reason_title') }}">{{ __('assistant::ui.asks.reasons.'.$ask->reason) !== 'assistant::ui.asks.reasons.'.$ask->reason ? __('assistant::ui.asks.reasons.'.$ask->reason) : $ask->reason }}</span>@endif
                                    @if ($ask->picked)<span style="padding:1px 9px;border-radius:999px;background:rgba(126,34,206,.12)">{{ __('assistant::ui.asks.picked') }}</span>@endif
                                    @if ($note)<span style="padding:1px 9px;border-radius:999px;background:rgba(127,127,127,.14)">{{ __('assistant::ui.asks.score', ['score' => $note['score']]) }}</span>@endif
                                </div>
                                @if ($ask->picks)
                                    <ol style="margin:0;padding-inline-start:20px;font-size:13.5px">
                                        @foreach ($ask->picks as $pick)<li><strong>{{ $pick['title'] }}</strong> · <span style="opacity:.8">{{ $pick['why'] }}</span></li>@endforeach
                                    </ol>
                                @endif
                                @if ($note && $note['note'] !== '')<div style="{{ $small }}">{{ $note['note'] }}</div>@endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('assistant::ui.questions.open_heading')" :description="__('assistant::ui.questions.open_description')">
            @if ($q['open']->isEmpty())
                <p style="opacity:.7">{{ __('assistant::ui.questions.none_open') }}</p>
            @else
                <div style="display:grid;gap:14px">
                    @foreach ($q['open'] as $rows)
                        @php($product = $rows->first()->page())
                        <div>
                            <div style="font-weight:600;margin-bottom:6px">
                                @if ($product === null){{ __('assistant::ui.questions.from_search') }}@elseif ($product->url)<a href="{{ $product->url }}" target="_blank" rel="noopener" style="text-decoration:underline">{{ $product->title }}</a>@else{{ $product->title }}@endif
                                @if ($product)<span style="{{ $small }}">#{{ $product->external_id }}</span>@endif
                            </div>
                            <div style="display:grid;gap:8px">
                                @foreach ($rows as $row)
                                    <div style="{{ $box }}">
                                        <div>{{ $row->question }} <span style="{{ $small }}">· {{ trans_choice('assistant::ui.questions.asked_times', $row->asked_count, ['count' => $row->asked_count]) }} · {{ $row->last_asked_at->diffForHumans() }}</span></div>
                                        <div style="display:flex;gap:8px;margin-top:8px;align-items:flex-start">
                                            <textarea wire:model="drafts.{{ $row->id }}" rows="2" placeholder="{{ __('assistant::ui.questions.write_answer') }}" style="flex:1;padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;font:inherit"></textarea>
                                            <button type="button" wire:click="answer('{{ $row->id }}')" style="{{ $btn }};background:#111827;color:#fff;border-color:#111827">{{ __('assistant::ui.questions.publish') }}</button>
                                            <button type="button" wire:click="hide('{{ $row->id }}')" style="{{ $btn }}">{{ __('assistant::ui.questions.hide') }}</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('assistant::ui.questions.answered_heading')" :description="__('assistant::ui.questions.answered_description')" collapsible>
            @if ($q['answered']->isEmpty())
                <p style="opacity:.7">{{ __('assistant::ui.questions.none_answered') }}</p>
            @else
                <div style="display:grid;gap:14px">
                    @foreach ($q['answered'] as $rows)
                        @php($product = $rows->first()->page())
                        <div>
                            <div style="font-weight:600;margin-bottom:6px">
                                @if ($product === null){{ __('assistant::ui.questions.from_search') }}@elseif ($product->url)<a href="{{ $product->url }}" target="_blank" rel="noopener" style="text-decoration:underline">{{ $product->title }}</a>@else{{ $product->title }}@endif
                                <span style="{{ $small }}">@if ($product)#{{ $product->external_id }} · @endif{{ trans_choice('assistant::ui.questions.asked_times', $rows->sum('asked_count'), ['count' => $rows->sum('asked_count')]) }}</span>
                            </div>
                            <div style="display:grid;gap:8px">
                                @foreach ($rows as $row)
                                    <div style="{{ $box }}">
                                        <div style="font-weight:600">{{ $row->question }} <span style="{{ $small }};font-weight:400">· {{ trans_choice('assistant::ui.questions.asked_times', $row->asked_count, ['count' => $row->asked_count]) }}</span></div>
                                        <div style="margin-top:4px">{{ $row->answer }}</div>
                                        <div style="{{ $small }};margin-top:4px">
                                            {{ __('assistant::ui.questions.sources.'.($row->source ?? 'store')) }}
                                            @if ($row->source !== 'team') · {{ $row->model }} · ${{ number_format((float) $row->cost_usd, 4) }} @endif
                                        </div>
                                        <details style="margin-top:6px">
                                            <summary style="{{ $small }};cursor:pointer">{{ __('assistant::ui.questions.edit') }}</summary>
                                            <div style="display:flex;gap:8px;margin-top:6px;align-items:flex-start">
                                                <textarea wire:model="drafts.{{ $row->id }}" rows="2" placeholder="{{ __('assistant::ui.questions.write_answer') }}" style="flex:1;padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;font:inherit"></textarea>
                                                <button type="button" wire:click="answer('{{ $row->id }}')" style="{{ $btn }}">{{ __('assistant::ui.questions.publish') }}</button>
                                                <button type="button" wire:click="hide('{{ $row->id }}')" style="{{ $btn }}">{{ __('assistant::ui.questions.hide') }}</button>
                                            </div>
                                        </details>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('assistant::ui.questions.refused_heading')" :description="__('assistant::ui.questions.refused_description')" collapsible collapsed>
            @if ($q['refused']->isEmpty())
                <p style="opacity:.7">{{ __('assistant::ui.questions.none_refused') }}</p>
            @else
                <ul style="display:grid;gap:6px">
                    @foreach ($q['refused'] as $row)
                        <li>{{ $row->question }} <span style="{{ $small }}">· {{ $row->page()?->title }}</span>
                            <details style="display:inline-block;margin-inline-start:6px"><summary style="{{ $small }};cursor:pointer;display:inline">{{ __('assistant::ui.questions.answer_anyway') }}</summary>
                                <div style="display:flex;gap:8px;margin-top:6px">
                                    <textarea wire:model="drafts.{{ $row->id }}" rows="2" style="flex:1;padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;font:inherit"></textarea>
                                    <button type="button" wire:click="answer('{{ $row->id }}')" style="{{ $btn }}">{{ __('assistant::ui.questions.publish') }}</button>
                                </div>
                            </details>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        @if ($q['hidden']->isNotEmpty())
            <x-filament::section :heading="__('assistant::ui.questions.hidden_heading')" collapsible collapsed>
                <ul style="display:grid;gap:6px">
                    @foreach ($q['hidden'] as $row)
                        <li>{{ $row->question }} <span style="{{ $small }}">· {{ $row->page()?->title }}</span>
                            <button type="button" wire:click="show('{{ $row->id }}')" style="{{ $btn }}">{{ __('assistant::ui.questions.show') }}</button>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
