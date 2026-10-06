<x-filament-panels::page>
    @php($r = $this->report())
    @php($small = 'font-size:12px;opacity:.7')
    @php($btn = 'font-size:12px;padding:3px 10px;border:1px solid #d4d4d8;border-radius:999px;background:#fff;cursor:pointer')
    @php($cell = 'padding:6px 8px;border-bottom:1px solid #f1f1f4;text-align:start;vertical-align:top')
    @php($input = 'padding:6px 10px;border:1px solid #d4d4d8;border-radius:8px;font:inherit;min-width:0')

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        @if ($this->picksShop())
            <select wire:model.live="shop" style="{{ $input }};min-width:220px">
                @foreach ($this->shops() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        <select wire:model.live="days" style="{{ $input }}">
            @foreach ([7, 30, 90] as $d)
                <option value="{{ $d }}">{{ __('search::ui.days', ['days' => $d]) }}</option>
            @endforeach
        </select>
    </div>

    @if ($r === null)
        <x-filament::section>{{ __('search::ui.no_shop') }}</x-filament::section>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px">
            @foreach ([
                'searches' => number_format($r['totals']['searches']),
                'distinct' => number_format($r['totals']['distinct']),
                'empty_share' => $r['totals']['empty_share'].'%',
                'click_share' => $r['totals']['click_share'].'%',
            ] as $key => $value)
                <x-filament::section compact>
                    <div style="{{ $small }}">{{ __("search::ui.totals.{$key}") }}</div>
                    <div style="font-size:22px;font-weight:600;margin-top:4px" dir="ltr">{{ $value }}</div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section :heading="__('search::ui.empty.heading')" :description="__('search::ui.empty.description')">
            @if ($r['empty']->isEmpty())
                <p style="opacity:.7">{{ __('search::ui.empty.none') }}</p>
            @else
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead><tr>
                        <th style="{{ $cell }}">{{ __('search::ui.columns.query') }}</th>
                        <th style="{{ $cell }}">{{ __('search::ui.columns.empty') }}</th>
                        <th style="{{ $cell }}">{{ __('search::ui.columns.searches') }}</th>
                        <th style="{{ $cell }}"></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($r['empty'] as $row)
                            <tr>
                                <td style="{{ $cell }};font-weight:600">{{ $row->query }}</td>
                                <td style="{{ $cell }}" dir="ltr">{{ number_format((int) $row->empty) }}</td>
                                <td style="{{ $cell }}" dir="ltr">{{ number_format((int) $row->searches) }}</td>
                                <td style="{{ $cell }}"><button type="button" style="{{ $btn }}" wire:click="$set('synonymTerm', @js($row->query))">{{ __('search::ui.empty.make_synonym') }}</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('search::ui.synonyms.heading')" :description="__('search::ui.synonyms.description')">
            <form wire:submit="addSynonym" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                <input type="text" wire:model="synonymTerm" placeholder="{{ __('search::ui.synonyms.term') }}" style="{{ $input }}" maxlength="80">
                <span style="opacity:.6">=</span>
                <input type="text" wire:model="synonymMeans" placeholder="{{ __('search::ui.synonyms.means') }}" style="{{ $input }}" maxlength="120">
                <button type="submit" style="{{ $btn }};background:#111827;color:#fff;border-color:#111827">{{ __('search::ui.synonyms.add') }}</button>
            </form>
            @if ($r['synonyms']->isNotEmpty())
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px">
                    @foreach ($r['synonyms'] as $synonym)
                        <span style="display:inline-flex;gap:8px;align-items:center;padding:4px 10px;border:1px solid #e4e4e7;border-radius:999px;font-size:13px">
                            <strong>{{ $synonym->term }}</strong> = {{ $synonym->means }}
                            @if ($synonym->origin !== 'team')<span style="{{ $small }}">{{ __('search::ui.synonyms.from_review') }}</span>@endif
                            <button type="button" wire:click="removeSynonym('{{ $synonym->id }}')" style="border:0;background:none;cursor:pointer;opacity:.6" aria-label="{{ __('search::ui.synonyms.remove') }}">×</button>
                        </span>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
            <x-filament::section :heading="__('search::ui.top.heading')">
                @if ($r['top']->isEmpty())
                    <p style="opacity:.7">{{ __('search::ui.top.none') }}</p>
                @else
                    <table style="width:100%;border-collapse:collapse;font-size:14px">
                        <thead><tr>
                            <th style="{{ $cell }}">{{ __('search::ui.columns.query') }}</th>
                            <th style="{{ $cell }}">{{ __('search::ui.columns.searches') }}</th>
                            <th style="{{ $cell }}">{{ __('search::ui.columns.clicks') }}</th>
                            <th style="{{ $cell }}">{{ __('search::ui.columns.results') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($r['top'] as $row)
                                <tr>
                                    <td style="{{ $cell }}">{{ $row->query }}</td>
                                    <td style="{{ $cell }}" dir="ltr">{{ number_format((int) $row->searches) }}</td>
                                    <td style="{{ $cell }}" dir="ltr">{{ number_format((int) $row->clicks) }}</td>
                                    <td style="{{ $cell }}" dir="ltr">{{ number_format((int) $row->results) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>

            <x-filament::section :heading="__('search::ui.clicked.heading')">
                @if ($r['clicked']->isEmpty())
                    <p style="opacity:.7">{{ __('search::ui.clicked.none') }}</p>
                @else
                    <table style="width:100%;border-collapse:collapse;font-size:14px">
                        <thead><tr>
                            <th style="{{ $cell }}">{{ __('search::ui.columns.result') }}</th>
                            <th style="{{ $cell }}">{{ __('search::ui.columns.clicks') }}</th>
                            <th style="{{ $cell }}">{{ __('search::ui.columns.queries') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($r['clicked'] as $row)
                                <tr>
                                    <td style="{{ $cell }}">{{ $row->title !== '' ? $row->title : $row->item }}</td>
                                    <td style="{{ $cell }}" dir="ltr">{{ number_format((int) $row->clicks) }}</td>
                                    <td style="{{ $cell }}" dir="ltr">{{ number_format((int) $row->queries) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        </div>

        <x-filament::section :heading="__('search::ui.index.heading')" collapsible>
            <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center">
                @if ($r['index'])
                    <span>{{ __('search::ui.index.status', [
                        'when' => $r['index']->built_at->diffForHumans(),
                        'products' => number_format((int) ($r['index']->counts['product'] ?? 0)),
                        'content' => number_format((int) ($r['index']->counts['content'] ?? 0)),
                        'categories' => number_format((int) ($r['index']->counts['category'] ?? 0)),
                    ]) }}</span>
                @else
                    <span style="opacity:.7">{{ __('search::ui.index.never') }}</span>
                @endif
                <button type="button" wire:click="buildNow" wire:loading.attr="disabled" style="{{ $btn }}">{{ __('search::ui.index.build_now') }}</button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
