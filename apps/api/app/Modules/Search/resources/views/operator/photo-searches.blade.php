<x-filament-panels::page>
    @php($s = $this->summary())
    @php($small = 'font-size:12px;opacity:.7')
    @php($btn = 'font-size:12px;padding:3px 10px;border:1px solid #d4d4d8;border-radius:999px;background:#fff;cursor:pointer')
    @php($input = 'padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;font:inherit;min-width:0')
    @php($chip = 'display:inline-block;padding:2px 10px;border-radius:999px;background:rgba(127,127,127,.12);font-size:12px')

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        <select wire:model.live="shop" style="{{ $input }};min-width:220px">
            @foreach ($this->shops() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        <select wire:model.live="show" style="{{ $input }}">
            @foreach (['all', 'unmarked', 'wrong', 'marked'] as $which)
                <option value="{{ $which }}">{{ __('search::ui.photo_log.show.'.$which) }}</option>
            @endforeach
        </select>
    </div>

    @if ($s === null)
        <x-filament::section>{{ __('search::ui.no_shop') }}</x-filament::section>
    @else
        <x-filament::section :heading="__('search::ui.photo_log.measure')" :description="__('search::ui.photo_log.'.($this->operatorView() ? 'measure_about' : 'measure_about_shop'))">
            <div style="display:grid;gap:10px;font-size:14px;line-height:1.6">
                <div style="display:flex;flex-wrap:wrap;gap:18px">
                    <span>{{ __('search::ui.photo_log.asks', ['count' => $s['asks'], 'days' => 30]) }} <span style="{{ $small }}">({{ __('search::ui.photo_log.found_nothing', ['count' => $s['nothing']]) }})</span></span>
                    <span>{{ __('search::ui.photo_log.marked', ['count' => $s['marked'], 'right' => $s['right']]) }}</span>
                    @if ($s['ranks'] !== null)
                        <span data-ranks>{{ __('search::ui.photo_log.ranks', $s['ranks']) }}</span>
                    @endif
                    @if ($s['ranks_second'] !== null && $this->operatorView())
                        <span data-ranks-second>{{ __('search::ui.photo_log.ranks_second', $s['ranks_second']) }}</span>
                    @endif
                    @if ($s['checked'] > 0 && $this->operatorView())
                        <span data-checked>{{ __('search::ui.photo_log.checked', ['right' => $s['checked_right'], 'count' => $s['checked'], 'when' => \Illuminate\Support\Carbon::parse($s['checked_at'])->diffForHumans()]) }}</span>
                    @endif
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
                    @if (! $this->operatorView())
                        <span style="{{ $small }}">{{ __('search::ui.photo_log.mark_how') }}</span>
                    @elseif ($s['running'])
                        <span style="{{ $small }}" wire:poll.10s>{{ __('search::ui.photo_log.check_running') }}</span>
                    @elseif ($s['marked'] > 0 || $s['products'] > 0)
                        <button type="button" wire:click="checkNow" style="{{ $btn }}">{{ __('search::ui.photo_log.check_now') }}</button>
                        <span style="{{ $small }}">{{ __('search::ui.photo_log.check_how') }}</span>
                    @else
                        <span style="{{ $small }}">{{ __('search::ui.photo_log.mark_first') }}</span>
                    @endif
                </div>
                <div style="{{ $small }}">{{ __('search::ui.photo_log.'.($this->operatorView() ? 'ranks_how' : 'ranks_how_shop')) }}</div>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('search::ui.photo_log.heading')">
            @php($asks = $this->asks())
            @php($expectedTitles = $this->expectedTitles($asks))
            @if ($asks === [])
                <div style="{{ $small }}">{{ __('search::ui.photo_log.none') }}</div>
            @else
                <div style="display:grid;gap:14px">
                    @foreach ($asks as $ask)
                        <div style="display:grid;grid-template-columns:96px minmax(0,1fr) minmax(200px,260px);gap:14px;padding:10px 0;border-bottom:1px solid #f1f1f4;font-size:13px;line-height:1.5" data-ask="{{ $ask->id }}">
                            <div>
                                @if ($ask->thumb)
                                    <div style="position:relative;width:96px;line-height:0">
                                        <img src="data:image/jpeg;base64,{{ $ask->thumb }}" alt="" style="width:96px;height:auto;border-radius:8px;background:#f4f4f5">
                                        @if (is_array($ask->box) && count($ask->box) === 4)
                                            @php([$bl, $bt, $br, $bb] = array_map('floatval', $ask->box))
                                            <span title="{{ __('search::ui.photo_log.box') }}" style="position:absolute;left:{{ round($bl * 100, 1) }}%;top:{{ round($bt * 100, 1) }}%;width:{{ round(($br - $bl) * 100, 1) }}%;height:{{ round(($bb - $bt) * 100, 1) }}%;border:2px solid #f59e0b;border-radius:4px;box-shadow:0 0 0 1px rgba(0,0,0,.35)"></span>
                                        @endif
                                    </div>
                                @else
                                    <div style="width:96px;height:96px;border-radius:8px;background:#f4f4f5"></div>
                                @endif
                                <div style="{{ $small }};margin-top:4px">{{ $ask->created_at->diffForHumans() }}</div>
                            </div>
                            <div style="display:grid;gap:6px;min-width:0">
                                <div>
                                    <span style="{{ $small }}">{{ __('search::ui.photo_log.seen') }}</span>
                                    <strong>{{ $ask->object ?? '—' }}</strong>
                                    @if (! empty($ask->details))
                                        <span style="{{ $small }}">· {{ implode(' · ', $ask->details) }}</span>
                                    @endif
                                    @if ($ask->sure !== null)
                                        <span style="{{ $small }}">{{ (int) round($ask->sure * 100) }}%</span>
                                    @endif
                                    @if ($ask->doubt && $this->operatorView())
                                        <span style="{{ $chip }}" title="{{ __('search::ui.photo_log.doubt_about') }}">{{ __('search::ui.photo_log.doubt.'.$ask->doubt) }}</span>
                                    @endif
                                    @if ($ask->second && $this->operatorView())
                                        <span style="{{ $chip }}">{{ __('search::ui.photo_log.second') }}</span>
                                    @endif
                                </div>
                                @if (! empty($ask->items))
                                    <div>
                                        <span style="{{ $small }}">{{ __('search::ui.photo_log.items') }}</span>
                                        @foreach ($ask->items as $at => $name)
                                            <span style="{{ $chip }}{{ $at === (int) $ask->item ? ';background:rgba(245,158,11,.18);font-weight:600' : '' }}">{{ $name }}</span>
                                        @endforeach
                                        <span style="{{ $small }}">{{ __('search::ui.photo_log.item_chosen', ['item' => $ask->items[(int) $ask->item] ?? '']) }}</span>
                                    </div>
                                @endif
                                @if ($ask->cut)
                                    <div style="{{ $small }}">{{ __('search::ui.photo_log.cut') }}</div>
                                @endif
                                <div>
                                    <span style="{{ $small }}">{{ __('search::ui.photo_log.showed') }}</span>
                                    @if ($ask->total === 0)
                                        <span>{{ __('search::ui.photo_log.nothing') }}</span>
                                    @else
                                        <strong>{{ $ask->main ?? '—' }}</strong>
                                        <span style="{{ $small }}">· {{ __('search::ui.photo_log.total', ['count' => $ask->total]) }}</span>
                                    @endif
                                    @foreach ($ask->tags as $tag)
                                        <span style="{{ $chip }}">{{ $tag['title'] }}</span>
                                    @endforeach
                                </div>
                                @if ($ask->results !== [])
                                    @php($described = collect($ask->results)->contains(fn ($r) => isset($r['about'])))
                                    <div style="{{ $small }}">{{ $described ? __('search::ui.photo_log.order_details') : __('search::ui.photo_log.order_look') }} {{ __('search::ui.photo_log.order_words') }}</div>
                                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start">
                                        @foreach (array_slice($ask->results, 0, 8) as $result)
                                            <div style="width:64px;display:grid;gap:2px;font-size:11px;line-height:1.3" title="{{ $result['title'] }}">
                                                @if (! empty($result['image']))
                                                    <img src="{{ $result['image'] }}" alt="" loading="lazy" style="width:64px;height:64px;object-fit:cover;border-radius:6px;background:#f4f4f5">
                                                @else
                                                    <span style="{{ $chip }}">{{ $result['title'] }}</span>
                                                @endif
                                                @if (! empty($result['same']))
                                                    <span style="color:#15803d;font-weight:600">{{ __('search::ui.photo_log.same') }}</span>
                                                @endif
                                                @isset($result['look'])
                                                    <span>{{ __('search::ui.photo_log.look', ['score' => $result['look']]) }}</span>
                                                @endisset
                                                @isset($result['about'])
                                                    <span>{{ __('search::ui.photo_log.about', ['score' => $result['about']]) }}</span>
                                                @endisset
                                                @isset($result['words'])
                                                    <span>{{ __('search::ui.photo_log.words', ['score' => $result['words']]) }}</span>
                                                @endisset
                                                @if (! empty($result['lacks']))
                                                    <span style="color:#b45309">{{ __('search::ui.photo_log.has_lacked') }}</span>
                                                @endif
                                                @if (array_key_exists('kind', $result) && ! $result['kind'] && $described)
                                                    <span style="opacity:.7">{{ __('search::ui.photo_log.other_kind') }}</span>
                                                @endif
                                                @if ($ask->expected_product === ($result['external_id'] ?? null))
                                                    <span style="color:#15803d;font-weight:600">✓ {{ __('search::ui.photo_log.the_product') }}</span>
                                                @else
                                                    <button type="button" wire:click="markProduct('{{ $ask->id }}', @js((string) ($result['external_id'] ?? '')))" style="{{ $btn }};font-size:11px;padding:1px 6px">{{ __('search::ui.photo_log.this_product') }}</button>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
                                    @if ($ask->expected_product !== null)
                                        <span data-expected>{{ __('search::ui.photo_log.expected_product', ['title' => $expectedTitles[$ask->expected_product] ?? $ask->expected_product]) }}</span>
                                        @if ($ask->ranked_at !== null)
                                            <span style="{{ $chip }}">{{ $ask->checked_rank === null ? __('search::ui.photo_log.rank_missing') : __('search::ui.photo_log.rank_now', ['rank' => $ask->checked_rank]) }}</span>
                                            @if ($ask->checked_rank_ranked !== null && $this->operatorView())
                                                <span style="{{ $chip }}">{{ __('search::ui.photo_log.rank_second', ['rank' => $ask->checked_rank_ranked]) }}</span>
                                            @endif
                                        @endif
                                    @endif
                                    <input type="text" wire:model="product.{{ $ask->id }}" placeholder="{{ __('search::ui.photo_log.product_field') }}" style="{{ $input }};font-size:12px;min-width:220px">
                                    <button type="button" wire:click="markProduct('{{ $ask->id }}')" style="{{ $btn }}">{{ __('search::ui.photo_log.product_mark') }}</button>
                                </div>
                                @if ($ask->checked_at !== null && $this->operatorView())
                                    <div style="{{ $small }}" data-check>
                                        {{ $ask->checked_right ? '✓' : '✗' }}
                                        {{ __('search::ui.photo_log.check_result', ['main' => $ask->checked_main ?? __('search::ui.photo_log.nothing'), 'when' => $ask->checked_at->diffForHumans()]) }}
                                    </div>
                                @endif
                            </div>
                            <div style="display:grid;gap:6px;align-content:start">
                                @if ($ask->verdict === null)
                                    <div style="display:flex;gap:6px">
                                        <button type="button" wire:click="markRight('{{ $ask->id }}')" style="{{ $btn }}">✓ {{ __('search::ui.photo_log.right') }}</button>
                                        <button type="button" wire:click="markWrong('{{ $ask->id }}')" style="{{ $btn }}">✗ {{ __('search::ui.photo_log.wrong') }}</button>
                                    </div>
                                    <input type="text" wire:model="expected.{{ $ask->id }}" placeholder="{{ __('search::ui.photo_log.expected') }}" style="{{ $input }};font-size:13px">
                                @else
                                    <div>
                                        <span style="{{ $chip }};{{ $ask->verdict === 'right' ? 'background:rgba(27,175,122,.14)' : 'background:rgba(235,104,52,.14)' }}">
                                            {{ $ask->verdict === 'right' ? '✓ '.__('search::ui.photo_log.right') : '✗ '.__('search::ui.photo_log.wrong') }}
                                        </span>
                                        @if ($ask->expected)
                                            <span>{{ __('search::ui.photo_log.was', ['what' => $ask->expected]) }}</span>
                                        @endif
                                    </div>
                                    <button type="button" wire:click="unmark('{{ $ask->id }}')" style="{{ $btn }};justify-self:start">{{ __('search::ui.photo_log.unmark') }}</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
