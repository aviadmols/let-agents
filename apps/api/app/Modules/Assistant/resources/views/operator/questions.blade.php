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
                <x-filament::section compact>
                    <div style="{{ $small }}">{{ __("assistant::ui.questions.totals.{$key}") }}</div>
                    <div style="font-size:22px;font-weight:600;margin-top:4px" dir="ltr">{{ $value }}</div>
                </x-filament::section>
            @endforeach
        </div>

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
