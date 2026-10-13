<x-filament-panels::page>
    @php($json = fn ($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
    @php($small = 'font-size:12px;opacity:.7')
    @php($btn = 'font-size:12px;padding:2px 8px;border:1px solid #d4d4d8;border-radius:999px;background:#fff')

    <x-filament::section>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('widget::ui.page.search') }}"
               style="width:100%;padding:8px 12px;border:1px solid #d4d4d8;border-radius:8px">
        @if ($this->matches()->isNotEmpty())
            <ul style="margin-top:8px;display:grid;gap:4px">
                @foreach ($this->matches() as $match)
                    <li>
                        <button type="button" wire:click="pick('{{ $match['shop_id'] }}', '{{ $match['type'] }}', '{{ $match['external_id'] }}')" style="text-decoration:underline;text-align:start">{{ $match['title'] }}</button>
                        <span style="{{ $small }}">#{{ $match['external_id'] }} · {{ __('widget::ui.preview.page_types.'.$match['type']) }}@if ($this->picksShop()) · {{ $match['shop'] }}@endif</span>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($this->recent()->isNotEmpty())
            <p style="margin-top:10px;{{ $small }}">{{ __('widget::ui.page.recent') }}</p>
            <ul style="margin-top:4px;display:grid;gap:4px">
                @foreach ($this->recent() as $item)
                    <li>
                        <button type="button" wire:click="pick('{{ $item['shop_id'] }}', '{{ $item['type'] }}', '{{ $item['external_id'] }}')" style="text-decoration:underline;text-align:start">{{ $item['title'] }}</button>
                        <span style="{{ $small }}">{{ __('widget::ui.preview.page_types.'.$item['type']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    @php($page = $this->page())

    @if ($page === null)
        <p style="opacity:.7">{{ __('widget::ui.page.pick') }}</p>
    @elseif ($page['subject'] === null)
        <p style="opacity:.7">{{ __('widget::ui.page.not_found') }}</p>
    @else
        @php($bank = $page['bank'])
        @php($why = $bank['explain'] ?? [])

        <x-filament::section :heading="$page['subject']->title" :description="'#'.$page['subject']->external_id">
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
                @if ($page['preview_url'])
                    <a href="{{ $page['preview_url'] }}" target="_blank" rel="noopener" style="text-decoration:underline">{{ __('widget::ui.page.open_preview') }}</a>
                @endif
                <span style="{{ $small }}">{{ __('widget::ui.page.circles', ['count' => count($bank['sections'])]) }} · {{ __('widget::ui.page.max_products', ['count' => $this->maxProducts()]) }}</span>
                @if (! $bank['enabled'])
                    <x-filament::badge color="gray">{{ __('widget::ui.page.disabled') }}</x-filament::badge>
                @endif
            </div>
            <div style="margin-top:12px">
                <x-filament::button size="sm" color="gray" wire:click="rescan" wire:loading.attr="disabled">{{ __('widget::ui.page.rescan') }}</x-filament::button>
            </div>

            @if ($this->changed !== null)
                <div style="margin-top:10px;padding:10px 12px;border-radius:10px;border:1px solid #e4e4e7;background:#fafafa;font-size:13px;line-height:1.7">
                    @if ($this->changed['added'] === [] && $this->changed['removed'] === [] && $this->changed['sections'] === [])
                        {{ __('widget::ui.page.rescan_same') }}
                    @else
                        @foreach ($this->changed['added'] as $line)
                            <div style="color:#15803d">+ {{ $line }}</div>
                        @endforeach
                        @foreach ($this->changed['removed'] as $line)
                            <div style="color:#b91c1c">− {{ $line }}</div>
                        @endforeach
                        @foreach ($this->changed['sections'] as $candidate => $count)
                            <div style="{{ $small }}">{{ __('widget::bank.chips.'.$candidate) }}: {{ $count[0] }} → {{ $count[1] }}</div>
                        @endforeach
                    @endif
                </div>
            @endif

            @if ($page['hidden_sections'] !== [])
                <p style="margin-top:10px;{{ $small }}">{{ __('widget::ui.page.hidden_sections') }}:
                    @foreach ($page['hidden_sections'] as $candidate)
                        <span style="margin-inline-start:6px">{{ __('widget::bank.chips.'.$candidate) }}
                            <button type="button" wire:click="clear('{{ $candidate }}')" style="{{ $btn }}">{{ __('widget::ui.page.show_again') }}</button>
                        </span>
                    @endforeach
                </p>
            @endif
            @foreach ($page['hidden_items'] as $candidate => $items)
                <p style="margin-top:6px;{{ $small }}">{{ __('widget::ui.page.hidden_in', ['section' => __('widget::bank.chips.'.$candidate)]) }}:
                    @foreach ($items as $item)
                        <span style="margin-inline-start:6px">{{ $page['titles'][$item] ?? $item }}
                            <button type="button" wire:click="clear('{{ $candidate }}', '{{ $item }}')" style="{{ $btn }}">{{ __('widget::ui.page.show_again') }}</button>
                        </span>
                    @endforeach
                </p>
            @endforeach
        </x-filament::section>

        @php($activity = $page['activity'])
        @php($counts = $activity['by_candidate'])
        @php($cell = 'padding:6px 10px;text-align:start;border-bottom:1px solid #f1f1f3')

        <x-filament::section
            :heading="__('widget::ui.page.overview')"
            :description="__('widget::ui.page.overview_description', ['days' => $activity['days'], 'views' => number_format($activity['views'])])"
        >
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead>
                        <tr style="{{ $small }}">
                            <th style="{{ $cell }}">{{ __('widget::ui.page.part') }}</th>
                            <th style="{{ $cell }}">{{ __('widget::ui.page.exposures') }}</th>
                            <th style="{{ $cell }}">{{ __('widget::ui.page.opens') }}</th>
                            <th style="{{ $cell }}">{{ __('widget::ui.page.clicks') }}</th>
                            <th style="{{ $cell }}">{{ __('widget::ui.page.adds') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bank['sections'] as $position => $section)
                            @php($n = $counts[$section['candidate']] ?? [])
                            <tr>
                                <td style="{{ $cell }}">
                                    <strong>{{ $section['title'] }}</strong>
                                    <span style="{{ $small }}">— {{ __('widget::ui.page.circle', ['position' => $position + 1]) }}</span>
                                </td>
                                <td style="{{ $cell }}" dir="ltr">{{ number_format($n['exposures'] ?? 0) }}</td>
                                <td style="{{ $cell }}" dir="ltr">{{ number_format($n['opens'] ?? 0) }}</td>
                                <td style="{{ $cell }}" dir="ltr">{{ number_format($n['clicks'] ?? 0) }}</td>
                                <td style="{{ $cell }}" dir="ltr">{{ number_format($n['adds'] ?? 0) }}</td>
                            </tr>
                        @endforeach

                        @foreach ($page['extras'] as $extra)
                            @php($n = $counts[$extra['key']] ?? [])
                            <tr style="{{ $extra['on'] ? '' : 'opacity:.55' }}">
                                <td style="{{ $cell }}">
                                    {{ __('widget::ui.page.parts.'.$extra['key']) }}
                                    <x-filament::badge :color="$extra['on'] ? 'success' : 'gray'" style="display:inline-block">
                                        {{ __($extra['on'] ? 'widget::ui.page.shown' : 'widget::ui.page.not_shown') }}
                                    </x-filament::badge>
                                    <div style="{{ $small }}">{{ $extra['detail'] ?: __('widget::ui.page.parts_note.'.$extra['key']) }}</div>
                                </td>
                                <td style="{{ $cell }}" dir="ltr">{{ number_format($n['exposures'] ?? 0) }}</td>
                                <td style="{{ $cell }}" dir="ltr">{{ number_format($n['opens'] ?? 0) }}</td>
                                <td style="{{ $cell }}" dir="ltr">{{ number_format(($n['clicks'] ?? 0) + ($n['questions'] ?? 0)) }}</td>
                                <td style="{{ $cell }}" dir="ltr">{{ number_format($n['adds'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if ($bank['page']['type'] === 'product')
            @php($pop = $why['popularity'][''] ?? null)
            <x-filament::section :heading="__('widget::ui.page.popularity')" collapsible collapsed>
                @if ($pop === null)
                    <p style="{{ $small }}">{{ __('widget::ui.page.no_popularity') }}</p>
                @else
                    <p>{{ __('widget::ui.page.why_popularity', ['adds' => $pop['adds'], 'orders' => $pop['orders'], 'units' => $pop['units'], 'days' => $pop['days'], 'rank' => $pop['rank']]) }}</p>
                    <p style="{{ $small }}">
                        {{ __($pop['popular'] ? 'widget::ui.page.popular_yes' : 'widget::ui.page.popular_no') }}
                        · {{ empty($bank['popularity']['text']) ? __('widget::ui.page.popularity_hidden', ['min' => $pop['min_count']]) : $bank['popularity']['text'] }}
                    </p>
                @endif
            </x-filament::section>
        @endif

        @foreach ($bank['sections'] as $position => $section)
            @php($candidate = $section['candidate'])
            @php($sectionWhy = $why[$candidate][''] ?? [])
            @php($pinnedSection = in_array($candidate, $page['pinned_sections'], true))
            <x-filament::section :heading="($position + 1).'. '.$section['title']" :description="$section['chip']" collapsible>
                <x-slot name="headerEnd">
                    <div style="display:flex;gap:6px">
                        @if ($pinnedSection)
                            <button type="button" wire:click="clear('{{ $candidate }}')" style="{{ $btn }}">{{ __('widget::ui.page.unpin_section') }}</button>
                        @else
                            <button type="button" wire:click="pin('{{ $candidate }}')" style="{{ $btn }}">{{ __('widget::ui.page.pin_section') }}</button>
                        @endif
                        <button type="button" wire:click="hide('{{ $candidate }}')" style="{{ $btn }}">{{ __('widget::ui.page.hide_section') }}</button>
                    </div>
                </x-slot>

                @if (isset($sectionWhy['learned']))
                    @php($l = $sectionWhy['learned'])
                    <p style="{{ $small }};margin-bottom:8px">
                        {{ __('widget::ui.page.learned') }}:
                        @if ($l['page_score'] !== null)
                            {{ __('widget::ui.page.page_score', ['score' => number_format((float) $l['page_score'], 3), 'opens' => $l['page_opens'], 'exposures' => $l['page_exposures']]) }}
                        @elseif ($l['module_score'] !== null)
                            {{ __('widget::ui.page.module_score', ['score' => number_format((float) $l['module_score'], 3)]) }}
                        @else
                            {{ __('widget::ui.page.explore') }}
                        @endif
                        @if ($pinnedSection) · <strong>{{ __('widget::ui.page.pinned_by_team') }}</strong> @endif
                    </p>
                @endif

                @if (! empty($section['lines']) || ! empty($section['items']))
                    <ul style="display:grid;gap:6px;padding-inline-start:18px;list-style:disc">
                        @foreach ($section['lines'] ?? [] as $line)
                            <li>{{ $line['text'] }} <span style="{{ $small }}">— {{ __('widget::ui.page.why_position', ['metric' => $line['metric']]) }}</span></li>
                        @endforeach
                        @foreach ($section['items'] ?? [] as $item)
                            @php($h = $why['highlights'][$item['key']] ?? [])
                            <li><strong>{{ $item['key'] }}</strong> {{ $item['text'] }}
                                <span style="{{ $small }}">— {{ filament()->getCurrentPanel()?->getId() === 'operator' ? __('widget::ui.page.why_highlight', ['quote' => mb_substr((string) ($h['quote'] ?? ''), 0, 90), 'model' => $h['model'] ?? '', 'review' => $h['review'] ?? '']) : __('widget::ui.page.why_highlight_shop', ['quote' => mb_substr((string) ($h['quote'] ?? ''), 0, 90)]) }}@if (! empty($item['common'])) · {{ __('widget::ui.page.common_highlight', ['count' => $h['products_with_this_quote'] ?? 0]) }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if (! empty($section['specs']))
                    <p style="{{ $small }}">{{ __('widget::ui.page.why_specs') }}: {{ collect($section['specs'])->map(fn ($s) => $s['label'].': '.$s['value'])->implode(' · ') }}</p>
                @endif

                @if (! empty($section['uses']))
                    <p style="{{ $small }}">{{ __('widget::ui.page.why_uses') }}: {{ implode(' · ', $section['uses']) }}</p>
                @endif

                @if (isset($section['products']))
                    <table style="width:100%;font-size:14px;border-collapse:collapse;margin-top:6px">
                        @foreach ($section['products'] as $i => $product)
                            @php($w = $why[$candidate][$product['id']] ?? [])
                            @php($isPinned = in_array($product['id'], $page['pinned_items'][$candidate] ?? [], true))
                            <tr style="border-top:1px solid #e4e4e7;{{ $i >= $this->maxProducts() && ! $isPinned ? 'opacity:.5' : '' }}">
                                <td style="padding:6px 4px;width:24px;{{ $small }}">{{ $i + 1 }}</td>
                                <td style="padding:6px 4px">
                                    @php($hits = $activity['by_item'][$candidate][$product['id']] ?? [])
                                    <div>{{ $product['title'] }} <span style="{{ $small }}">#{{ $product['id'] }}</span>
                                        @if ($isPinned) <x-filament::badge color="success">{{ __('widget::ui.page.pinned') }}</x-filament::badge> @endif
                                        @if ($hits !== [])
                                            <x-filament::badge color="info">{{ __('widget::ui.page.item_clicks', ['clicks' => $hits['clicks'] ?? 0]) }}@if (! empty($hits['adds'])) · {{ __('widget::ui.page.item_adds', ['adds' => $hits['adds']]) }}@endif</x-filament::badge>
                                        @endif
                                    </div>
                                    <div style="{{ $small }}">
                                        @if ($isPinned && $w === [])
                                            {{ __('widget::ui.page.why_pinned') }}
                                        @elseif ($w !== [])
                                            {{ $w['label'] ?? __('widget::ui.page.sources.'.($w['source'] ?? 'unknown')) }}
                                            · {{ __('widget::ui.page.relation_score', ['score' => $w['score'] ?? 0]) }}
                                            @if (! empty($w['reasons'])) · <code dir="ltr" style="font-size:11px">{{ $json($w['reasons']) }}</code> @endif
                                        @else
                                            {{ __('widget::ui.page.why_merchant') }}
                                        @endif
                                    </div>
                                </td>
                                <td style="padding:6px 4px;white-space:nowrap;text-align:end">
                                    @if ($isPinned)
                                        <button type="button" wire:click="clear('{{ $candidate }}', '{{ $product['id'] }}')" style="{{ $btn }}">{{ __('widget::ui.page.unpin') }}</button>
                                    @else
                                        <button type="button" wire:click="pin('{{ $candidate }}', '{{ $product['id'] }}')" style="{{ $btn }}">{{ __('widget::ui.page.pin') }}</button>
                                    @endif
                                    <button type="button" wire:click="hide('{{ $candidate }}', '{{ $product['id'] }}')" style="{{ $btn }}">{{ __('widget::ui.page.hide') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                    <div style="margin-top:8px">
                        @if ($this->addTo === $candidate)
                            <input type="search" wire:model.live.debounce.300ms="addSearch" placeholder="{{ __('widget::ui.page.add_search') }}" style="width:100%;padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px">
                            <ul style="margin-top:6px;display:grid;gap:4px">
                                @foreach ($this->addMatches() as $match)
                                    <li><button type="button" wire:click="add('{{ $match->external_id }}')" style="text-decoration:underline;text-align:start">{{ $match->title }}</button> <span style="{{ $small }}">#{{ $match->external_id }}</span></li>
                                @endforeach
                            </ul>
                        @else
                            <button type="button" wire:click="startAdding('{{ $candidate }}')" style="{{ $btn }}">{{ __('widget::ui.page.add_product') }}</button>
                        @endif
                    </div>
                @endif

                @if (! empty($section['guides']))
                    <ul style="display:grid;gap:6px;margin-top:6px">
                        @foreach ($section['guides'] as $guide)
                            @php($g = $why['guide'][$guide['id']] ?? [])
                            <li>
                                <a href="{{ $guide['url'] }}" target="_blank" rel="noopener" style="text-decoration:underline">{{ $guide['title'] }}</a>
                                <span style="{{ $small }}">— {{ __('widget::ui.page.why_guide', ['score' => $g['score'] ?? '?', 'topic' => __('widget::ui.page.topics.'.($g['topic'] ?? 'unknown')), 'kind' => $g['kind'] ?? '?', 'uses' => implode(', ', $g['shared_uses'] ?? [])]) }}@if (! empty($g['matched'])) · {{ __('widget::ui.page.matched_article') }}@endif</span>
                                <button type="button" wire:click="hide('{{ $candidate }}', '{{ $guide['id'] }}')" style="{{ $btn }}">{{ __('widget::ui.page.hide') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if (! empty($section['categories']))
                    <p style="{{ $small }};margin-top:6px">{{ __('widget::ui.page.browse_links') }}: {{ collect($section['categories'])->pluck('title')->implode(' · ') }}</p>
                @endif
            </x-filament::section>
        @endforeach

        @if ($bank['page']['type'] === 'product')
            <x-filament::section
                :heading="__('widget::ui.page.questions')"
                :description="__('widget::ui.page.questions_description')"
                collapsible
            >
                @if ($page['questions']->isEmpty())
                    <p style="{{ $small }}">{{ __('widget::ui.page.no_questions') }}</p>
                @else
                    <div style="display:grid;gap:10px">
                        @foreach ($page['questions'] as $question)
                            <div style="padding:10px 12px;border:1px solid #e4e4e7;border-radius:10px;background:#fff">
                                <div style="font-weight:600">{{ $question->question }}</div>
                                <div style="margin-top:4px">{{ $question->answer ?: __('widget::ui.page.no_answer') }}</div>
                                <div style="margin-top:4px;{{ $small }}">
                                    {{ trans_choice('widget::ui.page.asked_times', $question->asked_count, ['count' => $question->asked_count]) }}
                                    @if ($question->source)
                                        · {{ __('widget::ui.page.answer_sources.'.$question->source) }}
                                    @endif
                                    @if ($question->status === \App\Modules\Assistant\Models\AssistantAnswer::HIDDEN)
                                        · {{ __('widget::ui.page.answer_hidden') }}
                                    @endif
                                    · {{ $question->last_asked_at?->format('d/m/Y H:i') }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endif

        @php($absent = array_diff(\App\Modules\Widget\Models\WidgetCuration::PRODUCT_SECTIONS, array_column($bank['sections'], 'candidate'), $page['hidden_sections'], ['article_products']))
        @if ($bank['page']['type'] === 'product' && $absent !== [])
            <x-filament::section :heading="__('widget::ui.page.absent_heading')" :description="__('widget::ui.page.absent_description')">
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                    @foreach ($absent as $candidate)
                        @if ($this->addTo === $candidate)
                            <div style="flex:1 1 100%">
                                <strong>{{ __('widget::bank.chips.'.$candidate) }}</strong>
                                <input type="search" wire:model.live.debounce.300ms="addSearch" placeholder="{{ __('widget::ui.page.add_search') }}" style="width:100%;margin-top:4px;padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px">
                                <ul style="margin-top:6px;display:grid;gap:4px">
                                    @foreach ($this->addMatches() as $match)
                                        <li><button type="button" wire:click="add('{{ $match->external_id }}')" style="text-decoration:underline;text-align:start">{{ $match->title }}</button> <span style="{{ $small }}">#{{ $match->external_id }}</span></li>
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <button type="button" wire:click="startAdding('{{ $candidate }}')" style="{{ $btn }}">{{ __('widget::ui.page.add_to', ['section' => __('widget::bank.chips.'.$candidate)]) }}</button>
                        @endif
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
