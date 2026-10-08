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
        @if ($install = $this->install())
            @php($ready = $install['connected'] && $install['indexed'])
            <x-filament::section :heading="__('search::ui.install.heading')" :description="__('search::ui.install.description')" collapsible :collapsed="$ready">
                <div style="display:grid;gap:16px;font-size:14px;line-height:1.6">
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        @foreach ([
                            'connected' => $install['connected'],
                            'plugin' => $install['plugin_version'] !== null,
                            'indexed' => $install['indexed'],
                        ] as $check => $ok)
                            <span style="display:inline-flex;gap:6px;align-items:center;padding:3px 12px;border-radius:999px;font-size:12.5px;{{ $ok ? 'background:rgba(27,175,122,.14)' : 'background:rgba(235,104,52,.14)' }}">
                                {{ $ok ? '✓' : '!' }}
                                {{ __('search::ui.install.checks.'.$check.'.'.($ok ? 'yes' : 'no'), ['version' => $install['plugin_version']]) }}
                            </span>
                        @endforeach
                    </div>

                    <div>
                        <strong>{{ __('search::ui.install.wordpress.heading') }}</strong>
                        <ol style="margin:6px 0 0;padding-inline-start:20px;display:grid;gap:6px">
                            <li>{{ __('search::ui.install.wordpress.plugin') }}</li>
                            <li>{{ __('search::ui.install.wordpress.mode') }}</li>
                            <li>
                                {{ __('search::ui.install.wordpress.field') }}
                                <code dir="ltr" style="padding:1px 6px;border-radius:6px;background:rgba(127,127,127,.12)">{{ $install['selector'] }}</code>.
                                {{ __('search::ui.install.wordpress.field_other') }}
                            </li>
                            <li>{{ __('search::ui.install.wordpress.results', ['how' => __('search::settings.results.options.'.$install['results'])]) }}</li>
                            <li>
                                {{ __('search::ui.install.wordpress.check') }}
                                @if ($install['preview_url'])
                                    <br><a href="{{ $install['preview_url'] }}" target="_blank" rel="noopener" dir="ltr" style="text-decoration:underline;word-break:break-all">{{ $install['preview_url'] }}</a>
                                @endif
                            </li>
                        </ol>
                    </div>

                    <details>
                        <summary style="cursor:pointer;font-weight:600">{{ __('search::ui.install.other.heading') }}</summary>
                        <p style="margin:8px 0">{{ __('search::ui.install.other.text') }}</p>
                        @if ($install['site_key'])
                            <pre dir="ltr" style="white-space:pre-wrap;word-break:break-all;font-size:12.5px;line-height:1.5;padding:12px;border-radius:12px;background:rgba(127,127,127,.1);margin:0">{{ '<script>'."\n".'window.LetAgentsSearchContext = '.json_encode(['site' => $install['site_key'], 'api' => $install['api'], 'locale' => $install['locale'], 'searchUrl' => ($install['site_url'] ?? '').'/'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).';'."\n".'</script>'."\n".'<script src="'.$install['api'].'/search/let-agents-search.js" defer></script>' }}</pre>
                            <p style="margin:8px 0 0;font-size:12.5px;opacity:.75">{{ __('search::ui.install.other.note', ['site' => $install['site_url']]) }}</p>
                        @else
                            <p style="margin:0;opacity:.75">{{ __('search::ui.install.other.no_key') }}</p>
                        @endif
                    </details>
                </div>
            </x-filament::section>
        @endif

        @if ($photos = $this->photos())
            <x-filament::section :heading="__('search::ui.photos.heading')" :description="__('search::ui.photos.'.($photos['switch'] ? 'description' : 'description_shop'))">
                <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;justify-content:space-between">
                    <div style="display:grid;gap:6px;font-size:14px">
                        <strong>{{ __('search::ui.photos.'.($photos['on'] ? 'is_on' : 'is_off')) }}</strong>
                        <span>
                            @if ($photos['total'] === 0)
                                {{ __('search::ui.photos.no_pictures') }}
                            @elseif (! $photos['scanning'] && $photos['scanned'] === 0)
                                {{ __('search::ui.photos.not_scanning') }}
                            @else
                                {{ __('search::ui.photos.scanned', ['scanned' => number_format(min($photos['scanned'], $photos['total'])), 'total' => number_format($photos['total'])]) }}
                                @if ($photos['unreadable'] > 0)
                                    <span style="{{ $small }}">· {{ __('search::ui.photos.unreadable', ['count' => number_format($photos['unreadable'])]) }}</span>
                                @endif
                            @endif
                        </span>
                        @if ($photos['running'] || $photos['queued'])
                            {{-- While a scan waits or runs, the status refreshes itself. --}}
                            <span wire:poll.5s style="{{ $small }}">{{ __('search::ui.photos.'.($photos['running'] ? 'running' : 'queued')) }}</span>
                        @endif
                        @if ($photos['stuck'])
                            <span style="{{ $small }}">⚠ {{ __('search::ui.photos.'.($photos['switch'] ? 'stuck' : 'stuck_shop')) }}</span>
                        @endif
                        @if (! $photos['running'] && $photos['stopped'])
                            <span style="{{ $small }}">⚠ {{ $photos['switch'] ? __('search::ui.photos.stopped.'.(in_array($photos['stopped'], ['unknown_provider', 'no_key', 'spend_cap', 'unsupported'], true) ? $photos['stopped'] : 'other'), ['reason' => $photos['stopped']]) : __('search::ui.photos.stopped_shop') }}</span>
                        @elseif (! $photos['running'] && $photos['last'] && $photos['pending'] > 0)
                            <span style="{{ $small }}">{{ __('search::ui.photos.pending', ['count' => number_format($photos['pending'])]) }}</span>
                        @endif
                        @if ($photos['switch'] && $photos['unreadable'] > 0)
                            <span style="{{ $small }}">{{ __('search::ui.photos.unreadable_why') }}</span>
                        @endif
                        @if ($photos['last'])
                            <span style="{{ $small }}">
                                {{ __('search::ui.photos.last_scan', ['when' => $photos['last']->diffForHumans()]) }}
                                @if ($photos['last_failed'])
                                    · {{ __('search::ui.photos.'.($photos['switch'] ? 'last_failed' : 'last_failed_shop')) }}
                                @endif
                            </span>
                        @endif
                        <span>
                            @if ($photos['on'] && $photos['ready'])
                                ✓ {{ __('search::ui.photos.camera_shown') }}
                            @elseif ($photos['on'])
                                {{ __('search::ui.photos.not_ready') }}
                            @elseif (! $photos['switch'])
                                <span style="{{ $small }}">{{ __('search::ui.photos.ask_us') }}</span>
                            @endif
                        </span>
                    </div>
                    @if ($photos['can_scan'] || $photos['switch'])
                        <div style="display:flex;flex-wrap:wrap;gap:8px">
                            @if ($photos['can_scan'])
                                <button type="button" style="{{ $btn }}" wire:click="scanPicturesNow" wire:loading.attr="disabled" @disabled($photos['running'] || $photos['total'] === 0)>{{ __('search::ui.photos.scan_now') }}</button>
                            @endif
                            @if ($photos['switch'])
                                <button type="button" style="{{ $btn }}" wire:click="setPhotos({{ $photos['on'] ? 'false' : 'true' }})">{{ __('search::ui.photos.'.($photos['on'] ? 'turn_off' : 'turn_on')) }}</button>
                            @endif
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif

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

        <x-filament::section :heading="__('search::ui.resolved.heading')" :description="__('search::ui.resolved.description')">
            @if ($r['resolutions']->isEmpty())
                <p style="opacity:.7">{{ __('search::ui.resolved.none') }}</p>
            @else
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead><tr>
                        <th style="{{ $cell }}">{{ __('search::ui.columns.query') }}</th>
                        <th style="{{ $cell }}">{{ __('search::ui.columns.searches') }}</th>
                        <th style="{{ $cell }}">{{ __('search::ui.resolved.shows') }}</th>
                        <th style="{{ $cell }}"></th>
                        <th style="{{ $cell }}"></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($r['resolutions'] as $row)
                            <tr>
                                <td style="{{ $cell }};font-weight:600">{{ $row->query }}</td>
                                <td style="{{ $cell }}" dir="ltr">{{ number_format((int) $row->searches) }}</td>
                                <td style="{{ $cell }}">
                                    @if ($row->status === 'resolved' || $row->status === 'rejected')
                                        {{ collect($row->candidates)->whereIn('id', collect($row->products)->map(fn ($p) => 'p:'.$p)->merge(collect($row->content)->map(fn ($c) => 'c:'.$c)))->pluck('title')->take(4)->implode(' · ') }}
                                        @if ($row->synonym_means)<span style="{{ $small }}"> · {{ __('search::ui.resolved.synonym') }}: {{ $row->query }} = {{ $row->synonym_means }}</span>@endif
                                    @else
                                        <span style="{{ $small }}">{{ $row->reason }}</span>
                                    @endif
                                </td>
                                <td style="{{ $cell }}"><span style="{{ $small }}">{{ __('search::ui.resolved.statuses.'.$row->status) }}</span></td>
                                <td style="{{ $cell }}">
                                    @if ($row->status === 'resolved')
                                        <button type="button" style="{{ $btn }}" wire:click="undoResolution('{{ $row->id }}')">{{ __('search::ui.resolved.undo') }}</button>
                                    @elseif ($row->status === 'rejected' || $row->status === 'refused')
                                        <button type="button" style="{{ $btn }}" wire:click="restoreResolution('{{ $row->id }}')">{{ __('search::ui.resolved.restore') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <div style="margin-top:12px"><button type="button" wire:click="resolveNow" wire:loading.attr="disabled" style="{{ $btn }}">{{ __('search::ui.resolved.resolve_now') }}</button></div>
        </x-filament::section>

        <x-filament::section :heading="__('search::ui.tags.heading')" :description="__('search::ui.tags.description')" collapsible>
            @if ($r['pageTags']->isEmpty())
                <p style="opacity:.7">{{ __('search::ui.tags.none') }}</p>
            @else
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead><tr>
                        <th style="{{ $cell }}">{{ __('search::ui.tags.page') }}</th>
                        <th style="{{ $cell }}"></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($r['pageTags'] as $row)
                            <tr>
                                <td style="{{ $cell }};font-weight:600;width:30%">{{ $row->title }}</td>
                                <td style="{{ $cell }}">
                                    @foreach ($row->shown() as $tag)
                                        <span style="display:inline-flex;align-items:center;gap:4px;margin:2px;padding:3px 10px;border-radius:999px;background:#f5f0fb;font-size:13px" title="{{ $tag['query'] }}">
                                            {{ $tag['label'] }}
                                            <button type="button" style="border:0;background:none;cursor:pointer;opacity:.6" aria-label="{{ __('search::ui.tags.remove') }}" wire:click="hideTag('{{ $row->id }}', {{ \Illuminate\Support\Js::from($tag['label']) }})">×</button>
                                        </span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <div style="margin-top:12px"><button type="button" wire:click="writeTagsNow" wire:loading.attr="disabled" style="{{ $btn }}">{{ __('search::ui.tags.write_now') }}</button></div>
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
                                    <td style="{{ $cell }}">{{ $row->query === '[photo]' ? __('search::ui.photo_query') : $row->query }}</td>
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
